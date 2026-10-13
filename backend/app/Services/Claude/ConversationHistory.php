<?php

namespace App\Services\Claude;

use App\Models\Conversation;
use App\Models\Message;

/**
 * Kayıtlı mesajları Claude API'ye gönderilecek `messages` dizisine çevirir.
 *
 * Geçmiş sadece sona eklenerek büyür: önceki turların içerik blokları değiştirilmeden geri gönderilir.
 * Claude Sonnet 5.5 düşünme bloklarını üretildikleri konuşmaya bağlar; geçmiş değişirse istek reddedilir.
 */
class ConversationHistory
{
    /**
     * Yanıt sırasında yedek modele geçildiyse, geçişten önceki bu blok tipleri geri gönderilmez.
     */
    private const DROPPED_BEFORE_FALLBACK = ['thinking', 'redacted_thinking', 'tool_use'];

    /**
     * @return list<array{role: string, content: list<array<string, mixed>>}>
     */
    public function forConversation(Conversation $conversation): array
    {
        return $conversation->messages()
            ->get(['id', 'role', 'content'])
            ->reject(fn (Message $message) => $message->content === [])
            ->map(fn (Message $message) => [
                'role' => $message->role,
                'content' => $message->role === Message::ROLE_ASSISTANT
                    ? self::forReplay($message->content)
                    : $message->content,
            ])
            ->values()
            ->all();
    }

    /**
     * API yanıtındaki içerik bloklarını saklanacak biçime getirir: null alanlar silinir.
     *
     * @param  array<int, mixed>  $blocks
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $blocks): array
    {
        $strip = function (mixed $value) use (&$strip): mixed {
            if (! is_array($value)) {
                return $value;
            }

            $clean = [];
            foreach ($value as $key => $item) {
                if ($item !== null) {
                    $clean[$key] = $strip($item);
                }
            }

            return $clean;
        };

        return array_values(array_map($strip, $blocks));
    }

    /**
     * Yanıtın ortasında yedek modele geçildiyse, son `fallback` bloğundan önceki
     * düşünme ve araç çağrısı bloklarını çıkarır. Diğer durumlarda içerik aynen döner.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    public static function forReplay(array $blocks): array
    {
        $lastFallback = null;
        foreach ($blocks as $index => $block) {
            if (($block['type'] ?? null) === 'fallback') {
                $lastFallback = $index;
            }
        }

        if ($lastFallback === null) {
            return $blocks;
        }

        return array_values(array_filter(
            $blocks,
            fn (array $block, int $index) => $index >= $lastFallback
                || ! in_array($block['type'] ?? null, self::DROPPED_BEFORE_FALLBACK, true),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * İçerik bloklarındaki düz metni birleştirir (ekranda gösterilecek metin).
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    public static function plainText(array $blocks): string
    {
        return trim(implode("\n\n", array_map(
            fn (array $block) => $block['text'],
            array_filter($blocks, fn (array $block) => ($block['type'] ?? null) === 'text' && isset($block['text'])),
        )));
    }
}
