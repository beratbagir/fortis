<?php

namespace App\Agents;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;
use App\Ai\ModelRouter;
use App\Models\AgentRun;
use App\Models\AgentRunScore;
use App\Models\AiCall;
use App\Models\AuditLog;
use App\Services\Auth\Audit;
use App\Services\Claude\CostCalculator;
use App\Services\Usage\Budget;
use App\Services\Usage\BudgetExceededException;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Çalışmanın çıktısını agent'ın değerlendirme kurallarına göre hızlı modelle puanlar. Claude'a sadece agent'ın adı,
 * açıklaması, kurallar ve çalışmanın çıktı metni gider: sorgu sonuçları, araç girdileri ve çalışma konuşması gitmez.
 * Puan = geçen kural oranı (0–100). Maliyet ai_calls'a purpose = evaluation ile yazılır.
 */
class RunScorer
{
    private const SYSTEM = 'Bir yapay zekâ agentının çıktısını verilen kurallara göre değerlendiriyorsun. Sadece çıktı metnini görürsün; agentın baktığı veriyi göremezsin. '
        .'Bir kural veriyle karşılaştırma gerektiriyorsa (ör. "rakamlar kaynakla tutarlı") çıktının kendi içindeki tutarlılığına bak ve bunu gerekçede belirt. '
        .'Her kural için, kurallarla aynı sırada, geçti/kaldı ve bir cümlelik Türkçe gerekçe ver. Kişisel bilgi yazma.';

    private const MAX_OUTPUT_CHARS = 12000;

    /** @var Closure(array<string, mixed>): BetaMessage */
    private Closure $send;

    /**
     * @param  (Closure(array<string, mixed>): BetaMessage)|null  $send  API çağrısı (testte değiştirilir)
     */
    public function __construct(
        Client $client,
        private readonly ModelRouter $router,
        private readonly CostCalculator $costs,
        private readonly Budget $budget,
        ?Closure $send = null,
    ) {
        $this->send = $send ?? fn (array $request): BetaMessage => $client->beta->messages->create(...$request);
    }

    /** Değerlendirme açık, kural var ve çıktı varsa puanlanır (sohbet çalışmaları hariç). */
    public static function shouldScore(AgentRun $run): bool
    {
        return $run->source !== 'chat'
            && trim((string) $run->output) !== ''
            && in_array($run->status, [AgentRun::SUCCEEDED, AgentRun::WAITING_APPROVAL], true)
            && ($run->agent?->evaluation?->isActive() ?? false);
    }

    /**
     * @throws RuntimeException Model kurala uygun cevap vermezse veya API hatasında (kuyruk işi yeniden dener)
     */
    public function score(AgentRun $run): ?AgentRunScore
    {
        $agent = $run->agent;
        $criteria = array_values($agent->evaluation?->criteria ?? []);
        if ($criteria === [] || trim((string) $run->output) === '') {
            return null;
        }

        try {
            $this->budget->assertChatAllowed();
        } catch (BudgetExceededException) {
            return null;
        }

        $model = $this->router->fast();
        $startedAt = hrtime(true);
        $rules = implode("\n", array_map(fn (string $c, int $i) => ($i + 1).'. '.$c, $criteria, array_keys($criteria)));
        $request = [
            'model' => $model,
            'maxTokens' => 2000,
            'system' => self::SYSTEM,
            'messages' => [['role' => 'user', 'content' => "Agent: {$agent->name}".($agent->description ? " — {$agent->description}" : '')
                ."\n\nKurallar:\n{$rules}\n\nÇıktı:\n<<<\n".mb_substr((string) $run->output, 0, self::MAX_OUTPUT_CHARS)."\n>>>"]],
            // Yapılandırılmış çıktı (JSON şeması). Zorunlu araç seçimi bu modellerde desteklenmiyor.
            'outputConfig' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => self::schema()]],
        ];

        try {
            $response = ($this->send)($request);
        } catch (Throwable $e) {
            AiCall::create(['conversation_id' => $run->conversation_id, 'agent_run_id' => $run->id, 'source' => AiCall::SOURCE_CHAT, 'purpose' => 'evaluation', 'model' => $model, 'duration_ms' => self::elapsedMs($startedAt), 'error' => mb_substr($e->getMessage(), 0, 2000)]);

            throw new RuntimeException('Puanlama yapılamadı: '.$e->getMessage(), previous: $e);
        }

        $usage = $response->usage;
        AiCall::create([
            'conversation_id' => $run->conversation_id,
            'agent_run_id' => $run->id,
            'source' => AiCall::SOURCE_CHAT,
            'purpose' => 'evaluation',
            'model' => $response->model,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cost_usd' => $this->costs->cost($response->model, $usage->inputTokens, $usage->outputTokens, 0, 0),
            'duration_ms' => self::elapsedMs($startedAt),
            'stop_reason' => $response->stopReason,
            'request_id' => $response->id,
        ]);

        $text = collect(json_decode((string) json_encode($response->content), true))->where('type', 'text')->pluck('text')->implode('');
        $input = json_decode($text, true);
        $results = is_array($input['results'] ?? null) ? array_values(array_filter($input['results'], 'is_array')) : [];
        if (count($results) !== count($criteria)) {
            throw new RuntimeException('Puanlama cevabı kural sayısıyla uyuşmuyor.');
        }

        $details = array_map(fn (string $rule, array $result) => [
            'rule' => $rule,
            'passed' => (bool) ($result['passed'] ?? false),
            'reason' => mb_substr(trim((string) ($result['reason'] ?? '')), 0, 300),
        ], $criteria, $results);
        $score = (int) round(100 * count(array_filter($details, fn (array $d) => $d['passed'])) / count($details));

        $run->score()->delete();
        $saved = AgentRunScore::create(['agent_run_id' => $run->id, 'score' => $score, 'details' => $details, 'model' => $response->model]);
        Audit::record('agent.run.score', $run, AuditLog::RESULT_ALLOWED, ['agent_id' => $agent->id, 'score' => $score], actorType: AuditLog::ACTOR_AGENT, actorLabel: $agent->name, user: $run->ownerUser());

        return $saved;
    }

    /** @return array<string, mixed> Her kural için (aynı sırada) geçti/kaldı ve gerekçe. */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'results' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['passed' => ['type' => 'boolean'], 'reason' => ['type' => 'string']],
                        'required' => ['passed', 'reason'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['results'],
            'additionalProperties' => false,
        ];
    }

    private static function elapsedMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
