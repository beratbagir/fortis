<?php

namespace App\Services\Flows;

use Symfony\Component\Yaml\Yaml;

/**
 * Kaydedilmiş (doğrulanmış, kanonik) bir akış YAML'ından çalıştırma için gereken bilgileri okur:
 * akış girdileri, görevler (ör. bekleyen Pause görevi) ve webhook key'i.
 */
final class FlowDefinition
{
    private const PAUSE = 'io.kestra.plugin.core.flow.Pause';

    private const WEBHOOK = 'io.kestra.plugin.core.trigger.Webhook';

    /** @param  array<string, mixed>  $flow */
    private function __construct(private readonly array $flow) {}

    public static function fromYaml(string $yaml): self
    {
        $flow = Yaml::parse($yaml);

        return new self(is_array($flow) ? $flow : []);
    }

    /**
     * Akışın girdileri, çalıştırma formu için.
     *
     * @return list<array{id: string, type: string, required: bool, defaults: mixed, description: string|null, values: list<string>|null}>
     */
    public function inputs(): array
    {
        return self::describeInputs($this->flow['inputs'] ?? []);
    }

    /**
     * Bir Pause görevinin devam ederken istediği girdiler ve (varsa) tek BOOL onay girdisi.
     *
     * @return array{task_id: string, inputs: list<array<string, mixed>>, approval_input: string|null}|null
     */
    public function pause(string $taskId): ?array
    {
        $task = $this->findTask($this->flow, $taskId);

        if ($task === null || ($task['type'] ?? null) !== self::PAUSE) {
            return null;
        }

        $inputs = self::describeInputs($task['onResume'] ?? []);
        $booleans = array_values(array_filter($inputs, fn (array $input) => in_array($input['type'], ['BOOL', 'BOOLEAN'], true)));

        return [
            'task_id' => $taskId,
            'inputs' => $inputs,
            'approval_input' => count($booleans) === 1 ? $booleans[0]['id'] : null,
            // Çalışmalar ekranındaki onay kartı: soru, zaman aşımı (ISO süre) ve zaman aşımında ne olacağı.
            'question' => count($booleans) === 1 ? $booleans[0]['description'] : null,
            'timeout' => is_string($task['pauseDuration'] ?? null) ? $task['pauseDuration'] : null,
            'on_timeout' => is_string($task['behavior'] ?? null) ? $task['behavior'] : null,
        ];
    }

    public function webhookKey(): ?string
    {
        foreach ($this->flow['triggers'] ?? [] as $trigger) {
            if (is_array($trigger) && ($trigger['type'] ?? null) === self::WEBHOOK && is_string($trigger['key'] ?? null)) {
                return $trigger['key'];
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string, type: string, required: bool, defaults: mixed, description: string|null, values: list<string>|null}>
     */
    private static function describeInputs(mixed $inputs): array
    {
        if (! is_array($inputs)) {
            return [];
        }

        $described = [];
        foreach ($inputs as $input) {
            if (! is_array($input) || ! is_string($input['id'] ?? null)) {
                continue;
            }

            $described[] = [
                'id' => $input['id'],
                'type' => is_string($input['type'] ?? null) ? $input['type'] : 'STRING',
                'required' => ($input['required'] ?? true) !== false && ! array_key_exists('defaults', $input),
                'defaults' => $input['defaults'] ?? null,
                'description' => is_string($input['description'] ?? null) ? $input['description'] : null,
                'values' => is_array($input['values'] ?? null) ? array_values(array_map('strval', $input['values'])) : null,
            ];
        }

        return $described;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function findTask(array $node, string $taskId): ?array
    {
        if (($node['id'] ?? null) === $taskId && isset($node['type'])) {
            return $node;
        }

        foreach ($node as $child) {
            if (is_array($child) && ($found = $this->findTask($child, $taskId)) !== null) {
                return $found;
            }
        }

        return null;
    }
}
