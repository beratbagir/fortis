<?php

namespace App\Services\Flows;

use RuntimeException;

/**
 * Tuval, YAML veya chat düzenlemesi kaydedilemedi. Hatalar mümkünse bir bloğa (task_id) bağlıdır;
 * tuvalde ilgili blok kırmızı çerçeveyle gösterilir.
 */
class FlowEditException extends RuntimeException
{
    /** @var list<array{message: string, task_id: string|null}> */
    public readonly array $errors;

    /**
     * @param  list<string|array{message: string, task_id: string|null}>  $errors
     */
    public function __construct(array $errors, public readonly int $status = 422, public readonly ?int $latestVersion = null)
    {
        $this->errors = array_map(fn ($error) => is_string($error) ? ['message' => $error, 'task_id' => null] : $error, $errors);

        parent::__construct(implode(' ', array_column($this->errors, 'message')));
    }

    public static function forTask(string $taskId, string $message): self
    {
        return new self([['message' => $message, 'task_id' => $taskId]]);
    }

    public static function conflict(int $latestVersion): self
    {
        return new self(['Akış bu arada değişti (başka bir düzenleme veya chat). Sayfayı yenileyip tekrar deneyin.'], 409, $latestVersion);
    }
}
