<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetLimit;
use App\Services\Auth\Audit;
use App\Services\Settings\AppSettings;
use App\Services\Settings\Policies;
use App\Services\Usage\Budget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI ayarları: izinli modeller, genel varsayılan model, aylık bütçe ve AI veri erişimi. Okumak herkese açık (model
 * seçici); değiştirmek admin'de. Ayrıntılı politikalar Yönetişim > Politikalar'da (GovernancePolicyController).
 */
class SettingsController extends Controller
{
    public function __construct(private readonly AppSettings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->present()]);
    }

    /**
     * Eski uç (A4.4'ten beri Yönetişim > Politikalar ile aynı kurallar): her değişiklik önceki/yeni değerle kayda geçer.
     * `monthly_budget_usd` şirket bütçe satırını günceller.
     */
    public function update(Request $request, Policies $policies): JsonResponse
    {
        $request->validate(['monthly_budget_usd' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000']]);
        $validated = $policies->validate($request->only(['default_model', 'budget_blocks_flow_ai_steps', 'ai_data_access', 'ai_allow_employee_names']));
        $policies->update($validated, $request->user());

        if ($request->has('monthly_budget_usd')) {
            $org = BudgetLimit::org();
            $before = $org->monthly_limit_usd;
            $after = $request->input('monthly_budget_usd') === null ? null : round((float) $request->input('monthly_budget_usd'), 2);
            if ($before !== $after) {
                $org->update(['monthly_limit_usd' => $after, 'warn_sent_month' => null, 'full_sent_month' => null]);
                Budget::forget();
                Audit::record('settings.update', meta: ['setting' => 'budget.org', 'before' => ['monthly_limit_usd' => $before], 'after' => ['monthly_limit_usd' => $after]], user: $request->user());
            }
        }

        return response()->json(['data' => $this->present()]);
    }

    /** @return array<string, mixed> */
    private function present(): array
    {
        return [
            'models' => collect($this->settings->models())->map(fn (array $model, string $id) => ['id' => $id, 'strength' => config("ai.model_strengths.{$id}")] + $model)->values(),
            // Model seçicinin altındaki not; doğrulanmamış ifade (ör. veri bölgesi) yazılmaz.
            'policy_note' => strtr((string) config('ai.policy_note'), ['{data}' => $this->settings->dataAccessEnabled() ? 'Kişisel bilgiler AI\'a gönderilmez' : 'Şirket verisi AI\'a gönderilmez']),
            'default_model' => $this->settings->defaultModel(),
            'monthly_budget_usd' => $this->settings->monthlyBudget(),
            'budget_blocks_flow_ai_steps' => $this->settings->blocksFlowAiSteps(),
            'ai_data_access' => $this->settings->dataAccess(),
            'ai_allow_employee_names' => $this->settings->allowEmployeeNames(),
        ];
    }
}
