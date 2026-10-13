<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Akış editöründeki sohbetin önerdiği değişiklik. FlowValidator'dan geçmiştir ama sürüm değildir; kullanıcı
 * Uygula derse yeni taslak sürüm (source = chat) olur, Geri al derse reddedilir.
 */
#[Fillable(['flow_id', 'conversation_id', 'base_version', 'title', 'summary', 'yaml', 'validation', 'status', 'flow_version_id'])]
class FlowProposal extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'validation' => 'array',
        ];
    }

    /** @return BelongsTo<Flow, $this> */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class)->withTrashed();
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<FlowVersion, $this> */
    public function flowVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class);
    }
}
