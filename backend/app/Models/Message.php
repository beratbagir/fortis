<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sohbetteki bir tur.
 *
 * `content` Claude API'nin içerik bloklarını (text, thinking, tool_use, tool_result...) API'nin kendi
 * biçiminde, değiştirilmeden tutar ve sonraki isteklerde aynen geri gönderilir. `text` ise ekranda
 * gösterilen düz metindir. `meta` Claude'a gönderilmeyen ekran verisidir (ör. `flow_cards`).
 */
#[Fillable(['role', 'content', 'text', 'model', 'stop_reason', 'status', 'meta'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    /** Claude ask_user ile soru sordu; kullanıcının cevabı bekleniyor. */
    public const STATUS_AWAITING_INPUT = 'awaiting_input';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'meta' => 'array',
        ];
    }

    public function isAwaitingInput(): bool
    {
        return $this->status === self::STATUS_AWAITING_INPUT;
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
