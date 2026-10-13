<?php

namespace App\Services\Settings;

use App\Models\BudgetLimit;
use App\Models\User;
use App\Services\Auth\Audit;
use App\Services\Usage\Budget;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Yönetişim > Politikalar (A4.4): veri, modeller, bütçe ve agent ayarlarının tek yeri. Her değişiklik ayrı bir
 * `settings.update` denetim kaydına önceki ve yeni değerle yazılır. Eski /api/settings/ai de buradan geçer.
 * Bütçe satırları (şirket/departman) /api/governance/budgets'ta; bağlantılar /api/connectors'ta (aynı veri).
 */
class Policies
{
    /** Alan → ayar anahtarı. */
    private const KEYS = [
        'ai_data_access' => AppSettings::DATA_ACCESS,
        'ai_allow_employee_names' => AppSettings::ALLOW_EMPLOYEE_NAMES,
        'allowed_models' => AppSettings::ALLOWED_MODELS,
        'default_model' => AppSettings::DEFAULT_MODEL,
        'data_question_model' => AppSettings::DATA_QUESTION_MODEL,
        'fast_model' => AppSettings::FAST_MODEL,
        'budget_blocks_flow_ai_steps' => AppSettings::BLOCKS_FLOW_AI_STEPS,
        'allow_external_recipients' => AppSettings::ALLOW_EXTERNAL_RECIPIENTS,
        'allowed_recipient_domains' => AppSettings::RECIPIENT_DOMAINS,
        'max_tool_calls' => AppSettings::MAX_TOOL_CALLS,
        'max_cost_usd' => AppSettings::MAX_COST_USD,
        'max_seconds' => AppSettings::MAX_SECONDS,
    ];

    public function __construct(private readonly AppSettings $settings) {}

    /** @return array<string, mixed> Alanların şu anki (geçerli) değerleri */
    public function values(): array
    {
        return [
            'ai_data_access' => $this->settings->dataAccess(),
            'ai_allow_employee_names' => $this->settings->allowEmployeeNames(),
            'allowed_models' => array_keys($this->settings->models()),
            'default_model' => $this->settings->defaultModel(),
            'data_question_model' => $this->settings->dataQuestionModel(),
            'fast_model' => $this->settings->fastModel(),
            'budget_blocks_flow_ai_steps' => $this->settings->blocksFlowAiSteps(),
            'allow_external_recipients' => $this->settings->allowExternalRecipients(),
            'allowed_recipient_domains' => $this->settings->recipientDomains(),
            'max_tool_calls' => $this->settings->maxToolCalls(),
            'max_cost_usd' => $this->settings->maxCostUsd(),
            'max_seconds' => $this->settings->maxSeconds(),
        ];
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        $catalog = collect(config('ai.models'))->map(fn (array $m, string $id) => ['id' => $id, 'label' => $m['label'], 'strength' => config("ai.model_strengths.{$id}"), 'input_per_mtok' => $m['input_per_mtok'], 'output_per_mtok' => $m['output_per_mtok']])->values();

        return $this->values() + [
            'model_catalog' => $catalog,
            // Salt okunur (.env): Claude'a giden sorgu sonucu sınırları.
            'max_rows' => (int) config('ai-data.max_rows'),
            'max_chars' => (int) config('ai-data.max_chars'),
            // Pilotta bütün yazan araçlar her zaman onaylı (kodda zorunlu); burada sadece gösterilir.
            'approval_policy' => collect(config('agents.tools'))->filter(fn (array $t) => ($t['kind'] ?? null) === 'write')->map(fn (array $t, string $name) => ['tool' => $name, 'label' => $t['label'], 'mode' => 'always'])->values(),
            'company_budget' => BudgetLimit::org()->only(['monthly_limit_usd', 'warn_at_percent', 'hard_stop']),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> Doğrulanmış alanlar
     */
    public function validate(array $input): array
    {
        $catalog = array_keys(config('ai.models'));
        $validated = validator($input, [
            'ai_data_access' => ['sometimes', Rule::in([AppSettings::DATA_ACCESS_OFF, AppSettings::DATA_ACCESS_MASKED])],
            'ai_allow_employee_names' => ['sometimes', 'boolean'],
            'allowed_models' => ['sometimes', 'array', 'min:1'],
            'allowed_models.*' => ['string', Rule::in($catalog)],
            'default_model' => ['sometimes', 'string', Rule::in($catalog)],
            'data_question_model' => ['sometimes', 'string', Rule::in($catalog)],
            'fast_model' => ['sometimes', 'string', Rule::in($catalog)],
            'budget_blocks_flow_ai_steps' => ['sometimes', 'boolean'],
            'allow_external_recipients' => ['sometimes', 'boolean'],
            'allowed_recipient_domains' => ['sometimes', 'array', 'min:1', 'max:20'],
            'allowed_recipient_domains.*' => ['string', 'max:100', 'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/i'],
            'max_tool_calls' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'max_cost_usd' => ['sometimes', 'numeric', 'min:0.1', 'max:50'],
            'max_seconds' => ['sometimes', 'integer', 'min:60', 'max:3600'],
        ], [
            'default_model.in' => 'Bu model kullanılamaz. İzinli modeller: '.implode(', ', $catalog).'.',
            'allowed_recipient_domains.*.regex' => 'Geçersiz alan adı.',
            'allowed_models.min' => 'En az bir model izinli olmalı.',
        ])->validate();

        // Seçili modeller izinli listede olmalı (liste değişiyorsa yenisine göre).
        $allowed = $validated['allowed_models'] ?? array_keys($this->settings->models());
        foreach (['default_model' => 'Genel varsayılan model', 'data_question_model' => 'Veri sorusu modeli', 'fast_model' => 'Hızlı model'] as $field => $label) {
            $model = $validated[$field] ?? $this->values()[$field];
            if (! in_array($model, $allowed, true) && ($field === 'default_model' || array_key_exists($field, $validated))) {
                throw ValidationException::withMessages([$field => "{$label} izinli modellerden biri olmalı."]);
            }
        }

        if (isset($validated['allowed_recipient_domains'])) {
            $validated['allowed_recipient_domains'] = array_values(array_unique(array_map(fn (string $d) => mb_strtolower(trim($d)), $validated['allowed_recipient_domains'])));
        }
        if (isset($validated['allowed_models'])) {
            $validated['allowed_models'] = array_values(array_unique($validated['allowed_models']));
        }

        return $validated;
    }

    /**
     * Doğrulanmış alanları kaydeder; değişen her alan ayrı denetim kaydı (önceki → yeni).
     *
     * @param  array<string, mixed>  $validated
     * @return list<string> Değişen alanlar
     */
    public function update(array $validated, ?User $admin = null): array
    {
        $before = $this->values();
        $changed = [];

        foreach ($validated as $field => $value) {
            $value = match ($field) {
                'ai_allow_employee_names', 'budget_blocks_flow_ai_steps', 'allow_external_recipients' => (bool) $value,
                'max_tool_calls', 'max_seconds' => (int) $value,
                'max_cost_usd' => round((float) $value, 2),
                default => $value,
            };
            if ($before[$field] === $value) {
                continue;
            }

            $this->settings->set(self::KEYS[$field], $value);
            Audit::record('settings.update', meta: ['setting' => self::KEYS[$field], 'before' => $before[$field], 'after' => $value], user: $admin);
            $changed[] = $field;
        }

        if (in_array('budget_blocks_flow_ai_steps', $changed, true)) {
            Budget::forget();
        }

        return $changed;
    }
}
