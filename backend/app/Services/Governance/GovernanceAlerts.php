<?php

namespace App\Services\Governance;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentRunScore;
use App\Models\AuditLog;
use App\Models\BudgetLimit;
use App\Models\ConnectorCheck;
use App\Models\GovernanceAlert;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\Auth\Audit;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Connectors\Connectors;
use App\Services\Usage\Budget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Dikkat gerekenler" (A4.2). `governance:scan` (5 dk) koşulları tarar; aynı konu (tür + hedef) için açık uyarı varsa
 * yenisi açılmaz. Koşulu ortadan kalkan uyarılar kendiliğinden kapanır (bağlantı düzeldi, onay verildi, puan yükseldi…);
 * olay türündeki uyarılar (sınır aşımı, izinsiz araç) admin "Çözüldü" diyene kadar açık kalır.
 */
class GovernanceAlerts
{
    public const CONNECTOR_ATTENTION_MINUTES = 30;

    public const FAILING_STREAK = 3;

    public const LOW_SCORE = 60;

    public const APPROVAL_OVERDUE_HOURS = 24;

    public function __construct(
        private readonly Connectors $connectors,
        private readonly ConnectorRegistry $registry,
        private readonly Budget $budget,
    ) {}

    /** Açık uyarı yoksa açar, varsa mesajını günceller. */
    public function raise(string $type, string $severity, ?string $subjectType, int|string|null $subjectId, string $message, array $meta = []): GovernanceAlert
    {
        $open = $this->open($type, $subjectType, $subjectId);
        if ($open !== null) {
            $open->update(['message' => mb_substr($message, 0, 500), 'severity' => $severity, 'meta' => $meta ?: $open->meta]);

            return $open;
        }

        return GovernanceAlert::query()->create([
            'type' => $type, 'severity' => $severity, 'subject_type' => $subjectType, 'subject_id' => $subjectId === null ? null : (string) $subjectId,
            'message' => mb_substr($message, 0, 500), 'meta' => $meta ?: null,
        ]);
    }

    public function resolve(GovernanceAlert $alert, ?User $by): void
    {
        if ($alert->resolved_at !== null) {
            return;
        }
        $alert->update(['resolved_at' => now(), 'resolved_by' => $by?->id]);
        if ($by !== null) {
            Audit::record('governance.alert.resolve', $alert, meta: ['type' => $alert->type, 'subject' => "{$alert->subject_type}:{$alert->subject_id}"], user: $by);
        }
    }

    /** @return array<string, int> Tür başına açık uyarı sayısı (tarama sonrası). */
    public function scan(): array
    {
        $this->connectorAttention();
        $this->budgetAlerts();
        $this->agentLimits();
        $this->failingAgents();
        $this->lowScores();
        $this->blockedTools();
        $this->overdueApprovals();

        return GovernanceAlert::query()->open()->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type')->map(fn ($n) => (int) $n)->all();
    }

    private function connectorAttention(): void
    {
        $lastOk = ConnectorCheck::query()->where('ok', true)->selectRaw('connector, max(checked_at) as at')->groupBy('connector')->pluck('at', 'connector');
        $limit = now()->subMinutes(self::CONNECTOR_ATTENTION_MINUTES);

        foreach ($this->connectors->all() as $c) {
            $key = $c['key'];
            // Canlı kontrol edilebilen bağlantı dikkat istiyor ve 30 dakikadır (ya da hiç) başarılı kontrolü yok.
            $lastSuccess = $lastOk[$key] ?? null;
            $stale = $c['status'] === Connectors::ATTENTION && $this->connectors->isCheckable($key)
                && ($lastSuccess !== null ? Carbon::parse($lastSuccess)->lt($limit) : $this->firstCheckBefore($key, $limit));

            if ($stale) {
                $this->raise(GovernanceAlert::CONNECTOR_ATTENTION, GovernanceAlert::WARNING, 'connector', $key,
                    "{$c['label']} bağlantısı ".self::CONNECTOR_ATTENTION_MINUTES.' dakikadan uzun süredir dikkat istiyor: '.rtrim($c['detail'] ?? 'son kontrol başarısız', '.').'.');
            } else {
                $this->autoResolve(GovernanceAlert::CONNECTOR_ATTENTION, 'connector', $key);
            }
        }
    }

    private function firstCheckBefore(string $key, mixed $limit): bool
    {
        return ConnectorCheck::query()->where('connector', $key)->where('checked_at', '<', $limit)->exists();
    }

    /** Her tanımlı bütçe (şirket, departman, kullanıcı) için: uyarı eşiği aşıldı → uyarı; doldu → kritik. */
    private function budgetAlerts(): void
    {
        $month = Budget::month()->format('Y-m');

        foreach (BudgetLimit::query()->get() as $budget) {
            $status = $this->budget->statusFor($budget);
            $subject = "{$budget->id}:{$month}";
            $ratio = $status['ratio'];

            if ($ratio !== null && $ratio >= 1) {
                $this->raise(GovernanceAlert::BUDGET_EXHAUSTED, GovernanceAlert::CRITICAL, 'budget', $subject, sprintf('%s AI bütçesi bu ay doldu: $%s / $%s%s', $budget->label(), number_format($status['spent'], 2), number_format((float) $status['limit'], 2), $budget->hard_stop ? ' (yeni sorular duruyor).' : '.'));
                $this->autoResolve(GovernanceAlert::BUDGET_WARNING, 'budget', $subject);
            } elseif ($ratio !== null && $ratio * 100 >= $budget->warn_at_percent) {
                $this->raise(GovernanceAlert::BUDGET_WARNING, GovernanceAlert::WARNING, 'budget', $subject, sprintf('%s AI bütçesinin %%%d\'i kullanıldı: $%s / $%s.', $budget->label(), (int) round($ratio * 100), number_format($status['spent'], 2), number_format((float) $status['limit'], 2)));
            } else {
                $this->autoResolve(GovernanceAlert::BUDGET_WARNING, 'budget', $subject);
                $this->autoResolve(GovernanceAlert::BUDGET_EXHAUSTED, 'budget', $subject);
            }
        }
    }

    /** Son 24 saatte sınır aşan çalışmalar (test çalışmaları dahil). Olay uyarısı: admin kapatır. */
    private function agentLimits(): void
    {
        AuditLog::query()->where('action', 'agent.run.limit')->where('created_at', '>=', now()->subDay())->get()
            ->each(function (AuditLog $log) {
                $run = AgentRun::query()->with('agent')->find((int) $log->subject_id);
                if ($run !== null && $this->open(GovernanceAlert::AGENT_LIMIT, 'agent_run', $run->id) === null && ! $this->wasResolved(GovernanceAlert::AGENT_LIMIT, 'agent_run', $run->id)) {
                    $this->agentLimit($run, (string) ($log->meta['limit'] ?? ''), (string) ($log->meta['message'] ?? ''));
                }
            });
    }

    /** Sınır aşımı anında da çağrılır (ClaudeChat), taramayı beklemeden. */
    public function agentLimit(AgentRun $run, string $limit, string $message): GovernanceAlert
    {
        return $this->raise(GovernanceAlert::AGENT_LIMIT, GovernanceAlert::CRITICAL, 'agent_run', $run->id,
            "{$run->agent?->name} çalışması #{$run->id}".($run->is_test ? ' (test)' : '').": {$message}", ['agent_id' => $run->agent_id, 'limit' => $limit]);
    }

    private function failingAgents(): void
    {
        foreach ($this->activeAgents() as $agent) {
            $last = $agent->runs()->where('is_test', false)->whereIn('status', [AgentRun::SUCCEEDED, AgentRun::FAILED])->latest('id')->limit(self::FAILING_STREAK)->pluck('status');
            if ($last->count() === self::FAILING_STREAK && $last->every(fn (string $s) => $s === AgentRun::FAILED)) {
                $this->raise(GovernanceAlert::AGENT_FAILING, GovernanceAlert::WARNING, 'agent', $agent->id, "{$agent->name} üst üste ".self::FAILING_STREAK.' kez başarısız oldu.');
            } else {
                $this->autoResolve(GovernanceAlert::AGENT_FAILING, 'agent', $agent->id);
            }
        }
    }

    private function lowScores(): void
    {
        foreach ($this->activeAgents() as $agent) {
            $scores = AgentRunScore::query()->whereHas('run', fn ($q) => $q->where('agent_id', $agent->id)->where('is_test', false))->latest('id')->limit(10)->pluck('score');
            $avg = $scores->isEmpty() ? null : $scores->avg();
            if ($avg !== null && $avg < self::LOW_SCORE) {
                $this->raise(GovernanceAlert::AGENT_LOW_SCORE, GovernanceAlert::WARNING, 'agent', $agent->id, sprintf('%s: son %d çalışmanın ortalama değerlendirme puanı %d (eşik %d).', $agent->name, $scores->count(), (int) round($avg), self::LOW_SCORE));
            } else {
                $this->autoResolve(GovernanceAlert::AGENT_LOW_SCORE, 'agent', $agent->id);
            }
        }
    }

    /** Son 24 saatteki izinsiz araç denemeleri, agent başına. Olay uyarısı: admin kapatır. */
    private function blockedTools(): void
    {
        AuditLog::query()->where('action', 'agent.tool_blocked')->where('created_at', '>=', now()->subDay())->get()
            ->groupBy(fn (AuditLog $log) => AgentRun::query()->whereKey((int) $log->subject_id)->value('agent_id'))
            ->each(function (Collection $logs, $agentId) {
                $agent = $agentId ? Agent::query()->find($agentId) : null;
                if ($agent === null) {
                    return;
                }
                $tools = $logs->map(fn (AuditLog $l) => $l->meta['tool'] ?? '?')->unique()->implode(', ');
                $this->raise(GovernanceAlert::TOOL_BLOCKED, GovernanceAlert::WARNING, 'agent', $agent->id, "{$agent->name} izinsiz araç denedi ({$tools}); son 24 saatte {$logs->count()} kez. Talimatı gözden geçirin.", ['count' => $logs->count()]);
            });
    }

    private function overdueApprovals(): void
    {
        $open = InboxItem::query()->where('type', InboxItem::APPROVAL)->whereIn('status', [InboxItem::OPEN, InboxItem::SNOOZED])->get();

        foreach ($open->filter(fn (InboxItem $i) => $i->created_at < now()->subHours(self::APPROVAL_OVERDUE_HOURS)) as $item) {
            $hours = (int) $item->created_at->diffInHours(now(), true);
            $this->raise(GovernanceAlert::APPROVAL_OVERDUE, GovernanceAlert::WARNING, 'inbox_item', $item->id, "\"{$item->title}\" onayı {$hours} saattir bekliyor ({$item->source_label}).", ['user_id' => $item->user_id]);
        }

        GovernanceAlert::query()->open()->where('type', GovernanceAlert::APPROVAL_OVERDUE)->get()
            ->reject(fn (GovernanceAlert $a) => $open->contains('id', (int) $a->subject_id))
            ->each(fn (GovernanceAlert $a) => $this->resolve($a, null));
    }

    /** @return Collection<int, Agent> */
    private function activeAgents(): Collection
    {
        return Agent::query()->whereIn('status', [Agent::STATUS_PILOT, Agent::STATUS_LIVE, Agent::STATUS_PAUSED])->get();
    }

    private function open(string $type, ?string $subjectType, int|string|null $subjectId): ?GovernanceAlert
    {
        return GovernanceAlert::query()->open()->where('type', $type)->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId === null ? null : (string) $subjectId)->first();
    }

    private function wasResolved(string $type, string $subjectType, int|string $subjectId): bool
    {
        return GovernanceAlert::query()->where('type', $type)->where('subject_type', $subjectType)->where('subject_id', (string) $subjectId)->whereNotNull('resolved_at')->exists();
    }

    private function autoResolve(string $type, string $subjectType, int|string $subjectId): void
    {
        if (($alert = $this->open($type, $subjectType, $subjectId)) !== null) {
            $this->resolve($alert, null);
        }
    }

    /** Uyarının "Git" bağlantısı (arayüz adresi). */
    public static function link(GovernanceAlert $alert): ?string
    {
        return match ($alert->subject_type) {
            'connector' => "#/connectors/{$alert->subject_id}",
            'agent' => "#/agents/{$alert->subject_id}",
            'agent_run' => ($agentId = $alert->meta['agent_id'] ?? AgentRun::query()->whereKey((int) $alert->subject_id)->value('agent_id')) ? "#/agents/{$agentId}/runs/{$alert->subject_id}" : null,
            'inbox_item' => null, // onay sahibinin Inbox'ında; mesaj kimin onayı olduğunu söyler
            'budget' => '#/governance/usage',
            default => null,
        };
    }

    public static function severityRank(string $severity): int
    {
        return match ($severity) {
            GovernanceAlert::CRITICAL => 0,
            GovernanceAlert::WARNING => 1,
            default => 2,
        };
    }
}
