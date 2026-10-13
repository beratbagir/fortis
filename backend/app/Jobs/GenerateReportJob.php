<?php

namespace App\Jobs;

use App\Models\Report;
use App\Services\Reports\ReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Çok satırlı raporun (REPORT_SYNC_ROWS üstü) arka planda üretilmesi. Veri yeniden çekilir.
 */
class GenerateReportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public readonly string $reportId) {}

    public function handle(ReportService $reports): void
    {
        $report = Report::find($this->reportId);

        if ($report !== null && $report->status === Report::STATUS_QUEUED) {
            $reports->generate($report);
        }
    }
}
