<?php

namespace App\Services\Usage;

use App\Mail\BudgetAlert;
use App\Models\AiCall;
use App\Services\Settings\AppSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Aylık AI bütçesi. %80'de yöneticilere bir kez (ayda bir) e-posta gider; %100'de yeni sohbet AI çağrıları durur.
 * Akış içi AI adımlarının durması ayrı bir ayardır (budget_blocks_flow_ai_steps).
 */
class Budget
{
    public function __construct(private readonly AppSettings $settings) {}

    public function spent(?Carbon $month = null): float
    {
        $month ??= now();

        return round((float) AiCall::query()
            ->whereBetween('created_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->sum('cost_usd'), 6);
    }

    /** @return array{limit: float|null, spent: float, ratio: float|null} */
    public function status(): array
    {
        $limit = $this->settings->monthlyBudget();
        $spent = $this->spent();

        return ['limit' => $limit, 'spent' => $spent, 'ratio' => $limit === null ? null : round($spent / $limit, 4)];
    }

    /** @throws BudgetExceededException */
    public function assertChatAllowed(): void
    {
        $status = $this->status();

        if ($status['limit'] !== null && $status['spent'] >= $status['limit']) {
            throw new BudgetExceededException(sprintf(
                'Bu ayın AI bütçesi doldu ($%s / $%s). Yeni AI istekleri ay sonuna kadar durduruldu. Yönetici "Kullanım ve maliyet" ekranından limiti artırabilir.',
                number_format($status['spent'], 2),
                number_format($status['limit'], 2),
            ));
        }
    }

    /** Akış içi AI adımı: bütçe dolduysa ve ayar açıksa adım hata vermeli. */
    public function flowStepBlocked(): bool
    {
        $status = $this->status();

        return $this->settings->blocksFlowAiSteps() && $status['limit'] !== null && $status['spent'] >= $status['limit'];
    }

    /** Her AI çağrısından sonra: %80'e ulaşıldıysa ve bu ay gönderilmediyse yöneticilere e-posta. */
    public function checkAlert(): void
    {
        $status = $this->status();
        $month = now()->format('Y-m');

        if ($status['ratio'] === null || $status['ratio'] < (float) config('ai.budget_alert_ratio')
            || $this->settings->get(AppSettings::BUDGET_ALERT_MONTH) === $month) {
            return;
        }

        $this->settings->set(AppSettings::BUDGET_ALERT_MONTH, $month);
        $recipients = config('ai.admin_emails');

        if ($recipients === []) {
            Log::warning('AI bütçesinin %80\'i aşıldı ama FORTIS_ADMIN_EMAILS boş; uyarı e-postası gönderilmedi.', $status);

            return;
        }

        Mail::to($recipients)->send(new BudgetAlert($status['spent'], (float) $status['limit'], $month));
    }
}
