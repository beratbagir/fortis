<?php

namespace App\Models;

use App\Models\Concerns\HasOwner;
use App\Services\Usage\Budget;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Claude API'ye yapılan her çağrının kaydı: model, token, maliyet ve süre.
 */
#[Fillable([
    'user_id', 'department_id', 'conversation_id', 'message_id', 'flow_id', 'execution_id', 'agent_run_id', 'model', 'purpose', 'source',
    'input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens',
    'cost_usd', 'duration_ms', 'stop_reason', 'request_id', 'error', 'warning',
])]
class AiCall extends Model
{
    use HasOwner;

    public const SOURCE_CHAT = 'chat';

    public const SOURCE_FLOW_STEP = 'flow_step';

    protected static function booted(): void
    {
        static::created(fn () => app(Budget::class)->checkAlert());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_usd' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Flow, $this> */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class)->withTrashed();
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
