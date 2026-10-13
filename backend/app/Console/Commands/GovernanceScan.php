<?php

namespace App\Console\Commands;

use App\Services\Governance\GovernanceAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('governance:scan')]
#[Description('Yönetişim "Dikkat gerekenler" uyarılarını üretir ve düzelenleri kapatır (zamanlayıcıda 5 dakikada bir).')]
class GovernanceScan extends Command
{
    public function handle(GovernanceAlerts $alerts): int
    {
        $open = $alerts->scan();
        $this->info(array_sum($open).' açık uyarı'.($open === [] ? '' : ': '.collect($open)->map(fn ($n, $t) => "{$t} {$n}")->implode(', ')));

        return self::SUCCESS;
    }
}
