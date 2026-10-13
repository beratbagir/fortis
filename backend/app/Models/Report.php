<?php

namespace App\Models;

use App\Models\Concerns\HasOwner;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Laravel'in ürettiği bir rapor dosyası (Excel, CSV, PDF). id: rpt_<ulid>.
 */
#[Fillable([
    'user_id', 'conversation_id', 'message_id', 'flow_id', 'execution_id', 'data_source', 'params', 'format', 'title', 'columns',
    'sort_by', 'sort_dir', 'group_by', 'row_count', 'aggregates', 'file_path', 'file_size', 'status', 'error', 'expires_at',
])]
class Report extends Model
{
    use HasOwner;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (Report $report) {
            $report->id ??= 'rpt_'.Str::lower((string) Str::ulid());
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'params' => 'array',
            'columns' => 'array',
            'aggregates' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return HasMany<ReportDownload, $this> */
    public function downloads(): HasMany
    {
        return $this->hasMany(ReportDownload::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY && $this->file_path !== null;
    }

    /** İndirilen dosyanın adı: başlıktan, Türkçe karakterler korunarak. */
    public function downloadName(): string
    {
        $name = trim((string) preg_replace('/[^\pL\pN _-]+/u', '', $this->title)) ?: 'rapor';

        return mb_substr($name, 0, 80).'.'.$this->format;
    }
}
