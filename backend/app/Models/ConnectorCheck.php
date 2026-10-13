<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Bir bağlantı kontrolünün sonucu (connectors:check).
 */
#[Fillable(['connector', 'ok', 'error', 'duration_ms', 'checked_at'])]
class ConnectorCheck extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }
}
