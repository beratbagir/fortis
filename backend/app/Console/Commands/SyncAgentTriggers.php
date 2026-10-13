<?php

namespace App\Console\Commands;

use App\Agents\AgentSchedules;
use App\Models\AgentTrigger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fortis:agent:sync-triggers {--all : Hatasız olanları da yeniden yükle}')]
#[Description('Zamanlama tetikleyicilerini Kestra\'ya yeniden aktarır (varsayılan: sadece hata almış olanlar). Zamanlayıcıda 15 dakikada bir.')]
class SyncAgentTriggers extends Command
{
    public function handle(AgentSchedules $schedules): int
    {
        $triggers = AgentTrigger::query()->with('agent')->where('type', AgentTrigger::SCHEDULE)
            ->when(! $this->option('all'), fn ($q) => $q->where(fn ($q) => $q->whereNotNull('sync_error')->orWhereNull('synced_at')))
            ->whereHas('agent')
            ->get();

        $failed = $triggers->reject(fn (AgentTrigger $t) => $schedules->sync($t))->count();
        $this->info("{$triggers->count()} tetikleyici denendi, {$failed} başarısız.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
