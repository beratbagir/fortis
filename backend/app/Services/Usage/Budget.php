<?php

namespace App\Services\Usage;

use App\Agents\Inbox;
use App\Mail\BudgetAlert;
use App\Models\AiCall;
use App\Models\BudgetLimit;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Aylık AI bütçeleri (A4.3): şirket, departman, kullanıcı. Ay Türkiye saatine göredir. Harcama tek kaynaktan
 * (ai_calls.cost_usd); bu ayın toplamı 1 dk önbellekte, her ai_calls yazımında artırılır.
 * - Uyarı eşiğinde (varsayılan %80) ve dolunca admin(ler)e ayda bir kez Inbox uyarısı (şirket eşiğinde ayrıca e-posta).
 * - hard_stop açık ve bütçe dolmuşsa o kapsamdan yeni soru ve agent çalışması başlamaz; admin muaf; devam eden
 *   çalışma bitirilir. Akış içi AI adımları ayrıca budget_blocks_flow_ai_steps ayarına bağlı (şirket bütçesi).
 */
class Budget
{
    private const CACHE_SECONDS = 60;

    public function __construct(private readonly AppSettings $settings) {}

    public static function month(): CarbonImmutable
    {
        return CarbonImmutable::now('Europe/Istanbul')->startOfMonth();
    }

    /** Şirketin bu ayki toplam harcaması. */
    public function spent(): float
    {
        return $this->spentFor(BudgetLimit::org());
    }

    /** @return array{limit: float|null, spent: float, ratio: float|null} Şirket bütçesi (eski çağıranlar için). */
    public function status(): array
    {
        return $this->statusFor(BudgetLimit::org());
    }

    /** @return array{limit: float|null, spent: float, ratio: float|null} */
    public function statusFor(BudgetLimit $budget): array
    {
        $limit = $budget->monthly_limit_usd !== null && $budget->monthly_limit_usd > 0 ? (float) $budget->monthly_limit_usd : null;
        $spent = $this->spentFor($budget);

        return ['limit' => $limit, 'spent' => $spent, 'ratio' => $limit === null ? null : round($spent / $limit, 4)];
    }

    public function spentFor(BudgetLimit $budget): float
    {
        $micro = Cache::remember(self::cacheKey($budget->scope, $budget->scope_id), self::CACHE_SECONDS, function () use ($budget) {
            $query = AiCall::query()->where('created_at', '>=', self::month()->utc());
            match ($budget->scope) {
                BudgetLimit::DEPARTMENT => $query->where('department_id', $budget->scope_id),
                BudgetLimit::USER => $query->where('user_id', $budget->scope_id),
                default => null,
            };

            return (int) round((float) $query->sum('cost_usd') * 1_000_000);
        });

        return round($micro / 1_000_000, 6);
    }

    /**
     * Yeni soru: kullanıcının (veya oturumdakinin) kapsamındaki hard_stop'lu dolmuş bütçe varsa durdurur. Admin muaf.
     *
     * @throws BudgetExceededException
     */
    public function assertChatAllowed(?User $user = null): void
    {
        $user ??= auth()->user();
        if ($user?->isAdmin()) {
            return;
        }

        foreach ($this->applicable($user?->department_id, $user?->id) as $budget) {
            $status = $this->statusFor($budget);
            if ($budget->hard_stop && $status['limit'] !== null && $status['spent'] >= $status['limit']) {
                throw new BudgetExceededException(match ($budget->scope) {
                    BudgetLimit::DEPARTMENT => 'Departman bütçesi bu ay doldu; yöneticinize başvurun.',
                    BudgetLimit::USER => 'Bu ayki kişisel AI bütçeniz doldu; yöneticinize başvurun.',
                    default => sprintf('Şirketin bu ayki AI bütçesi doldu ($%s / $%s); yöneticinize başvurun.', number_format($status['spent'], 2), number_format($status['limit'], 2)),
                });
            }
        }
    }

    /** Agent çalışması: çalışmayı başlatanın (yoksa agent sahibinin) bütçesi. */
    public function assertAgentAllowed(?User $user): void
    {
        $this->assertChatAllowed($user ?? new User(['role' => User::ROLE_USER]));
    }

    /** Akış içi AI adımı: şirket bütçesi dolduysa ve ayar açıksa (veya şirket bütçesinde hard_stop varsa) adım hata vermeli. */
    public function flowStepBlocked(): bool
    {
        $org = BudgetLimit::org();
        $status = $this->statusFor($org);

        return ($this->settings->blocksFlowAiSteps() || $org->hard_stop) && $status['limit'] !== null && $status['spent'] >= $status['limit'];
    }

    /** Her AI çağrısından sonra: önbellekteki toplamları artırır, eşik/dolma uyarılarını gönderir. */
    public function recorded(AiCall $call): void
    {
        $micro = (int) round((float) $call->cost_usd * 1_000_000);
        foreach ([[BudgetLimit::ORG, null], [BudgetLimit::DEPARTMENT, $call->department_id], [BudgetLimit::USER, $call->user_id]] as [$scope, $id]) {
            if ($scope !== BudgetLimit::ORG && $id === null) {
                continue;
            }
            $key = self::cacheKey($scope, $id);
            if ($micro > 0 && Cache::has($key)) {
                Cache::increment($key, $micro);
            }
        }

        $this->checkAlerts($this->applicable($call->department_id, $call->user_id));
    }

    /** @param  Collection<int, BudgetLimit>  $budgets */
    public function checkAlerts(Collection $budgets): void
    {
        $month = self::month()->format('Y-m');

        foreach ($budgets as $budget) {
            $status = $this->statusFor($budget);
            if ($status['ratio'] === null) {
                continue;
            }

            if ($status['ratio'] >= 1 && $budget->full_sent_month !== $month) {
                $budget->update(['full_sent_month' => $month, 'warn_sent_month' => $month]);
                $this->notifyAdmins($budget, "{$budget->label()} AI bütçesi doldu", sprintf('Bu ay $%s / $%s harcandı. %s', number_format($status['spent'], 2), number_format($status['limit'], 2),
                    $budget->hard_stop ? 'Dolunca durdur açık: bu kapsamdan yeni soru ve agent çalışması başlamıyor (admin muaf).' : 'Dolunca durdur kapalı: kullanım devam ediyor.'));
            } elseif ($budget->warn_at_percent <= $status['ratio'] * 100 && $budget->warn_sent_month !== $month) {
                $budget->update(['warn_sent_month' => $month]);
                $this->notifyAdmins($budget, "{$budget->label()} AI bütçesinin %{$budget->warn_at_percent}'i kullanıldı", sprintf('Bu ay $%s / $%s harcandı (%%%d).', number_format($status['spent'], 2), number_format($status['limit'], 2), (int) round($status['ratio'] * 100)));
                if ($budget->scope === BudgetLimit::ORG) {
                    $this->mailAdmins($status, $month);
                }
            }
        }
    }

    /** @return Collection<int, BudgetLimit> Şirket + departman + kullanıcı bütçeleri (tanımlı olanlar). */
    public function applicable(?int $departmentId, ?int $userId): Collection
    {
        return BudgetLimit::query()
            ->where(fn ($q) => $q->where('scope', BudgetLimit::ORG)
                ->when($departmentId, fn ($q) => $q->orWhere(fn ($q) => $q->where('scope', BudgetLimit::DEPARTMENT)->where('scope_id', $departmentId)))
                ->when($userId, fn ($q) => $q->orWhere(fn ($q) => $q->where('scope', BudgetLimit::USER)->where('scope_id', $userId))))
            ->get();
    }

    public static function forget(): void
    {
        foreach (BudgetLimit::query()->get() as $budget) {
            Cache::forget(self::cacheKey($budget->scope, $budget->scope_id));
        }
        Cache::forget(self::cacheKey(BudgetLimit::ORG, null));
    }

    private static function cacheKey(string $scope, ?int $id): string
    {
        return 'budget-spent:'.self::month()->format('Y-m').":{$scope}:".($id ?? '-');
    }

    /** Bütçe uyarısı admin(ler)e (varsayım: "departman yöneticisi" kavramı yok). */
    private function notifyAdmins(BudgetLimit $budget, string $title, string $body): void
    {
        foreach (User::query()->where('role', User::ROLE_ADMIN)->where('is_active', true)->get() as $admin) {
            InboxItem::create([
                'user_id' => $admin->id, 'type' => InboxItem::ALERT, 'title' => $title, 'body' => $body,
                'subject_type' => Inbox::SUBJECT_BUDGET, 'subject_id' => $budget->id, 'source_label' => 'Yönetişim · Bütçe', 'icon' => 'BÜ',
            ]);
        }
    }

    /** @param  array{limit: float|null, spent: float, ratio: float|null}  $status */
    private function mailAdmins(array $status, string $month): void
    {
        $recipients = config('ai.admin_emails');
        if ($recipients === []) {
            Log::warning('AI bütçesinin uyarı eşiği aşıldı ama FORTIS_ADMIN_EMAILS boş; uyarı e-postası gönderilmedi.', $status);

            return;
        }

        Mail::to($recipients)->send(new BudgetAlert($status['spent'], (float) $status['limit'], $month));
    }
}
