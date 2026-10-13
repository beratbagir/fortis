<?php

namespace App\Services\Reports\Generators;

use App\Services\Reports\ReportTable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel: ilk satır kalın başlık, filtre açık, ilk satır sabit, sütun genişlikleri içeriğe göre.
 * Tarihler gerçek Excel tarihi (Türkiye saati) ve dd.mm.yyyy hh:mm biçiminde; sayılar sayı hücresi.
 */
class XlsxGenerator implements ReportGenerator
{
    private const FORMATS = [
        'date' => 'dd.mm.yyyy',
        'datetime' => 'dd.mm.yyyy hh:mm',
        'integer' => '#,##0',
        'number' => '#,##0.00',
    ];

    public function write(ReportTable $table, string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        // Sayfa adı en fazla 31 karakter; Excel'in yasak karakterleri çıkarılır.
        $sheet->setTitle(mb_substr(trim((string) preg_replace(['/[\[\]:*?\/\\\\]+/', '/\s+/'], ' ', $table->title)) ?: 'Rapor', 0, 31));
        $spreadsheet->getProperties()->setTitle($table->title)->setCreator('Fortis');

        $keys = array_keys($table->columns);
        foreach ($keys as $i => $key) {
            $sheet->setCellValue([$i + 1, 1], $table->columns[$key]['label']);
        }

        foreach ($table->rows as $r => $row) {
            foreach ($keys as $i => $key) {
                $value = $row[$key] ?? null;
                $type = $table->columns[$key]['type'];

                $sheet->setCellValue([$i + 1, $r + 2], match (true) {
                    $value instanceof DateTimeInterface => Date::dateTimeToExcel($value),
                    is_bool($value) => $value ? 'Evet' : 'Hayır',
                    default => $value,
                });
            }
        }

        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($keys)));
        $lastRow = count($table->rows) + 1;

        foreach ($keys as $i => $key) {
            $column = Coordinate::stringFromColumnIndex($i + 1);
            if (isset(self::FORMATS[$table->columns[$key]['type']]) && $lastRow > 1) {
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode(self::FORMATS[$table->columns[$key]['type']]);
            }
            if (in_array($table->columns[$key]['type'], ['integer', 'number'], true)) {
                $sheet->getStyle("{$column}1:{$column}{$lastRow}")->getAlignment()->setHorizontal('right');
            }
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType('solid')->getStartColor()->setRGB('E6EDF5');
        $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");
        $sheet->freezePane('A2');

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }
}
