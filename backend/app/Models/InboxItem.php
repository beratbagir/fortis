<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inbox öğesi: kullanıcıdan beklenen (onay, soru) veya kullanıcıya bildirilen (güncelleme, uyarı) şey.
 * Ertelenen öğe süresi dolunca yeniden açık sayılır.
 */
#[Fillable(['user_id', 'type', 'title', 'body', 'subject_type', 'subject_id', 'source_label', 'icon', 'status', 'snoozed_until', 'read_at'])]
class InboxItem extends Model
{
    public const APPROVAL = 'approval';

    public const QUESTION = 'question';

    public const UPDATE = 'update';

    public const ALERT = 'alert';

    public const MENTION = 'mention';

    public const SHARED = 'shared';

    /** Kullanıcıdan aksiyon bekleyen türler (rozet sayısı). */
    public const ACTION_TYPES = [self::APPROVAL, self::QUESTION];

    public const OPEN = 'open';

    public const DONE = 'done';

    public const SNOOZED = 'snoozed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['snoozed_until' => 'datetime', 'read_at' => 'datetime'];
    }

    /** Açık öğeler: açık veya ertelenme süresi dolmuş. */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('status', self::OPEN)->orWhere(fn (Builder $s) => $s->where('status', self::SNOOZED)->where('snoozed_until', '<=', now())));
    }

    public function isActionable(): bool
    {
        return in_array($this->type, self::ACTION_TYPES, true) && ($this->status === self::OPEN || ($this->status === self::SNOOZED && $this->snoozed_until?->isPast()));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
