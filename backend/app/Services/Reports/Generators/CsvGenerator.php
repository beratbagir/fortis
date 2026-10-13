<?php

namespace App\Services\Reports\Generators;

use App\Services\Reports\ReportTable;
use RuntimeException;

/**
 * CSV: UTF-8 BOM (Excel'de Türkçe karakterler doğru görünsün), ayraç ";" (Türkçe Excel'in beklediği).
 * Sayılar ve tarihler Türkçe biçimde metin olarak yazılır.
 */
class CsvGenerator implements ReportGenerator
{
    public function write(ReportTable $table, string $path): void
    {
        $out = fopen($path, 'wb') ?: throw new RuntimeException('CSV dosyası açılamadı.');
        fwrite($out, "\u{FEFF}");
        fputcsv($out, array_column($table->columns, 'label'), ';', '"', '');

        foreach ($table->rows as $row) {
            fputcsv($out, array_map(
                fn (string $key) => ReportTable::text($row[$key] ?? null, $table->columns[$key]['type']),
                array_keys($table->columns),
            ), ';', '"', '');
        }

        fclose($out);
    }
}
