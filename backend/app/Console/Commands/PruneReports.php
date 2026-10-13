<?php

namespace App\Console\Commands;

use App\Services\Reports\ReportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reports:prune')]
#[Description('Saklama süresi (REPORT_RETENTION_DAYS) dolan rapor dosyalarını siler ve kayıtlarını "expired" yapar')]
class PruneReports extends Command
{
    public function handle(ReportService $reports): int
    {
        $this->info($reports->prune().' rapor süresi doldu olarak işaretlendi.');

        return self::SUCCESS;
    }
}
