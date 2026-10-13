<?php

namespace App\Services\Privacy;

/**
 * TC kimlik numaralarını metinlerde maskeler. Sadece kontrol hanesi tutan 11 haneli sayılar
 * maskelenir; telefon numarası gibi diğer sayılar olduğu gibi kalır.
 */
class NationalIdMasker
{
    public const MASK = '***********';

    public static function mask(string $text): string
    {
        return (string) preg_replace_callback(
            '/(?<!\d)[1-9]\d{10}(?!\d)/',
            fn (array $m) => self::isNationalId($m[0]) ? self::MASK : $m[0],
            $text,
        );
    }

    /**
     * Dizideki bütün metinleri maskeler (anahtarlar dahil değil).
     *
     * @template T of array<array-key, mixed>
     *
     * @param  T  $data
     * @return T
     */
    public static function maskArray(array $data): array
    {
        array_walk_recursive($data, function (mixed &$value): void {
            if (is_string($value)) {
                $value = self::mask($value);
            } elseif (is_int($value) && $value >= 10_000_000_000 && $value <= 99_999_999_999 && self::isNationalId((string) $value)) {
                $value = self::MASK;
            }
        });

        return $data;
    }

    public static function isNationalId(string $number): bool
    {
        if (! preg_match('/^[1-9]\d{10}$/', $number)) {
            return false;
        }

        $d = array_map('intval', str_split($number));
        $odd = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
        $even = $d[1] + $d[3] + $d[5] + $d[7];

        return (($odd * 7 - $even) % 10 + 10) % 10 === $d[9]
            && array_sum(array_slice($d, 0, 10)) % 10 === $d[10];
    }
}
