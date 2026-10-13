<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir artifact'ın tek bir sürümü (tam içerik). Sayfa türünde ayrıca kaynak sorgular, enjekte edilen maskeli veri ve
 * verinin zamanı.
 */
#[Fillable(['artifact_id', 'number', 'title', 'content', 'sources', 'data', 'data_at', 'warning'])]
class ArtifactVersion extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<Artifact, $this> */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['number' => 'integer', 'sources' => 'array', 'data' => 'array', 'data_at' => 'datetime'];
    }
}
