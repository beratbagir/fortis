<?php

namespace App\Console\Commands;

use App\Services\Connectors\Connectors;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('connectors:check')]
#[Description('Bağlı sistemleri (IBS, APM, SuccessFactors) dener ve sonucu connector_checks tablosuna yazar')]
class CheckConnectors extends Command
{
    public function handle(Connectors $connectors): int
    {
        foreach ($connectors->checkAll() as $check) {
            $this->line(sprintf('%-15s %s %4d ms %s', $check->connector, $check->ok ? 'OK  ' : 'HATA', $check->duration_ms, $check->error ?? ''));
        }

        return self::SUCCESS;
    }
}
