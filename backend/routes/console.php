<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Süresi dolan rapor dosyaları her gün silinir.
Schedule::command('reports:prune')->daily();

// Bağlantı durumları (Home çipleri) 10 dakikada bir kontrol edilir.
Schedule::command('connectors:check')->everyTenMinutes()->withoutOverlapping();

// Agent olay tetikleyicileri: APM uçuş değişiklikleri (rötar, iptal) 5 dakikada bir.
Schedule::command('fortis:agent:watch-flights')->everyFiveMinutes()->withoutOverlapping();

// Kestra'ya aktarılamamış agent zamanlamaları yeniden denenir.
Schedule::command('fortis:agent:sync-triggers')->everyFifteenMinutes()->withoutOverlapping();

// Yönetişim "Dikkat gerekenler" uyarıları.
Schedule::command('governance:scan')->everyFiveMinutes()->withoutOverlapping();
