<?php

namespace App\Services\Flows;

/**
 * FlowValidator'ın tek bir doğrulama sırasında topladığı hatalar, uyarılar ve bulgular.
 */
final class ValidationState
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $missingSecrets = [];

    /** @var list<array{path: string, id: string|null, type: string, node: array<string, mixed>}> */
    public array $plugins = [];

    public ?string $canonicalYaml = null;

    /** @var list<string> APM token alan görevlerin id'leri */
    public array $apmTokenTasks = [];

    /** @var list<array{where: string, task: string}> APM isteklerinin token aldığı görevler */
    public array $apmTokenRefs = [];

    /** @var list<array{path: string, task: string|null}> Metinlerdeki outputs başvuruları (task null: tüm outputs) */
    public array $outputRefs = [];

    /** @var array<string, mixed>|null */
    public ?array $flow = null;

    /**
     * Laravel'in kendi ürettiği agent zamanlama akışı: sadece bu tam adrese (iç uç) istek atılabilir.
     * Claude'un önerdiği akışlarda hiçbir zaman verilmez.
     */
    public ?string $internalCallbackUri = null;

    /** @var array<string, true> */
    private array $secrets = [];

    public function error(string $message): void
    {
        if (! in_array($message, $this->errors, true)) {
            $this->errors[] = $message;
        }
    }

    public function warning(string $message): void
    {
        if (! in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }

    public function addSecret(string $name): void
    {
        $this->secrets[$name] = true;
    }

    /** @return list<string> */
    public function secrets(): array
    {
        return array_keys($this->secrets);
    }

    /**
     * @return array{
     *     valid: bool,
     *     errors: list<string>,
     *     warnings: list<string>,
     *     missing_secrets: list<string>,
     *     secrets: list<string>,
     *     canonical_yaml: string|null,
     *     flow: array<string, mixed>|null,
     *     plugins: list<array{path: string, id: string|null, type: string, node: array<string, mixed>}>,
     * }
     */
    public function result(): array
    {
        $valid = $this->errors === [];

        return [
            'valid' => $valid,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'missing_secrets' => $this->missingSecrets,
            'secrets' => $this->secrets(),
            // Geçersiz bir akışın kanonik YAML'ı hiçbir yere gitmesin.
            'canonical_yaml' => $valid ? $this->canonicalYaml : null,
            'flow' => $this->flow,
            'plugins' => $this->plugins,
        ];
    }
}
