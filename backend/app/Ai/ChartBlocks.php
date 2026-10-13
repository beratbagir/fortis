<?php

namespace App\Ai;

use App\Models\Conversation;
use App\Models\Message;

/**
 * Cevaptaki :::chart blokları (JSON). Model HTML/kod yazmaz; yapılandırılmış blok koyar, arayüz hazır bileşenle çizer.
 *
 * Her blok bu cevapta çalışmış bir sorguya bağlıdır (`source: "q:ID"`) ve değerleri o sorgunun Claude'a giden
 * (maskelenmiş) satırlarından gelmelidir. Şemaya uymayan, HTML içeren, sınırı aşan veya kaynağı doğrulanamayan blok
 * çizilmez; yerine hata notu kalır. Doğrulanmış grafikler mesajın meta.answer.charts alanına yazılır.
 */
class ChartBlocks
{
    public const PATTERN = '/:::chart[ \t]*\r?\n(.*?)\r?\n[ \t]*:::/s';

    public const TYPES = ['line', 'bar', 'hbar', 'stacked_bar'];

    public const UNITS = ['%', 'dk', 'adet', '$', '₺', ''];

    public const MAX_CHARTS = 3;

    public const MAX_SERIES = 6;

    public const MAX_POINTS = 400;

    public const ERROR_SOURCE = 'Grafik kaynağı doğrulanamadı';

    public const ERROR_INVALID = 'Grafik gösterilemedi';

    /** "Diğer" kovası: kaynakta satırı olmayan toplu kategori (derived grafiklerde). */
    private const OTHER = 'Diğer';

    /**
     * @param  list<string>  $knownQueryIds  Bu cevapta çalışmış sorgular
     * @param  callable(string $queryId): ?array{columns: list<string>, rows: list<list<mixed>>}  $rowsFor  Sorgunun Claude'a giden satırları
     * @return array{charts: list<array<string, mixed>>, source_mismatch: list<string>}
     */
    public static function validate(string $text, array $knownQueryIds, callable $rowsFor): array
    {
        preg_match_all(self::PATTERN, $text, $matches);
        $charts = [];
        $mismatch = [];

        foreach ($matches[1] as $i => $json) {
            if ($i >= self::MAX_CHARTS) {
                $charts[] = ['error' => self::ERROR_INVALID, 'reason' => 'Cevap başına en fazla '.self::MAX_CHARTS.' grafik.'];

                continue;
            }

            $chart = self::normalize($json);
            if (is_string($chart)) {
                $charts[] = ['error' => self::ERROR_INVALID, 'reason' => $chart];

                continue;
            }

            $id = substr($chart['source'], 2);
            $rows = in_array($id, $knownQueryIds, true) ? $rowsFor($id) : null;
            if ($rows === null) {
                $mismatch[] = $id;
                $charts[] = ['error' => self::ERROR_SOURCE, 'source' => $chart['source'], 'title' => $chart['title']];

                continue;
            }

            $problem = self::checkValues($chart, $rows);
            $charts[] = $problem === null ? $chart : ['error' => self::ERROR_SOURCE, 'source' => $chart['source'], 'title' => $chart['title'], 'reason' => $problem];
            if ($problem !== null) {
                $mismatch[] = $id;
            }
        }

        return ['charts' => $charts, 'source_mismatch' => array_values(array_unique($mismatch))];
    }

    /**
     * Şemaya göre doğrular ve normalleştirir; sorun varsa açıklama döner.
     *
     * @return array<string, mixed>|string
     */
    public static function normalize(string $json): array|string
    {
        $data = json_decode($json, true);
        if (! is_array($data) || array_is_list($data)) {
            return 'Geçersiz JSON.';
        }
        if (self::containsMarkup($data)) {
            return 'HTML veya kod içeriyor.';
        }
        if (array_diff(array_keys($data), ['type', 'title', 'source', 'x', 'y', 'series', 'data', 'reference', 'derived', 'note']) !== []) {
            return 'Bilinmeyen alan.';
        }

        $type = $data['type'] ?? null;
        $title = $data['title'] ?? null;
        $source = $data['source'] ?? null;
        $x = $data['x'] ?? null;
        $y = $data['y'] ?? [];
        $series = $data['series'] ?? null;
        $points = $data['data'] ?? null;

        if (! in_array($type, self::TYPES, true)) {
            return 'Grafik türü '.implode(', ', self::TYPES).' olmalı.';
        }
        if (! is_string($title) || trim($title) === '' || mb_strlen($title) > 120) {
            return 'Başlık zorunlu (en fazla 120 karakter).';
        }
        if (! is_string($source) || ! preg_match('/^q:[A-Za-z0-9_]+$/', $source)) {
            return 'Kaynak "q:ID" biçiminde olmalı.';
        }
        if (! is_array($x) || ! is_string($x['field'] ?? null) || ! in_array($x['type'] ?? 'category', ['date', 'category'], true)) {
            return 'x.field zorunlu; x.type date veya category.';
        }
        if (! is_array($y) || ! in_array($y['unit'] ?? '', self::UNITS, true) || (isset($y['min']) && ! is_numeric($y['min']))) {
            return 'y.unit '.implode(', ', array_map(fn ($u) => $u === '' ? 'boş' : $u, self::UNITS)).' olmalı.';
        }
        if (! is_array($series) || ! array_is_list($series) || $series === [] || count($series) > self::MAX_SERIES) {
            return 'En az 1, en fazla '.self::MAX_SERIES.' seri.';
        }
        foreach ($series as $s) {
            if (! is_array($s) || ! is_string($s['name'] ?? null) || ! is_string($s['field'] ?? null) || mb_strlen($s['name']) > 60 || $s['field'] === $x['field']) {
                return 'Her seride name ve field olmalı.';
            }
        }
        if (count(array_unique(array_column($series, 'field'))) !== count($series)) {
            return 'Seri alanları tekrar ediyor.';
        }
        if (! is_array($points) || ! array_is_list($points) || $points === [] || count($points) > self::MAX_POINTS) {
            return 'Veri 1–'.self::MAX_POINTS.' nokta olmalı.';
        }

        $fields = array_column($series, 'field');
        $clean = [];
        foreach ($points as $point) {
            if (! is_array($point)) {
                return 'Veri satırı nesne olmalı.';
            }
            $label = $point[$x['field']] ?? null;
            if (! is_string($label) && ! is_int($label) && ! is_float($label)) {
                return "Her satırda {$x['field']} olmalı.";
            }
            if (($x['type'] ?? 'category') === 'date' && ! preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', (string) $label)) {
                return 'Tarih YYYY-MM-DD veya YYYY-MM-DDTHH:mm olmalı.';
            }
            $row = [$x['field'] => is_string($label) ? mb_substr($label, 0, 80) : $label];
            foreach ($fields as $field) {
                $value = $point[$field] ?? null;
                if ($value !== null && ! is_int($value) && ! is_float($value)) {
                    return "{$field} sayı olmalı.";
                }
                $row[$field] = $value;
            }
            $clean[] = $row;
        }

        $reference = $data['reference'] ?? null;
        if ($reference !== null && (! is_array($reference) || ! is_numeric($reference['y'] ?? null) || (isset($reference['label']) && ! is_string($reference['label'])))) {
            return 'reference {y, label} olmalı.';
        }

        return [
            'type' => $type,
            'title' => trim($title),
            'source' => $source,
            'x' => ['field' => $x['field'], 'type' => $x['type'] ?? 'category', 'label' => is_string($x['label'] ?? null) ? mb_substr($x['label'], 0, 60) : null],
            'y' => ['unit' => $y['unit'] ?? '', 'label' => is_string($y['label'] ?? null) ? mb_substr($y['label'], 0, 60) : null, 'min' => isset($y['min']) ? (float) $y['min'] : null],
            'series' => array_map(fn (array $s) => ['name' => $s['name'], 'field' => $s['field']], $series),
            'data' => $clean,
            'reference' => $reference === null ? null : ['y' => (float) $reference['y'], 'label' => is_string($reference['label'] ?? null) ? mb_substr($reference['label'], 0, 40) : null],
            'derived' => (bool) ($data['derived'] ?? false),
            'note' => is_string($data['note'] ?? null) ? mb_substr($data['note'], 0, 200) : null,
        ];
    }

    /**
     * Değerler kaynak satırlarda mı: etiketler her zaman; sayılar sadece derived değilse.
     *
     * @param  array<string, mixed>  $chart
     * @param  array{columns: list<string>, rows: list<list<mixed>>}  $source
     */
    private static function checkValues(array $chart, array $source): ?string
    {
        $cells = array_merge(...array_map(fn ($row) => is_array($row) ? array_values($row) : [], $source['rows'] ?: [[]]));
        $strings = [];
        $numbers = [];
        foreach ($cells as $cell) {
            if (is_int($cell) || is_float($cell)) {
                $numbers[] = (float) $cell;
                $strings[] = (string) $cell;
            } elseif (is_string($cell)) {
                $strings[] = $cell;
                if (is_numeric($cell)) {
                    $numbers[] = (float) $cell;
                }
            }
        }

        // Açıklamada değer yazılmaz (kaynakta olmayan değer kişisel veri olabilir); sadece satır numarası.
        $field = $chart['x']['field'];
        foreach ($chart['data'] as $i => $point) {
            $label = (string) $point[$field];
            if ($chart['derived'] && $label === self::OTHER) {
                continue;
            }
            if (! self::labelInSource($label, $strings)) {
                return 'Etiket kaynakta yok (satır '.($i + 1).').';
            }
        }

        if ($chart['derived']) {
            return count($chart['data']) <= count($source['rows']) + 1 ? null : 'Kaynaktan fazla satır.';
        }

        foreach ($chart['data'] as $i => $point) {
            foreach ($chart['series'] as $s) {
                $value = $point[$s['field']];
                if ($value !== null && ! self::numberInSource((float) $value, $numbers)) {
                    return "Değer kaynakta yok ({$s['name']}, satır ".($i + 1).').';
                }
            }
        }

        return null;
    }

    /** Etiket kaynakta aynen veya tarih/saat öneki olarak var mı ("2026-10-05" ↔ "2026-10-05T00:00:00"). */
    private static function labelInSource(string $label, array $strings): bool
    {
        foreach ($strings as $cell) {
            if ($cell === $label || (preg_match('/^\d{4}-\d{2}-\d{2}/', $label) && str_starts_with(str_replace(' ', 'T', $cell), $label))) {
                return true;
            }
        }

        return false;
    }

    /** Sayı kaynakta var mı; modelin yuvarlamasına izin (82,4123 → 82,4). */
    private static function numberInSource(float $value, array $numbers): bool
    {
        foreach ($numbers as $number) {
            if (abs($number - $value) <= max(0.051, abs($number) * 0.0005)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<array-key, mixed>  $data */
    private static function containsMarkup(array $data): bool
    {
        foreach ($data as $key => $value) {
            if ((is_string($key) && preg_match('/[<>]/', $key)) || (is_string($value) && preg_match('/[<>]|javascript:|on\w+\s*=/i', $value)) || (is_array($value) && self::containsMarkup($value))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sohbette saklanan araç sonuçlarından sorgunun Claude'a giden satırları (soru kartından sonra devam eden
     * cevaplarda önceki turların sorguları da bulunur).
     *
     * @param  list<string>  $queryIds
     * @return array<string, array{columns: list<string>, rows: list<list<mixed>>}>
     */
    public static function sourceRows(Conversation $conversation, array $queryIds, array $pending = []): array
    {
        $found = [];
        $blocks = $pending;
        $conversation->messages()->where('role', Message::ROLE_USER)->latest('id')->limit(40)->get(['id', 'content'])
            ->each(function (Message $m) use (&$blocks) {
                foreach (is_array($m->content) ? $m->content : [] as $block) {
                    $blocks[] = $block;
                }
            });

        foreach ($blocks as $block) {
            if (($block['type'] ?? null) !== 'tool_result' || ! is_string($block['content'] ?? null)) {
                continue;
            }
            $data = json_decode($block['content'], true);
            $id = is_array($data) ? ($data['query_id'] ?? null) : null;
            if ($id !== null && in_array($id, $queryIds, true) && ! isset($found[$id]) && is_array($data['rows'] ?? null)) {
                $found[$id] = ['columns' => $data['columns'] ?? [], 'rows' => $data['rows']];
            }
        }

        return $found;
    }
}
