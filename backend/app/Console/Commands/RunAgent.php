<?php

namespace App\Console\Commands;

use App\Agents\AgentRunner;
use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fortis:agent:run {slug} {--input= : Görev metni} {--test : Test çalışması (yazan aksiyonlar gönderilmez)}')]
#[Description('Bir agent\'ı elle çalıştırır (kuyruğa atar). Gerçek Claude çağrısı yapar.')]
class RunAgent extends Command
{
    public function handle(AgentRunner $runner): int
    {
        $agent = Agent::query()->where('slug', $this->argument('slug'))->first();

        if ($agent === null) {
            $this->error('Agent bulunamadı. Var olanlar: '.Agent::query()->pluck('slug')->implode(', '));

            return self::FAILURE;
        }

        $run = $runner->start($agent, $this->option('input') ?: 'Görevini şimdi yap.', $agent->owner, (bool) $this->option('test'));
        $run->refresh();

        $this->info("Çalışma #{$run->id}: {$run->status}".($run->status === AgentRun::QUEUED ? ' (işlemek için: php artisan queue:work --queue=agents)' : ''));
        if ($run->summary) {
            $this->line($run->summary);
        }
        if ($run->error) {
            $this->error($run->error);
        }

        return self::SUCCESS;
    }
}
