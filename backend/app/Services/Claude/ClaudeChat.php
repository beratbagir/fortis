<?php

namespace App\Services\Claude;

use Anthropic\Beta\Messages\BetaInputJSONDelta;
use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent;
use Anthropic\Beta\Messages\BetaRawContentBlockStartEvent;
use Anthropic\Beta\Messages\BetaTextDelta;
use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Lib\Streaming\MessageAccumulator;
use App\Agents\AgentSession;
use App\Agents\Architect;
use App\Ai\AnswerTrace;
use App\Ai\DataQueryTools;
use App\Ai\ModelRouter;
use App\Models\Agent;
use App\Models\AiCall;
use App\Models\Artifact;
use App\Models\Conversation;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\Report;
use App\Services\Connectors\Connectors;
use App\Services\Settings\AppSettings;
use Closure;

/**
 * Bir sohbetin sıradaki asistan yanıtını Claude'dan streaming ile alır.
 *
 * Claude araç çağırdıkça araçlar çalıştırılır ve sonuçlar geri verilir; Claude metinle yanıtlayınca döngü biter.
 * Her tur (asistan mesajı ve araç sonuçları) sırayla kaydedilir, her API çağrısı ai_calls'a yazılır.
 */
class ClaudeChat
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /** Girdisi uzun olan araçlarda (artifact kodu) ilerleme bu kadar karakterde bir bildirilir. */
    private const PROGRESS_CHARS = 2000;

    /** Tek bir kullanıcı mesajı için en fazla API çağrısı (araç döngüsü dahil; veri sorusunda 8 sorgu + cevap sığar). */
    private const MAX_ROUNDS = 14;

    /** Bu cevapta veri sorgusu yapıldı mı: sonraki turlar veri sorusu modeliyle (config ai.data_question_model) devam eder. */
    private bool $dataQuestion = false;

    /** Bu turda kullanılan model (ModelRouter). */
    private ?string $turnModel = null;

    private AnswerTrace $trace;

    /** Agent kipi (AgentRunner): sadece agent'ın araçları, yazma araçları agent_actions üzerinden, sınırlar. */
    private ?AgentSession $agent = null;

    /** Architect kipi: agent kuran sohbet. */
    private ?Agent $architect = null;

    public function forArchitect(Agent $agent): self
    {
        $this->architect = $agent;
        $this->tools->useArchitect($agent);

        return $this;
    }

    /** Agent kipine geçer. */
    public function forAgent(AgentSession $session): self
    {
        $this->agent = $session;
        $this->tools->useAgent($session);

        return $this;
    }

    public function __construct(
        private readonly Client $client,
        private readonly ConversationHistory $history,
        private readonly CostCalculator $costs,
        private readonly FlowTools $tools,
        private readonly string $model,
        private readonly string $effort,
        private readonly int $maxTokens,
        private readonly bool $fallbacks,
        private readonly string $systemPrompt,
        private readonly ModelRouter $router,
    ) {}

    /**
     * Sohbetin son kullanıcı mesajına yanıt üretir.
     *
     * @param  Closure(string $event, mixed $data): void  $emit  Olaylar: `thinking`, `delta` {text},
     *                                                           `tool` {name, status, ...}, `step` (canlı iz adımı),
     *                                                           `ask` (soru kartı), `message` (kaydedilen Message)
     *
     * @throws ClaudeException
     */
    public function reply(Conversation $conversation, Closure $emit): Message
    {
        $this->tools->startTurn($conversation);
        $this->dataQuestion = false;
        $this->trace = new AnswerTrace($emit, auth()->user());

        // Soru kartının cevabıyla devam: önceki adımlar ve sorgular bu cevabın parçası.
        $asked = $conversation->messages()->where('role', Message::ROLE_ASSISTANT)->latest('id')->first();
        if ($asked !== null && isset($asked->meta['ask'], $asked->meta['answer']) && ! $asked->isAwaitingInput()
            && ! $conversation->messages()->where('id', '>', $asked->id)->where('role', Message::ROLE_ASSISTANT)->exists()) {
            $this->trace->resume($asked->meta['answer']);
            $this->dataQuestion = $this->trace->queries() !== [];
        } else {
            $this->trace->scope($this->scopeSources($conversation));
        }

        try {
            return $this->loop($conversation, $emit);
        } catch (ClaudeException $e) {
            $this->trace->failed($e->getMessage());

            throw $e;
        }
    }

    /**
     * @param  Closure(string $event, mixed $data): void  $emit
     *
     * @throws ClaudeException
     */
    private function loop(Conversation $conversation, Closure $emit): Message
    {
        for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
            $route = $this->router->forTurn($conversation, $this->dataQuestion);
            $this->turnModel = $route['model'];
            $this->trace->model($route['model'], $this->router->label($route['model']), $route['auto'], $route['reason']);

            $this->guardAgentTime();

            $response = $this->streamOnce($conversation, $emit);
            $assistant = $this->storeAssistant($conversation, $response);

            $toolUses = array_values(array_filter($assistant->content, fn (array $block) => $block['type'] === 'tool_use'));

            if ($toolUses !== [] && ($limit = $this->agentLimitExceeded(count($toolUses))) !== null) {
                // Geçmiş geçerli kalsın: çalıştırılmayan çağrılara sonuç yazılır.
                $this->storeToolResults($conversation, array_map(fn (array $use) => $this->toolResultBlock($use['id'], ToolResult::error($limit)), $toolUses), []);

                throw new ClaudeException($limit);
            }

            if ($toolUses === []) {
                $this->finishAnswer($assistant, $response->id);
                $emit('message', $assistant);

                return $assistant;
            }

            $emit('message', $assistant);

            if ($response->stopReason !== 'tool_use') {
                // Kesilmiş (max_tokens) veya reddedilmiş bir turdaki araç çağrıları çalıştırılmaz.
                // Geçmiş geçerli kalsın diye her çağrıya "çalıştırılmadı" sonucu yazılır.
                $this->storeToolResults($conversation, array_map(
                    fn (array $use) => $this->toolResultBlock($use['id'], ToolResult::error('Araç çalıştırılmadı: yanıt tamamlanmadı.')),
                    $toolUses,
                ), []);

                throw new ClaudeException($response->stopReason === 'max_tokens'
                    ? 'Yanıt çok uzun olduğu için yarıda kesildi. İsteği daha küçük parçalara bölmeyi deneyin.'
                    : 'Bu isteğe yanıt veremiyorum. İsteği farklı bir şekilde ifade etmeyi deneyebilirsiniz.');
            }

            $ask = collect($toolUses)->firstWhere('name', 'ask_user');
            $askInput = $ask === null ? null : FlowTools::normalizeQuestions(is_array($ask['input'] ?? null) ? $ask['input'] : []);

            $results = [];
            $cards = [];
            $queries = [];
            foreach ($toolUses as $use) {
                if ($use === $ask && is_array($askInput)) {
                    continue;
                }

                $input = is_array($use['input'] ?? null) ? $use['input'] : [];
                $purpose = in_array($use['name'], DataQueryTools::TOOLS, true) && is_string($input['purpose'] ?? null) ? mb_substr($input['purpose'], 0, 200) : null;
                $emit('tool', array_filter(['name' => $use['name'], 'status' => 'running', 'purpose' => $purpose]));
                $this->trace->toolStarted($use['id'], $use['name'], $input);

                $result = $use['name'] === 'ask_user'
                    ? ToolResult::invalidInput($input, (string) $askInput)
                    : $this->tools->execute($use['name'], $input);
                $results[] = $this->toolResultBlock($use['id'], $result);
                $this->trace->toolFinished($use['id'], $result);

                if ($result->card !== null) {
                    $cards[] = $result->card;
                }

                if (in_array($use['name'], DataQueryTools::TOOLS, true)) {
                    $this->dataQuestion = true;
                    if ($result->meta !== null) {
                        $queries[] = array_diff_key($result->meta, ['query' => true]);
                    }
                }

                $emit('tool', ['name' => $use['name'], 'status' => $result->isError ? 'error' : 'done'] + array_intersect_key($result->meta ?? [], array_flip(['purpose', 'rows', 'total_rows', 'masked', 'source'])));
            }

            // Soru kartı: tur durur. Diğer araçların sonuçları bekletilir; kullanıcının cevabıyla birlikte gönderilir.
            if (is_array($askInput)) {
                $this->trace->waitingForUser($askInput['questions']);
                $assistant->update([
                    'status' => Message::STATUS_AWAITING_INPUT,
                    'meta' => array_merge($assistant->meta ?? [], [
                        'ask' => ['tool_use_id' => $ask['id'], 'questions' => $askInput['questions'], 'pending_results' => $results, 'cards' => $cards],
                        'answer' => $this->trace->summary(),
                    ]),
                ]);
                $emit('ask', ['message_id' => $assistant->id, 'questions' => $askInput['questions']]);
                $emit('message', $assistant->refresh());

                return $assistant;
            }

            $toolMessage = $this->storeToolResults($conversation, $results, $cards, $queries);

            if ($cards !== []) {
                $this->linkCards($cards, $toolMessage);
            }

            if ($cards !== [] || $queries !== []) {
                $emit('message', $toolMessage);
            }
        }

        throw new ClaudeException('Yanıt hazırlanırken çok fazla adım gerekti. İsteği sadeleştirip tekrar deneyin.');
    }

    /** Agent çalışması 10 dakikayı geçtiyse durur. */
    private function guardAgentTime(): void
    {
        $started = $this->agent?->run->started_at;

        if ($started !== null && $started->diffInSeconds(now()) > (int) config('agents.max_seconds')) {
            throw new ClaudeException('Çalışma süre sınırını ('.intdiv((int) config('agents.max_seconds'), 60).' dakika) aştı.');
        }
    }

    /** Agent çalışmasında araç çağrısı veya maliyet sınırı aşıldıysa hata mesajı; yoksa null. Sayaç çalışmaya yazılır. */
    private function agentLimitExceeded(int $calls): ?string
    {
        if ($this->agent === null) {
            return null;
        }

        $run = $this->agent->run;
        $max = (int) config('agents.max_tool_calls');
        $run->increment('tool_calls_count', $calls);

        if ($run->tool_calls_count > $max) {
            return "Araç çağrısı sınırı ({$max}) aşıldı; çalışma durduruldu.";
        }

        $cost = (float) AiCall::query()->where('agent_run_id', $run->id)->sum('cost_usd');
        if ($cost > (float) config('agents.max_cost_usd')) {
            return 'Maliyet sınırı ($'.number_format((float) config('agents.max_cost_usd'), 2).') aşıldı; çalışma durduruldu.';
        }

        return null;
    }

    /**
     * Kartları (rapor, artifact, akış sürümü) araç sonucu mesajına bağlar.
     *
     * @param  list<array<string, mixed>>  $cards
     */
    public function linkCards(array $cards, Message $toolMessage): void
    {
        Report::query()
            ->whereIn('id', array_column(array_filter($cards, fn (array $card) => ($card['type'] ?? null) === 'report'), 'report_id'))
            ->update(['message_id' => $toolMessage->id]);

        Artifact::query()
            ->whereIn('id', array_column(array_filter($cards, fn (array $card) => ($card['type'] ?? null) === 'artifact'), 'artifact_id'))
            ->whereNull('message_id')
            ->update(['message_id' => $toolMessage->id]);

        FlowVersion::query()
            ->whereIn('id', array_column($cards, 'flow_version_id'))
            ->whereNull('message_id')
            ->update(['message_id' => $toolMessage->id]);
    }

    /**
     * Son cevap: kaynak numaralarını bu cevapta çalışan sorgularla karşılaştırır, iz özetini mesaja yazar.
     * Çalışmamış bir sorguya kaynak verilmişse ai_calls'a citation_mismatch uyarısı düşer.
     */
    private function finishAnswer(Message $assistant, string $requestId): void
    {
        preg_match_all('/\[\^q:([A-Za-z0-9_]+)\]/', $assistant->text, $m);
        $known = array_column($this->trace->queries(), 'query_id');
        $unknown = array_values(array_unique(array_diff($m[1], $known)));

        if ($unknown !== []) {
            AiCall::query()->where('request_id', $requestId)->update(['warning' => mb_substr('citation_mismatch: '.implode(', ', $unknown), 0, 255)]);
        }

        $assistant->update(['meta' => array_merge($assistant->meta ?? [], ['answer' => $this->trace->summary($unknown)])]);
    }

    /**
     * İzin kapsamı: bağlı kaynaklar ve bu sohbette kapsamda olanlar (veri erişimi açık, kullanıcı kapatmamış).
     *
     * @return list<array{key: string, label: string, in_scope: bool, configured: bool}>
     */
    private function scopeSources(Conversation $conversation): array
    {
        $connectors = app(Connectors::class);
        $dataOn = app(AppSettings::class)->dataAccessEnabled();
        $disabled = $conversation->disabledSources();

        return collect(config('connectors.sources'))
            ->filter(fn (array $source) => $source['tools'] !== [])
            ->map(fn (array $source, string $key) => [
                'key' => $key,
                'label' => $source['label'],
                'configured' => $configured = $connectors->isConfigured($key),
                'in_scope' => $configured && $dataOn && ! in_array($key, $disabled, true),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Closure(string $event, mixed $data): void  $emit
     *
     * @throws ClaudeException
     */
    private function streamOnce(Conversation $conversation, Closure $emit): BetaMessage
    {
        $startedAt = hrtime(true);
        $accumulator = MessageAccumulator::forBetaMessages();

        try {
            $stream = $this->client->beta->messages->createStream(
                maxTokens: $this->maxTokens,
                messages: $this->history->forConversation($conversation),
                model: $this->turnModel ?? $this->router->forTurn($conversation, false)['model'],
                cacheControl: ['type' => 'ephemeral'],
                fallbacks: $this->fallbacks ? 'default' : null,
                outputConfig: ['effort' => $this->effort],
                system: match (true) {
                    $this->agent !== null => $this->systemPrompt."\n\n".$this->agent->promptSection(),
                    $this->architect !== null => $this->systemPrompt."\n\n".app(Architect::class)->promptSection($this->architect),
                    default => $this->systemPrompt,
                },
                thinking: ['type' => 'adaptive'],
                tools: $this->tools->definitions(),
                betas: $this->fallbacks ? [self::FALLBACK_BETA] : null,
            );

            // Akan araç girdisi: hangi araç ve şimdiye kadar kaç karakter (uzun artifact kodunda ilerleme göstermek için).
            $tool = null;
            $chars = 0;
            $reported = 0;

            foreach ($stream as $event) {
                $accumulator->accumulate($event);

                if ($event instanceof BetaRawContentBlockStartEvent) {
                    $tool = $event->contentBlock->type === 'tool_use' ? $event->contentBlock->name : null;
                    $chars = $reported = 0;
                    if ($event->contentBlock->type === 'thinking') {
                        $emit('thinking', []);
                    } elseif ($tool !== null) {
                        $emit('tool', ['name' => $tool, 'status' => 'writing']);
                    }
                } elseif ($event instanceof BetaRawContentBlockDeltaEvent && $event->delta instanceof BetaTextDelta) {
                    $emit('delta', ['text' => $event->delta->text]);
                } elseif ($event instanceof BetaRawContentBlockDeltaEvent && $event->delta instanceof BetaInputJSONDelta && $tool !== null) {
                    $chars += mb_strlen($event->delta->partialJSON);
                    if ($chars - $reported >= self::PROGRESS_CHARS) {
                        $reported = $chars;
                        $emit('tool_progress', ['name' => $tool, 'chars' => $chars]);
                    }
                }
            }

            /** @var BetaMessage $response */
            $response = $accumulator->message();
        } catch (AnthropicException $e) {
            $this->recordFailure($conversation, $e, $startedAt);

            throw new ClaudeException(self::userMessage($e), previous: $e);
        }

        $this->recordCall($conversation, $response, $startedAt);

        return $response;
    }

    private function storeAssistant(Conversation $conversation, BetaMessage $response): Message
    {
        $wire = json_decode(json_encode($response), true);
        $content = ConversationHistory::normalize($wire['content'] ?? []);
        $text = ConversationHistory::plainText($content);

        if ($response->stopReason === 'refusal' && $text === '') {
            $text = 'Bu isteğe yanıt veremiyorum. İsteği farklı bir şekilde ifade etmeyi deneyebilirsiniz.';
        }

        $message = $conversation->messages()->create([
            'role' => Message::ROLE_ASSISTANT,
            'content' => $content,
            'text' => $text,
            'model' => $response->model,
            'stop_reason' => $response->stopReason,
        ]);

        AiCall::query()->where('request_id', $response->id)->update(['message_id' => $message->id]);
        $conversation->touch();

        return $message;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  list<array<string, mixed>>  $cards
     * @param  list<array<string, mixed>>  $queries  Veri sorgusu adımları (arayüzde amaç ve satır sayısı gösterilir)
     */
    private function storeToolResults(Conversation $conversation, array $results, array $cards, array $queries = []): Message
    {
        $meta = array_filter(['flow_cards' => $cards, 'queries' => $queries]);

        return $conversation->messages()->create([
            'role' => Message::ROLE_USER,
            'content' => $results,
            'text' => '',
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    /** @return array<string, mixed> */
    private function toolResultBlock(string $toolUseId, ToolResult $result): array
    {
        return array_filter([
            'type' => 'tool_result',
            'tool_use_id' => $toolUseId,
            'content' => $result->content,
            'is_error' => $result->isError ?: null,
        ], fn ($value) => $value !== null);
    }

    private function recordCall(Conversation $conversation, BetaMessage $response, int $startedAt): void
    {
        $usage = $response->usage;

        AiCall::create([
            'conversation_id' => $conversation->id,
            'flow_id' => $conversation->context_flow_id,
            'source' => AiCall::SOURCE_CHAT,
            'model' => $response->model,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cache_creation_input_tokens' => $usage->cacheCreationInputTokens ?? 0,
            'cache_read_input_tokens' => $usage->cacheReadInputTokens ?? 0,
            'cost_usd' => $this->costs->cost(
                $response->model,
                $usage->inputTokens,
                $usage->outputTokens,
                $usage->cacheCreationInputTokens ?? 0,
                $usage->cacheReadInputTokens ?? 0,
            ),
            'duration_ms' => self::elapsedMs($startedAt),
            'stop_reason' => $response->stopReason,
            'request_id' => $response->id,
            'agent_run_id' => $this->agent?->run->id,
        ]);
    }

    private function recordFailure(Conversation $conversation, AnthropicException $e, int $startedAt): void
    {
        AiCall::create([
            'conversation_id' => $conversation->id,
            'flow_id' => $conversation->context_flow_id,
            'source' => AiCall::SOURCE_CHAT,
            'model' => $this->turnModel ?? $this->router->forTurn($conversation, false)['model'],
            'duration_ms' => self::elapsedMs($startedAt),
            'error' => mb_substr($e::class.': '.$e->getMessage(), 0, 2000),
            'agent_run_id' => $this->agent?->run->id,
        ]);

        report($e);
    }

    private static function userMessage(AnthropicException $e): string
    {
        return match (true) {
            $e instanceof AuthenticationException => 'Claude API anahtarı geçersiz. Yöneticiye haber verin.',
            $e instanceof RateLimitException => 'Claude şu an çok yoğun. Birkaç saniye sonra tekrar deneyin.',
            $e instanceof BadRequestException => 'Claude isteği kabul etmedi. Yöneticiye haber verin.',
            $e instanceof APIStatusException => 'Claude geçici bir hata verdi. Tekrar deneyin.',
            $e instanceof APIConnectionException => "Claude'a bağlanılamadı. İnternet bağlantısını kontrol edip tekrar deneyin.",
            default => 'Beklenmeyen bir hata oluştu. Tekrar deneyin.',
        };
    }

    private static function elapsedMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
