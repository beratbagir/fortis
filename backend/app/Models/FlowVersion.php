<?php

namespace App\Models;

use Database\Factories\FlowVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir akışın önerilen YAML sürümü. Yüklendiğinde Kestra'daki revision numarası, zamanı ve yükleyen kaydedilir.
 */
#[Fillable([
    'flow_id', 'number', 'title', 'summary', 'yaml', 'yaml_hash', 'secrets_used', 'card', 'validation',
    'message_id', 'kestra_revision', 'uploaded_at', 'uploaded_by', 'source', 'created_by',
])]
class FlowVersion extends Model
{
    public const SOURCE_CHAT = 'chat';

    public const SOURCE_CANVAS = 'canvas';

    public const SOURCE_YAML = 'yaml';

    public const SOURCE_RESTORE = 'restore';

    /** @use HasFactory<FlowVersionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'secrets_used' => 'array',
            'card' => 'array',
            'validation' => 'array',
            'uploaded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Flow, $this> */
    public function flow(): BelongsTo
    {
        // Silinmiş akışın sürümleri ve çalışmaları eski kartlarda görünmeye devam eder.
        return $this->belongsTo(Flow::class)->withTrashed();
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isUploaded(): bool
    {
        return $this->uploaded_at !== null;
    }
}
