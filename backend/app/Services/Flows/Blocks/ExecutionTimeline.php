<?php

namespace App\Services\Flows\Blocks;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Çalışmalar ekranındaki adım zaman çizelgesi: akıştaki bloklar YAML sırasıyla, Kestra'daki adım durumlarıyla
 * birleştirilir. Henüz çalışmamış adımlar "bekliyor" olarak görünür; hata dalı adımları sadece çalıştıysa görünür.
 */
class ExecutionTimeline
{
    private const PRIORITY = ['FAILED', 'KILLED', 'PAUSED', 'RUNNING', 'RETRYING', 'CREATED', 'WARNING', 'SUCCESS', 'SKIPPED'];

    public function __construct(private readonly FlowGraph $graph) {}

    /**
     * @param  list<array<string, mixed>>  $taskRuns
     * @return list<array<string, mixed>>
     */
    public function build(?string $yaml, array $taskRuns): array
    {
        try {
            $flow = $yaml === null ? null : Yaml::parse($yaml);
        } catch (ParseException) {
            $flow = null;
        }

        if (! is_array($flow)) {
            return array_map(fn (array $run) => ['task_id' => $run['task_id'], 'title' => null, 'label' => null, 'kind' => null, 'depth' => 0, 'section' => 'tasks'] + self::state([$run]), $taskRuns);
        }

        $graph = $this->graph->build($flow);
        $runs = [];
        foreach ($taskRuns as $run) {
            $runs[$run['task_id']][] = $run;
        }

        $steps = [];
        $walk = function (array $nodes, int $depth, string $section) use (&$walk, &$steps, $runs): void {
            foreach ($nodes as $node) {
                $own = $runs[$node['id']] ?? [];

                if ($section === 'tasks' || $own !== []) {
                    $steps[] = [
                        'task_id' => $node['id'],
                        'title' => $node['title'],
                        'label' => $node['label'],
                        'kind' => $node['kind'],
                        'depth' => $depth,
                        'section' => $section,
                    ] + self::state($own);
                }

                $walk($node['children'] ?? [], $depth + 1, $section);
                foreach ($node['branches'] ?? [] as $branch) {
                    $walk($branch, $depth + 1, $section);
                }
            }
        };

        foreach (FlowGraph::ROOT_LISTS as $section) {
            $walk($graph[$section], 0, $section);
        }

        return $steps;
    }

    /**
     * Bir adımın (döngüde birden çok çalışmış olabilir) toplu durumu.
     *
     * @param  list<array<string, mixed>>  $runs
     * @return array{state: string|null, runs: int, started_at: string|null, duration: string|null, output_summary: string|null}
     */
    private static function state(array $runs): array
    {
        if ($runs === []) {
            return ['state' => null, 'runs' => 0, 'started_at' => null, 'duration' => null, 'output_summary' => null];
        }

        $states = array_column($runs, 'state');
        $rank = array_flip(self::PRIORITY);
        usort($states, fn ($a, $b) => ($rank[$a] ?? 99) <=> ($rank[$b] ?? 99));
        $starts = array_filter(array_column($runs, 'started_at'));

        return [
            'state' => $states[0],
            'runs' => count($runs),
            'started_at' => $starts === [] ? null : min($starts),
            'duration' => count($runs) === 1 ? ($runs[0]['duration'] ?? null) : null,
            'output_summary' => count($runs) === 1 ? ($runs[0]['output_summary'] ?? null) : null,
        ];
    }
}
