<?php

namespace App\Models;

use App\Agents\Inbox;
use App\Services\Kestra\KestraClient;
use Database\Factories\ExecutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chat'ten başlatılan bir Kestra çalışmasının özeti. Çalışma anının asıl kaynağı Kestra'dır;
 * `snapshot` Kestra'dan son okunan adımları ve logları tutar.
 */
#[Fillable([
    'flow_id', 'flow_version_id', 'conversation_id', 'kestra_execution_id', 'state', 'inputs',
    'started_at', 'ended_at', 'snapshot', 'started_by', 'triggered_by_user_id', 'is_test',
])]
class Execution extends Model
{
    /** @use HasFactory<ExecutionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // Onay adımında duran akış çalışması Inbox'a onay olarak düşer; durumu değişince öğe kapanır.
        static::saved(function (Execution $execution) {
            if ($execution->is_test || str_starts_with((string) $execution->kestra_execution_id, 'demo-') && ! app()->environment('local')) {
                return;
            }

            $inbox = app(Inbox::class);
            if ($execution->state === 'PAUSED' && ($execution->wasRecentlyCreated || $execution->wasChanged('state'))) {
                $inbox->pausedExecution($execution);
            } elseif ($execution->wasChanged('state') && $execution->getOriginal('state') === 'PAUSED') {
                $inbox->close(Inbox::SUBJECT_EXECUTION, $execution->id);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'inputs' => 'array',
            'snapshot' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'is_test' => 'boolean',
        ];
    }

    /** @return BelongsTo<Flow, $this> */
    public function flow(): BelongsTo
    {
        // Silinmiş akışın sürümleri ve çalışmaları eski kartlarda görünmeye devam eder.
        return $this->belongsTo(Flow::class)->withTrashed();
    }

    /** @return BelongsTo<FlowVersion, $this> */
    public function flowVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class);
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

    public function isFinished(): bool
    {
        return KestraClient::isTerminal($this->state);
    }
}
