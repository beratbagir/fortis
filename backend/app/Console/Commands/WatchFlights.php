<?php

namespace App\Console\Commands;

use App\Agents\FlightEvents;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('fortis:agent:watch-flights')]
#[Description('APM uçuşlarını okur; rötar ve iptal olaylarıyla olay tetikleyicili agentları çalıştırır (zamanlayıcıda 5 dakikada bir).')]
class WatchFlights extends Command
{
    public function handle(FlightEvents $events): int
    {
        if ($events->activeTriggers()->isEmpty()) {
            $this->line('Açık olay tetikleyicisi yok; APM okunmadı.');

            return self::SUCCESS;
        }

        try {
            $summary = $events->scan();
        } catch (RuntimeException $e) {
            $this->error("APM okunamadı: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("{$summary['legs']} uçuş tarandı, ".count($summary['events'])." olay, {$summary['runs']} çalışma başlatıldı, {$summary['skipped']} atlandı.");

        return self::SUCCESS;
    }
}
