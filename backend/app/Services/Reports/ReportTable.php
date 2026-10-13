<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Üreticilere giden tablo: seçili sütunlar (anahtar => alan tanımı), sıralanmış satırlar ve başlık bilgileri.
 */
final class ReportTable
{
    /**
     * @param  array<string, array{label: string, type: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $filters  Ekranda gösterilen filtreler (parametre etiketi => değer)
     */
    public function __construct(
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $filters,
        public readonly ?string $groupBy,
        public readonly CarbonImmutable $generatedAt,
    ) {}

    /** Hücrenin metin hali: tarihler dd.MM.yyyy HH:mm (Türkiye saati), evet/hayır, sayılar Türkçe biçimle. */
    public static function text(mixed $value, string $type): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format($type === 'date' ? 'd.m.Y' : 'd.m.Y H:i'),
            is_bool($value) => $value ? 'Evet' : 'Hayır',
            is_int($value) => number_format($value, 0, ',', '.'),
            is_float($value) => number_format($value, 2, ',', '.'),
            default => (string) $value,
        };
    }

    /**
     * group_by alanına göre satır sayıları (PDF özet kutusu), çoktan aza.
     *
     * @return array<string, int>
     */
    public function groups(): array
    {
        if ($this->groupBy === null) {
            return [];
        }

        $type = $this->columns[$this->groupBy]['type'] ?? 'string';
        $counts = [];
        foreach ($this->rows as $row) {
            $key = self::text($row[$this->groupBy] ?? null, $type) ?: '(boş)';
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);

        return $counts;
    }
}
