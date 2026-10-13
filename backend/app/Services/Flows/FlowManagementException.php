<?php

namespace App\Services\Flows;

use RuntimeException;

/**
 * Akış yönetimi işlemi (aktif/pasif yapma, silme) başarısız oldu. Mesajı kullanıcıya gösterilebilir.
 */
class FlowManagementException extends RuntimeException {}
