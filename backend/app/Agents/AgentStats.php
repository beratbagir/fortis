<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentAction;
use App\Models\AgentFeedback;
use App\Models\AgentRun;
use App\Models\AgentRunScore;
use App\Models\AgentTrigger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Agent Kaydı kartları ve agent sayfasının Genel bakış'ı için ölçüler. Test çalışmaları ölçülere girmez.
 * Faydalı oranı: son 30 günde "faydalı" geri bildirimlerin oranı; geri bildirim yoksa null (gösterilmez).
 */
class AgentStats
{
    private const TIMEZONE = 'Europe/Istanbul';

    /**
     * @param  Collection<int, Agent>  $agents
     * @return array<int, array{runs_7d: int, runs_30d: int, useful_pct: int|null, score_avg: int|null, approved_actions_30d: int, last_run_at: string|null}>
     */
    public function cards(Collection $agents): array
    {
        $ids = $agents->pluck('id')->all();
        $since30 = now()->subDays(30);
        $since7 = now()->subDays(7);

        $runs = AgentRun::query()->whereIn('agent_id', $ids)->where('is_test', false)->where('created_at', '>=', $since30)->get(['id', 'agent_id', 'created_at']);
        $feedback = AgentFeedback::query()->join('agent_runs', 'agent_runs.id', '=', 'agent_feedback.agent_run_id')
            ->whereIn('agent_runs.agent_id', $ids)->where('agent_feedback.created_at', '>=', $since30)
            ->get(['agent_runs.agent_id', 'agent_feedback.rating']);
        $approved = AgentAction::query()->join('agent_runs', 'agent_runs.id', '=', 'agent_actions.agent_run_id')
            ->whereIn('agent_runs.agent_id', $ids)->where('agent_runs.is_test', false)->where('agent_actions.created_at', '>=', $since30)
            ->whereIn('agent_actions.status', [AgentAction::APPROVED, AgentAction::EXECUTED])
            ->selectRaw('agent_runs.agent_id, count(*) as n')->groupBy('agent_runs.agent_id')->pluck('n', 'agent_runs.agent_id');
        $scores = AgentRunScore::query()->join('agent_runs', 'agent_runs.id', '=', 'agent_run_scores.agent_run_id')
            ->join('agent_evaluations', 'agent_evaluations.agent_id', '=', 'agent_runs.agent_id')
            ->whereIn('agent_runs.agent_id', $ids)->where('agent_evaluations.enabled', true)->where('agent_runs.is_test', false)->where('agent_run_scores.created_at', '>=', $since30)
            ->selectRaw('agent_runs.agent_id, avg(agent_run_scores.score) as s')->groupBy('agent_runs.agent_id')->pluck('s', 'agent_runs.agent_id');
        $last = AgentRun::query()->whereIn('agent_id', $ids)->where('is_test', false)->selectRaw('agent_id, max(created_at) as last_at')->groupBy('agent_id')->pluck('last_at', 'agent_id');

        $result = [];
        foreach ($ids as $id) {
            $mine = $runs->where('agent_id', $id);
            $votes = $feedback->where('agent_id', $id);
            $result[$id] = [
                'runs_7d' => $mine->filter(fn (AgentRun $r) => $r->created_at >= $since7)->count(),
                'runs_30d' => $mine->count(),
                'useful_pct' => $votes->isEmpty() ? null : (int) round(100 * $votes->where('rating', AgentFeedback::USEFUL)->count() / $votes->count()),
                'score_avg' => isset($scores[$id]) ? (int) round((float) $scores[$id]) : null,
                'approved_actions_30d' => (int) ($approved[$id] ?? 0),
                'last_run_at' => isset($last[$id]) ? CarbonImmutable::parse($last[$id])->toIso8601String() : null,
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function overview(Agent $agent): array
    {
        $now = CarbonImmutable::now(self::TIMEZONE);
        $from = $now->subDays(29)->startOfDay();
        $previousFrom = $from->subDays(30);

        $runs = $agent->runs()->where('is_test', false)->where('created_at', '>=', $previousFrom->utc())->with('actions')->get();
        [$current, $previous] = $runs->partition(fn (AgentRun $r) => $r->created_at >= $from->utc());
        $finished = $current->whereIn('status', [AgentRun::SUCCEEDED, AgentRun::FAILED]);
        $duration = fn (Collection $list) => $list->filter(fn (AgentRun $r) => $r->started_at && $r->finished_at)->map(fn (AgentRun $r) => $r->finished_at->diffInSeconds($r->started_at, true))->avg();
        $actions = $current->flatMap->actions;
        $votes = AgentFeedback::query()->whereIn('agent_run_id', $current->pluck('id'))->pluck('rating');
        $scores = AgentRunScore::query()->whereIn('agent_run_id', $current->pluck('id'))->pluck('score');
        $cost = (float) $current->sum('cost_usd');
        $avg = $duration($current);
        $previousAvg = $duration($previous);

        $days = collect(range(0, 29))->map(function (int $i) use ($from, $current) {
            $day = $from->addDays($i);
            $onDay = $current->filter(fn (AgentRun $r) => $r->created_at->setTimezone(self::TIMEZONE)->isSameDay($day));

            return [
                'date' => $day->toDateString(),
                'succeeded' => $onDay->where('status', AgentRun::SUCCEEDED)->where('attempts', '<=', 1)->count(),
                'retried' => $onDay->where('status', AgentRun::SUCCEEDED)->where('attempts', '>', 1)->count(),
                'failed' => $onDay->where('status', AgentRun::FAILED)->count(),
                'other' => $onDay->whereNotIn('status', [AgentRun::SUCCEEDED, AgentRun::FAILED])->count(),
            ];
        })->values();

        return [
            'kpis' => [
                'runs' => $current->count(),
                'retried' => $current->where('attempts', '>', 1)->count(),
                'success_rate' => $finished->isEmpty() ? null : (int) round(100 * $finished->where('status', AgentRun::SUCCEEDED)->count() / $finished->count()),
                'avg_seconds' => $avg === null ? null : (int) round($avg),
                'avg_seconds_delta' => $avg === null || $previousAvg === null ? null : (int) round($avg - $previousAvg),
                'cost_usd' => round($cost, 4),
                'cost_per_run_usd' => $current->isEmpty() ? null : round($cost / $current->count(), 4),
                'useful_pct' => $votes->isEmpty() ? null : (int) round(100 * $votes->filter(fn ($r) => $r === AgentFeedback::USEFUL)->count() / $votes->count()),
                'feedback' => ['useful' => $votes->filter(fn ($r) => $r === AgentFeedback::USEFUL)->count(), 'not_useful' => $votes->filter(fn ($r) => $r === AgentFeedback::NOT_USEFUL)->count()],
                'score_avg' => $scores->isEmpty() ? null : (int) round($scores->avg()),
                'scored_runs' => $scores->count(),
                'actions_approved' => $actions->whereIn('status', [AgentAction::APPROVED, AgentAction::EXECUTED])->count(),
                'actions_total' => $actions->count(),
            ],
            'days' => $days,
            'config' => self::configSummary($agent),
        ];
    }

    /** @return array<string, string> Yapılandırma özeti (Tetikleyici, Teslim, Okuduğu, Yazdığı, Model, Bellek). */
    public static function configSummary(Agent $agent): array
    {
        $catalog = config('agents.tools');
        $tools = collect($agent->tools ?? []);
        $writes = $tools->filter(fn (array $t) => ($catalog[$t['name']]['kind'] ?? null) === 'write');

        return [
            'trigger' => $agent->triggers()->orderBy('id')->get()->map(fn (AgentTrigger $t) => self::triggerText($t))->implode(' · '),
            'delivery' => $writes->isEmpty() ? 'Teslim yok (sadece sohbet ve çıktı)' : $writes->map(function (array $t) use ($catalog) {
                $c = $t['constraints'] ?? [];
                $to = $c['recipients'] ?? (isset($c['channels']) ? array_map(fn ($ch) => "#{$ch}", $c['channels']) : []);

                return $catalog[$t['name']]['label'].($to ? ' → '.implode(', ', $to) : '').' · Fortis Inbox onayı';
            })->implode('; '),
            'reads' => $tools->filter(fn (array $t) => ($catalog[$t['name']]['kind'] ?? null) === 'read' && ($catalog[$t['name']]['system'] ?? '') !== 'Fortis')->map(fn (array $t) => $catalog[$t['name']]['system'])->unique()->implode(', ').' · salt okunur',
            'writes' => $writes->isEmpty() ? 'Hiçbir şey' : $writes->map(fn (array $t) => $catalog[$t['name']]['system'])->unique()->implode(', ').' (onaydan sonra)',
            'model' => $agent->model === 'auto' ? 'Auto' : (config('ai.models')[$agent->model]['label'] ?? $agent->model),
            'memory' => 'Bellek A5\'te gelecek',
        ];
    }

    public static function triggerText(AgentTrigger $trigger): string
    {
        return match ($trigger->type) {
            AgentTrigger::SCHEDULE => CronText::describe($trigger->config['cron'] ?? ''),
            AgentTrigger::EVENT => (config('agents.events')[$trigger->config['event'] ?? '']['label'] ?? ($trigger->config['event'] ?? '')).(isset($trigger->config['min_delay_minutes']) ? " ≥ {$trigger->config['min_delay_minutes']} dk" : ''),
            default => 'Elle',
        };
    }
}
