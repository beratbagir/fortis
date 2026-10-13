<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir bağlantının şirket genelindeki durumu (açık/kapalı) ve kapsam etiketleri. Tanımı config/connectors.php'de;
 * kimlik bilgisi hiçbir zaman burada değil, sadece .env'de.
 */
#[Fillable(['key', 'enabled', 'scopes', 'disabled_reason', 'disabled_by', 'disabled_at'])]
class Connector extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'scopes' => 'array', 'disabled_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function disabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }
}
