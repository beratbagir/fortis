<?php

namespace App\Services\Warehouse;

/**
 * Akışlardaki veri ambarı SQL'inin statik kontrolü.
 *
 * Asıl koruma veritabanı kullanıcısının sadece okuma yetkisidir; bu sınıf ek olarak sorgunun tek bir
 * SELECT/WITH olmasını, süre ve satır sınırı taşımasını, şablon ifadesi içermemesini (değerler parametre
 * olarak bağlanır) ve gizli sütunlara dokunmamasını sağlar.
 */
class SqlGuard
{
    private const FORBIDDEN_WORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'GRANT', 'REVOKE',
        'CALL', 'HANDLER', 'RENAME', 'LOAD_FILE', 'SLEEP', 'BENCHMARK', 'GET_LOCK', 'RELEASE_LOCK',
    ];

    private const FORBIDDEN_PHRASES = [
        '/\bINTO\s+(OUTFILE|DUMPFILE)\b/i' => 'INTO OUTFILE/DUMPFILE',
        '/\bINTO\s+@/i' => 'INTO @değişken',
        '/\bFOR\s+UPDATE\b/i' => 'FOR UPDATE',
        '/\bLOCK\s+IN\s+SHARE\s+MODE\b/i' => 'LOCK IN SHARE MODE',
        '/\bFOR\s+SHARE\b/i' => 'FOR SHARE',
    ];

    private const HINT = '/\/\*\+\s*MAX_EXECUTION_TIME\(\s*(\d+)\s*\)\s*\*\//i';

    /**
     * @param  list<string>  $hiddenColumns
     */
    public function __construct(
        private readonly array $hiddenColumns = [],
        private readonly int $maxExecutionMs = 120000,
        private readonly int $maxLimit = 5000,
    ) {}

    /**
     * @param  bool  $requireLimit  Tüm satırlar dönüyorsa (FETCH) LIMIT zorunludur.
     * @return array{errors: list<string>, placeholders: list<string>}
     */
    public function check(string $sql, bool $requireLimit): array
    {
        $errors = [];

        if (str_contains($sql, '{{') || str_contains($sql, '{%')) {
            $errors[] = 'SQL içinde {{ }} kullanılamaz. Değerleri :ad yer tutucusuyla yazıp parameters alanında ver.';
        }

        if (! preg_match(self::HINT, $sql, $hint)) {
            $errors[] = "SQL'in ilk SELECT'inde süre sınırı olmalı: SELECT /*+ MAX_EXECUTION_TIME(60000) */ ... (en fazla {$this->maxExecutionMs} ms).";
        } elseif ((int) $hint[1] < 1 || (int) $hint[1] > $this->maxExecutionMs) {
            $errors[] = "MAX_EXECUTION_TIME en fazla {$this->maxExecutionMs} ms olabilir.";
        }

        $code = $this->code($sql);
        $trimmed = rtrim(trim($code), ';');

        if (str_contains($trimmed, ';')) {
            $errors[] = 'Tek bir SQL ifadesi yazılabilir (noktalı virgülle birden fazla ifade olamaz).';
        }

        if (! preg_match('/^\(*\s*(SELECT|WITH)\b/i', trim($trimmed))) {
            $errors[] = 'SQL sadece SELECT veya WITH ile başlayabilir; veri ambarında sadece okuma yapılır.';
        }

        foreach (self::FORBIDDEN_WORDS as $word) {
            if (preg_match('/\b'.$word.'\b/i', $trimmed)) {
                $errors[] = "SQL'de {$word} kullanılamaz.";
            }
        }

        foreach (self::FORBIDDEN_PHRASES as $pattern => $label) {
            if (preg_match($pattern, $trimmed)) {
                $errors[] = "SQL'de {$label} kullanılamaz.";
            }
        }

        foreach ($this->hiddenColumns as $column) {
            if (preg_match('/\b'.preg_quote($column, '/').'\b/i', $trimmed)) {
                $errors[] = "'{$column}' sütunu kullanılamaz.";
            }
        }

        if ($requireLimit) {
            if (! preg_match('/\bLIMIT\s+(\d+)\s*$/i', trim($trimmed), $limit)) {
                $errors[] = "Tüm satırları döndüren sorgu (fetchType: FETCH) sonunda LIMIT içermeli (en fazla {$this->maxLimit}).";
            } elseif ((int) $limit[1] > $this->maxLimit) {
                $errors[] = "LIMIT en fazla {$this->maxLimit} olabilir.";
            }
        }

        return ['errors' => $errors, 'placeholders' => $this->placeholders($code)];
    }

    /**
     * :ad yer tutucularını ? ile değiştirir (EXPLAIN için) ve sıralı adları döndürür.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function positional(string $sql): array
    {
        $names = [];
        $out = preg_replace_callback(
            '/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|`[^`]*`|(?<![:\w]):([A-Za-z_]\w*)/s',
            function (array $m) use (&$names) {
                if (! isset($m[1])) {
                    return $m[0];
                }
                $names[] = $m[1];

                return '?';
            },
            $sql,
        );

        return [(string) $out, $names];
    }

    /** Karşılaştırma için normalleştirilmiş SQL (boşluklar ve sondaki ; yok sayılır). */
    public static function normalize(string $sql): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', rtrim(trim($sql), ';'))));
    }

    /** @return list<string> */
    private function placeholders(string $code): array
    {
        preg_match_all('/(?<![:\w]):([A-Za-z_]\w*)/', $code, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Metin sabitlerini ve yorumları çıkarır; optimizer ipuçları (/*+ ... *\/) kalır.
     */
    private function code(string $sql): string
    {
        return (string) preg_replace(
            ['/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', '/\/\*(?!\+).*?\*\//s', '/(--\s|#)[^\n]*/'],
            ["''", ' ', ' '],
            $sql,
        );
    }
}
