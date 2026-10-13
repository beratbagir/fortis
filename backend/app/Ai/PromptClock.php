<?php

namespace App\Ai;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Sistem promptunun sonundaki "şimdiki zaman" bloğu. Göreli tarihler ("dün", "bu hafta") Türkiye saatine göre hesaplanır
 * ve sorgular için UTC aralığı olarak verilir: gece 00:30'da "dün", UTC'deki dün değil Türkiye'deki dündür.
 * Tur başında bir kez üretilir (aynı turdaki araç döngüsünde değişmez, prompt önbelleği bozulmaz).
 */
class PromptClock
{
    public const TIMEZONE = 'Europe/Istanbul';

    private const MONTHS = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    private const DAYS = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];

    /** @return array<string, array{0: CarbonImmutable, 1: CarbonImmutable}> Ad → [başlangıç, bitiş) Türkiye saatiyle */
    public static function ranges(?CarbonInterface $now = null): array
    {
        $tr = CarbonImmutable::instance($now ?? now())->setTimezone(self::TIMEZONE);
        $today = $tr->startOfDay();
        $week = $today->startOfWeek(CarbonInterface::MONDAY);
        $month = $today->startOfMonth();

        return [
            'bugün' => [$today, $today->addDay()],
            'dün' => [$today->subDay(), $today],
            'yarın' => [$today->addDay(), $today->addDays(2)],
            'bu hafta (Pzt–Paz)' => [$week, $week->addWeek()],
            'geçen hafta' => [$week->subWeek(), $week],
            'bu ay' => [$month, $month->addMonth()],
            'geçen ay' => [$month->subMonth(), $month],
        ];
    }

    public static function block(?CarbonInterface $now = null): string
    {
        $utcNow = CarbonImmutable::instance($now ?? now())->utc();
        $tr = $utcNow->setTimezone(self::TIMEZONE);

        $lines = [
            '# Şimdiki zaman',
            '',
            'Şu an: **'.self::date($tr).' '.self::DAYS[$tr->dayOfWeek].', '.$tr->format('H:i').'** (Türkiye saati, UTC+3). UTC: '.$utcNow->format('Y-m-d\TH:i\Z').'.',
            '',
            'Göreli tarihler her zaman Türkiye saatine göredir. Sorgularda aşağıdaki UTC aralıklarını kullan (başlangıç dahil, bitiş hariç):',
        ];

        foreach (self::ranges($now) as $name => [$from, $to]) {
            $last = $to->subDay();
            $label = $from->isSameDay($last) ? self::date($from) : self::span($from, $last);
            $lines[] = "- {$name}: {$label} → `".$from->utc()->format('Y-m-d\TH:i:s\Z').'` – `'.$to->utc()->format('Y-m-d\TH:i:s\Z').'`';
        }

        $lines[] = '';
        $lines[] = 'Kurallar: APM `dateFrom`/`dateTo` bu UTC aralıklarıyla verilir. IBS ve SuccessFactors\'ta tarih (gün) sütunlarında Türkiye tarihini, zaman damgası sütunlarında UTC aralığını kullan. Cevapta hangi tarihe baktığını açıkça yaz (ör. "Dün (12 Ekim 2026)"). Gece yarısından sonra bile "dün" Türkiye\'deki bir önceki gündür.';

        return implode("\n", $lines);
    }

    public static function date(CarbonInterface $day): string
    {
        return $day->day.' '.self::MONTHS[$day->month - 1].' '.$day->year;
    }

    private static function span(CarbonInterface $from, CarbonInterface $last): string
    {
        if ($from->year === $last->year && $from->month === $last->month) {
            return $from->day.'–'.$last->day.' '.self::MONTHS[$last->month - 1].' '.$last->year;
        }

        return self::date($from).' – '.self::date($last);
    }
}
