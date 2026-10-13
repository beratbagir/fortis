<?php

namespace App\Services\Flows;

use App\Services\Apm\ApmApi;
use App\Services\Kestra\KestraClient;
use App\Services\Kestra\KestraException;
use App\Services\SuccessFactors\SuccessFactors;
use App\Services\Warehouse\Warehouse;
use Closure;
use DateTimeInterface;
use Symfony\Component\Yaml\Exception\DumpException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Kestra'ya giden her akışın geçtiği doğrulayıcı. Rol ve yetki olmadığı için tek güvenlik katmanı budur;
 * Kestra sunucusunda script, Docker, SSH ve dosya sistemi eklentileri kurulu olduğundan Kestra'nın kendi
 * doğrulaması yasaklı görevleri yakalamaz.
 *
 * İlke: izin listesi. Katalogda olmayan tip, izinli olmayan anahtar, fonksiyon, filtre veya etiket reddedilir.
 *
 * Ayrıştırma farkı önlemi: YAML ayrıştırılır, doğrulama ayrıştırılmış yapı üzerinde yapılır ve bu yapı yeniden
 * YAML'a yazılır (kanonik YAML). Kestra'ya yalnızca kanonik YAML gönderilir. Böylece anchor, alias, merge
 * anahtarı, özel tag veya tekrar eden anahtar gibi iki ayrıştırıcının farklı yorumlayabileceği yapılar Kestra'ya
 * ulaşmaz; kullanıcının kartta gördüğü, doğrulanan ve yüklenen metin aynıdır.
 */
class FlowValidator
{
    /** Öneri: tanımsız secret ve Kestra'ya ulaşılamaması uyarıdır. Yükleme: ikisi de hatadır. */
    public const MODE_PROPOSAL = 'proposal';

    public const MODE_UPLOAD = 'upload';

    private const MAX_BYTES = 100_000;

    private const FLOW_ID = '/^[a-z0-9][a-z0-9-]{0,99}$/';

    private const TOP_LEVEL_KEYS = [
        'id', 'namespace', 'description', 'labels', 'inputs', 'variables', 'outputs',
        'tasks', 'errors', 'finally', 'triggers', 'concurrency', 'disabled',
    ];

    private const VALUE_TYPES = [
        'STRING', 'INT', 'FLOAT', 'BOOL', 'BOOLEAN', 'DATE', 'DATETIME', 'TIME', 'DURATION',
        'EMAIL', 'URI', 'SELECT', 'MULTISELECT', 'ENUM', 'JSON', 'ARRAY',
    ];

    /**
     * Noktasız `type` değerleri (eklenti olmayan tipler) sadece bu bağlamlarda ve bu değerlerle kullanılabilir.
     * Bağlam, düğümün bağlı olduğu anahtardır (ör. inputs[0] için `inputs`, retry için `retry`).
     */
    private const CONTEXT_TYPES = [
        'inputs' => self::VALUE_TYPES,
        'onResume' => self::VALUE_TYPES,
        'outputs' => self::VALUE_TYPES,
        'retry' => ['constant', 'exponential', 'random'],
        'auth' => ['BASIC', 'BEARER'],
    ];

    /** Şablonlarda çağrılabilecek fonksiyonlar. `render`, `read`, `kv`, `http`, `fileURI`, `printContext`... yok. */
    private const FUNCTIONS = ['secret', 'now', 'fromJson', 'max', 'min', 'range', 'randomInt', 'uuid', 'currentEachOutput', 'errorLogs'];

    private const FILTERS = [
        'abbreviate', 'abs', 'base64decode', 'base64encode', 'capitalize', 'chunk', 'date', 'dateAdd', 'default',
        'distinct', 'first', 'flatten', 'indent', 'join', 'jq', 'json', 'keys', 'last', 'length', 'lower', 'merge',
        'nindent', 'number', 'numberFormat', 'replace', 'reverse', 'round', 'rsort', 'slice', 'slugify', 'sort',
        'split', 'string', 'substringAfter', 'substringAfterLast', 'substringBefore', 'substringBeforeLast',
        'timestamp', 'timestampMicro', 'timestampMilli', 'timestampNano', 'title', 'toJson', 'trim', 'upper',
        'urldecode', 'urlencode', 'values', 'yaml',
    ];

    private const TAGS = ['if', 'elseif', 'else', 'endif', 'for', 'endfor', 'set'];

    /** Şablonlarda kök değişken olarak kullanılamaz: ortam değişkenleri ve sunucu geneli değişkenler. */
    private const FORBIDDEN_ROOTS = ['envs', 'globals'];

    /** Pebble anahtar sözcükleri ve testleri; değişken sayılmaz. */
    private const KEYWORDS = [
        'and', 'or', 'not', 'is', 'in', 'true', 'false', 'null', 'none', 'empty', 'defined', 'equals', 'contains',
        'odd', 'even', 'iterable', 'map', 'loop', 'if', 'else', 'elseif', 'endif', 'for', 'endfor', 'set',
    ];

    /** Değeri sadece `{{ secret('...') }}` olabilecek anahtarlar (küçük harfle). */
    private const SECRET_KEYS = [
        'password', 'passwd', 'token', 'apikey', 'api_key', 'accesstoken', 'access_token', 'secret',
        'clientsecret', 'client_secret', 'authorization', 'privatekey', 'private_key', 'x-api-key',
        'basicauthpassword', 'proxypassword',
    ];

    /** Açık yazılmış gizli bilgi kalıpları. */
    private const SECRET_PATTERNS = [
        '/sk-ant-[A-Za-z0-9_-]{10,}/' => 'Anthropic API anahtarı',
        '/\bsk-[A-Za-z0-9]{20,}/' => 'API anahtarı',
        '/\bAKIA[0-9A-Z]{16}\b/' => 'AWS erişim anahtarı',
        '/\bgh[pousr]_[A-Za-z0-9]{30,}/' => 'GitHub token',
        '/\bxox[abposr]-[A-Za-z0-9-]{10,}/' => 'Slack token',
        '/\bAIza[0-9A-Za-z_-]{35}\b/' => 'Google API anahtarı',
        '#hooks\.slack\.com/(services|workflows|triggers)/#i' => 'Slack webhook adresi',
        '#webhook\.office\.com/#i' => 'Teams webhook adresi',
        '#\.logic\.azure\.com/#i' => 'Teams webhook adresi',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/' => 'özel anahtar',
        '/\beyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}/' => 'JWT token',
        '#\b[a-z][a-z0-9+.-]*://[^/\s:@{}]+:[^/\s@{}]+@#i' => 'adres içinde kullanıcı adı/şifre',
        '/\bBearer\s+[A-Za-z0-9._~+\/=-]{16,}/' => 'Bearer token',
    ];

    private const SECRET_ONLY = '/^\{\{\s*secret\(\s*([\'"])([A-Z][A-Z0-9_]*)\1\s*\)\s*\}\}$/';

    /** APM isteklerinde Bearer token: aynı akıştaki token görevinin çıktısı. */
    private const APM_TOKEN_REF = '/^\{\{\s*outputs\.([A-Za-z0-9_]+)\.body\s*\|\s*jq\(\s*([\'"])\.access_token\2\s*\)\s*\|\s*first\s*\}\}$/';

    /** @var Closure(string): list<string> */
    private Closure $resolveHost;

    /**
     * @param  list<string>  $blockedHosts  İstek atılamayacak ek sunucu adları (Kestra'nın ve Laravel'in kendisi).
     * @param  (Closure(string): list<string>)|null  $resolveHost  Sunucu adını IP'lere çözer (testte değiştirilir).
     */
    public function __construct(
        private readonly Catalog $catalog,
        private readonly KestraClient $kestra,
        private readonly array $blockedHosts = [],
        ?Closure $resolveHost = null,
        private readonly ?Warehouse $warehouse = null,
        private readonly ?SuccessFactors $successFactors = null,
        private readonly ?ApmApi $apm = null,
    ) {
        $this->resolveHost = $resolveHost ?? fn (string $host): array => gethostbynamel($host) ?: [];
    }

    /**
     * @param  string|null  $namespace  Beklenen namespace; verilmezse katalogdaki (fortis.chat). Test çalıştırmada
     *                                  kuru çalıştırma kopyası test namespace'iyle doğrulanır.
     * @param  string|null  $internalCallbackUri  Sadece Laravel'in ürettiği agent zamanlama akışında: Laravel'in iç ucuna
     *                                            (X-Fortis-Key secret'ıyla, POST) bu tam adrese istek izni.
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
    public function validate(string $yaml, string $mode = self::MODE_PROPOSAL, ?string $namespace = null, ?string $internalCallbackUri = null): array
    {
        $state = new ValidationState;
        $state->internalCallbackUri = $internalCallbackUri;

        $flow = $this->parse($yaml, $state);
        if ($flow === null) {
            return $state->result();
        }

        $canonical = $this->canonicalize($flow, $state);
        if ($canonical === null) {
            return $state->result();
        }
        $state->canonicalYaml = $canonical;
        $state->flow = $flow;

        $this->checkTopLevel($flow, $namespace ?? $this->catalog->namespace(), $state);
        $this->walk($flow, '', null, $state);

        $this->checkApmTokenUse($state);

        $missing = array_values(array_diff($state->secrets(), $this->catalog->secrets()));
        $state->missingSecrets = $missing;
        if ($missing !== [] && $mode === self::MODE_UPLOAD) {
            $state->error('Kestra\'da tanımlı olmayan secret\'lar kullanılıyor: '.implode(', ', $missing).'. Yöneticinin bunları eklemesi gerekiyor.');
        }

        if ($state->errors === []) {
            $this->kestraValidation($canonical, $mode, $state);
        }

        return $state->result();
    }

    /** @return array<string, mixed>|null */
    private function parse(string $yaml, ValidationState $state): ?array
    {
        if (strlen($yaml) > self::MAX_BYTES) {
            $state->error('YAML çok büyük (en fazla 100 KB).');

            return null;
        }

        try {
            $flow = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_DATETIME);
        } catch (ParseException $e) {
            $state->error('YAML geçersiz: '.$e->getMessage());

            return null;
        }

        if (! is_array($flow) || $flow === [] || array_is_list($flow)) {
            $state->error('YAML en üst seviyede bir nesne (id, namespace, tasks...) olmalı.');

            return null;
        }

        return $flow;
    }

    /** @param  array<string, mixed>  $flow */
    private function canonicalize(array &$flow, ValidationState $state): ?string
    {
        array_walk_recursive($flow, function (mixed &$value): void {
            if ($value instanceof DateTimeInterface) {
                $value = $value->format('c');
            }
        });

        try {
            $canonical = Yaml::dump($flow, 64, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_COMPACT_NESTED_MAPPING | Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE);
            $reparsed = Yaml::parse($canonical, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (DumpException|ParseException $e) {
            $state->error('YAML yeniden yazılamadı: '.$e->getMessage());

            return null;
        }

        if ($reparsed !== $flow) {
            $state->error('YAML yeniden yazıldığında aynı yapı elde edilemedi. Anchor, alias veya özel tag kullanmadan, düz YAML yaz.');

            return null;
        }

        return $canonical;
    }

    /** @param  array<string, mixed>  $flow */
    private function checkTopLevel(array $flow, string $namespace, ValidationState $state): void
    {
        foreach (array_keys($flow) as $key) {
            if (! in_array($key, self::TOP_LEVEL_KEYS, true)) {
                $state->error("En üst seviyede '{$key}' alanı kullanılamaz. İzinli alanlar: ".implode(', ', self::TOP_LEVEL_KEYS).'.');
            }
        }

        if (! is_string($flow['id'] ?? null) || ! preg_match(self::FLOW_ID, $flow['id'])) {
            $state->error('id zorunlu; küçük harf, rakam ve tire içerebilir (ör. site-health-check).');
        }

        if (($flow['namespace'] ?? null) !== $namespace) {
            $state->error("namespace tam olarak '{$namespace}' olmalı.");
        }

        if (! is_array($flow['tasks'] ?? null) || $flow['tasks'] === [] || ! array_is_list($flow['tasks'])) {
            $state->error('tasks en az bir görev içeren bir liste olmalı.');
        }
    }

    /**
     * Ağacın tamamını dolaşır: her düğümde `type`, her anahtarda ve her metin değerde kontrol yapılır.
     *
     * @param  array<array-key, mixed>  $node
     */
    private function walk(array $node, string $path, ?string $context, ValidationState $state): void
    {
        if (array_key_exists('type', $node)) {
            $this->checkType($node, $path, $context, $state);
        }

        foreach ($node as $key => $child) {
            $childPath = is_int($key) ? "{$path}[{$key}]" : ltrim("{$path}.{$key}", '.');

            if (is_string($key)) {
                $this->checkString($key, "{$childPath} (anahtar)", null, $state);
            }

            if (is_array($child)) {
                // Liste elemanları için bağlam, listenin bağlı olduğu anahtardır (ör. inputs[0] -> inputs).
                $this->walk($child, $childPath, is_int($key) ? $context : $key, $state);
            } elseif (is_string($child)) {
                $this->checkString($child, $childPath, is_string($key) ? $key : null, $state);
            }
        }
    }

    /** @param  array<array-key, mixed>  $node */
    private function checkType(array $node, string $path, ?string $context, ValidationState $state): void
    {
        $where = $path === '' ? '(kök)' : $path;
        $type = $node['type'];

        if (! is_string($type)) {
            $state->error("{$where}: type bir metin olmalı.");

            return;
        }

        if (! str_contains($type, '.')) {
            if ($context === null || ! in_array($type, self::CONTEXT_TYPES[$context] ?? [], true)) {
                $state->error("{$where}: '{$type}' tipi burada kullanılamaz.");
            }

            return;
        }

        if (! $this->catalog->isAllowed($type)) {
            $state->error("{$where}: '{$type}' tipi izinli değil. Sadece katalogdaki tipler kullanılabilir.");

            return;
        }

        $state->plugins[] = [
            'path' => $where,
            'id' => is_string($node['id'] ?? null) ? $node['id'] : null,
            'type' => $type,
            'node' => $node,
        ];

        match ($type) {
            'io.kestra.plugin.core.http.Request' => $this->checkHttpRequest($node, $where, $state),
            'io.kestra.plugin.email.MailSend' => $this->checkMail($node, $where, $state),
            'io.kestra.plugin.slack.notifications.SlackIncomingWebhook',
            'io.kestra.plugin.microsoft365.teams.TeamsIncomingWebhook' => $this->requireSecretOnly($node, ['url'], $where, $state, required: true),
            'io.kestra.plugin.core.flow.Subflow' => $this->checkSubflow($node, $where, $state),
            'io.kestra.plugin.core.kv.Set', 'io.kestra.plugin.core.kv.Get', 'io.kestra.plugin.core.kv.Delete' => $this->checkKv($node, $where, $state),
            'io.kestra.plugin.core.trigger.Webhook' => $this->checkWebhook($node, $where, $state),
            'io.kestra.plugin.jdbc.mysql.Query' => $this->checkWarehouseQuery($node, $where, $state),
            default => null,
        };
    }

    /** @param  array<array-key, mixed>  $node */
    private function checkHttpRequest(array $node, string $where, ValidationState $state): void
    {
        $uri = $node['uri'] ?? null;

        if (! is_string($uri) || ! preg_match('#^(https?)://([^/?\#\s]+)#i', $uri, $m)) {
            $state->error("{$where}: uri sabit bir http:// veya https:// adresiyle başlamalı.");

            return;
        }

        $authority = $m[2];

        if ($state->internalCallbackUri !== null && $uri === $state->internalCallbackUri) {
            $this->checkInternalCallback($node, $where, $state);

            return;
        }

        if (str_contains($authority, '{') || str_contains($authority, '}')) {
            $state->error("{$where}: adresin sunucu kısmı ifade ({{ }}) içeremez; istek atılacak sunucu sabit olmalı.");

            return;
        }

        foreach (array_keys(is_array($node['options'] ?? null) ? $node['options'] : []) as $option) {
            if (str_starts_with(strtolower((string) $option), 'proxy')) {
                $state->error("{$where}: options.{$option} kullanılamaz.");
            }
        }

        // APM iç ağda; tünel adresi (APM_KESTRA_BASE_URL) iç IP kontrolünden bilerek muaf, yerine APM kuralları uygulanır.
        if ($this->apm?->isApmUrl($uri)) {
            $this->checkApmRequest($node, $uri, $where, $state);

            return;
        }

        if (str_contains($authority, '@') || str_contains($authority, '\\')) {
            $state->error("{$where}: adres kullanıcı adı, şifre veya ters bölü içeremez.");

            return;
        }

        if (str_starts_with($authority, '[')) {
            $state->error("{$where}: IPv6 adreslerine istek atılamaz; sunucu adı kullan.");

            return;
        }

        $host = strtolower(preg_replace('/:\d+$/', '', $authority) ?? '');

        if ($reason = $this->blockedHostReason($host)) {
            $state->error("{$where}: {$host} adresine istek atılamaz ({$reason}).");
        }

        if ($this->successFactors?->isSuccessFactorsUrl($uri)) {
            $this->checkSuccessFactorsRequest($node, $uri, $where, $state);
        }
    }

    /**
     * APM (iç ağ, tünel üzerinden): uç spec'te olmalı, yazma kapalıyken sadece okuma, token isteğinde kimlik
     * bilgileri secret'tan, diğer isteklerde Bearer token aynı akıştaki token görevinden.
     *
     * @param  array<array-key, mixed>  $node
     */
    private function checkApmRequest(array $node, string $uri, string $where, ValidationState $state): void
    {
        $method = strtoupper(is_string($node['method'] ?? null) ? $node['method'] : 'GET');

        if (preg_match('#(^|/)\.\.?(/|$)|%2e|%2f#i', $this->apm->pathOf($uri))) {
            $state->error("{$where}: APM adresinde '..', '.' veya kodlanmış bölü olamaz.");

            return;
        }

        $operation = $this->apm->operationFor($method, $uri);

        if ($operation === null) {
            $state->error("{$where}: APM'de böyle bir uç yok ({$method} {$this->apm->pathOf($uri)}). describe_apm ile işlemlere bak.");

            return;
        }

        if (! $this->apm->allowWrites() && ! $this->apm->isReadOnly($method, $operation['path'])) {
            $state->error("{$where}: APM'ye sadece okuma yapılabilir; {$method} {$operation['path']} bir yazma işlemi ve yazma kapalı.");
        }

        if ($this->apm->isTokenOperation($method, $operation['path'])) {
            $form = $node['formData'] ?? null;
            $grant = $this->apm->grantType();

            if (! is_array($form) || ($form['grant_type'] ?? null) !== $grant) {
                $state->error("{$where}: APM token isteğinde formData.grant_type '{$grant}' olmalı.");
            }

            $fields = $grant === 'password' ? ['username', 'password'] : ['client_id', 'client_secret'];
            $this->requireSecretOnly(is_array($form) ? $form : [], $fields, "{$where}.formData", $state, required: true);

            if (stripos((string) ($node['contentType'] ?? ''), 'application/x-www-form-urlencoded') !== 0) {
                $state->error("{$where}: APM token isteğinde contentType: application/x-www-form-urlencoded olmalı.");
            }

            if (is_string($node['id'] ?? null)) {
                $state->apmTokenTasks[] = $node['id'];
            }

            return;
        }

        $auth = $node['options']['auth'] ?? null;
        if (! is_array($auth) || ($auth['type'] ?? null) !== 'BEARER' || ! is_string($auth['token'] ?? null)
            || ! preg_match(self::APM_TOKEN_REF, $auth['token'], $m)) {
            $state->error("{$where}: APM isteğinde options.auth: {type: BEARER, token: \"{{ outputs.<token_görevi>.body | jq('.access_token') | first }}\"} olmalı.");

            return;
        }

        $state->apmTokenRefs[] = ['where' => $where, 'task' => $m[1]];
    }

    /**
     * APM token'ı yalnızca APM isteklerinin options.auth.token alanında kullanılabilir; başka bir yere
     * (log, bildirim, dış adrese istek) yazılırsa sızar.
     */
    private function checkApmTokenUse(ValidationState $state): void
    {
        foreach ($state->apmTokenRefs as $ref) {
            if (! in_array($ref['task'], $state->apmTokenTasks, true)) {
                $state->error("{$ref['where']}: Bearer token, akıştaki bir APM token görevinden (POST .../oauth/token) gelmeli; '{$ref['task']}' öyle bir görev değil.");
            }
        }

        if ($state->apmTokenTasks === []) {
            return;
        }

        $allowed = array_map(fn (array $ref) => "{$ref['where']}.options.auth.token", $state->apmTokenRefs);

        foreach ($state->outputRefs as $ref) {
            if (($ref['task'] === null || in_array($ref['task'], $state->apmTokenTasks, true)) && ! in_array($ref['path'], $allowed, true)) {
                $what = $ref['task'] === null ? 'outputs (tümü)' : "outputs.{$ref['task']}";
                $state->error("{$ref['path']}: {$what} APM token'ı içerir; sadece APM isteklerinin options.auth.token alanında kullanılabilir.");
            }
        }
    }

    /**
     * SuccessFactors (İK sistemi): kimlik bilgisi secret'tan basic auth, yazma kapalıyken sadece GET, JSON yanıt.
     *
     * @param  array<array-key, mixed>  $node
     */
    private function checkSuccessFactorsRequest(array $node, string $uri, string $where, ValidationState $state): void
    {
        $method = strtoupper(is_string($node['method'] ?? null) ? $node['method'] : 'GET');

        if ($method !== 'GET' && ! $this->successFactors->allowWrites()) {
            $state->error("{$where}: SuccessFactors'a sadece okuma (GET) yapılabilir; yazma işlemleri (upsert vb.) kapalı.");
        }

        $auth = $node['options']['auth'] ?? null;
        if (! is_array($auth) || ($auth['type'] ?? null) !== 'BASIC') {
            $state->error("{$where}: SuccessFactors isteğinde options.auth (type: BASIC, username ve password secret'tan) zorunlu.");
        } else {
            $this->requireSecretOnly($auth, ['username', 'password'], "{$where}.options.auth", $state, required: true);
        }

        if (SuccessFactors::mentionsNationalId($uri)) {
            $state->error("{$where}: TC kimlik bilgisi (PerNationalId, nationalIdNav, nationalId) SuccessFactors'tan istenemez.");
        }

        if (stripos($uri, '$format=json') === false && stripos($uri, '/$count') === false) {
            $state->error("{$where}: SuccessFactors adresine \$format=JSON eklenmeli (varsayılan yanıt XML'dir).");
        }
    }

    /**
     * Laravel'in iç ucuna istek: sadece POST, anahtar başlığı yalnızca secret ifadesi.
     *
     * @param  array<array-key, mixed>  $node
     */
    private function checkInternalCallback(array $node, string $where, ValidationState $state): void
    {
        if (strtoupper((string) ($node['method'] ?? 'GET')) !== 'POST') {
            $state->error("{$where}: iç uca sadece POST isteği atılabilir.");
        }

        $key = $node['headers']['X-Fortis-Key'] ?? null;
        if (! is_string($key) || ! preg_match(self::SECRET_ONLY, $key)) {
            $state->error("{$where}: X-Fortis-Key başlığı sadece secret('…') ifadesi olabilir.");
        }
    }

    private function blockedHostReason(string $host): ?string
    {
        if (in_array($host, array_map('strtolower', $this->blockedHosts), true)) {
            return 'sistemin kendi sunucusu';
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host) ? null : 'iç ağ veya özel adres';
        }

        if (! str_contains($host, '.') || preg_match('/^[0-9a-fx.]+$/', $host)) {
            return 'geçersiz veya iç ağ sunucu adı';
        }

        if (preg_match('/(^|\.)(localhost|local|internal|localdomain|home\.arpa)$/', $host)) {
            return 'iç ağ sunucu adı';
        }

        foreach (($this->resolveHost)($host) as $ip) {
            if (! $this->isPublicIp($ip)) {
                return 'iç ağ adresine çözümleniyor';
            }
        }

        return null;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    /** @param  array<array-key, mixed>  $node */
    private function checkMail(array $node, string $where, ValidationState $state): void
    {
        $this->requireSecretOnly($node, ['host', 'username', 'password'], $where, $state, required: true);
        $this->requireSecretOnly($node, ['port', 'from', 'accessToken'], $where, $state, required: false);

        foreach (['attachments', 'embeddedImages'] as $field) {
            if (array_key_exists($field, $node)) {
                $state->error("{$where}: {$field} kullanılamaz.");
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $fields
     */
    private function requireSecretOnly(array $node, array $fields, string $where, ValidationState $state, bool $required): void
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $node)) {
                if ($required) {
                    $state->error("{$where}: {$field} zorunlu ve bir secret olmalı: \"{{ secret('İSİM') }}\".");
                }

                continue;
            }

            if (! is_string($node[$field]) || ! preg_match(self::SECRET_ONLY, $node[$field])) {
                $state->error("{$where}: {$field} sadece bir secret olabilir: \"{{ secret('İSİM') }}\".");
            }
        }
    }

    /** @param  array<array-key, mixed>  $node */
    private function checkSubflow(array $node, string $where, ValidationState $state): void
    {
        if (($node['namespace'] ?? null) !== $this->catalog->namespace()) {
            $state->error("{$where}: alt akış sadece '{$this->catalog->namespace()}' namespace'inden çağrılabilir.");
        }

        if (! is_string($node['flowId'] ?? null) || ! preg_match(self::FLOW_ID, $node['flowId'])) {
            $state->error("{$where}: flowId sabit bir akış id'si olmalı.");
        }
    }

    /** @param  array<array-key, mixed>  $node */
    private function checkKv(array $node, string $where, ValidationState $state): void
    {
        if (array_key_exists('namespace', $node)) {
            $state->error("{$where}: anahtar-değer görevlerinde namespace verilemez; akış kendi namespace'ini kullanır.");
        }
    }

    /**
     * Veri ambarı sorgusu: bağlantı secret'tan, sonuç modu sınırlı, yazma yapabilen özellikler yasak, SQL kuralları
     * (SqlGuard) ve ambarda EXPLAIN ile çalıştırmadan deneme.
     *
     * @param  array<array-key, mixed>  $node
     */
    private function checkWarehouseQuery(array $node, string $where, ValidationState $state): void
    {
        $this->requireSecretOnly($node, ['url', 'username', 'password'], $where, $state, required: true);

        foreach (['afterSQL', 'inputFile', 'outputFiles', 'store', 'fetch', 'fetchOne'] as $field) {
            if (array_key_exists($field, $node)) {
                $state->error("{$where}: {$field} kullanılamaz.");
            }
        }

        $fetchType = $node['fetchType'] ?? null;
        if (! in_array($fetchType, ['FETCH', 'FETCH_ONE'], true)) {
            $state->error("{$where}: fetchType FETCH (tüm satırlar) veya FETCH_ONE (tek satır) olmalı.");
        }

        $sql = $node['sql'] ?? null;
        if (! is_string($sql) || trim($sql) === '') {
            $state->error("{$where}: sql zorunlu bir metin.");

            return;
        }

        if ($this->warehouse === null) {
            $state->error("{$where}: veri ambarı bu ortamda tanımlı değil.");

            return;
        }

        $check = $this->warehouse->guard()->check($sql, requireLimit: $fetchType === 'FETCH');
        foreach ($check['errors'] as $error) {
            $state->error("{$where}: {$error}");
        }

        $parameters = $node['parameters'] ?? [];
        if (! is_array($parameters) || ($parameters !== [] && array_is_list($parameters))) {
            $state->error("{$where}: parameters ad => değer eşlemesi olmalı.");
            $parameters = [];
        }

        $missing = array_diff($check['placeholders'], array_map('strval', array_keys($parameters)));
        $unused = array_diff(array_map('strval', array_keys($parameters)), $check['placeholders']);
        if ($missing !== []) {
            $state->error("{$where}: SQL'deki şu yer tutucuların parameters'ta değeri yok: :".implode(', :', $missing).'.');
        }
        if ($unused !== []) {
            $state->error("{$where}: parameters'taki şu adlar SQL'de kullanılmıyor: ".implode(', ', $unused).'.');
        }
        foreach ($parameters as $name => $value) {
            if (! is_scalar($value) && $value !== null) {
                $state->error("{$where}: parameters.{$name} bir metin veya sayı olmalı.");
            }
        }

        if ($check['errors'] !== []) {
            return;
        }

        $explain = $this->warehouse->explain($sql);
        if ($explain === null) {
            $state->warning("{$where}: SQL veri ambarında denenemedi (bağlantı yok). Hatalar çalışma anında görülebilir.");
        } elseif (! $explain['ok']) {
            $state->error("{$where}: SQL veri ambarında çalışmıyor: {$explain['error']}");
        } else {
            foreach ($explain['warnings'] as $warning) {
                $state->warning("{$where}: {$warning}");
            }
        }
    }

    /** @param  array<array-key, mixed>  $node */
    private function checkWebhook(array $node, string $where, ValidationState $state): void
    {
        if (! is_string($node['key'] ?? null) || ! preg_match('/^[A-Za-z0-9_-]{24,64}$/', $node['key'])) {
            $state->error("{$where}: webhook key'i 24-64 karakter, harf, rakam, - ve _ içeren rastgele bir değer olmalı.");
        }
    }

    private function checkString(string $value, string $path, ?string $key, ValidationState $state): void
    {
        if ($key !== null && in_array(strtolower($key), self::SECRET_KEYS, true) && ! $this->isSecretValue($value)) {
            $state->error("{$path}: '{$key}' değeri açık yazılamaz; \"{{ secret('İSİM') }}\" kullan.");
        }

        foreach (self::SECRET_PATTERNS as $pattern => $label) {
            if (preg_match($pattern, $value)) {
                $state->error("{$path}: açık yazılmış {$label} var. Gizli bilgiler için secret kullan.");
            }
        }

        if (str_contains($value, '{{') || str_contains($value, '{%') || str_contains($value, '}}') || str_contains($value, '%}')) {
            $this->checkTemplate($value, $path, $state);

            preg_match_all('/\boutputs\b(?:\s*\.\s*([A-Za-z0-9_]+)|\s*\[\s*([\'"])([A-Za-z0-9_]+)\2)?/', $value, $refs, PREG_SET_ORDER);
            foreach ($refs as $ref) {
                $task = ($ref[1] ?? '') !== '' ? $ref[1] : (($ref[3] ?? '') !== '' ? $ref[3] : null);
                $state->outputRefs[] = ['path' => $path, 'task' => $task];
            }
        }
    }

    /**
     * Gizli alanların değeri: boş, tek bir secret veya "Bearer {{ secret('X') }}" gibi önekli bir secret.
     */
    private function isSecretValue(string $value): bool
    {
        if (trim($value) === '') {
            return true;
        }

        // APM Bearer token: aynı akıştaki token görevinin çıktısı (checkApmRequest ayrıca doğrular).
        if (preg_match(self::APM_TOKEN_REF, trim($value))) {
            return true;
        }

        if (! preg_match('/\{\{\s*secret\(\s*([\'"])[A-Z][A-Z0-9_]*\1\s*\)\s*\}\}/', $value)) {
            return false;
        }

        $literal = trim((string) preg_replace('/\{\{.*?\}\}/s', '', $value));

        return in_array($literal, ['', 'Bearer', 'Basic', 'Token', 'token'], true);
    }

    /**
     * Pebble şablonunu izin listesine göre kontrol eder. Metin sabitleri çıkarıldıktan sonra kalan
     * kodda fonksiyonlar, filtreler, metot çağrıları, etiketler ve kök değişkenler incelenir.
     */
    private function checkTemplate(string $value, string $path, ValidationState $state): void
    {
        $segments = $this->templateSegments($value, $path, $state);

        foreach ($segments as [$kind, $body]) {
            if ($kind === 'tag') {
                $tag = preg_match('/^\s*([a-zA-Z_]\w*)/', $body, $m) ? $m[1] : '';
                if (! in_array($tag, self::TAGS, true)) {
                    $state->error("{$path}: '{% {$tag} %}' etiketi kullanılamaz.");
                }
                $body = (string) preg_replace('/^\s*[a-zA-Z_]\w*/', '', $body);
            }

            $this->checkExpression($body, $path, $state);
        }
    }

    /** @return list<array{0: 'expr'|'tag', 1: string}> */
    private function templateSegments(string $value, string $path, ValidationState $state): array
    {
        $segments = [];
        $offset = 0;

        while (preg_match('/\{\{|\{%|\{#/', $value, $m, PREG_OFFSET_CAPTURE, $offset)) {
            [$open, $start] = $m[0];
            $close = ['{{' => '}}', '{%' => '%}', '{#' => '#}'][$open];
            $end = strpos($value, $close, $start + 2);

            if ($end === false) {
                $state->error("{$path}: kapanmamış ifade ({$open} ... {$close}).");

                return $segments;
            }

            if ($open !== '{#') {
                $segments[] = [$open === '{{' ? 'expr' : 'tag', substr($value, $start + 2, $end - $start - 2)];
            }
            $offset = $end + 2;
        }

        $rest = (string) preg_replace('/\{\{.*?\}\}|\{%.*?%\}|\{#.*?#\}/s', '', $value);
        if (str_contains($rest, '}}') || str_contains($rest, '%}')) {
            $state->error("{$path}: eşleşmeyen ifade kapanışı.");
        }

        return $segments;
    }

    private function checkExpression(string $body, string $path, ValidationState $state): void
    {
        // secret() sadece sabit ve geçerli bir isimle çağrılabilir.
        if (preg_match_all('/\bsecret\s*\(/', $body) !== preg_match_all('/\bsecret\s*\(\s*([\'"])([A-Z][A-Z0-9_]*)\1\s*\)/', $body, $secretCalls)) {
            $state->error("{$path}: secret() sadece sabit bir isimle kullanılabilir: secret('İSİM').");
        }
        foreach ($secretCalls[2] as $name) {
            $state->addSecret($name);
        }

        // Metin sabitlerini çıkar: içlerindeki kelimeler kod değildir.
        $code = (string) preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', '""', $body);

        if (preg_match('/[\'"`]/', str_replace('""', '', $code))) {
            $state->error("{$path}: ifadede kapanmamış metin sabiti var.");

            return;
        }

        preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)/', $code, $words, PREG_OFFSET_CAPTURE);

        foreach ($words[1] as [$word, $offset]) {
            $before = rtrim(substr($code, 0, $offset));
            $previous = $before === '' ? '' : substr($before, -1);
            $after = ltrim(substr($code, $offset + strlen($word)));
            $isCall = str_starts_with($after, '(');

            // Anahtar sözcükler parantezle kullanılabilir: "x and (y > 1)", "not (a)". Fonksiyon değildir.
            if ($previous !== '.' && $previous !== '|' && in_array($word, self::KEYWORDS, true)) {
                continue;
            }

            if ($previous === '.') {
                if ($isCall) {
                    $state->error("{$path}: ifadede metot çağrısı ({$word}()) kullanılamaz.");
                }

                continue;
            }

            if ($previous === '|') {
                if (! in_array($word, self::FILTERS, true)) {
                    $state->error("{$path}: '{$word}' filtresi izinli değil.");
                }

                continue;
            }

            if ($isCall) {
                if (! in_array($word, self::FUNCTIONS, true)) {
                    $state->error("{$path}: '{$word}()' fonksiyonu izinli değil.");
                }

                continue;
            }

            if (str_starts_with($word, '_') || in_array($word, self::FORBIDDEN_ROOTS, true)) {
                $state->error("{$path}: '{$word}' değişkeni kullanılamaz.");
            }
        }

        if (str_contains($code, '[') && preg_match('/\b(envs|globals)\s*\[/', $code)) {
            $state->error("{$path}: ortam veya sunucu değişkenlerine erişilemez.");
        }
    }

    private function kestraValidation(string $canonical, string $mode, ValidationState $state): void
    {
        try {
            $results = $this->kestra->validateFlow($canonical);
        } catch (KestraException $e) {
            $message = "Kestra doğrulaması yapılamadı: {$e->getMessage()}";
            $mode === self::MODE_UPLOAD ? $state->error($message) : $state->warning($message);

            return;
        }

        foreach ($results as $result) {
            if (! empty($result['constraints'])) {
                $state->error('Kestra: '.$result['constraints']);
            }
            foreach ($result['warnings'] ?? [] as $warning) {
                $state->warning('Kestra: '.$warning);
            }
            foreach ($result['deprecationPaths'] ?? [] as $deprecated) {
                $state->warning("Kestra: kullanımdan kalkmış alan: {$deprecated}");
            }
        }
    }
}
