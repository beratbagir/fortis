<?php

namespace App\Services\Claude;

use RuntimeException;

/**
 * Claude çağrısı başarısız oldu. Mesajı kullanıcıya gösterilebilir.
 */
class ClaudeException extends RuntimeException {}
