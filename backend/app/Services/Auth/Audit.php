<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Denetim kaydına yazar. Aktör verilmezse oturumdaki kullanıcı, o da yoksa sistem kabul edilir.
 */
class Audit
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        string $result = AuditLog::RESULT_ALLOWED,
        array $meta = [],
        ?string $system = null,
        ?float $costUsd = null,
        ?User $user = null,
        ?string $actorType = null,
        ?string $actorLabel = null,
    ): AuditLog {
        $user ??= auth()->user();

        return AuditLog::create([
            'user_id' => $user?->id,
            'actor_type' => $actorType ?? ($user === null ? AuditLog::ACTOR_SYSTEM : AuditLog::ACTOR_USER),
            'actor_label' => $actorLabel ?? $user?->name,
            'action' => $action,
            'subject_type' => $subject === null ? null : $subject->getMorphClass(),
            'subject_id' => $subject?->getKey() === null ? null : (string) $subject->getKey(),
            'system' => $system,
            'cost_usd' => $costUsd,
            'result' => $result,
            'meta' => $meta === [] ? null : $meta,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
