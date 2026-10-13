<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir artifact'ın tek bir sürümü (tam içerik).
 */
#[Fillable(['artifact_id', 'number', 'title', 'content'])]
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
        return ['number' => 'integer'];
    }
}
