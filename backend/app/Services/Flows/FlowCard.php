<?php

namespace App\Services\Flows;

use App\Services\Apm\ApmApi;
use App\Services\SuccessFactors\SuccessFactors;
use App\Services\Warehouse\Warehouse;

/**
 * Chat'te gösterilen akış kartının verisi.
 *
 * Başlık ve özet Claude'dan gelir. Tetikleyiciler, uyarılı adımlar ve eksik secret'lar ise
 * Claude'a bırakılmadan YAML'dan çıkarılır; kullanıcı neyi onayladığını buradan görür.
 */
class FlowCard
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly Warehouse $warehouse,
        private readonly SuccessFactors $successFactors,
        private readonly ApmApi $apm,
    ) {}

    /**
     * @param  list<string>  $summary
     * @param  array{flow: array<string, mixed>|null, plugins: list<array{path: string, id: string|null, type: string, node: array<string, mixed>}>, missing_secrets: list<string>, warnings: list<string>}  $check
     * @return array{
     *     title: string,
     *     summary: list<string>,
     *     flow_id: string,
     *     namespace: string,
     *     triggers: list<string>,
     *     warned_steps: list<array{task_id: string|null, label: string, details: string|null}>,
     *     missing_secrets: list<string>,
     *     yaml: string,
     * }
     */
    public function build(string $title, array $summary, string $yaml, array $check): array
    {
        $triggers = [];
        $warned = [];

        foreach ($check['plugins'] as $plugin) {
            $entry = $this->catalog->entry($plugin['type']);

            if ($entry === null) {
                continue;
            }

            if ($entry['kind'] === 'trigger') {
                $triggers[] = $this->describeTrigger($plugin['type'], $plugin['node']);
            }

            if ($plugin['type'] === 'io.kestra.plugin.core.http.Request' && is_string($plugin['node']['uri'] ?? null)
                && $this->apm->isApmUrl($plugin['node']['uri'])) {
                $warned[] = ['task_id' => $plugin['id']] + $this->apmStep($plugin['node']);

                continue;
            }

            if ($plugin['type'] === 'io.kestra.plugin.core.http.Request' && is_string($plugin['node']['uri'] ?? null)
                && $this->successFactors->isSuccessFactorsUrl($plugin['node']['uri'])) {
                $warned[] = ['task_id' => $plugin['id']] + $this->successFactorsStep($plugin['node']);

                continue;
            }

            if ($entry['category'] === 'warned') {
                $warned[] = [
                    'task_id' => $plugin['id'],
                    'label' => $entry['warning'],
                    'details' => $plugin['type'] === 'io.kestra.plugin.jdbc.mysql.Query'
                        ? $this->queryDetails($plugin['node'])
                        : $this->details($plugin['node'], $entry['warning_fields'] ?? []),
                ];
            }
        }

        return [
            'title' => $title,
            'summary' => array_values($summary),
            'flow_id' => (string) ($check['flow']['id'] ?? ''),
            'namespace' => (string) ($check['flow']['namespace'] ?? ''),
            'triggers' => $triggers === [] ? ['Elle çalıştırılır'] : $triggers,
            'warned_steps' => $warned,
            'missing_secrets' => $check['missing_secrets'],
            'yaml' => $yaml,
        ];
    }

    /**
     * Veri ambarı sorgusu: hazır sorguysa adı, değilse SQL'in başı.
     *
     * @param  array<string, mixed>  $node
     */
    private function queryDetails(array $node): string
    {
        $sql = is_string($node['sql'] ?? null) ? $node['sql'] : '';
        $named = $this->warehouse->namedQueryFor($sql);

        if ($named !== null) {
            return "Onaylı sorgu: {$named['title']}";
        }

        return 'Serbest SQL: '.mb_strimwidth((string) preg_replace('/\s+/', ' ', $sql), 0, 220, '…');
    }

    /**
     * SuccessFactors isteği: okuma mı yazma mı, hazır istek mi, kişisel veri içeriyor mu.
     *
     * @param  array<string, mixed>  $node
     * @return array{label: string, details: string}
     */
    private function successFactorsStep(array $node): array
    {
        $uri = (string) $node['uri'];
        $method = strtoupper(is_string($node['method'] ?? null) ? $node['method'] : 'GET');
        $named = $this->successFactors->namedRequestFor($uri);
        $entity = $this->successFactors->entityOf($uri) ?? '?';

        $details = $named !== null
            ? "Hazır istek: {$named['title']}".($named['personal_data'] ? ' (kişisel veri içerir)' : '')
            : "Serbest istek: {$method} {$entity}";

        return [
            'label' => $method === 'GET' ? "SuccessFactors'tan (İK) veri okuyor" : "SuccessFactors'a (İK) YAZIYOR",
            'details' => $details,
        ];
    }

    /**
     * APM isteği: token alma, okuma veya yazma.
     *
     * @param  array<string, mixed>  $node
     * @return array{label: string, details: string}
     */
    private function apmStep(array $node): array
    {
        $uri = (string) $node['uri'];
        $method = strtoupper(is_string($node['method'] ?? null) ? $node['method'] : 'GET');
        $operation = $this->apm->operationFor($method, $uri);
        $path = $operation['path'] ?? $this->apm->pathOf($uri);

        if ($this->apm->isTokenOperation($method, $path)) {
            return ['label' => "APM'ye giriş yapıyor (token alır)", 'details' => 'POST /oauth/token'];
        }

        $summary = trim((string) ($operation['operation']['summary'] ?? ''));

        return [
            'label' => $this->apm->isReadOnly($method, $path) ? "APM'den veri okuyor" : "APM'ye YAZIYOR",
            'details' => "{$method} {$path}".($summary !== '' ? " — {$summary}" : ''),
        ];
    }

    /** @param  array<string, mixed>  $node */
    private function describeTrigger(string $type, array $node): string
    {
        return match ($type) {
            'io.kestra.plugin.core.trigger.Schedule' => sprintf(
                'Zamanlanmış: %s (%s)',
                is_string($node['cron'] ?? null) ? $node['cron'] : '?',
                is_string($node['timezone'] ?? null) ? $node['timezone'] : 'UTC',
            ),
            'io.kestra.plugin.core.trigger.Webhook' => 'Webhook ile tetiklenir',
            default => $this->catalog->entry($type)['title'],
        };
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $fields
     */
    private function details(array $node, array $fields): ?string
    {
        $values = [];
        foreach ($fields as $field) {
            if (isset($node[$field]) && is_scalar($node[$field]) && (string) $node[$field] !== '') {
                $values[] = (string) $node[$field];
            }
        }

        return $values === [] ? null : implode(' ', $values);
    }
}
