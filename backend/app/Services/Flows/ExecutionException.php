<?php

namespace App\Services\Flows;

use RuntimeException;

/**
 * Çalıştırma, durum okuma veya devam ettirme başarısız oldu. Mesajı kullanıcıya gösterilebilir.
 */
class ExecutionException extends RuntimeException {}
