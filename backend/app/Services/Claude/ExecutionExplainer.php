<?php

namespace App\Services\Claude;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use App\Models\AiCall;
use App\Models\Execution;
use App\Services\Settings\AppSettings;
use App\Services\Usage\Budget;
use Closure;

/**
 * "AI'a hatayı açıklat": başarısız bir çalışmanın hatasını sade dille açıklatır.
 *
 * Claude'a SADECE hata mesajı ve görev adı gider. Görev çıktıları, loglar (hata satırı dışında), girdiler ve
 * döngü değerleri gönderilmez; bunlarda uçuş veya personel verisi olabilir.
 */
class ExecutionExplainer
{
    private const SYSTEM = 'Fortis bir iş akışı otomasyon aracıdır; akışlar Kestra üzerinde çalışır. Kullanıcıya, başarısız bir adımın hata mesajını teknik olmayan, kısa Türkçe ile açıkla: büyük olasılıkla ne oldu ve ne yapılabilir (en fazla 3 madde). Elinde sadece görev adı, hata türü ve Fortis\'in yazdığı kısa açıklama var; ham hata metnini, veriyi ve çıktıları görmüyorsun, uydurma. Emin değilsen bunu söyle.';

    /** @var Closure(array<string, mixed>): BetaMessage */
    private Closure $send;

    /**
     * @param  (Closure(array<string, mixed>): BetaMessage)|null  $send  API çağrısı (testte değiştirilir)
     */
    public function __construct(
        Client $client,
        private readonly CostCalculator $costs,
        private readonly AppSettings $settings,
        private readonly Budget $budget,
        ?Closure $send = null,
    ) {
        $this->send = $send ?? fn (array $request): BetaMessage => $client->beta->messages->create(...$request);
    }

    /**
     * Claude'a gidecek hata bilgisi: hata veren adım, hata türü ve sabit açıklama (ExecutionSummaryForAi).
     * Kestra'nın ham hata metni gönderilmez.
     *
     * @return list<array{task: string|null, type: string, message: string}>
     */
    public static function failures(Execution $execution): array
    {
        $snapshot = $execution->snapshot ?? [];
        $hasFailure = in_array($execution->state, ['FAILED', 'KILLED', 'WARNING'], true)
            || array_filter($snapshot['task_runs'] ?? [], fn (array $run) => in_array($run['state'], ['FAILED', 'KILLED'], true)) !== [];

        if (! $hasFailure) {
            return [];
        }

        $taskRuns = array_map(fn (array $run) => ['taskId' => $run['task_id'], 'state' => ['current' => $run['state']]], $snapshot['task_runs'] ?? []);

        return [ExecutionSummaryForAi::error($execution->state, $taskRuns, $snapshot['logs'] ?? [])];
    }

    /**
     * @throws ClaudeException
     */
    public function explain(Execution $execution): string
    {
        $failures = self::failures($execution);

        if ($failures === []) {
            throw new ClaudeException('Bu çalışmada hata veren bir adım yok.');
        }

        $this->budget->assertChatAllowed();

        $text = implode("\n\n", array_map(fn (array $f) => 'Görev: '.($f['task'] ?? '(akış)')."\nHata türü: {$f['type']}\nAçıklama: {$f['message']}", $failures));
        $model = $this->settings->defaultModel();
        $startedAt = hrtime(true);

        try {
            $response = ($this->send)([
                'model' => $model,
                'maxTokens' => 2000,
                'system' => self::SYSTEM,
                'messages' => [['role' => 'user', 'content' => $text]],
                'outputConfig' => ['effort' => 'low'],
            ]);
        } catch (AnthropicException $e) {
            report($e);

            throw new ClaudeException('Açıklama alınamadı. Tekrar deneyin.', previous: $e);
        }

        $usage = $response->usage;
        AiCall::create([
            'flow_id' => $execution->flow_id,
            'execution_id' => $execution->id,
            'source' => AiCall::SOURCE_CHAT,
            'purpose' => 'explain_error',
            'model' => $response->model,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cache_creation_input_tokens' => $usage->cacheCreationInputTokens ?? 0,
            'cache_read_input_tokens' => $usage->cacheReadInputTokens ?? 0,
            'cost_usd' => $this->costs->cost($response->model, $usage->inputTokens, $usage->outputTokens, $usage->cacheCreationInputTokens ?? 0, $usage->cacheReadInputTokens ?? 0),
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'stop_reason' => $response->stopReason,
            'request_id' => $response->id,
        ]);

        $answer = '';
        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $answer .= $block->text;
            }
        }

        return trim($answer) !== '' ? trim($answer) : 'Açıklama üretilemedi.';
    }
}
