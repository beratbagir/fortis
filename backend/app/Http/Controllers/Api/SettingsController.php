<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\Audit;
use App\Services\Settings\AppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AI ayarları: genel varsayılan model, aylık bütçe ve AI veri erişimi. Değiştirmek admin'de.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly AppSettings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->present()]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'default_model' => ['sometimes', 'string', Rule::in(array_keys($this->settings->models()))],
            'monthly_budget_usd' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'budget_blocks_flow_ai_steps' => ['sometimes', 'boolean'],
            'ai_data_access' => ['sometimes', 'string', Rule::in([AppSettings::DATA_ACCESS_OFF, AppSettings::DATA_ACCESS_MASKED])],
            'ai_allow_employee_names' => ['sometimes', 'boolean'],
        ], ['default_model.in' => 'Bu model kullanılamaz. İzinli modeller: '.implode(', ', array_keys($this->settings->models())).'.']);

        if (array_key_exists('default_model', $validated)) {
            $this->settings->set(AppSettings::DEFAULT_MODEL, $validated['default_model']);
        }

        if (array_key_exists('monthly_budget_usd', $validated)) {
            $this->settings->set(AppSettings::MONTHLY_BUDGET, $validated['monthly_budget_usd'] === null ? null : (float) $validated['monthly_budget_usd']);
        }

        if (array_key_exists('budget_blocks_flow_ai_steps', $validated)) {
            $this->settings->set(AppSettings::BLOCKS_FLOW_AI_STEPS, (bool) $validated['budget_blocks_flow_ai_steps']);
        }

        if (array_key_exists('ai_data_access', $validated)) {
            $this->settings->set(AppSettings::DATA_ACCESS, $validated['ai_data_access']);
        }

        if (array_key_exists('ai_allow_employee_names', $validated)) {
            $this->settings->set(AppSettings::ALLOW_EMPLOYEE_NAMES, (bool) $validated['ai_allow_employee_names']);
        }

        Audit::record('settings.update', meta: ['changes' => $validated]);

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
