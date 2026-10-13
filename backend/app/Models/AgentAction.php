<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agent'ın dışarıya yazan bir aksiyonu (e-posta, Teams, rapor gönderimi). Onay gerekiyorsa Inbox'ta onaylanınca,
 * gerekmiyorsa hemen bir kez çalışır. Test çalışmalarında hiç çalışmaz (preview).
 */
#[Fillable(['agent_run_id', 'type', 'payload', 'reason', 'status', 'requires_approval', 'decided_by', 'decided_at', 'executed_at', 'result'])]
class AgentAction extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const EXECUTED = 'executed';

    public const FAILED = 'failed';

    /** Test çalışmasında: sadece önizlendi, gönderilmedi. */
    public const PREVIEW = 'preview';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'requires_approval' => 'boolean',
            'decided_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }
}
