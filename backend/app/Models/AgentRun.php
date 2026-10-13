<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Bir agent çalışması. Konuşması conversation_id'deki sohbettedir (ClaudeChat aynı döngüyle çalışır).
 */
#[Fillable(['agent_id', 'agent_version', 'trigger_id', 'source', 'attempts', 'triggered_by_user_id', 'conversation_id', 'status', 'input', 'summary', 'output', 'tool_calls_count', 'cost_usd', 'error', 'notes', 'started_at', 'finished_at', 'is_test'])]
class AgentRun extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const WAITING_APPROVAL = 'waiting_approval';

    public const WAITING_INPUT = 'waiting_input';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notes' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'is_test' => 'boolean',
            'cost_usd' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class)->withTrashed();
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    /** @return HasMany<AgentAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(AgentAction::class);
    }

    /** Çalışmanın sahibi: tetikleyen kullanıcı, yoksa agent sahibi. */
    public function ownerUser(): ?User
    {
        return $this->triggeredBy ?? $this->agent?->owner;
    }

    public function addNote(string $note): void
    {
        $this->update(['notes' => [...($this->notes ?? []), ['at' => now()->toIso8601String(), 'text' => $note]]]);
    }

    /** @return HasMany<AgentFeedback, $this> */
    public function feedback(): HasMany
    {
        return $this->hasMany(AgentFeedback::class);
    }

    /** @return BelongsTo<AgentTrigger, $this> */
    public function trigger(): BelongsTo
    {
        return $this->belongsTo(AgentTrigger::class, 'trigger_id');
    }

    /** Çalışma nereden başladı: chat, manual, schedule, event. */
    public function origin(): string
    {
        return $this->source ?? $this->trigger?->type ?? AgentTrigger::MANUAL;
    }

    /** @return HasOne<AgentRunScore, $this> */
    public function score(): HasOne
    {
        return $this->hasOne(AgentRunScore::class)->latestOfMany('id');
    }
}
