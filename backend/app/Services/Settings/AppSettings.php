<?php

namespace App\Services\Settings;

use App\Models\BudgetLimit;
use App\Models\Setting;

/**
 * Uygulama geneli ayarlar: varsayılan model, aylık bütçe ve bütçenin akış içi AI adımlarına etkisi.
 * Model listesi ve fiyatlar config/ai.php'dedir; burada sadece seçimler tutulur.
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

    /** @return array<string, array{label: string, input_per_mtok: float, output_per_mtok: float, cache_write_per_mtok: float, cache_read_per_mtok: float}> */
    public function models(): array
    {
        return config('ai.models');
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
