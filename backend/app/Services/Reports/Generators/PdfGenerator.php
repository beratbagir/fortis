<?php

namespace App\Services\Reports\Generators;

use App\Services\Reports\ReportTable;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * PDF: sabit Blade şablonu (resources/views/reports/pdf.blade.php), DejaVu Sans, yatay A4.
 * group_by verilmişse üstte gruplara göre sayılar. Sayfa altında sayfa numarası. Uzak kaynak yüklenmez.
 */
class PdfGenerator implements ReportGenerator
{
    public function write(ReportTable $table, string $path): void
    {
        $fontCache = storage_path('app/dompdf');
        if (! is_dir($fontCache)) {
            mkdir($fontCache, 0755, true);
        }

        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setFontCache($fontCache);
        $options->setTempDir(sys_get_temp_dir());
        $options->setChroot([resource_path('views/reports')]);

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('reports.pdf', ['table' => $table])->render(), 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->addInfo('Title', $table->title);
        $pdf->addInfo('Creator', 'Fortis');
        $pdf->render();

        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 120, $canvas->get_height() - 28, 'Sayfa {PAGE_NUM} / {PAGE_COUNT}', $font, 7, [0.36, 0.4, 0.46]);

        if (file_put_contents($path, $pdf->output()) === false) {
            throw new RuntimeException('PDF dosyası yazılamadı.');
        }
    }
}
