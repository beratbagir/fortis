<?php

namespace App\Services\Flows\Blocks;

use App\Services\Apm\ApmApi;
use App\Services\Flows\Catalog;
use App\Services\SuccessFactors\SuccessFactors;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Tuvaldeki Fortis blokları (config/kestra-catalog.json → blocks) ile Kestra görevleri arasındaki dönüşüm.
 *
 * Kullanıcı Kestra görev tiplerini değil blokları görür. Her blok için iki yön vardır:
 *  - fromTask: YAML görevinden form değerleri (settings),
 *  - toTask: form değerlerinden YAML görevi. Mevcut görevdeki bilinmeyen alanlar (retry, timeout...) korunur;
 *    sadece değeri değişen alanlar yazılır.
 *
 * Güvence: bir görev ancak toTask(fromTask(görev), görev) görevin kendisini veriyorsa bloğa çevrilir. Aksi halde
 * "Gelişmiş" (salt okunur) bloktur; tuval onu değiştiremez, dolayısıyla hiçbir alan sessizce kaybolmaz.
 */
class BlockCatalog
{
    public const ADVANCED = 'advanced';

    private const HTTP = 'io.kestra.plugin.core.http.Request';

    /** @var array<string, array<string, mixed>> */
    private array $blocks = [];

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $apps
     */
    public function __construct(
        array $blocks,
        private readonly array $apps,
        private readonly Catalog $catalog,
        private readonly ApmApi $apm,
        private readonly SuccessFactors $successFactors,
    ) {
        foreach ($blocks as $block) {
            $this->blocks[$block['kind']] = $block;
        }
    }

    public static function fromConfig(Catalog $catalog, ApmApi $apm, SuccessFactors $successFactors): self
    {
        $data = json_decode((string) file_get_contents(config('kestra.catalog_path')), true);

        if (! is_array($data['blocks'] ?? null)) {
            throw new RuntimeException('kestra-catalog.json içinde blocks bölümü yok.');
        }

        return new self($data['blocks'], $data['apps'] ?? [], $catalog, $apm, $successFactors);
    }

    /** @return list<array<string, mixed>> */
    public function blocks(): array
    {
        return array_values($this->blocks);
    }

    /** @return array<string, mixed>|null */
    public function block(string $kind): ?array
    {
        return $this->blocks[$kind] ?? null;
    }

    /** Paletten eklenebilir mi: katalogda, palette açık, kullanılabilir ve tipi izinli. */
    public function canInsert(string $kind): bool
    {
        $block = $this->blocks[$kind] ?? null;

        return $block !== null
            && ($block['palette'] ?? false)
            && ($block['available'] ?? true)
            && ! ($block['readonly'] ?? false)
            && is_string($block['match']['type'] ?? null)
            && $this->catalog->isAllowed($block['match']['type']);
    }

    public function isTrigger(string $kind): bool
    {
        return (bool) ($this->blocks[$kind]['trigger'] ?? false);
    }

    /** @return list<string> Konteyner bloğun dalları (Döngü: tasks, Koşul: then/else); konteyner değilse boş. */
    public function branches(string $kind): array
    {
        return $this->blocks[$kind]['branches'] ?? [];
    }

    /**
     * Uygulamalar ve bağlantı durumları. Bağlantı = gerekli Kestra secret'larının tanımlı olması; kimlik bilgileri
     * Kestra'da durur, Fortis (ve AI) yalnızca secret adlarını bilir.
     *
     * @return list<array<string, mixed>>
     */
    public function apps(): array
    {
        return array_map(function (array $app) {
            $required = match ($app['required_secrets'] ?? []) {
                'apm' => $this->apm->requiredSecrets(),
                'successfactors' => $this->successFactors->requiredSecrets(),
                default => $app['required_secrets'] ?? [],
            };
            $missing = array_values(array_diff($required, $this->catalog->secrets()));
            $available = $app['available'] ?? true;

            return [
                ...$app,
                'required_secrets' => $required,
                'missing_secrets' => $missing,
                'available' => $available,
                'connected' => $available && $required !== [] && $missing === [],
            ];
        }, $this->apps);
    }

    /**
     * YAML görevini (veya tetikleyiciyi) bloğa çevirir.
     *
     * @param  array<string, mixed>  $task
     * @return array{kind: string, settings: array<string, mixed>, readonly: bool}
     */
    public function recognize(array $task, bool $trigger = false): array
    {
        $kind = $this->match($task, $trigger);

        if ($kind !== null) {
            $settings = $this->fromTask($kind, $task);

            if (self::same($this->toTask($kind, $settings, $task), $task)) {
                return ['kind' => $kind, 'settings' => $settings, 'readonly' => false];
            }
        }

        return ['kind' => self::ADVANCED, 'settings' => [], 'readonly' => true];
    }

    /**
     * Form değerlerini YAML görevine yazar. $existing verilirse onun bilinmeyen alanları korunur; verilmezse
     * bloğun yeni görev şablonu kullanılır (secret'lar, sabit alanlar).
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>  $context  Yeni görevlerde: id, apm_token (APM token görevinin id'si)
     * @return array<string, mixed>
     */
    public function toTask(string $kind, array $settings, ?array $existing = null, array $context = []): array
    {
        $task = $existing ?? $this->template($kind, $context);

        $task = match ($kind) {
            'trigger_schedule' => self::put(self::put($task, 'cron', $settings['cron'] ?? null), 'timezone', $settings['timezone'] ?? null),
            'trigger_webhook', 'apm_token', self::ADVANCED => $task,
            'data_apm' => self::put($task, 'uri', $this->apmUri($settings, $task['uri'] ?? null)),
            'data_sf' => self::put($task, 'uri', $this->successFactors->baseUrl().ltrim((string) ($settings['request'] ?? ''), '/')),
            'data_ibs' => self::put(self::put(self::put($task, 'sql', $settings['sql'] ?? null), 'parameters', ($settings['parameters'] ?? []) ?: null), 'fetchType', $settings['fetch_type'] ?? null),
            'http_request' => self::put(self::put($task, 'method', $settings['method'] ?? null), 'uri', $settings['uri'] ?? null),
            'notify_email' => self::put(self::put(self::put(self::put($task, 'to', $settings['to'] ?? null), 'cc', $settings['cc'] ?? null), 'subject', $settings['subject'] ?? null), 'htmlTextContent', $settings['message'] ?? null),
            'notify_teams' => $this->teamsPayload($task, $settings['message'] ?? null),
            'notify_slack' => self::put($task, 'messageText', $settings['message'] ?? null),
            'log' => self::put(self::put($task, 'message', $settings['message'] ?? null), 'level', $settings['level'] ?? null),
            'foreach' => self::put($task, 'values', self::loopValues($settings['values'] ?? null, $task['values'] ?? null)),
            'if' => self::put($task, 'condition', $settings['condition'] ?? null),
            'pause' => $this->pause($task, $settings),
            'sleep' => self::put($task, 'duration', $settings['duration'] ?? null),
            default => throw new RuntimeException("Bilinmeyen blok: {$kind}"),
        };

        // Her blokta ortak "Başlık" alanı: Kestra görevinin description'ı.
        if ($kind !== self::ADVANCED && array_key_exists('title', $settings)) {
            $task = self::put($task, 'description', $settings['title']);
        }

        return $task;
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    public function fromTask(string $kind, array $task): array
    {
        $settings = match ($kind) {
            'trigger_schedule' => ['cron' => $task['cron'] ?? null, 'timezone' => $task['timezone'] ?? null],
            'trigger_webhook' => ['key' => $task['key'] ?? null],
            'data_apm' => ['path' => $this->apm->pathOf((string) $task['uri']), 'params' => self::parseQuery((string) $task['uri'])],
            'data_sf' => ['request' => substr((string) $task['uri'], strlen($this->successFactors->baseUrl()))],
            'data_ibs' => ['sql' => $task['sql'] ?? null, 'parameters' => $task['parameters'] ?? [], 'fetch_type' => $task['fetchType'] ?? null],
            'http_request' => ['method' => $task['method'] ?? null, 'uri' => $task['uri'] ?? null],
            'notify_email' => ['to' => $task['to'] ?? null, 'cc' => $task['cc'] ?? null, 'subject' => $task['subject'] ?? null, 'message' => $task['htmlTextContent'] ?? null],
            'notify_teams' => ['message' => self::teamsText($task['payload'] ?? null)],
            'notify_slack' => ['message' => $task['messageText'] ?? null],
            'log' => ['message' => $task['message'] ?? null, 'level' => $task['level'] ?? null],
            'foreach' => ['values' => is_array($task['values'] ?? null) ? json_encode($task['values'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ($task['values'] ?? null)],
            'if' => ['condition' => $task['condition'] ?? null],
            'pause' => self::pauseSettings($task),
            'sleep' => ['duration' => $task['duration'] ?? null],
            default => [],
        };

        return ['title' => $task['description'] ?? null] + $settings;
    }

    /** Akışta benzersiz, okunur bir görev id'si: notify_teams, notify_teams_2, ... */
    public function uniqueId(string $kind, array $taken): string
    {
        $prefix = $this->blocks[$kind]['id_prefix'] ?? 'step';

        if (! in_array($prefix, $taken, true)) {
            return $prefix;
        }

        for ($i = 2; ; $i++) {
            if (! in_array("{$prefix}_{$i}", $taken, true)) {
                return "{$prefix}_{$i}";
            }
        }
    }

    /** @param  array<string, mixed>  $task */
    private function match(array $task, bool $trigger): ?string
    {
        $type = $task['type'] ?? null;

        if (! is_string($type) || ! is_string($task['id'] ?? null)) {
            return null;
        }

        if ($type === self::HTTP) {
            return $trigger ? null : $this->matchHttp($task);
        }

        foreach ($this->blocks as $kind => $block) {
            if (($block['match']['type'] ?? null) === $type && (bool) ($block['trigger'] ?? false) === $trigger) {
                return $kind;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $task */
    private function matchHttp(array $task): ?string
    {
        $uri = $task['uri'] ?? null;
        $method = strtoupper((string) ($task['method'] ?? 'GET'));

        if (! is_string($uri)) {
            return null;
        }

        if ($this->apm->isApmUrl($uri)) {
            if ($this->apm->pathOf($uri) === '/oauth/token') {
                return 'apm_token';
            }

            return $method === 'GET' && ($task['options']['auth']['type'] ?? null) === 'BEARER' ? 'data_apm' : 'http_request';
        }

        if ($this->successFactors->baseUrl() !== '' && str_starts_with($uri, $this->successFactors->baseUrl())
            && $method === 'GET' && ($task['options']['auth']['type'] ?? null) === 'BASIC') {
            return 'data_sf';
        }

        return 'http_request';
    }

    /**
     * Yeni görev şablonu: kimlik bilgileri yalnızca secret, sabit alanlar katalogdaki örneklerle aynı.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function template(string $kind, array $context): array
    {
        $block = $this->blocks[$kind] ?? throw new RuntimeException("Bilinmeyen blok: {$kind}");
        $base = ['id' => $context['id'] ?? $kind, 'type' => $block['match']['type'] ?? null];
        $secret = fn (string $name) => "{{ secret('{$name}') }}";

        return match ($kind) {
            'trigger_schedule' => $base + ['timezone' => 'Europe/Istanbul'],
            'trigger_webhook' => $base + ['key' => Str::random(32)],
            'apm_token' => $base + [
                'uri' => $this->apm->baseUrl().'/oauth/token',
                'method' => 'POST',
                'contentType' => 'application/x-www-form-urlencoded',
                'formData' => $this->apm->grantType() === 'password'
                    ? ['grant_type' => 'password', 'username' => $secret('APM_USERNAME'), 'password' => $secret('APM_PASSWORD')]
                    : ['grant_type' => 'client_credentials', 'client_id' => $secret('APM_CLIENT_ID'), 'client_secret' => $secret('APM_CLIENT_SECRET')],
            ],
            'data_apm' => $base + [
                'uri' => '',
                'method' => 'GET',
                'options' => ['auth' => ['type' => 'BEARER', 'token' => "{{ outputs.{$context['apm_token']}.body | jq('.access_token') | first }}"]],
            ],
            'data_sf' => $base + [
                'uri' => '',
                'method' => 'GET',
                'options' => ['auth' => array_combine(['type', 'username', 'password'], ['BASIC', ...array_map($secret, $this->successFactors->requiredSecrets())])],
            ],
            'data_ibs' => $base + [
                'url' => $secret('IBS_JDBC_URL'),
                'username' => $secret('IBS_DB_USERNAME'),
                'password' => $secret('IBS_DB_PASSWORD'),
                'fetchType' => 'FETCH',
            ],
            'notify_email' => $base + [
                'host' => $secret('SMTP_HOST'),
                'port' => $secret('SMTP_PORT'),
                'transportStrategy' => 'SMTP_TLS',
                'username' => $secret('SMTP_USERNAME'),
                'password' => $secret('SMTP_PASSWORD'),
                'from' => $secret('SMTP_FROM'),
            ],
            'notify_teams' => $base + ['url' => $secret('TEAMS_WEBHOOK_URL')],
            'notify_slack' => $base + ['url' => $secret('SLACK_WEBHOOK_URL')],
            'foreach' => $base + ['values' => '', 'tasks' => []],
            'if' => $base + ['condition' => '', 'then' => []],
            'pause' => $base + ['pauseDuration' => 'PT24H', 'behavior' => 'CANCEL'],
            default => $base,
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function apmUri(array $settings, ?string $current): string
    {
        $params = is_array($settings['params'] ?? null) ? $settings['params'] : [];

        // Değişmediyse adres aynen kalır (parametre yazımı birebir korunur).
        if (is_string($current) && $this->apm->isApmUrl($current)
            && $this->apm->pathOf($current) === ($settings['path'] ?? null) && self::parseQuery($current) === $params) {
            return $current;
        }

        $query = implode('&', array_map(fn ($key, $value) => "{$key}={$value}", array_keys($params), $params));

        return $this->apm->baseUrl().'/'.ltrim((string) ($settings['path'] ?? ''), '/').($query === '' ? '' : "?{$query}");
    }

    /**
     * Adresin sorgu kısmı, ham haliyle (kodlama çözülmeden). {{ }} içindeki & ve = bölünmez.
     *
     * @return array<string, string>
     */
    public static function parseQuery(string $uri): array
    {
        $start = strpos($uri, '?');

        if ($start === false) {
            return [];
        }

        $parts = [];
        $current = '';
        $depth = 0;
        $query = substr($uri, $start + 1);

        for ($i = 0, $n = strlen($query); $i < $n; $i++) {
            $two = substr($query, $i, 2);

            if ($two === '{{' || $two === '}}') {
                $depth += $two === '{{' ? 1 : -1;
                $current .= $two;
                $i++;

                continue;
            }

            if ($query[$i] === '&' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $query[$i];
        }
        $parts[] = $current;

        $params = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function teamsPayload(array $task, mixed $message): array
    {
        if ($message === null || $message === '') {
            return self::put($task, 'payload', null);
        }

        if (self::teamsText($task['payload'] ?? null) === $message) {
            return $task;
        }

        return self::put($task, 'payload', json_encode(['text' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function teamsText(mixed $payload): ?string
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : null;

        return is_array($decoded) && array_keys($decoded) === ['text'] && is_string($decoded['text']) ? $decoded['text'] : null;
    }

    private static function loopValues(mixed $value, mixed $current): mixed
    {
        if (is_array($current) && is_string($value) && json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === $value) {
            return $current;
        }

        $decoded = is_string($value) && str_starts_with(trim($value), '[') ? json_decode($value, true) : null;

        return is_array($decoded) && array_is_list($decoded) ? $decoded : $value;
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private static function pauseSettings(array $task): array
    {
        $onResume = $task['onResume'] ?? null;
        $question = is_array($onResume) && count($onResume) === 1 && in_array($onResume[0]['type'] ?? null, ['BOOL', 'BOOLEAN'], true)
            ? ($onResume[0]['description'] ?? null)
            : null;

        return ['question' => $question, 'timeout' => $task['pauseDuration'] ?? null, 'on_timeout' => $task['behavior'] ?? null];
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function pause(array $task, array $settings): array
    {
        $task = self::put(self::put($task, 'pauseDuration', $settings['timeout'] ?? null), 'behavior', $settings['on_timeout'] ?? null);
        $question = $settings['question'] ?? null;
        $onResume = $task['onResume'] ?? null;

        if (is_array($onResume) && count($onResume) === 1 && in_array($onResume[0]['type'] ?? null, ['BOOL', 'BOOLEAN'], true)) {
            $onResume[0] = self::put($onResume[0], 'description', $question);
            $task['onResume'] = $onResume;
        } elseif ($onResume === null && is_string($question) && $question !== '') {
            $task['onResume'] = [['id' => 'approved', 'type' => 'BOOL', 'description' => $question, 'defaults' => false]];
        }

        return $task;
    }

    /**
     * Değeri yazar; boş değer alanı kaldırır. Değer aynıysa görev değişmez (anahtar sırası korunur).
     *
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private static function put(array $task, string $key, mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            unset($task[$key]);

            return $task;
        }

        $task[$key] = $value;

        return $task;
    }

    /** Anahtar sırasından bağımsız, tip duyarlı karşılaştırma. */
    public static function same(mixed $a, mixed $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::normalize(...), $value);
    }
}
