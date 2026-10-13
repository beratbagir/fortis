<?php

namespace App\Services\Reports;

use App\Services\Apm\ApmApi;
use App\Services\SuccessFactors\SuccessFactors;
use App\Services\Warehouse\Warehouse;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Raporların veri kaynakları (resources/reports/sources.json). Her kaynak mevcut bir hazır isteğe bağlıdır:
 * IBS hazır sorgusu, SuccessFactors hazır isteği veya APM okuma ucu. Veriyi Laravel kendisi çeker; Kestra'dan veri
 * taşınmaz, AI veriyi görmez.
 *
 * Rapora sadece kaynağın `fields` listesindeki alanlar eklenebilir. TC kimlik, telefon, doğum tarihi gibi alanlar
 * katalogda bilerek yoktur.
 */
class ReportSources
{
    /** APM'de en fazla sayfa (sayfa başına 100 kayıt). */
    private const MAX_PAGES = 100;

    /** @var array<string, array<string, mixed>> */
    private array $sources = [];

    /**
     * @param  list<array<string, mixed>>  $sources
     */
    public function __construct(
        array $sources,
        private readonly Warehouse $warehouse,
        private readonly SuccessFactors $successFactors,
        private readonly ApmApi $apm,
    ) {
        foreach ($sources as $source) {
            $this->sources[$source['name']] = $source;
        }
    }

    /** @return array<string, mixed>|null */
    public function source(string $name): ?array
    {
        return $this->sources[$name] ?? null;
    }

    /**
     * Claude'a verilen katalog: kaynaklar, parametreler ve alanlar (veri yok).
     *
     * @return list<array<string, mixed>>
     */
    public function describe(): array
    {
        return array_values(array_map(fn (array $source) => [
            'name' => $source['name'],
            'title' => $source['title'],
            'description' => $source['description'],
            'params' => $source['params'],
            'fields' => array_map(fn (array $field) => array_filter([
                'label' => $field['label'],
                'type' => $field['type'],
                'aggregatable' => $field['aggregatable'] ?? false,
            ]), $source['fields']),
        ], $this->sources));
    }

    /**
     * Kaynağın parametrelerini doğrular. Tarihler YYYY-MM-DD; metinlerde sadece güvenli karakterler.
     *
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     *
     * @throws RuntimeException
     */
    public function checkParams(array $source, array $params): array
    {
        $known = array_column($source['params'], null, 'name');
        $unknown = array_diff(array_keys($params), array_keys($known));

        if ($unknown !== []) {
            throw new RuntimeException("Bu kaynakta '".implode("', '", $unknown)."' parametresi yok.");
        }

        $clean = [];
        foreach ($known as $name => $definition) {
            $value = $params[$name] ?? null;

            if ($value === null || $value === '') {
                if ($definition['required'] ?? false) {
                    throw new RuntimeException("'{$name}' parametresi zorunlu ({$definition['label']}).");
                }

                continue;
            }

            $value = (string) $value;
            $valid = match ($definition['type']) {
                'date' => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false,
                default => (bool) preg_match('/^[\pL\pN _.\-]{1,60}$/u', $value),
            };

            if (! $valid) {
                throw new RuntimeException("'{$name}' parametresi geçersiz".($definition['type'] === 'date' ? ' (YYYY-MM-DD olmalı).' : '.'));
            }

            $clean[$name] = $value;
        }

        return $clean;
    }

    /**
     * Kaynaktan satırları çeker ve alanlara dönüştürür. Her satır: alan anahtarı => değer (tip dönüşümü yapılmış).
     *
     * @param  array<string, mixed>  $source
     * @param  array<string, string>  $params
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException
     */
    public function fetch(array $source, array $params, int $maxRows): array
    {
        $records = match ($source['system']) {
            'ibs' => $this->warehouse->runNamedQuery($source['query'], $params),
            'successfactors' => $this->successFactors->fetchAll($this->successFactorsPath($source, $params), $maxRows),
            'apm' => $this->apmRecords($source, $params, $maxRows),
            default => throw new RuntimeException('Bilinmeyen kaynak sistemi.'),
        };

        if (count($records) > $maxRows) {
            throw new RowLimitException($maxRows);
        }

        return array_map(fn (array $record) => $this->normalize($source['fields'], $record), $records);
    }

    /** @param  array<string, string>  $params */
    private function successFactorsPath(array $source, array $params): string
    {
        $request = collect($this->successFactors->requests())->firstWhere('name', $source['request'])
            ?? throw new RuntimeException("Hazır istek yok: {$source['request']}");

        return (string) preg_replace_callback('/\{(\w+)\}/', fn (array $m) => rawurlencode($params[$m[1]] ?? ''), $request['path']);
    }

    /**
     * @param  array<string, string>  $params
     * @return list<array<string, mixed>>
     */
    private function apmRecords(array $source, array $params, int $maxRows): array
    {
        $request = $source['request'];
        $path = (string) preg_replace_callback('/\{(\w+)\}/', fn (array $m) => rawurlencode($params[$m[1]] ?? ''), $request['path']);
        $records = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $separator = str_contains($path, '?') ? '&' : '?';
            $response = $this->apm->fetch("{$path}{$separator}{$request['page_param']}={$page}");
            array_push($records, ...(self::get($response, $request['rows']) ?? []));

            if ($page >= (int) (self::get($response, $request['total_pages']) ?? 1) || count($records) > $maxRows) {
                break;
            }
        }

        return $records;
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function normalize(array $fields, array $record): array
    {
        $row = [];

        foreach ($fields as $key => $field) {
            $value = isset($field['paths'])
                ? implode('', array_map(fn (string $path) => (string) (self::get($record, $path) ?? ''), $field['paths']))
                : self::get($record, $field['path'] ?? $key);

            $row[$key] = self::cast($value, $field['type']);
        }

        return $row;
    }

    /** Nokta yolu: a.b.0.c; liste indeksleri sayı. */
    private static function get(mixed $data, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (! is_array($data)) {
                return null;
            }
            $data = $data[ctype_digit($segment) ? (int) $segment : $segment] ?? null;
        }

        return $data;
    }

    /**
     * Değeri alan tipine çevirir. Tarihler Türkiye saatine (CarbonImmutable); SuccessFactors /Date(ms)/ biçimi desteklenir.
     */
    private static function cast(mixed $value, string $type): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return match ($type) {
                'integer' => is_numeric($value) ? (int) $value : null,
                'number' => is_numeric($value) ? (float) $value : null,
                'boolean' => is_bool($value) ? $value : in_array(strtolower((string) $value), ['1', 'true', 'yes', 'evet'], true),
                'date', 'datetime' => self::date((string) $value, $type),
                default => is_scalar($value) ? (string) $value : null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private static function date(string $value, string $type): CarbonImmutable
    {
        $timezone = (string) config('reports.timezone');

        if (preg_match('#^/Date\((-?\d+)(?:[+-]\d+)?\)/$#', $value, $m)) {
            $date = CarbonImmutable::createFromTimestampMs((int) $m[1], 'UTC');

            // SuccessFactors tarihleri UTC gece yarısıdır; gün kaymasın.
            return $type === 'date' ? CarbonImmutable::parse($date->toDateString(), $timezone) : $date->setTimezone($timezone);
        }

        $hasZone = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value);
        $date = CarbonImmutable::parse($value, $hasZone ? null : $timezone);

        return $type === 'date' ? CarbonImmutable::parse($date->toDateString(), $timezone) : $date->setTimezone($timezone);
    }
}
