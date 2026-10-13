<?php

namespace App\Services\Claude;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;
use App\Ai\ModelRouter;
use App\Models\AiCall;
use App\Models\Conversation;
use App\Services\Usage\Budget;
use App\Services\Usage\BudgetExceededException;
use Closure;
use Illuminate\Support\Str;
use Throwable;

/**
 * İlk cevaptan sonra sohbete 6–8 kelimelik başlık üretir (hızlı modelle, sohbet başına bir kez). Hata olursa veya bütçe
 * dolduysa ilk mesajın ilk 60 karakteri başlık olarak kalır. Maliyet ai_calls'a purpose = title ile yazılır.
 */
class TitleGenerator
{
    private const SYSTEM = 'Sohbete kısa bir Türkçe başlık yaz: 6-8 kelime, nokta ve tırnak yok, sadece başlık. Kişi adı, e-posta, telefon gibi kişisel bilgi yazma.';

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

    /** Başlık üretildiyse true. Kullanıcı yeniden adlandırdıysa veya daha önce üretildiyse dokunmaz. */
    public function generate(Conversation $conversation, string $question, string $answer): bool
    {
        if ($conversation->title_generated_at !== null || ! $conversation->isChat()) {
            return false;
        }

        $conversation->update(['title_generated_at' => now()]);

        try {
            $this->budget->assertChatAllowed();
        } catch (BudgetExceededException) {
            return false;
        }

        $model = $this->router->fast();
        $startedAt = hrtime(true);

        try {
            $response = ($this->send)([
                'model' => $model,
                'maxTokens' => 2000,
                'system' => self::SYSTEM,
                'messages' => [['role' => 'user', 'content' => 'Soru: '.mb_substr($question, 0, 1000)."\n\nCevabın başı: ".mb_substr($answer, 0, 600)]],
                'outputConfig' => ['effort' => 'low'],
            ]);
        } catch (Throwable $e) {
            AiCall::create(['conversation_id' => $conversation->id, 'source' => AiCall::SOURCE_CHAT, 'purpose' => 'title', 'model' => $model, 'duration_ms' => self::elapsedMs($startedAt), 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            report($e);

            return false;
        }

        $usage = $response->usage;
        AiCall::create([
            'conversation_id' => $conversation->id,
            'source' => AiCall::SOURCE_CHAT,
            'purpose' => 'title',
            'model' => $response->model,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cost_usd' => $this->costs->cost($response->model, $usage->inputTokens, $usage->outputTokens, 0, 0),
            'duration_ms' => self::elapsedMs($startedAt),
            'stop_reason' => $response->stopReason,
            'request_id' => $response->id,
        ]);

        $text = collect(json_decode(json_encode($response->content), true))->where('type', 'text')->pluck('text')->implode(' ');
        $title = Str::of($text)->squish()->trim(" \t\n\"'“”.:")->limit(80, '')->value();

        if ($title === '') {
            return false;
        }

        $conversation->update(['title' => $title]);

        return true;
    }

    private static function elapsedMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
