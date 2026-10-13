<?php

namespace App\Jobs;

use App\Agents\RunScorer;
use App\Models\AgentRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Biten çalışmanın değerlendirme puanı ("agents" kuyruğu). Hata olursa bir kez yeniden denenir; puanlanamazsa çalışma
 * etkilenmez, sadece puanı olmaz.
 */
class ScoreAgentRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [60];

    public int $timeout = 120;

    public function __construct(public readonly int $runId) {}

    public function handle(RunScorer $scorer): void
    {
        $run = AgentRun::query()->with('agent.evaluation')->find($this->runId);

        if ($run !== null && RunScorer::shouldScore($run)) {
            $scorer->score($run);
        }
    }
}
