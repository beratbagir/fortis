<?php

namespace App\Services\Flows;

use App\Services\Apm\ApmApi;

/**
 * Test çalıştırma için akışın kuru çalıştırma kopyası. Dış sistemlere yazan adımlar (e-posta, Teams, Slack,
 * GET dışı HTTP istekleri, alt akışlar) aynı id'li bir Log adımına çevrilir; tetikleyiciler kaldırılır ve
 * akış test namespace'ine taşınır. Dönüşüm yalnızca adım kaldırır veya Log'a çevirir; akışa yeni yetenek eklemez.
 */
class DryRun
{
    private const LOG = 'io.kestra.plugin.core.log.Log';

    public function __construct(private readonly ApmApi $apm) {}

    /**
     * @param  array<string, mixed>  $flow
     * @return array{flow: array<string, mixed>, skipped: list<string>}
     */
    public function transform(array $flow, string $namespace): array
    {
        $skipped = [];
        $flow['namespace'] = $namespace;
        unset($flow['triggers'], $flow['disabled']);

        foreach (['tasks', 'errors', 'finally'] as $list) {
            if (is_array($flow[$list] ?? null)) {
                $flow[$list] = $this->walk($flow[$list], $skipped);
            }
        }

        return ['flow' => $flow, 'skipped' => $skipped];
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $skipped
     * @return array<array-key, mixed>
     */
    private function walk(array $node, array &$skipped): array
    {
        if (is_string($node['type'] ?? null) && is_string($node['id'] ?? null) && ($what = $this->writes($node)) !== null) {
            $skipped[] = $node['id'];

            return array_filter([
                'id' => $node['id'],
                'type' => self::LOG,
                'description' => $node['description'] ?? null,
                'level' => 'WARN',
                'message' => "[Kuru çalıştırma] {$what} gönderilmedi.",
            ], fn ($value) => $value !== null);
        }

        foreach ($node as $key => $child) {
            if (is_array($child)) {
                $node[$key] = $this->walk($child, $skipped);
            }
        }

        return $node;
    }

    /** @param  array<string, mixed>  $task */
    private function writes(array $task): ?string
    {
        $method = strtoupper((string) ($task['method'] ?? 'GET'));

        return match ($task['type']) {
            'io.kestra.plugin.email.MailSend' => 'E-posta ('.self::text($task['to'] ?? null).' · '.self::text($task['subject'] ?? null).')',
            'io.kestra.plugin.microsoft365.teams.TeamsIncomingWebhook' => 'Teams mesajı',
            'io.kestra.plugin.slack.notifications.SlackIncomingWebhook' => 'Slack mesajı',
            'io.kestra.plugin.core.flow.Subflow' => 'Alt akış çağrısı ('.self::text($task['flowId'] ?? null).')',
            'io.kestra.plugin.core.http.Request' => in_array($method, ['GET', 'HEAD'], true) || $this->isApmToken($task) ? null : "{$method} isteği",
            default => null,
        };
    }

    /** @param  array<string, mixed>  $task */
    private function isApmToken(array $task): bool
    {
        return is_string($task['uri'] ?? null) && $this->apm->isApmUrl($task['uri']) && $this->apm->pathOf($task['uri']) === '/oauth/token';
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '-';
    }
}
