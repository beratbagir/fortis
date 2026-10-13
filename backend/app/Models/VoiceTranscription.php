<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Bir sesli komutun ölçümü (süre, işlem zamanı, karakter sayısı, düzeltildi mi). Metin ve ses saklanmaz.
 */
#[Fillable(['duration_s', 'processing_ms', 'char_count', 'was_edited'])]
class VoiceTranscription extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_s' => 'float',
            'processing_ms' => 'integer',
            'char_count' => 'integer',
            'was_edited' => 'boolean',
        ];
    }
}
