<?php

namespace App\Services\Voice;

use Illuminate\Support\Str;

/**
 * Whisper çıktısını temizler. Sessizlikte üretilen bilinen anlamsız çıktılar ("Altyazı M.K." gibi) boş metne çevrilir.
 */
class TranscriptFilter
{
    /** @param  list<string>  $hallucinations */
    public function __construct(private readonly array $hallucinations) {}

    public static function fromConfig(): self
    {
        return new self(config('voice.hallucination_filters'));
    }

    public function clean(string $text): string
    {
        $text = trim(Str::squish($text));

        $key = self::key($text);
        if ($key === '' || in_array($key, array_map(self::key(...), $this->hallucinations), true)) {
            return '';
        }

        return $text;
    }

    /** Küçük harf, noktalama ve boşluklar yok sayılır. */
    private static function key(string $text): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $text)));
    }
}
