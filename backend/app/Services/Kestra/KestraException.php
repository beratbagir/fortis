<?php

namespace App\Services\Kestra;

use Illuminate\Http\Client\Response;
use RuntimeException;

class KestraException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?array $body = null,
    ) {
        parent::__construct($message, $status ?? 0);
    }

    public static function fromResponse(Response $response, string $action): self
    {
        $body = $response->json();
        $body = is_array($body) ? $body : null;

        // 2.0+: RFC 9457 problem details (title/detail). 1.x: message alanı.
        $detail = $body['detail'] ?? $body['message'] ?? $body['title'] ?? null;

        if ($detail === null) {
            $detail = $response->status() === 401
                ? 'Kimlik doğrulama başarısız (KESTRA_USERNAME / KESTRA_PASSWORD).'
                : mb_substr(trim($response->body()), 0, 500);
        }

        return new self(
            sprintf('Kestra %s başarısız (HTTP %d): %s', $action, $response->status(), $detail),
            $response->status(),
            $body,
        );
    }
}
