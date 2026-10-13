<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Yönetişim uyarısı ("Dikkat gerekenler"). Türler: bağlantı uzun süredir dikkat istiyor, bütçe eşiği/doldu, agent
 * sınır aştı, agent üst üste başarısız, düşük değerlendirme puanı, izinsiz araç denemesi, 24 saatten uzun bekleyen onay.
 */
#[Fillable(['type', 'severity', 'subject_type', 'subject_id', 'message', 'meta', 'resolved_at', 'resolved_by'])]
class GovernanceAlert extends Model
{
    public const CONNECTOR_ATTENTION = 'connector_attention';

    public const BUDGET_WARNING = 'budget_warning';

    public const BUDGET_EXHAUSTED = 'budget_exhausted';

    public const AGENT_LIMIT = 'agent_limit';

    public const AGENT_FAILING = 'agent_failing';

    public const AGENT_LOW_SCORE = 'agent_low_score';

    public const TOOL_BLOCKED = 'tool_blocked';

    public const APPROVAL_OVERDUE = 'approval_overdue';

    public const INFO = 'info';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['meta' => 'array', 'resolved_at' => 'datetime'];
    }

    /** @param  Builder<GovernanceAlert>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
