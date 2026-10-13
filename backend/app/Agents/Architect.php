<?php

namespace App\Agents;

use App\Ai\ApmResultShaper;
use App\Models\Agent;
use App\Models\User;
use App\Services\Settings\AppSettings;

/**
 * Architect kipi: agent'ı sohbetle kuran Claude. Sistem promptuna Architect bölümü (katalog, kurallar, şu anki taslak)
 * eklenir; araçlar `propose_agent`, `ask_user` ve veri yapısını anlamaya yarayan okuma araçlarıyla sınırlıdır.
 */
class Architect
{
    /** Architect'in kullanabileceği araçlar (veri sorguları ayar kapalıyken zaten sunulmaz). */
    public const TOOLS = [
        'propose_agent', 'ask_user', 'get_queries', 'describe_warehouse', 'describe_apm', 'describe_successfactors',
        'list_report_sources', 'query_warehouse', 'query_apm', 'query_successfactors',
    ];

    public function __construct(private readonly AgentDrafts $drafts) {}

    public function promptSection(Agent $agent): string
    {
        $catalog = collect(config('agents.tools'))
            ->map(fn (array $tool, string $name) => "- `{$name}` ({$tool['kind']} · {$tool['system']}): {$tool['label']}. {$tool['description']}")
            ->implode("\n");
        $events = collect(config('agents.events'))->map(fn (array $e, string $name) => "`{$name}` ({$e['label']})")->implode(', ');
        $draft = $agent->current_version > 0 ? AgentDrafts::snapshot($agent, forClaude: true) : ['durum' => 'Henüz taslak yok; ilk propose_agent çağrısıyla oluşur.'];

        return strtr((string) file_get_contents(resource_path('prompts/architect.md')), [
            '{{TOOL_CATALOG}}' => $catalog,
            '{{EVENTS}}' => $events,
            '{{APM_FIELDS}}' => collect(app(ApmResultShaper::class)->fields())->map(fn (array $columns, string $operation) => "- `{$operation}` → ".implode(', ', array_map(fn (string $c) => "`{$c}`", $columns)))->implode("\n"),
            '{{DOMAINS}}' => implode(', ', app(AppSettings::class)->recipientDomains()),
            '{{TEAMS_CHANNELS}}' => implode(', ', array_keys(config('agents.teams_channels'))) ?: 'henüz yok',
            '{{DRAFT}}' => json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * `propose_agent` aracının sonucu (Claude'a döner).
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, result: array<string, mixed>}
     */
    public function propose(Agent $agent, array $input, User $actor): array
    {
        if (! in_array($agent->status, [Agent::STATUS_DRAFT, Agent::STATUS_PILOT, Agent::STATUS_PAUSED, Agent::STATUS_LIVE], true)) {
            return ['ok' => false, 'result' => ['saved' => false, 'errors' => ['Bu agent düzenlenemez.']]];
        }

        // Onay kuralı: Architect onaysız gönderim öneremez. Önerdiği mod yok sayılır; yazan araç her zaman onaylıdır.
        // Sadece builder/admin'in Yapılandırma'dan elle seçtiği mod (varsa) yeniden kayıtta korunur.
        $requested = collect(is_array($input['approval_policy'] ?? null) ? $input['approval_policy'] : [])
            ->filter(fn ($rule, $tool) => $tool !== 'approvers' && is_array($rule) && ($rule['mode'] ?? 'always') !== 'always')->keys()->all();
        $input['approval_policy'] = ['approvers' => $input['approval_policy']['approvers'] ?? ['owner']];

        ['errors' => $errors, 'draft' => $draft] = $this->drafts->validate($input, $actor, $agent->owner);
        if (($raw = self::rawApmFields($input)) !== []) {
            $errors[] = 'Talimatta APM\'nin ham alan yolları var ('.implode(', ', $raw).'). query_apm bunları döndürmez; promptta listelenen düz alan adlarını kullan (ör. dep_delay_min, delay_codes, cancelled, std_utc).';
        }

        if ($errors !== []) {
            return ['ok' => false, 'result' => ['saved' => false, 'reason' => 'Taslak kaydedilmedi; hataları düzeltip propose_agent\'ı tekrar çağır.', 'errors' => $errors]];
        }

        foreach ($draft['approval_policy'] as $tool => $rule) {
            $manual = ($agent->approval_policy ?? [])[$tool]['mode'] ?? 'always';
            if ($tool !== 'approvers' && $manual !== 'always') {
                $draft['approval_policy'][$tool] = ['mode' => $manual];
            }
        }

        $agent = $this->drafts->apply($agent, $draft, $actor);

        return ['ok' => true, 'result' => [
            'saved' => true,
            'version' => $agent->current_version,
            'status' => $agent->status,
            ...($requested !== [] ? ['approval_note' => 'Onaysız gönderim Architect\'te ayarlanamaz; '.implode(', ', $requested).' her zaman onaylı kaydedildi. Kullanıcı isterse Yapılandırma > Onaylar\'dan değiştirebilir; bunu kullanıcıya söyle.'] : []),
            'note' => 'Taslak kaydedildi ve sağdaki plan görünümü güncellendi. Kullanıcı "Son veriyle test et" ile deneyebilir, sonra "Pilota al".',
        ]];
    }

    /**
     * Talimat ve görev metinlerinde geçen ham APM alan yolları (query_apm'in düzleştirdiği yapının içi).
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public static function rawApmFields(array $input): array
    {
        $text = implode("\n", [(string) ($input['instructions'] ?? ''), ...array_map(fn ($t) => (string) ($t['input_template'] ?? ''), (array) ($input['triggers'] ?? []))]);
        preg_match_all('/\b(?:delaysInfo|flightTimes|statusInfo|flightNumberInfo|airportsInfo|aircraftInfo|passengerInfo|changeReasons|legLogList|departureDelay\d|totalNumberOfPages|totalNumberOfItems)\b[\w.]*/', $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    /** @return array<string, mixed> */
    public static function definition(): array
    {
        $step = ['type' => 'object', 'properties' => [
            'type' => ['type' => 'string', 'enum' => array_keys(config('agents.plan_step_types'))],
            'title' => ['type' => 'string'],
            'detail' => ['type' => 'string'],
            'no' => ['type' => 'string', 'description' => 'Sadece decide: "hayır" dalında ne olur.'],
        ], 'required' => ['type', 'title']];

        return [
            'name' => 'propose_agent',
            'description' => 'Agent taslağını doğrular ve kaydeder (her çağrı yeni sürüm). Taslağın tamamını gönder.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'icon' => ['type' => 'string', 'description' => '2 harf'],
                    'instructions' => ['type' => 'string'],
                    'model' => ['type' => 'string'],
                    'tools' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'constraints' => ['type' => 'object']], 'required' => ['name']]],
                    'triggers' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['schedule', 'event']],
                        'cron' => ['type' => 'string'],
                        'event' => ['type' => 'string'],
                        'min_delay_minutes' => ['type' => 'integer'],
                        'input_template' => ['type' => 'string'],
                    ], 'required' => ['type']]],
                    'approval_policy' => ['type' => 'object'],
                    'plan' => ['type' => 'array', 'items' => $step],
                    'suggested_prompts' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['name', 'description', 'instructions', 'tools', 'plan'],
            ],
        ];
    }
}
