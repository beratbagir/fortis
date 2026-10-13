<?php

namespace App\Services\Settings;

use App\Models\BudgetLimit;
use App\Models\Setting;

/**
 * Uygulama geneli ayarlar (Yönetişim > Politikalar): modeller, veri erişimi, bütçenin akış adımlarına etkisi, agent
 * sınırları ve alıcı kuralları. Ayar yoksa config/.env değeri geçerli. Model kataloğu ve fiyatlar config/ai.php'dedir.
 */
class AppSettings
{
    public const DEFAULT_MODEL = 'ai.default_model';

    public const MONTHLY_BUDGET = 'budget.monthly_usd';

    public const BLOCKS_FLOW_AI_STEPS = 'budget.blocks_flow_ai_steps';

    /** Claude'un veriye bakması: off (araçlar yok) | masked (kişisel veri maskelenerek). Varsayılan off. */
    public const DATA_ACCESS = 'ai.data_access';

    public const DATA_ACCESS_OFF = 'off';

    public const DATA_ACCESS_MASKED = 'masked';

    /** SuccessFactors çalışan adı/soyadı Claude'a gidebilir mi. Varsayılan evet. */
    public const ALLOW_EMPLOYEE_NAMES = 'ai.allow_employee_names';

    /** Politikalar (A4.4): ayar yoksa config/.env değeri geçerli. */
    public const ALLOWED_MODELS = 'ai.allowed_models';

    public const DATA_QUESTION_MODEL = 'ai.data_question_model';

    public const FAST_MODEL = 'ai.fast_model';

    public const PAGE_MODEL = 'ai.page_model';

    public const ALLOW_EXTERNAL_RECIPIENTS = 'agents.allow_external_recipients';

    public const RECIPIENT_DOMAINS = 'agents.allowed_recipient_domains';

    public const MAX_TOOL_CALLS = 'agents.max_tool_calls';

    public const MAX_COST_USD = 'agents.max_cost_usd';

    public const MAX_SECONDS = 'agents.max_seconds';

    /** Bütçe uyarısının gönderildiği son ay (YYYY-MM); ayda bir kez gider. */
    public const BUDGET_ALERT_MONTH = 'budget.alert_sent_month';

    public function get(string $key, mixed $default = null): mixed
    {
        return Setting::query()->where('key', $key)->value('value') ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * İzinli modeller: config'teki katalog, admin bir alt küme seçtiyse o kadarı. Fiyat hesabı her zaman bütün katalogla.
     *
     * @return array<string, array{label: string, input_per_mtok: float, output_per_mtok: float, cache_write_per_mtok: float, cache_read_per_mtok: float}>
     */
    public function models(): array
    {
        $catalog = config('ai.models');
        $allowed = $this->get(self::ALLOWED_MODELS);
        if (! is_array($allowed)) {
            return $catalog;
        }
        $models = array_intersect_key($catalog, array_flip($allowed));

        return $models === [] ? $catalog : $models;
    }

    public function isAllowedModel(mixed $model): bool
    {
        return is_string($model) && array_key_exists($model, $this->models());
    }

    public function defaultModel(): string
    {
        $model = $this->get(self::DEFAULT_MODEL);

        return $this->isAllowedModel($model) ? $model : (string) config('ai.default_model');
    }

    /** Şirket bütçesi (A4.3'ten beri budgets tablosunda). Boş: limitsiz. */
    public function monthlyBudget(): ?float
    {
        $budget = BudgetLimit::org()->monthly_limit_usd;

        return $budget !== null && $budget > 0 ? (float) $budget : null;
    }

    /** Veri sorgusundan sonraki turların modeli (Auto). İzinli değilse çağıran genel varsayılana düşer. */
    public function dataQuestionModel(): string
    {
        return (string) ($this->get(self::DATA_QUESTION_MODEL) ?? config('ai.data_question_model'));
    }

    /** Kısa işlerin modeli (sohbet başlığı). */
    public function fastModel(): string
    {
        return (string) ($this->get(self::FAST_MODEL) ?? config('ai.fast_model'));
    }

    /** HTML sayfa istenen sohbetlerde veri sorgusundan sonraki turların modeli (Auto). */
    public function pageModel(): string
    {
        return (string) ($this->get(self::PAGE_MODEL) ?? config('ai.page_model'));
    }

    /** Agentlar şirket dışı alıcıya (onaylı) gönderebilir mi. */
    public function allowExternalRecipients(): bool
    {
        return (bool) ($this->get(self::ALLOW_EXTERNAL_RECIPIENTS) ?? config('agents.allow_external_recipients'));
    }

    /** @return list<string> Şirket içi sayılan alıcı alan adları */
    public function recipientDomains(): array
    {
        $domains = $this->get(self::RECIPIENT_DOMAINS);

        return array_values(is_array($domains) ? $domains : config('agents.allowed_recipient_domains'));
    }

    public function maxToolCalls(): int
    {
        return (int) ($this->get(self::MAX_TOOL_CALLS) ?? config('agents.max_tool_calls'));
    }

    public function maxCostUsd(): float
    {
        return (float) ($this->get(self::MAX_COST_USD) ?? config('agents.max_cost_usd'));
    }

    public function maxSeconds(): int
    {
        return (int) ($this->get(self::MAX_SECONDS) ?? config('agents.max_seconds'));
    }

    public function blocksFlowAiSteps(): bool
    {
        return (bool) $this->get(self::BLOCKS_FLOW_AI_STEPS, false);
    }

    public function dataAccess(): string
    {
        return $this->get(self::DATA_ACCESS) === self::DATA_ACCESS_MASKED ? self::DATA_ACCESS_MASKED : self::DATA_ACCESS_OFF;
    }

    public function dataAccessEnabled(): bool
    {
        return $this->dataAccess() === self::DATA_ACCESS_MASKED;
    }

    public function allowEmployeeNames(): bool
    {
        return (bool) $this->get(self::ALLOW_EMPLOYEE_NAMES, true);
    }

    public function modelLabel(string $model): string
    {
        return config("ai.models.{$model}.label") ?? config("ai.pricing_only.{$model}.label") ?? $model;
    }
}
