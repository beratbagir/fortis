<?php

namespace App\Agents;

/**
 * Basit cron ifadelerini Türkçeye çevirir ("Her gün 06:00", "Her pazartesi 08:00"); tanımadığını olduğu gibi bırakır.
 * Arayüzdeki describeCron (frontend/src/agents/schedule.ts) ile aynı kurallar.
 */
class CronText
{
    private const DAYS = ['pazar', 'pazartesi', 'salı', 'çarşamba', 'perşembe', 'cuma', 'cumartesi', 'pazar'];

    public static function describe(string $cron): string
    {
        $parts = preg_split('/\s+/', trim($cron)) ?: [];
        if (count($parts) !== 5) {
            return $cron;
        }
        [$minute, $hour, $dom, $month, $dow] = $parts;

        if (preg_match('/^\*\/(\d+)$/', $minute, $m) && $hour === '*' && $dom === '*' && $month === '*' && $dow === '*') {
            return "{$m[1]} dakikada bir";
        }
        if (! ctype_digit($minute) || ! ctype_digit($hour) || $month !== '*') {
            return $cron;
        }
        $time = str_pad($hour, 2, '0', STR_PAD_LEFT).':'.str_pad($minute, 2, '0', STR_PAD_LEFT);

        return match (true) {
            $dom === '*' && $dow === '*' => "Her gün {$time}",
            $dom === '*' && in_array($dow, ['1-5', 'MON-FRI'], true) => "Hafta içi her gün {$time}",
            $dom === '*' && preg_match('/^[0-7]$/', $dow) === 1 => 'Her '.self::DAYS[(int) $dow]." {$time}",
            ctype_digit($dom) && $dow === '*' => "Her ayın {$dom}. günü {$time}",
            default => $cron,
        };
    }
}
