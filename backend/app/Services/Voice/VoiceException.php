<?php

namespace App\Services\Voice;

use RuntimeException;

/**
 * Sesli komut hatası. Mesaj kullanıcıya gösterilir; status HTTP yanıt kodudur.
 */
class VoiceException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function unavailable(): self
    {
        return new self('Sesli komut şu an kullanılamıyor, yazarak devam edebilirsin.', 503);
    }
}
