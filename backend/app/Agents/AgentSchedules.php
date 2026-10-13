<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentTrigger;
use App\Models\AuditLog;
use App\Services\Auth\Audit;
use App\Services\Flows\FlowValidator;
use App\Services\Kestra\KestraClient;
use App\Services\Kestra\KestraException;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Zamanlama tetikleyicilerinin Kestra'daki akışları. YAML'ı Claude değil Laravel üretir ve FlowValidator'dan geçirir:
 * tek görev, zamanı gelince Laravel'in iç ucuna (X-Fortis-Key secret'ıyla) çalışma isteği. Agent pilot/canlı değilse
 * veya tetikleyici kapalıysa akış Kestra'da devre dışıdır. Kestra'ya ulaşılamazsa hata tetikleyicide kalır ve agent
 * sahibinin Inbox'ına uyarı düşer; `fortis:agent:sync-triggers` sonra yeniden dener.
 */
class AgentSchedules
{
    public function __construct(
        private readonly KestraClient $kestra,
        private readonly FlowValidator $validator,
        private readonly Inbox $inbox,
    ) {}

    public static function namespace(): string
    {
        return config('agents.kestra_namespace');
    }

    public static function flowId(AgentTrigger $trigger): string
    {
        $slug = Str::of($trigger->agent->slug)->lower()->replaceMatches('/[^a-z0-9-]+/', '-')->trim('-')->limit(60, '');

        return "agent-{$slug}-{$trigger->id}";
    }

    public static function callbackUri(Agent $agent): string
    {
        return config('agents.internal_url')."/api/internal/agents/{$agent->id}/runs";
    }

    /** Akış Kestra'da çalışır durumda mı olmalı. */
    public static function shouldRun(AgentTrigger $trigger): bool
    {
        return $trigger->enabled && in_array($trigger->agent->status, [Agent::STATUS_PILOT, Agent::STATUS_LIVE], true);
    }

    public function yaml(AgentTrigger $trigger): string
    {
        $agent = $trigger->agent;
        $secret = config('agents.internal_key_secret');

        return Yaml::dump([
            'id' => self::flowId($trigger),
            'namespace' => self::namespace(),
            'description' => "Fortis agent zamanlaması: {$agent->name}. Laravel üretir; Kestra'da elle düzenlemeyin.",
            'labels' => ['fortis_agent' => (string) $agent->id, 'fortis_trigger' => (string) $trigger->id],
            'disabled' => ! self::shouldRun($trigger),
            'tasks' => [[
                'id' => 'start_agent_run',
                'type' => 'io.kestra.plugin.core.http.Request',
                'uri' => self::callbackUri($agent),
                'method' => 'POST',
                'contentType' => 'application/json',
                'headers' => ['X-Fortis-Key' => "{{ secret('{$secret}') }}", 'Accept' => 'application/json'],
                'body' => json_encode(['trigger_id' => $trigger->id]),
            ]],
            'triggers' => [[
                'id' => 'schedule',
                'type' => 'io.kestra.plugin.core.trigger.Schedule',
                'cron' => $trigger->config['cron'],
                'timezone' => config('agents.timezone'),
            ]],
        ], 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /** Agent'ın bütün zamanlama tetikleyicilerini eşitler (durum değişince). */
    public function syncAgent(Agent $agent): void
    {
        $agent->triggers()->where('type', AgentTrigger::SCHEDULE)->get()->each(fn (AgentTrigger $t) => $this->sync($t->setRelation('agent', $agent)));
    }

    /** Tetikleyicinin akışını Kestra'ya yükler veya günceller. Başarısızsa false; hata tetikleyicide ve Inbox'ta. */
    public function sync(AgentTrigger $trigger): bool
    {
        if ($trigger->type !== AgentTrigger::SCHEDULE) {
            return true;
        }

        // Taslak agentın zamanlaması Kestra'ya pilota alınınca yüklenir (Kestra'da kapalı akış biriktirmeyelim).
        if (! self::shouldRun($trigger) && $trigger->kestra_flow_id === null) {
            $trigger->update(['sync_error' => null]);

            return true;
        }

        $yaml = $this->yaml($trigger);
        $result = $this->validator->validate($yaml, FlowValidator::MODE_UPLOAD, self::namespace(), self::callbackUri($trigger->agent));

        if (! $result['valid']) {
            return $this->failed($trigger, 'Zamanlama akışı doğrulanamadı: '.implode(' ', $result['errors']));
        }

        $flowId = self::flowId($trigger);
        try {
            $this->kestra->flowExists(self::namespace(), $flowId)
                ? $this->kestra->updateFlow(self::namespace(), $flowId, $result['canonical_yaml'] ?? $yaml)
                : $this->kestra->createFlow($result['canonical_yaml'] ?? $yaml);
        } catch (KestraException $e) {
            return $this->failed($trigger, "Kestra'ya yüklenemedi: {$e->getMessage()}");
        }

        $trigger->update(['kestra_flow_id' => $flowId, 'synced_at' => now(), 'sync_error' => null]);
        $this->inbox->closeTriggerAlerts($trigger);
        Audit::record('agent.trigger.sync', $trigger, meta: ['agent_id' => $trigger->agent_id, 'flow_id' => $flowId, 'enabled' => self::shouldRun($trigger)], system: 'Kestra');

        return true;
    }

    /** Tetikleyici silinirken akışı Kestra'dan siler. Kestra'ya ulaşılamazsa false (tetikleyici silinmez). */
    public function remove(AgentTrigger $trigger): bool
    {
        if ($trigger->kestra_flow_id === null) {
            return true;
        }

        try {
            $this->kestra->deleteFlow(self::namespace(), $trigger->kestra_flow_id);
        } catch (KestraException $e) {
            $this->failed($trigger, "Kestra'daki akış silinemedi: {$e->getMessage()}");

            return false;
        }

        Audit::record('agent.trigger.delete', $trigger, meta: ['agent_id' => $trigger->agent_id, 'flow_id' => $trigger->kestra_flow_id], system: 'Kestra');

        return true;
    }

    private function failed(AgentTrigger $trigger, string $error): bool
    {
        $trigger->update(['sync_error' => mb_substr($error, 0, 2000)]);
        $this->inbox->triggerAlert($trigger, "{$trigger->agent->name}: zamanlama Kestra'ya aktarılamadı", $error.' Zamanlanmış çalışmalar bu düzelene kadar başlamayabilir; Fortis yeniden deneyecek.');
        Audit::record('agent.trigger.sync', $trigger, AuditLog::RESULT_FAILED, meta: ['agent_id' => $trigger->agent_id, 'error' => mb_substr($error, 0, 500)], system: 'Kestra');

        return false;
    }
}
