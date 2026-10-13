<?php

namespace App\Models;

use Database\Factories\FeatureRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * İstek listesi: kullanıcıların istediği ama katalogda olmadığı için yapılamayan şeyler.
 * Katalog genişletme kararları için toplanır.
 */
#[Fillable(['conversation_id', 'request', 'missing_capability', 'reason', 'status'])]
class FeatureRequest extends Model
{
    /** @use HasFactory<FeatureRequestFactory> */
    use HasFactory;

    public const STATUSES = ['open', 'planned', 'done', 'dismissed'];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
