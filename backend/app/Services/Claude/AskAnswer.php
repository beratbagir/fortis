<?php

namespace App\Services\Claude;

use App\Models\Message;

/**
 * Soru kartının (ask_user) cevabını Claude'a gidecek bloklara çevirir: bekletilen diğer araç sonuçları ve ask_user
 * sonucu. Sohbet ve Inbox (agent soruları) aynı biçimi kullanır.
 */
class AskAnswer
{
    /**
     * Soru kartının cevabı: bekletilen diğer araç sonuçları ve ask_user sonucu (Claude'a), görünen "Cevaplarım" metni.
     * Kullanıcı kartı kullanmadan yazdıysa yazdığı metin sonuç olarak gider.
     *
     * @param  array<string, mixed>|null  $answer
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public static function blocks(Message $pending, ?array $answer, string $content): array
    {
        $ask = $pending->meta['ask'];

        if ($answer === null) {
            $result = "Kullanıcı seçeneklerden seçmedi, şunu yazdı: {$content}";
            $visible = $content;
        } elseif ($answer['skipped'] ?? false) {
            $result = 'Kullanıcı soruyu atladı. En makul varsayımla devam et ve varsayımını cevabında açıkça yaz.';
            $visible = 'Soruyu atladım; en makul varsayımla devam et.';
        } else {
            $lines = array_map(function (array $a) {
                $chosen = array_values(array_filter([...$a['selected'], trim((string) ($a['other'] ?? ''))], fn (string $v) => $v !== ''));

                return '— '.$a['question'].' = '.($chosen === [] ? 'seçim yapılmadı' : implode('; ', $chosen));
            }, $answer['answers']);
            $result = "Kullanıcının cevapları:\n".implode("\n", $lines);
            $visible = "Cevaplarım:\n".implode("\n", $lines);
        }

        return [[...$ask['pending_results'], ['type' => 'tool_result', 'tool_use_id' => $ask['tool_use_id'], 'content' => $result]], $visible];
    }
}
