<?php

namespace App\Jobs;

use App\Agents\AgentRunner;
use App\Models\AgentRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Agent çalışması ("agents" kuyruğu). Claude 429 / 5xx verirse en fazla 2 kez yeniden denenir.
 */
class RunAgentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 90];

    public int $timeout = 660;

    public function __construct(public readonly int $runId) {}

    public function handle(AgentRunner $runner): void
    {
        $runner->execute(AgentRun::query()->findOrFail($this->runId));
    }

    public function failed(Throwable $e): void
    {
        $run = AgentRun::query()->find($this->runId);
        if ($run !== null && ! in_array($run->status, [AgentRun::SUCCEEDED, AgentRun::FAILED], true)) {
            app(AgentRunner::class)->fail($run, 'Çalışma tamamlanamadı: '.$e->getMessage());
        }
    }
}
