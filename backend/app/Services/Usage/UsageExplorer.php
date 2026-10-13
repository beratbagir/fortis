<?php

namespace App\Services\Usage;

use App\Models\AiCall;
use App\Models\Department;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Yönetişim > Kullanım (A4.3): ai_calls üzerinden filtreli toplamlar, kırılımlar ve satırlar. Tarihler Türkiye
 * gününe göre; her rakam ai_calls.cost_usd'den. Tür: sohbet, agent, başlık, puanlama, eval, akış adımı.
 */
class UsageExplorer
{
    public const TYPES = ['chat' => 'Sohbet', 'agent' => 'Agent', 'title' => 'Başlık', 'evaluation' => 'Puanlama', 'eval' => 'Eval', 'flow' => 'Akış adımı'];

    private const TZ = 'Europe/Istanbul';

    private const TYPE_SQL = "CASE WHEN ai_calls.purpose = 'title' THEN 'title' WHEN ai_calls.purpose = 'evaluation' THEN 'evaluation' WHEN ai_calls.agent_run_id IS NOT NULL THEN 'agent' WHEN ai_calls.source = 'flow_step' THEN 'flow' WHEN conversations.title LIKE '[eval]%' THEN 'eval' ELSE 'chat' END";

    /**
     * @param  array{from?: string|null, to?: string|null, department_id?: int|null, user_id?: int|null, model?: string|null, type?: string|null}  $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} Türkiye günü [başlangıç, bitiş+1)
     */
    public static function range(array $filters): array
    {
        $from = isset($filters['from']) ? CarbonImmutable::parse($filters['from'], self::TZ)->startOfDay() : CarbonImmutable::now(self::TZ)->startOfMonth();
        $to = isset($filters['to']) ? CarbonImmutable::parse($filters['to'], self::TZ)->startOfDay()->addDay() : CarbonImmutable::now(self::TZ)->startOfDay()->addDay();

        return [$from, $to];
    }

    /** @param  array<string, mixed>  $filters */
    public function query(array $filters): Builder
    {
        [$from, $to] = self::range($filters);

        return AiCall::query()
            ->leftJoin('conversations', 'conversations.id', '=', 'ai_calls.conversation_id')
            ->where('ai_calls.created_at', '>=', $from->utc())->where('ai_calls.created_at', '<', $to->utc())
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('ai_calls.department_id', $id))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('ai_calls.user_id', $id))
            ->when($filters['model'] ?? null, fn ($q, $model) => $q->where('ai_calls.model', $model))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->whereRaw(self::TYPE_SQL.' = ?', [$type]));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function report(array $filters): array
    {
        [$from, $to] = self::range($filters);
        $base = fn () => $this->query($filters);

        $totals = $base()->selectRaw('count(*) as calls, coalesce(sum(ai_calls.cost_usd), 0) as cost, coalesce(sum(ai_calls.input_tokens), 0) as input_tokens, coalesce(sum(ai_calls.output_tokens), 0) as output_tokens')->first();

        // Günlük (Türkiye günü) × tür: satırlar PHP'de gruplanır (veritabanından bağımsız saat dilimi).
        $days = [];
        for ($d = $from; $d < $to; $d = $d->addDay()) {
            $days[$d->toDateString()] = ['date' => $d->toDateString()] + array_fill_keys(array_keys(self::TYPES), 0.0);
        }
        foreach ($base()->selectRaw('ai_calls.created_at as at, ai_calls.cost_usd as cost, '.self::TYPE_SQL.' as type')->cursor() as $row) {
            $day = CarbonImmutable::parse($row->at, 'UTC')->setTimezone(self::TZ)->toDateString();
            if (isset($days[$day])) {
                $days[$day][$row->type] = round($days[$day][$row->type] + (float) $row->cost, 6);
            }
        }

        $departments = Department::query()->pluck('name', 'id');
        $byDepartment = $base()->selectRaw('ai_calls.department_id as id, count(*) as calls, sum(ai_calls.cost_usd) as cost')->groupBy('ai_calls.department_id')->orderByDesc('cost')->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->id ? ($departments[$r->id] ?? '—') : 'Departmansız', 'calls' => (int) $r->calls, 'cost_usd' => round((float) $r->cost, 4)])->values();
        $models = config('ai.models');
        $byModel = $base()->selectRaw('ai_calls.model as model, count(*) as calls, sum(ai_calls.cost_usd) as cost')->groupBy('ai_calls.model')->orderByDesc('cost')->get()
            ->map(fn ($r) => ['model' => $r->model, 'label' => $models[$r->model]['label'] ?? $r->model, 'calls' => (int) $r->calls, 'cost_usd' => round((float) $r->cost, 4)])->values();
        $byType = $base()->selectRaw(self::TYPE_SQL.' as type, count(*) as calls, sum(ai_calls.cost_usd) as cost')->groupBy('type')->get()
            ->map(fn ($r) => ['type' => $r->type, 'label' => self::TYPES[$r->type] ?? $r->type, 'calls' => (int) $r->calls, 'cost_usd' => round((float) $r->cost, 4)])->sortByDesc('cost_usd')->values();
        $topAgents = $base()->join('agent_runs', 'agent_runs.id', '=', 'ai_calls.agent_run_id')->join('agents', 'agents.id', '=', 'agent_runs.agent_id')
            ->selectRaw('agents.id as id, agents.name as name, count(distinct agent_runs.id) as runs, sum(ai_calls.cost_usd) as cost')->groupBy('agents.id', 'agents.name')->orderByDesc('cost')->limit(10)->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'runs' => (int) $r->runs, 'cost_usd' => round((float) $r->cost, 4)])->values();
        $users = User::query()->pluck('name', 'id');
        $topUsers = $base()->whereNotNull('ai_calls.user_id')->selectRaw('ai_calls.user_id as id, ai_calls.department_id as department_id, count(*) as calls, sum(ai_calls.cost_usd) as cost')->groupBy('ai_calls.user_id', 'ai_calls.department_id')->orderByDesc('cost')->limit(10)->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $users[$r->id] ?? '—', 'department' => $r->department_id ? ($departments[$r->department_id] ?? null) : null, 'calls' => (int) $r->calls, 'cost_usd' => round((float) $r->cost, 4)])->values();

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->subDay()->toDateString()],
            'totals' => ['calls' => (int) $totals->calls, 'cost_usd' => round((float) $totals->cost, 4), 'input_tokens' => (int) $totals->input_tokens, 'output_tokens' => (int) $totals->output_tokens],
            'daily' => array_values($days),
            'by_type' => $byType,
            'by_department' => $byDepartment,
            'by_model' => $byModel,
            'top_agents' => $topAgents,
            'top_users' => $topUsers,
            'rows_total' => (int) $totals->calls,
            'rows' => $this->rows($filters, 200),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function rows(array $filters, int $limit): array
    {
        $departments = Department::query()->pluck('name', 'id');
        $users = User::query()->pluck('name', 'id');

        return $this->query($filters)
            ->selectRaw('ai_calls.id, ai_calls.created_at, ai_calls.user_id, ai_calls.department_id, ai_calls.purpose, ai_calls.model, ai_calls.input_tokens, ai_calls.output_tokens, ai_calls.cost_usd, '.self::TYPE_SQL.' as type')
            ->orderByDesc('ai_calls.id')->limit($limit)->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'at' => CarbonImmutable::parse($r->created_at, 'UTC')->toIso8601String(),
                'user' => $r->user_id ? ($users[$r->user_id] ?? null) : null,
                'department' => $r->department_id ? ($departments[$r->department_id] ?? null) : null,
                'type' => $r->type,
                'purpose' => $r->purpose,
                'model' => $r->model,
                'input_tokens' => (int) $r->input_tokens,
                'output_tokens' => (int) $r->output_tokens,
                'cost_usd' => round((float) $r->cost_usd, 6),
            ])->all();
    }

    /** @return array<string, mixed> Filtre seçenekleri */
    public function options(): array
    {
        return [
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'users' => User::query()->whereIn('id', AiCall::query()->whereNotNull('user_id')->select('user_id')->distinct())->orderBy('name')->get(['id', 'name']),
            'models' => collect(config('ai.models'))->map(fn (array $m, string $id) => ['id' => $id, 'label' => $m['label']])->values()
                ->concat(AiCall::query()->select('model')->distinct()->pluck('model')->filter(fn ($m) => $m && ! isset(config('ai.models')[$m]))->map(fn ($m) => ['id' => $m, 'label' => $m]))->values(),
            'types' => collect(self::TYPES)->map(fn (string $label, string $key) => ['id' => $key, 'label' => $label])->values(),
        ];
    }
}
