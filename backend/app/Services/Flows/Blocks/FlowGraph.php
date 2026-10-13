<?php

namespace App\Services\Flows\Blocks;

/**
 * Kestra YAML'ının tuval görünümü (graf modeli). YAML tek doğruluk kaynağıdır; graf her seferinde ondan üretilir.
 *
 * Görevler ağaç yapısındadır: Döngü `children`, Koşul `branches.then/else` içerir. Tanınmayan görevler "Gelişmiş"
 * bloktur; içlerindeki alt görevler ayrıca gösterilmez ve tuvalden değiştirilemez.
 */
class FlowGraph
{
    /** Akışın üst seviyedeki görev listeleri. */
    public const ROOT_LISTS = ['tasks', 'errors', 'finally'];

    public function __construct(private readonly BlockCatalog $blocks) {}

    /**
     * @param  array<string, mixed>  $flow  Ayrıştırılmış YAML
     * @return array{flow_id: mixed, namespace: mixed, description: mixed, triggers: list<array<string, mixed>>, tasks: list<array<string, mixed>>, errors: list<array<string, mixed>>, finally: list<array<string, mixed>>}
     */
    public function build(array $flow): array
    {
        return [
            'flow_id' => $flow['id'] ?? null,
            'namespace' => $flow['namespace'] ?? null,
            'description' => $flow['description'] ?? null,
            'triggers' => array_map(fn (mixed $trigger) => $this->node($trigger, trigger: true), self::list($flow['triggers'] ?? [])),
            'tasks' => $this->nodes($flow['tasks'] ?? []),
            'errors' => $this->nodes($flow['errors'] ?? []),
            'finally' => $this->nodes($flow['finally'] ?? []),
        ];
    }

    /**
     * Grafı tek bir listeye düzleştirir (derinlik öncelikli, YAML sırasıyla).
     *
     * @param  array<string, mixed>  $graph
     * @return list<array<string, mixed>>
     */
    public static function flatten(array $graph): array
    {
        $out = [];
        $walk = function (array $nodes) use (&$walk, &$out): void {
            foreach ($nodes as $node) {
                $out[] = $node;
                $walk($node['children'] ?? []);
                foreach ($node['branches'] ?? [] as $branch) {
                    $walk($branch);
                }
            }
        };

        foreach (self::ROOT_LISTS as $list) {
            $walk($graph[$list] ?? []);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function nodes(mixed $tasks): array
    {
        return array_map(fn (mixed $task) => $this->node($task), self::list($tasks));
    }

    /** @return array<string, mixed> */
    private function node(mixed $task, bool $trigger = false): array
    {
        if (! is_array($task)) {
            return ['id' => null, 'kind' => BlockCatalog::ADVANCED, 'label' => 'Gelişmiş', 'title' => null, 'settings' => [], 'readonly' => true, 'raw_type' => null, 'writes' => false];
        }

        $recognized = $this->blocks->recognize($task, $trigger);
        $block = $this->blocks->block($recognized['kind']);

        $node = [
            'id' => is_string($task['id'] ?? null) ? $task['id'] : null,
            'kind' => $recognized['kind'],
            'label' => $block['label'] ?? 'Gelişmiş',
            'title' => is_string($task['description'] ?? null) ? $task['description'] : null,
            'settings' => $recognized['settings'],
            'readonly' => $recognized['readonly'],
            'raw_type' => is_string($task['type'] ?? null) ? $task['type'] : null,
            'writes' => (bool) ($block['writes'] ?? false) || ($recognized['kind'] === 'http_request' && ! in_array(strtoupper((string) ($task['method'] ?? 'GET')), ['GET', 'HEAD'], true)),
        ];

        if ($recognized['kind'] === 'foreach') {
            $node['children'] = $this->nodes($task['tasks'] ?? []);
        }

        if ($recognized['kind'] === 'if') {
            $node['branches'] = ['then' => $this->nodes($task['then'] ?? []), 'else' => $this->nodes($task['else'] ?? [])];
        }

        return $node;
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }
}
