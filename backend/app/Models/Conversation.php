<?php

namespace App\Models;

use App\Models\Concerns\HasOwner;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'title', 'title_generated_at', 'context_flow_id', 'model', 'disabled_sources', 'kind'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, HasOwner;

    public const KIND_CHAT = 'chat';

    /** Model seçimi "Auto": her tur için ModelRouter seçer (veritabanında boş). */
    public const MODEL_AUTO = 'auto';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'disabled_sources' => 'array',
            'title_generated_at' => 'datetime',
        ];
    }

    /**
     * Kullanıcının bu sohbette kapattığı kaynaklar (ibs, apm, successfactors...).
     *
     * @return list<string>
     */
    public function disabledSources(): array
    {
        return array_values($this->disabled_sources ?? []);
    }

    /** Akış editöründeki sohbet: öneriler sürüm olarak kaydedilmez, tuvalde fark olarak gösterilir. */
    public const KIND_EDITOR = 'editor';

    /** Agent çalışmasının konuşması (sohbet listesinde görünmez). */
    public const KIND_AGENT = 'agent';

    /** Architect sohbeti: agent kurar (agents.conversation_id). Sohbet listesinde görünmez. */
    public const KIND_ARCHITECT = 'architect';

    public function isArchitect(): bool
    {
        return $this->kind === self::KIND_ARCHITECT;
    }

    public function isAgent(): bool
    {
        return $this->kind === self::KIND_AGENT;
    }

    public function isChat(): bool
    {
        return $this->kind === self::KIND_CHAT || $this->kind === null;
    }

    public function isEditor(): bool
    {
        return $this->kind === self::KIND_EDITOR;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    /**
     * "Bu akışı chat'te değiştir" ile açılan sohbetin konusu olan akış.
     *
     * @return BelongsTo<Flow, $this>
     */
    public function contextFlow(): BelongsTo
    {
        return $this->belongsTo(Flow::class, 'context_flow_id')->withTrashed();
    }

    /** @return HasMany<AiCall, $this> */
    public function aiCalls(): HasMany
    {
        return $this->hasMany(AiCall::class);
    }
}
