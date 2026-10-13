<?php

namespace App\Services\Flows;

use RuntimeException;

/**
 * Akış yüklenemedi. `errors` kullanıcıya gösterilir.
 */
class FlowUploadException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
