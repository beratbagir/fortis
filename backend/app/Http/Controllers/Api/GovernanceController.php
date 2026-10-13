<?php

namespace App\Http\Controllers\Api;

use App\Agents\AgentRunner;
use App\Http\Controllers\Controller;
use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\AgentRunScore;
use App\Models\AiCall;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Execution;
use App\Models\GovernanceAlert;
use App\Models\Message;
use App\Services\Auth\Audit;
use App\Services\Flows\ExecutionException;
use App\Services\Flows\ExecutionService;
use App\Services\Governance\GovernanceAlerts;
use App\Services\Kestra\KestraClient;
use App\Services\Usage\Budget;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Yönetişim Genel bakış (A4.2, sadece admin): canlı çalışmalar (agent + akış) ve durdurma, bu ayın özeti, dikkat
 * gerekenler, kalite. Bütün harcamalar ai_calls.cost_usd'den.
 */
class GovernanceController extends Controller
{
    private const LIVE_AGENT_STATUSES = [AgentRun::QUEUED, AgentRun::RUNNING, AgentRun::WAITING_INPUT, AgentRun::WAITING_APPROVAL];

    public function overview(Budget $budget): JsonResponse
    {
        return response()->json(['data' => [
            'live' => $this->live(),
            'month' => $this->month($budget),
            'alerts' => $this->openAlerts(),
            'quality' => $this->quality(),
        ]]);
    }

    /** Admin durdurması: agent çalışması (iptal) veya akış çalışması (Kestra kill). Gerekçe zorunlu, kayda geçer. */
    public function stop(Request $request, string $id, AgentRunner $runner, ExecutionService $executions): JsonResponse
    {
        $validated = $request->validate(['kind' => ['required', Rule::in(['agent', 'flow'])], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        $admin = $request->user();

        if ($validated['kind'] === 'agent') {
            $runner->stop(AgentRun::query()->findOrFail((int) $id), $admin, trim($validated['reason']));
        } else {
            $execution = Execution::query()->where('kestra_execution_id', $id)->firstOrFail();
            abort_if($execution->isFinished(), 409, 'Bu çalışma zaten bitmiş.');
            try {
                $executions->kill($execution);
            } catch (ExecutionException $e) {
                abort(502, $e->getMessage());
            }
            Audit::record('governance.run.stop', $execution, AuditLog::RESULT_ALLOWED, ['kind' => 'flow', 'flow_id' => $execution->flow_id, 'reason' => trim($validated['reason'])], system: 'Kestra', user: $admin);
        }

        return response()->json(['data' => ['live' => $this->live()]]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $resolved = $request->boolean('resolved');

        return response()->json(['data' => GovernanceAlert::query()->with('resolver')
            ->when($resolved, fn ($q) => $q->whereNotNull('resolved_at')->latest('resolved_at')->limit(50), fn ($q) => $q->open())
            ->get()->map(self::presentAlert(...))->sortBy(fn (array $a) => [GovernanceAlerts::severityRank($a['severity']), -strtotime((string) $a['created_at'])])->values()]);
    }

    public function resolve(Request $request, GovernanceAlert $alert, GovernanceAlerts $alerts): JsonResponse
    {
        $alerts->resolve($alert, $request->user());

        return response()->json(['data' => self::presentAlert($alert->refresh())]);
    }

    /** @return list<array<string, mixed>> */
    private function live(): array
    {
        $runs = AgentRun::query()->with(['agent', 'triggeredBy', 'trigger'])->whereIn('status', self::LIVE_AGENT_STATUSES)->latest('id')->limit(50)->get();
        $runCosts = AiCall::query()->whereIn('agent_run_id', $runs->pluck('id'))->selectRaw('agent_run_id, sum(cost_usd) as c')->groupBy('agent_run_id')->pluck('c', 'agent_run_id');

        $executions = Execution::query()->with(['flow', 'triggeredBy'])->where('created_at', '>=', now()->subDay())->latest('id')->limit(100)->get()
            ->reject(fn (Execution $e) => KestraClient::isTerminal($e->state))->take(50);
        $execCosts = AiCall::query()->whereIn('execution_id', $executions->pluck('id'))->selectRaw('execution_id, sum(cost_usd) as c')->groupBy('execution_id')->pluck('c', 'execution_id');

        $agentRows = $runs->map(fn (AgentRun $r) => [
            'kind' => 'agent',
            'id' => (string) $r->id,
            'name' => $r->agent?->name,
            'link' => $r->agent ? "#/agents/{$r->agent_id}/runs/{$r->id}" : null,
            'started_by' => $r->triggeredBy?->name ?? match ($r->origin()) {
                'schedule' => 'Zamanlama', 'event' => 'Olay', default => 'Elle',
            },
            'status' => $r->status,
            'is_test' => $r->is_test,
            'started_at' => ($r->started_at ?? $r->created_at)?->toIso8601String(),
            'seconds' => (int) ($r->started_at ?? $r->created_at)->diffInSeconds(now(), true),
            'tool_calls' => $r->tool_calls_count,
            'cost_usd' => round((float) ($runCosts[$r->id] ?? 0), 4),
        ]);

        $flowRows = $executions->map(fn (Execution $e) => [
            'kind' => 'flow',
            'id' => $e->kestra_execution_id,
            'name' => $e->flow?->title ?? 'Akış',
            'link' => "#/runs/{$e->kestra_execution_id}",
            'started_by' => $e->triggeredBy?->name ?? ($e->started_by ?: 'Zamanlama'),
            'status' => strtolower((string) $e->state),
            'is_test' => (bool) $e->is_test,
            'started_at' => ($e->started_at ?? $e->created_at)?->toIso8601String(),
            'seconds' => (int) ($e->started_at ?? $e->created_at)->diffInSeconds(now(), true),
            'tool_calls' => null,
            'cost_usd' => round((float) ($execCosts[$e->id] ?? 0), 4),
        ]);

        return $agentRows->concat($flowRows)->sortByDesc('started_at')->values()->all();
    }

    /** @return array<string, mixed> */
    private function month(Budget $budget): array
    {
        $now = CarbonImmutable::now('Europe/Istanbul');
        $from = $now->startOfMonth()->utc();
        $prevFrom = $now->startOfMonth()->subMonth()->utc();
        // Geçen ayın aynı dönemi (ayın bugüne kadarki kısmıyla karşılaştırma).
        $prevTo = $prevFrom->addSeconds((int) $from->diffInSeconds(now(), true));

        $spent = (float) AiCall::query()->where('created_at', '>=', $from)->sum('cost_usd');
        $prev = (float) AiCall::query()->whereBetween('created_at', [$prevFrom, $prevTo])->sum('cost_usd');
        $status = $budget->status();
        $actions = AgentAction::query()->where('decided_at', '>=', $from)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'label' => $now->locale('tr')->isoFormat('MMMM YYYY'),
            'spent_usd' => round($spent, 4),
            'budget_usd' => $status['limit'],
            'ratio' => $status['limit'] ? round($spent / $status['limit'], 4) : null,
            'previous_same_period_usd' => round($prev, 4),
            'change_pct' => $prev > 0 ? (int) round(100 * ($spent - $prev) / $prev) : null,
            'questions' => Message::query()->where('role', Message::ROLE_USER)->where('text', '!=', '')->where('created_at', '>=', $from)
                ->whereIn('conversation_id', Conversation::query()->where('kind', Conversation::KIND_CHAT)->select('id'))->count(),
            'agent_runs' => AgentRun::query()->where('is_test', false)->where('created_at', '>=', $from)->count(),
            'actions_approved' => (int) (($actions[AgentAction::APPROVED] ?? 0) + ($actions[AgentAction::EXECUTED] ?? 0)),
            'actions_rejected' => (int) ($actions[AgentAction::REJECTED] ?? 0),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function openAlerts(): array
    {
        return GovernanceAlert::query()->open()->get()->map(self::presentAlert(...))
            ->sortBy(fn (array $a) => [GovernanceAlerts::severityRank($a['severity']), -strtotime((string) $a['created_at'])])->values()->all();
    }

    /** @return array<string, mixed> */
    private function quality(): array
    {
        // Son tam çalıştırma (setin en az %70'i); --only ile yapılan kısmi çalıştırmalar ayrıca belirtilir.
        $total = count(json_decode((string) @file_get_contents(base_path('tests/eval/questions.json')), true)['questions'] ?? []);
        $reports = collect(Storage::disk('local')->files('eval'))->filter(fn (string $f) => str_ends_with($f, '.json'))->sort()->reverse()
            ->map(fn (string $f) => json_decode((string) Storage::disk('local')->get($f), true)['summary'] ?? null)->filter()->values();
        $eval = $reports->first(fn (array $s) => ($s['questions'] ?? 0) >= max(1, (int) ceil($total * 0.7)));
        $partial = $reports->first();
        $partial = $partial !== null && $partial !== $eval ? $partial : null;
        $scores = AgentRunScore::query()->where('created_at', '>=', now()->subDays(30))->whereHas('run', fn ($q) => $q->where('is_test', false))->with('run.agent')->get();

        return [
            'eval' => $eval === null ? null : [
                'run_at' => $eval['run_at'] ?? null,
                'questions' => $eval['questions'] ?? 0,
                'passed' => $eval['passed'] ?? 0,
                'correct_tool_ratio' => $eval['correct_tool_ratio'] ?? null,
                'cost_usd' => $eval['total_cost_usd'] ?? null,
            ],
            'eval_partial' => $partial === null ? null : ['run_at' => $partial['run_at'] ?? null, 'questions' => $partial['questions'] ?? 0, 'passed' => $partial['passed'] ?? 0],
            'eval_total_questions' => $total,
            'agent_score_avg' => $scores->isEmpty() ? null : (int) round($scores->avg('score')),
            'scored_runs' => $scores->count(),
            'agents' => $scores->groupBy(fn (AgentRunScore $s) => $s->run?->agent_id)->map(fn ($group) => [
                'id' => $group->first()->run?->agent_id,
                'name' => $group->first()->run?->agent?->name,
                'score_avg' => (int) round($group->avg('score')),
                'runs' => $group->count(),
            ])->sortBy('score_avg')->values()->take(5)->all(),
        ];
    }

    /** @return array<string, mixed> */
    private static function presentAlert(GovernanceAlert $alert): array
    {
        return [
            'id' => $alert->id,
            'type' => $alert->type,
            'severity' => $alert->severity,
            'message' => $alert->message,
            'link' => GovernanceAlerts::link($alert),
            'created_at' => $alert->created_at?->toIso8601String(),
            'updated_at' => $alert->updated_at?->toIso8601String(),
            'resolved_at' => $alert->resolved_at?->toIso8601String(),
            'resolved_by' => $alert->resolver?->name,
        ];
    }
}
