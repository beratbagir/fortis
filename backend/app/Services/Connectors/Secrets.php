<?php

namespace App\Services\Connectors;

/**
 * Hata mesajlarındaki gizli bilgileri temizler: bağlantı kataloğundaki .env değişkenlerinin değerleri (şifre, token,
 * kullanıcı adı, adres), adres içindeki kullanıcı:şifre ve Bearer/Basic başlıkları. Ekrana ve API'ye çıkan her hata
 * buradan geçer.
 */
class Secrets
{
    private const MASK = '[gizli]';

    public static function scrub(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        foreach (self::values() as $value) {
            $text = str_replace($value, self::MASK, $text);
        }

        $text = (string) preg_replace('#(\b[a-z][a-z0-9+.-]*://)[^/\s:@]+:[^/\s@]+@#i', '$1'.self::MASK.'@', $text);

        return (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/', '$1 '.self::MASK, $text);
    }

    /**
     * Katalogdaki bütün .env değişkenlerinin (en az 4 karakterlik) değerleri, uzundan kısaya.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        $values = [];
        foreach (config('connectors.sources') as $source) {
            foreach ($source['env'] ?? [] as $configKey) {
                $value = config($configKey);
                if (is_string($value) && mb_strlen(trim($value)) >= 4 && ! in_array($value, ['smtp', 'log', 'array', 'client_credentials', 'password'], true)) {
                    $values[] = trim($value);
                }
            }
        }
        foreach (['services.anthropic.api_key', 'agents.internal_key'] as $key) {
            if (is_string($value = config($key)) && mb_strlen($value) >= 4) {
                $values[] = $value;
            }
        }
        usort($values, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return array_values(array_unique($values));
    }
}
