<?php

namespace App\Models;

use App\Models\Concerns\HasOwner;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Sohbette üretilen bir arayüz, diyagram veya belge. id: art_<ulid>. İçerik sürümlerde (artifact_versions).
 */
#[Fillable(['user_id', 'conversation_id', 'message_id', 'title', 'description', 'kind', 'latest_version'])]
class Artifact extends Model
{
    use HasOwner;

    /** create_artifact türleri (veri içermeyen tasarım ve belgeler). */
    public const KINDS = ['html', 'svg', 'mermaid', 'markdown'];

    /** create_page: şirket verisiyle çalışan HTML sayfa (pano, rapor); veri sunucudan enjekte edilir. */
    public const PAGE = 'page';

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (Artifact $artifact) {
            $artifact->id ??= 'art_'.Str::lower((string) Str::ulid());
        });
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return HasMany<ArtifactVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ArtifactVersion::class)->orderBy('number');
    }
}
