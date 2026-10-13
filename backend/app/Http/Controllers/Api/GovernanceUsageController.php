<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetLimit;
use App\Models\Department;
use App\Models\User;
use App\Services\Auth\Audit;
use App\Services\Settings\AppSettings;
use App\Services\Usage\Budget;
use App\Services\Usage\UsageExplorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Yönetişim > Kullanım (A4.3, sadece admin): filtreli harcama, kırılımlar, ai_calls satırları ve CSV; bütçeler
 * (şirket, departman, kullanıcı; uyarı eşiği, "dolunca durdur") ve kredi gösterimi.
 */
class GovernanceUsageController extends Controller
{
    public const CREDIT_SETTING = 'usage.credit_usd_value';

    private const CSV_LIMIT = 50000;

    public function __construct(
        private readonly UsageExplorer $explorer,
        private readonly Budget $budget,
        private readonly AppSettings $settings,
    ) {}

    public function usage(Request $request): JsonResponse|StreamedResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'department_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'model' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(array_keys(UsageExplorer::TYPES))],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
        ]);

        if (($filters['format'] ?? 'json') === 'csv') {
            return $this->csv($filters, $request->user());
        }

        return response()->json(['data' => $this->explorer->report($filters) + [
            'options' => $this->explorer->options(),
            'credit_usd_value' => $this->creditValue(),
        ]]);
    }

    public function budgets(): JsonResponse
    {
        return response()->json(['data' => $this->presentBudgets()]);
    }

    /** Bütçeleri ve kredi değerini kaydet. Boş limitli departman/kullanıcı bütçesi silinir; her değişiklik kayda geçer. */
    public function updateBudgets(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'budgets' => ['sometimes', 'array', 'max:200'],
            'budgets.*.scope' => ['required', Rule::in([BudgetLimit::ORG, BudgetLimit::DEPARTMENT, BudgetLimit::USER])],
            'budgets.*.scope_id' => ['nullable', 'integer'],
            'budgets.*.monthly_limit_usd' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'budgets.*.warn_at_percent' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'budgets.*.hard_stop' => ['sometimes', 'boolean'],
            'credit_usd_value' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:100'],
        ]);
        $admin = $request->user();

        foreach ($validated['budgets'] ?? [] as $i => $input) {
            $scope = $input['scope'];
            $scopeId = $scope === BudgetLimit::ORG ? null : ($input['scope_id'] ?? null);
            abort_if($scope !== BudgetLimit::ORG && $scopeId === null, 422, "budgets.{$i}.scope_id zorunlu.");
            abort_if($scope === BudgetLimit::DEPARTMENT && ! Department::query()->whereKey($scopeId)->exists(), 422, "budgets.{$i}: departman yok.");
            abort_if($scope === BudgetLimit::USER && ! User::query()->whereKey($scopeId)->exists(), 422, "budgets.{$i}: kullanıcı yok.");

            $row = BudgetLimit::query()->where('scope', $scope)->where('scope_id', $scopeId)->first();
            $before = $row ? ['monthly_limit_usd' => $row->monthly_limit_usd, 'warn_at_percent' => $row->warn_at_percent, 'hard_stop' => $row->hard_stop] : null;
            $limit = isset($input['monthly_limit_usd']) && (float) $input['monthly_limit_usd'] > 0 ? round((float) $input['monthly_limit_usd'], 2) : null;

            if ($limit === null && $scope !== BudgetLimit::ORG) {
                if ($row !== null) {
                    $row->delete();
                    Audit::record('settings.update', null, meta: ['setting' => "budget.{$scope}.{$scopeId}", 'before' => $before, 'after' => null], user: $admin);
                }

                continue;
            }

            $after = [
                'monthly_limit_usd' => $limit,
                'warn_at_percent' => (int) ($input['warn_at_percent'] ?? $row?->warn_at_percent ?? 80),
                'hard_stop' => (bool) ($input['hard_stop'] ?? $row?->hard_stop ?? false),
            ];
            if ($before === $after) {
                continue;
            }

            // Limit veya eşik değişince bu ayın uyarıları yeniden gönderilebilir.
            $reset = $before === null || $before['monthly_limit_usd'] !== $after['monthly_limit_usd'] || $before['warn_at_percent'] !== $after['warn_at_percent'] ? ['warn_sent_month' => null, 'full_sent_month' => null] : [];
            BudgetLimit::query()->updateOrCreate(['scope' => $scope, 'scope_id' => $scopeId], $after + $reset);
            Audit::record('settings.update', null, meta: ['setting' => "budget.{$scope}".($scopeId ? ".{$scopeId}" : ''), 'before' => $before, 'after' => $after], user: $admin);
        }

        if (array_key_exists('credit_usd_value', $validated)) {
            $before = $this->creditValue();
            $after = $validated['credit_usd_value'] === null ? null : (float) $validated['credit_usd_value'];
            if ($before !== $after) {
                $this->settings->set(self::CREDIT_SETTING, $after);
                Audit::record('settings.update', null, meta: ['setting' => self::CREDIT_SETTING, 'before' => $before, 'after' => $after], user: $admin);
            }
        }

        Budget::forget();
        $this->budget->checkAlerts(BudgetLimit::query()->get());

        return response()->json(['data' => $this->presentBudgets()]);
    }

    private function creditValue(): ?float
    {
        $value = $this->settings->get(self::CREDIT_SETTING);

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    /** @return array<string, mixed> */
    private function presentBudgets(): array
    {
        $rows = BudgetLimit::query()->get();
        $present = function (?BudgetLimit $row) {
            if ($row === null) {
                return null;
            }
            $status = $this->budget->statusFor($row);

            return ['id' => $row->id, 'monthly_limit_usd' => $row->monthly_limit_usd, 'warn_at_percent' => $row->warn_at_percent, 'hard_stop' => $row->hard_stop, 'spent_usd' => $status['spent'], 'ratio' => $status['ratio']];
        };

        return [
            'month' => Budget::month()->locale('tr')->isoFormat('MMMM YYYY'),
            'org' => $present(BudgetLimit::org()),
            'departments' => Department::query()->orderBy('name')->get()->map(fn (Department $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'budget' => $present($rows->first(fn ($r) => $r->scope === BudgetLimit::DEPARTMENT && $r->scope_id === $d->id)),
                'spent_usd' => $this->budget->spentFor(new BudgetLimit(['scope' => BudgetLimit::DEPARTMENT, 'scope_id' => $d->id])),
            ])->values(),
            'users' => $rows->where('scope', BudgetLimit::USER)->map(fn (BudgetLimit $r) => ['user_id' => $r->scope_id, 'name' => User::query()->whereKey($r->scope_id)->value('name'), 'budget' => $present($r)])->values(),
            'credit_usd_value' => $this->creditValue(),
            'blocks_flow_ai_steps' => $this->settings->blocksFlowAiSteps(),
        ];
    }

    /** @param  array<string, mixed>  $filters */
    private function csv(array $filters, User $admin): StreamedResponse
    {
        $rows = $this->explorer->rows($filters, self::CSV_LIMIT);
        Audit::record('usage.export', null, meta: ['filters' => array_diff_key($filters, ['format' => 1]), 'rows' => count($rows)], user: $admin);
        $types = UsageExplorer::TYPES;
        $credit = $this->creditValue();

        return response()->streamDownload(function () use ($rows, $types, $credit) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Zaman (UTC)', 'Kullanıcı', 'Departman', 'Tür', 'Amaç', 'Model', 'Giriş token', 'Çıkış token', 'Maliyet (USD)', ...($credit ? ['Kredi'] : [])]);
            foreach ($rows as $r) {
                fputcsv($out, [$r['at'], $r['user'], $r['department'], $types[$r['type']] ?? $r['type'], $r['purpose'], $r['model'], $r['input_tokens'], $r['output_tokens'], number_format($r['cost_usd'], 6, '.', ''), ...($credit ? [round($r['cost_usd'] / $credit, 2)] : [])]);
            }
            fclose($out);
        }, 'fortis-kullanim.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
