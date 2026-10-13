<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Değiştirilemez denetim kaydı: kim, ne zaman, hangi sistemde ne yaptı ve sonuç. Governance ekranı buna dayanır.
 * Kayıt güncellenemez ve silinemez; denenirse exception fırlar.
 */
#[Fillable(['user_id', 'actor_type', 'actor_label', 'action', 'subject_type', 'subject_id', 'system', 'cost_usd', 'result', 'meta', 'ip'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTOR_USER = 'user';

    public const ACTOR_AGENT = 'agent';

    public const ACTOR_SYSTEM = 'system';

    public const RESULT_ALLOWED = 'allowed';

    public const RESULT_BLOCKED = 'blocked';

    public const RESULT_AWAITING_APPROVAL = 'awaiting_approval';

    public const RESULT_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'cost_usd' => 'decimal:6',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Denetim kaydı değiştirilemez.'));
        static::deleting(fn () => throw new LogicException('Denetim kaydı silinemez.'));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
