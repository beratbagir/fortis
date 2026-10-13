<?php

namespace App\Ai;

use App\Services\Privacy\NationalIdMasker;
use App\Services\Settings\AppSettings;

/**
 * Dış sistemden gelen veri ile Claude arasındaki tek kapı. Veri getiren her aracın sonucu Claude'a gitmeden önce
 * buradan geçer (FlowTools::EXTERNAL_DATA_TOOLS); başka bir yol yoktur.
 *
 * Sıra: (1) alan adına göre maskeleme, (2) metin içeriğine göre maskeleme (TC kimlik, e-posta, telefon, PNR,
 * bilet no, pasaport), (3) SuccessFactors çalışan adı istisnası, (4) satır ve karakter sınırı, (5) sayım.
 */
class AiDataGate
{
    public const MASK = '[MASKED]';

    public const SOURCE_SUCCESSFACTORS = 'SuccessFactors';

    private int $maskedValues = 0;

    /** @var array<string, true> */
    private array $maskedFields = [];

    public function __construct(private readonly AppSettings $settings) {}

    /**
     * Tablo biçimindeki sonuç (query_* araçları).
     *
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows  Sütun adı → değer
     * @param  int|null  $totalRows  Kaynaktaki toplam satır (bilinmiyorsa satır sayısı)
     * @return array{columns: list<string>, rows: list<list<mixed>>, total_rows: int, returned_rows: int, truncated: bool, masked_fields: list<string>, masked_values: int}
     */
    public function table(string $source, array $columns, array $rows, ?int $totalRows = null): array
    {
        $this->reset();
        $total = $totalRows ?? count($rows);
        $maxRows = (int) config('ai-data.max_rows');

        $shaped = [];
        foreach (array_slice($rows, 0, $maxRows) as $row) {
            $shaped[] = array_map(fn (string $column) => $this->value($source, $column, $row[$column] ?? null), $columns);
        }

        // Karakter sınırı: sığana kadar sondan satır düşülür.
        $maxChars = (int) config('ai-data.max_chars');
        while ($shaped !== [] && mb_strlen((string) json_encode(['columns' => $columns, 'rows' => $shaped], JSON_UNESCAPED_UNICODE)) > $maxChars) {
            array_splice($shaped, max(1, (int) floor(count($shaped) * 0.9)));
        }

        return [
            'columns' => $columns,
            'rows' => $shaped,
            'total_rows' => $total,
            'returned_rows' => count($shaped),
            'truncated' => count($shaped) < $total,
            'masked_fields' => array_keys($this->maskedFields),
            'masked_values' => $this->maskedValues,
        ];
    }

    /**
     * İç içe yapı (önizleme araçlarının sonucu). Anahtar adları sütun adı gibi değerlendirilir; listeler karakter
     * sınırına sığana kadar kısaltılır.
     *
     * @param  array<array-key, mixed>  $data
     * @return array{data: array<array-key, mixed>, masked_fields: list<string>, masked_values: int}
     */
    public function structure(string $source, array $data): array
    {
        $this->reset();
        $data = $this->walk($source, $data, null);

        $maxChars = (int) config('ai-data.max_chars');
        for ($keep = 50; $keep >= 1 && mb_strlen((string) json_encode($data, JSON_UNESCAPED_UNICODE)) > $maxChars; $keep = intdiv($keep, 2)) {
            $data = self::shortenLists($data, $keep);
        }

        if (mb_strlen((string) json_encode($data, JSON_UNESCAPED_UNICODE)) > $maxChars) {
            $data = ['truncated' => true, 'note' => 'Sonuç çok büyük olduğu için gösterilemedi. İsteği daralt.'];
        }

        return ['data' => $data, 'masked_fields' => array_keys($this->maskedFields), 'masked_values' => $this->maskedValues];
    }

    /**
     * Serbest metindeki kişisel veriyi maskeler.
     *
     * @return array{0: string, 1: int} Maskelenmiş metin ve kaç değer maskelendi
     */
    public static function maskText(string $text, bool $operationalCode = false): array
    {
        $count = 0;
        $replace = function (string $pattern, string $text, ?callable $accept = null) use (&$count): string {
            return (string) preg_replace_callback($pattern, function (array $m) use (&$count, $accept) {
                if ($accept !== null && ! $accept($m[0])) {
                    return $m[0];
                }
                $count++;

                return self::MASK;
            }, $text);
        };

        // E-posta (b***@alan.com biçiminde kısmen maskelenmiş olanlar eşleşmez)
        $text = $replace('/(?<![\w.*%+-])[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text);
        // TC kimlik (kontrol haneli 11 hane)
        $text = $replace('/(?<!\d)[1-9]\d{10}(?!\d)/', $text, NationalIdMasker::isNationalId(...));
        // Bilet numarası (13 hane, 3-10 tireli olabilir). /Date(ms)/ zaman damgaları hariç.
        $text = $replace('/(?<![\d(])\d{3}-?\d{10}(?!\d)/', $text);
        // Telefon: Türkiye cep ve uluslararası biçim
        $text = $replace('/(?<![\d+])(?:\+?90[\s.-]?)?0?5\d{2}[\s.-]?\d{3}[\s.-]?\d{2}[\s.-]?\d{2}(?!\d)/', $text);
        $text = $replace('/(?<![\w+])\+\d{1,3}[\s.-]?\(?\d{1,4}\)?(?:[\s.-]?\d{2,4}){2,4}(?!\d)/', $text);
        // Operasyonel kod alanında (sefer no, tescil, çağrı adı) kod biçimli değerler PNR/pasaport sayılmaz.
        if ($operationalCode) {
            return [$text, $count];
        }

        // Pasaport (1-2 harf + 7-8 hane)
        $text = $replace('/\b[A-Z]{1,2}\d{7,8}\b/', $text);
        // PNR: 6 karakter, en az 2 harf ve 1 rakam. Uçuş numarası (FH1256) ve çağrı adı (FHY123) biçimleri hariç.
        $text = $replace('/\b[A-Z0-9]{6}\b/', $text, fn (string $token) => preg_match_all('/[A-Z]/', $token) >= 2
            && preg_match('/\d/', $token)
            && ! preg_match('/^[A-Z0-9]{2}\d{4}$|^[A-Z]{3}\d{3}$/', $token));

        return [$text, $count];
    }

    /** Sütun adının karşılaştırma biçimi: camelCase → snake_case, küçük harf, harf/rakam dışı → _. */
    public static function normalizeColumn(string $column): string
    {
        $snake = (string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $column);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($snake)), '_');
    }

    public function isMaskedColumn(string $source, string $column): bool
    {
        $name = self::normalizeColumn($column);
        $tokens = explode('_', $name);

        foreach (config('ai-data.aggregate_column_words') as $word) {
            if (in_array($word, $tokens, true) || (str_contains($word, '_') && str_contains($name, $word))) {
                return false;
            }
        }

        if ($source === self::SOURCE_SUCCESSFACTORS && $this->settings->allowEmployeeNames() && self::matches($name, config('ai-data.employee_name_columns'))) {
            return false;
        }

        return self::matches($name, config('ai-data.masked_columns'));
    }

    private function value(string $source, string $column, mixed $value): mixed
    {
        if ($value === null || is_bool($value) || $value === self::MASK) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\S\*\*\*@/', $value)) {
            return $value;
        }

        if ($this->isMaskedColumn($source, $column)) {
            return $this->masked($column);
        }

        if (is_int($value) || is_float($value)) {
            $digits = (string) $value;

            return is_int($value) && NationalIdMasker::isNationalId($digits) ? $this->masked($column) : $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        [$masked, $count] = self::maskText($value, self::matches(self::normalizeColumn($column), config('ai-data.operational_columns')));
        if ($count > 0) {
            $this->maskedValues += $count;
            $this->maskedFields[$column] = true;
        }

        $limit = (int) config('ai-data.max_value_chars');

        return mb_strlen($masked) > $limit ? mb_substr($masked, 0, $limit).'…' : $masked;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function walk(string $source, array $data, ?string $parentKey): array
    {
        $out = [];
        foreach ($data as $key => $item) {
            $column = is_string($key) ? $key : ($parentKey ?? '');

            $out[$key] = is_array($item) ? $this->walk($source, $item, $column) : $this->value($source, $column, $item);
        }

        return $out;
    }

    private function masked(string $column): string
    {
        $this->maskedValues++;
        $this->maskedFields[$column] = true;

        return self::MASK;
    }

    private function reset(): void
    {
        $this->maskedValues = 0;
        $this->maskedFields = [];
    }

    /** @param  list<string>  $patterns */
    private static function matches(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($name === $pattern || str_ends_with($name, '_'.$pattern) || str_starts_with($name, $pattern.'_') || str_contains($name, '_'.$pattern.'_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function shortenLists(array $data, int $keep): array
    {
        if (array_is_list($data) && count($data) > $keep) {
            $data = [...array_slice($data, 0, $keep), ['_kalan' => count($data) - $keep]];
        }

        return array_map(fn ($item) => is_array($item) ? self::shortenLists($item, $keep) : $item, $data);
    }
}
