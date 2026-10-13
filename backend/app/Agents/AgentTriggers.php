<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentTrigger;
use App\Models\AuditLog;
use App\Services\Auth\Audit;
use App\Services\Usage\BudgetExceededException;
use Illuminate\Support\Facades\Cache;

/**
 * Tetikleyiciden çalışma başlatma (zamanlama ve olay). Kurallar: tetikleyici açık, agent pilot veya canlı; aynı
 * tetikleyicinin önceki çalışması bitmediyse yenisi açılmaz; olay tetikleyicisi saatte en fazla
 * `agents.max_event_runs_per_hour` çalışma başlatır. Başlatılmayan her istek denetim kaydına düşer.
 */
class AgentTriggers
{
    public const SKIP_DISABLED = 'disabled';

    public const SKIP_INACTIVE = 'inactive';

    public const SKIP_BUSY = 'busy';

    public const SKIP_RATE_LIMITED = 'rate_limited';

    public const SKIP_BUDGET = 'budget';

    private const SKIP_MESSAGES = [
        self::SKIP_DISABLED => 'Tetikleyici kapalı.',
        self::SKIP_INACTIVE => 'Agent pilot veya canlı değil.',
        self::SKIP_BUSY => 'Bu tetikleyicinin önceki çalışması hâlâ sürüyor.',
        self::SKIP_RATE_LIMITED => 'Bu tetikleyici son bir saatte çalışma sınırına ulaştı.',
        self::SKIP_BUDGET => 'Bütçe bu ay doldu ("dolunca durdur" açık); çalışma başlatılmadı.',
    ];

    public function __construct(private readonly AgentRunner $runner, private readonly Inbox $inbox) {}

    public static function message(string $reason): string
    {
        return self::SKIP_MESSAGES[$reason] ?? $reason;
    }

    /**
     * @param  string|null  $event  Olay verisi (metin); görev şablonundaki {{event}} yerine konur.
     * @param  array<string, mixed>  $meta  Denetim kaydına eklenecek bilgi (olay adı, sefer no…).
     */
    public function fire(AgentTrigger $trigger, ?string $event = null, array $meta = []): AgentRun|string
    {
        $agent = $trigger->agent;

        $reason = match (true) {
            ! $trigger->enabled => self::SKIP_DISABLED,
            ! in_array($agent->status, [Agent::STATUS_PILOT, Agent::STATUS_LIVE], true) => self::SKIP_INACTIVE,
            default => null,
        };

        if ($reason === null) {
            $run = Cache::lock("agent-trigger-{$trigger->id}", 10)->block(5, function () use ($trigger, $agent, $event) {
                if ($trigger->type === AgentTrigger::SCHEDULE && $trigger->runs()->whereIn('status', [AgentRun::QUEUED, AgentRun::RUNNING])->exists()) {
                    return self::SKIP_BUSY;
                }
                if ($trigger->type === AgentTrigger::EVENT && $trigger->runs()->where('created_at', '>=', now()->subHour())->count() >= config('agents.max_event_runs_per_hour')) {
                    return self::SKIP_RATE_LIMITED;
                }

                try {
                    return $this->runner->start($agent, $trigger->input($event), null, false, $trigger);
                } catch (BudgetExceededException $e) {
                    $this->inbox->budgetSkipAlert($agent, $e->getMessage());

                    return self::SKIP_BUDGET;
                }
            });

            if ($run instanceof AgentRun) {
                return $run;
            }
            $reason = $run;
        }

        Audit::record('agent.run.skipped', $trigger, AuditLog::RESULT_BLOCKED, meta: $meta + ['agent_id' => $agent->id, 'trigger' => $trigger->type, 'reason' => $reason], actorType: AuditLog::ACTOR_AGENT, actorLabel: $agent->name, user: $agent->owner);

        return $reason;
    }
}
