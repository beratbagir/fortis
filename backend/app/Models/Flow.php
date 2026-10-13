<?php

namespace App\Models;

use App\Models\Concerns\HasOwner;
use Database\Factories\FlowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Chat'ten çıkan bir Kestra akışının Laravel'deki kaydı. Çalışma anının asıl kaynağı Kestra'dır.
 *
 * Durumlar: draft (hiç yüklenmedi), active (Kestra'da, tetikleyicileri çalışır), inactive (Kestra'da, pasif).
 * Silinen akış Kestra'dan kaldırılır, kaydı (sürümler, çalışmalar, eski kartlar için) yumuşak silinir.
 */
#[Fillable(['owner_id', 'department_id', 'namespace', 'flow_id', 'title', 'summary', 'status', 'conversation_id', 'current_version_id'])]
class Flow extends Model
{
    /** @use HasFactory<FlowFactory> */
    use HasFactory, HasOwner, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'summary' => 'array',
        ];
    }

    public function ownerColumn(): string
    {
        return 'owner_id';
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Kestra'ya yüklenmiş mi (aktif veya pasif). */
    public function isInKestra(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE], true);
    }

    /** @return HasMany<Execution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(Execution::class);
    }

    /** @return HasMany<FlowVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FlowVersion::class)->orderBy('number');
    }

    /** @return BelongsTo<FlowVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class, 'current_version_id');
    }

    /** @return HasMany<FlowProposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(FlowProposal::class);
    }

    /** Son (taslak olabilir) sürüm. */
    public function latestVersion(): ?FlowVersion
    {
        return $this->versions()->reorder('number', 'desc')->first();
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
