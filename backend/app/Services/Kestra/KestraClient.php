<?php

namespace App\Services\Kestra;

use App\Services\Privacy\NationalIdMasker;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Laravel → Kestra iletişiminin tek noktası.
 *
 * Uç noktaların yolları sadece bu sınıfta tutulur. Referans: infra/kestra-api/api-*.yml
 * (sunucudan ve imajdan alınmış OpenAPI dökümanları).
 */
class KestraClient
{
    /** Bir çalışmanın bundan sonra değişmeyeceği durumlar. */
    public const TERMINAL_STATES = ['SUCCESS', 'WARNING', 'FAILED', 'KILLED', 'CANCELLED', 'SKIPPED', 'RETRIED'];

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $username,
        private readonly ?string $password,
        private readonly string $tenant = 'main',
        private readonly string $version = '2.0.4',
        private readonly int $timeout = 15,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: config('kestra.url'),
            username: config('kestra.username'),
            password: config('kestra.password'),
            tenant: config('kestra.tenant'),
            version: config('kestra.version'),
            timeout: config('kestra.timeout'),
        );
    }

    // ---- Sunucu ----------------------------------------------------------

    /** Sürüm ve sunucu bilgisi. Kimlik doğrulama gerektirmez. */
    public function serverInfo(): array
    {
        return $this->send('sunucu bilgisi', fn (PendingRequest $r) => $r->get('/api/v1/configs'))->json();
    }

    /** Kimlik bilgilerini doğrular; geçersizse KestraException fırlatır. */
    public function assertAuthenticated(): void
    {
        $this->searchFlows(size: 1);
    }

    // ---- Akışlar ---------------------------------------------------------

    /**
     * Kestra'nın kendi doğrulaması. Her akış için bir sonuç döner;
     * `constraints` doluysa akış geçersizdir.
     *
     * @return array<int, array{constraints?: ?string, warnings?: array, infos?: array, deprecationPaths?: array, outdated?: bool}>
     */
    public function validateFlow(string $yaml): array
    {
        return $this->send('akış doğrulama', fn (PendingRequest $r) => $r
            ->withBody($yaml, 'application/x-yaml')
            ->post($this->api('flows/validate')))->json();
    }

    public function createFlow(string $yaml): array
    {
        return $this->send('akış oluşturma', fn (PendingRequest $r) => $r
            ->withBody($yaml, 'application/x-yaml')
            ->post($this->api('flows')))->json();
    }

    /** Mevcut akışın yeni revision'ını oluşturur. */
    public function updateFlow(string $namespace, string $flowId, string $yaml): array
    {
        return $this->send('akış güncelleme', fn (PendingRequest $r) => $r
            ->withBody($yaml, 'application/x-yaml')
            ->put($this->api("flows/{$namespace}/{$flowId}")))->json();
    }

    /** Akışı getirir; `source` alanında YAML bulunur. Yoksa null. */
    public function getFlow(string $namespace, string $flowId): ?array
    {
        $response = $this->send(
            'akış getirme',
            fn (PendingRequest $r) => $r->get($this->api("flows/{$namespace}/{$flowId}"), ['source' => 'true']),
            allowNotFound: true,
        );

        return $response->status() === 404 ? null : $response->json();
    }

    public function flowExists(string $namespace, string $flowId): bool
    {
        return $this->getFlow($namespace, $flowId) !== null;
    }

    public function deleteFlow(string $namespace, string $flowId): void
    {
        $this->send('akış silme', fn (PendingRequest $r) => $r->delete($this->api("flows/{$namespace}/{$flowId}")));
    }

    /**
     * Akışları aktif veya pasif yapar. Pasif akışın tetikleyicileri (zamanlayıcı, webhook) çalışmaz.
     *
     * @param  list<array{0: string, 1: string}>  $flows  [namespace, flowId] çiftleri
     */
    public function setFlowsEnabled(array $flows, bool $enabled): void
    {
        $body = array_map(fn (array $flow) => ['namespace' => $flow[0], 'id' => $flow[1]], $flows);

        $this->send($enabled ? 'akış aktifleştirme' : 'akış pasifleştirme', fn (PendingRequest $r) => $r
            ->post($this->api($enabled ? 'flows/enable/by-ids' : 'flows/disable/by-ids'), $body));
    }

    /** @return array{results: array, total: int} */
    public function searchFlows(?string $namespacePrefix = null, int $page = 1, int $size = 50): array
    {
        $query = ['page' => $page, 'size' => $size];

        // 2.0'da `namespace` parametresi kaldırıldı; PHP tarzı filters iki sürümde de çalışır.
        if ($namespacePrefix !== null) {
            $query['filters[namespace][PREFIX]'] = $namespacePrefix;
        }

        return $this->send('akış listeleme', fn (PendingRequest $r) => $r->get($this->api('flows/search'), $query))->json();
    }

    // ---- Çalışmalar ------------------------------------------------------

    /** Akışı başlatır. Kestra girdileri multipart/form-data olarak bekler. */
    public function createExecution(string $namespace, string $flowId, array $inputs = [], array $labels = []): array
    {
        $query = array_map(fn ($k, $v) => "{$k}:{$v}", array_keys($labels), $labels);

        return $this->send('çalıştırma', function (PendingRequest $r) use ($namespace, $flowId, $inputs, $query) {
            foreach ($inputs as $name => $value) {
                $r = $r->attach($name, (string) $value);
            }

            return $r->asMultipart()->post($this->api("executions/{$namespace}/{$flowId}").$this->labelQuery($query));
        })->json();
    }

    public function getExecution(string $executionId): array
    {
        // TC kimlik numaraları (girdi, döngü değeri, çıktı) Fortis'e maskeli gelir.
        return NationalIdMasker::maskArray($this->send('çalışma durumu', fn (PendingRequest $r) => $r->get($this->api("executions/{$executionId}")))->json());
    }

    /**
     * Çalışmaları en yeniden eskiye listeler: bir akışın veya (flowId verilmezse) namespace'in tamamının.
     *
     * @return array{results: list<array<string, mixed>>, total: int}
     */
    public function searchExecutions(string $namespace, ?string $flowId = null, int $size = 5): array
    {
        return $this->send('çalışma listeleme', fn (PendingRequest $r) => $r->get($this->api('executions/search'), array_filter([
            'filters[namespace][EQUALS]' => $namespace,
            'filters[flowId][EQUALS]' => $flowId,
            'sort' => 'state.startDate:desc',
            'size' => $size,
            'page' => 1,
        ], fn ($value) => $value !== null)))->json();
    }

    /**
     * Çalışmalar ekranı: namespace önekiyle (fortis.chat ve fortis.chat.test), isteğe bağlı akış ve durum filtresiyle,
     * sayfalı arama. (Kestra 1.3 tarih filtresini yok sayıyor; tarih süzmesi çağıranda yapılır.)
     *
     * @return array{results: list<array<string, mixed>>, total: int}
     */
    public function findExecutions(string $namespacePrefix, ?string $flowId = null, ?string $state = null, int $page = 1, int $size = 20, ?CarbonInterface $startedAfter = null): array
    {
        return $this->send('çalışma listeleme', fn (PendingRequest $r) => $r->get($this->api('executions/search'), array_filter([
            'filters[namespace][PREFIX]' => $namespacePrefix,
            'filters[flowId][EQUALS]' => $flowId,
            'filters[state][EQUALS]' => $state,
            'filters[startDate][GREATER_THAN_OR_EQUAL_TO]' => $startedAfter?->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'sort' => 'state.startDate:desc',
            'size' => $size,
            'page' => $page,
        ], fn ($value) => $value !== null)))->json();
    }

    /**
     * Akışların son çalışmaları (görev listesi olmadan; hafif): id, flowId, namespace, startDate, status.
     *
     * @param  list<array{namespace: string, id: string}>  $flows
     * @return list<array{id: string, flowId: string, namespace: string, startDate: string|null, status: string|null}>
     */
    public function latestExecutions(array $flows): array
    {
        if ($flows === []) {
            return [];
        }

        return $this->send('son çalışmalar', fn (PendingRequest $r) => $r->post($this->api('executions/latest'), $flows))->json() ?? [];
    }

    /** Bir namespace'te tarih aralığında başlayan çalışma sayısı (sadece toplam okunur). */
    public function countExecutions(string $namespace, CarbonInterface $from, CarbonInterface $to): int
    {
        $format = fn (CarbonInterface $date) => $date->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');

        return (int) ($this->send('çalışma sayma', fn (PendingRequest $r) => $r->get($this->api('executions/search'), [
            'filters[namespace][EQUALS]' => $namespace,
            'filters[startDate][GREATER_THAN_OR_EQUAL_TO]' => $format($from),
            // Aralığın sonu endDate filtresiyle verilir; aynı alana iki filtre (startDate GTE + LT) Kestra'da birleşmiyor.
            'filters[endDate][LESS_THAN_OR_EQUAL_TO]' => $format($to),
            'size' => 1,
            'page' => 1,
        ]))->json('total') ?? 0);
    }

    /** Pause görevinde bekleyen çalışmayı devam ettirir. */
    public function resumeExecution(string $executionId, array $inputs = []): void
    {
        $this->send('devam ettirme', function (PendingRequest $r) use ($executionId, $inputs) {
            foreach ($inputs as $name => $value) {
                $r = $r->attach($name, (string) $value);
            }

            return $r->asMultipart()->post($this->api($this->executionAction($executionId, 'resume')));
        });
    }

    public function killExecution(string $executionId): void
    {
        // Bu uç başarılı yanıtı `text/json` olarak döndürür; `application/json` isteği 406 alır (1.3 ve 2.0).
        $this->send('durdurma', fn (PendingRequest $r) => $r
            ->replaceHeaders(['Accept' => 'text/json, application/json, application/problem+json'])
            ->delete($this->api($this->executionAction($executionId, 'kill'))));
    }

    /**
     * Seviye filtresi Laravel tarafında uygulanır: 1.x `minLevel`, 2.0 `filters` bekliyor.
     *
     * @return array<int, array{taskId?: string, level: string, message: string, timestamp: string}>
     */
    public function getExecutionLogs(string $executionId, string $minLevel = 'INFO'): array
    {
        $levels = ['TRACE', 'DEBUG', 'INFO', 'WARN', 'ERROR'];
        $min = array_search($minLevel, $levels, true) ?: 0;

        $logs = $this->send('log okuma', fn (PendingRequest $r) => $r->get($this->api("logs/{$executionId}")))->json();

        return array_values(array_filter(
            NationalIdMasker::maskArray($logs),
            fn (array $log) => (array_search($log['level'] ?? 'INFO', $levels, true) ?: 0) >= $min,
        ));
    }

    /** Webhook tetikleyicili bir akışın tetiklenme adresi. */
    public function webhookUrl(string $namespace, string $flowId, string $key): string
    {
        return $this->baseUrl.$this->api("executions/webhook/{$namespace}/{$flowId}/{$key}");
    }

    public static function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL_STATES, true);
    }

    // ---- İç yardımcılar --------------------------------------------------

    private function api(string $path): string
    {
        return "/api/v1/{$this->tenant}/{$path}";
    }

    /** 2.0'da resume/kill gibi eylemler /executions/{id}/actions/{eylem} altına taşındı. */
    private function executionAction(string $executionId, string $action): string
    {
        return version_compare(ltrim($this->version, 'v'), '2.0.0', '>=')
            ? "executions/{$executionId}/actions/{$action}"
            : "executions/{$executionId}/{$action}";
    }

    /** Kestra etiketleri `labels=key:value` şeklinde tekrarlanan sorgu parametresi olarak alır. */
    private function labelQuery(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        return '?'.implode('&', array_map(fn ($l) => 'labels='.rawurlencode($l), $labels));
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->acceptJson();

        if ($this->username !== null && $this->username !== '') {
            $request = $request->withBasicAuth($this->username, (string) $this->password);
        }

        return $request;
    }

    /** @param callable(PendingRequest): Response $call */
    private function send(string $action, callable $call, bool $allowNotFound = false): Response
    {
        try {
            $response = $call($this->request());
        } catch (ConnectionException $e) {
            throw new KestraException("Kestra'ya bağlanılamadı ({$this->baseUrl}): {$e->getMessage()}");
        }

        if ($response->successful() || ($allowNotFound && $response->status() === 404)) {
            return $response;
        }

        throw KestraException::fromResponse($response, $action);
    }
}
