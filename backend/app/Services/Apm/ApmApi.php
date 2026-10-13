<?php

namespace App\Services\Apm;

use App\Services\Privacy\NationalIdMasker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * APM REST API: bilgi kaynağı repodaki OpenAPI dokümanıdır (docs/integrations/apm-openapi.json).
 *
 * Akışlar APM'ye Kestra'nın HTTP göreviyle, tünel üzerinden açılan adresle (kestra_base_url) bağlanır:
 * önce /oauth/token ile JWT alınır, sonraki istekler bu token'ı Bearer olarak kullanır.
 */
class ApmApi
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /** Yanıt alanları listesinde en fazla satır (çok büyük şemalar için). */
    private const MAX_FIELDS = 250;

    /** Denemede listelerden gösterilen eleman sayısı. */
    private const PREVIEW_ITEMS = 3;

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<string>  $readOnlyPosts
     * @param  array<string, string|null>  $credentials  Token isteğinin form alanları (grant_type hariç).
     */
    public function __construct(
        private readonly array $spec,
        private readonly string $kestraBaseUrl,
        private readonly string $grantType = 'client_credentials',
        private readonly bool $allowWrites = false,
        private readonly array $readOnlyPosts = ['/oauth/token'],
        private readonly string $directBaseUrl = '',
        private readonly array $credentials = [],
    ) {}

    /**
     * Bir okuma isteğini gerçek APM'de dener (Laravel'in doğrudan adresinden): yanıtın yapısı, listelerin
     * ilk birkaç elemanı ve toplam uzunlukları. Hata varsa APM'nin mesajı döner.
     *
     * Yazma kapalıyken de izinli rapor POST'ları (ör. zamanında kalkış raporu) gövdeyle denenebilir.
     *
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    public function preview(string $pathWithQuery, string $method = 'GET', ?array $body = null): array
    {
        $method = strtoupper($method);
        $pathWithQuery = '/'.ltrim(trim($pathWithQuery), '/');

        if ($this->directBaseUrl === '' || in_array(null, $this->credentials, true) || in_array('', $this->credentials, true)) {
            return ['ok' => false, 'error' => 'APM denemesi bu ortamda yapılandırılmamış.'];
        }
        if (str_contains($pathWithQuery, '{{') || str_contains($pathWithQuery, '{%')) {
            return ['ok' => false, 'error' => 'Denemede şablon ({{ }}) olamaz; tarih gibi değerleri somut yaz (ör. 2026-10-04T00:00:00Z).'];
        }

        $operation = $this->operationFor($method, $this->kestraBaseUrl.$pathWithQuery);
        if ($operation === null) {
            return ['ok' => false, 'error' => "APM'de böyle bir {$method} ucu yok. describe_apm ile işlemlere bak."];
        }
        if (! $this->isReadOnly($method, $operation['path']) || $this->isTokenOperation($method, $operation['path'])) {
            return ['ok' => false, 'error' => "{$method} {$operation['path']} denenemez; sadece okuma işlemleri (GET ve rapor POST'ları) denenebilir."];
        }

        try {
            $token = $this->previewToken();
            $request = Http::withToken($token)->timeout(90);
            $response = $method === 'POST'
                ? $request->post($this->directBaseUrl.$pathWithQuery, $body ?? [])
                : $request->get($this->directBaseUrl.$pathWithQuery);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'APM\'ye ulaşılamadı: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'status' => $response->status(), 'error' => mb_substr((string) preg_replace('/\s+/', ' ', $response->body()), 0, 600)];
        }

        return [
            'ok' => true,
            'operation' => "{$method} {$operation['path']}",
            'response' => NationalIdMasker::maskArray(self::trim($response->json() ?? $response->body())),
            'note' => 'Listelerden ilk '.self::PREVIEW_ITEMS.' eleman gösterildi; "_kalan" kaç eleman kesildiğini söyler. Akışta yanıt metindir: jq ile oku.',
        ];
    }

    /**
     * Bir okuma ucunun tam yanıtı (raporlar için; Laravel'in doğrudan adresinden). Sadece spec'teki okuma işlemleri.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public function fetch(string $pathWithQuery): array
    {
        $pathWithQuery = '/'.ltrim(trim($pathWithQuery), '/');

        if ($this->directBaseUrl === '' || in_array(null, $this->credentials, true) || in_array('', $this->credentials, true)) {
            throw new \RuntimeException('APM bu ortamda yapılandırılmamış.');
        }

        $operation = $this->operationFor('GET', $this->kestraBaseUrl.$pathWithQuery);
        if ($operation === null || ! $this->isReadOnly('GET', $operation['path']) || $this->isTokenOperation('GET', $operation['path'])) {
            throw new \RuntimeException('APM\'de böyle bir okuma ucu yok.');
        }

        try {
            $response = Http::withToken($this->previewToken())->timeout(90)->get($this->directBaseUrl.$pathWithQuery);
        } catch (Throwable $e) {
            throw new \RuntimeException('APM\'ye ulaşılamadı.', previous: $e);
        }

        if (! $response->successful()) {
            throw new \RuntimeException('APM isteği reddetti ('.$response->status().').');
        }

        return NationalIdMasker::maskArray($response->json() ?? []);
    }

    /**
     * Bir okuma işleminin (GET veya izinli rapor POST'u) tam yanıtı ve eşleşen spec yolu. Claude'un veri sorgusu
     * (query_apm) için; yanıt Claude'a doğrudan gitmez, önce ApmResultShaper ve AiDataGate'ten geçer.
     *
     * @param  array<string, mixed>|null  $body
     * @return array{operation_path: string, response: array<array-key, mixed>}
     *
     * @throws \RuntimeException
     */
    public function read(string $method, string $pathWithQuery, ?array $body = null): array
    {
        $method = strtoupper($method);
        $pathWithQuery = '/'.ltrim(trim($pathWithQuery), '/');

        if ($this->directBaseUrl === '' || in_array(null, $this->credentials, true) || in_array('', $this->credentials, true)) {
            throw new \RuntimeException('APM bu ortamda yapılandırılmamış.');
        }

        $operation = $this->operationFor($method, $this->kestraBaseUrl.$pathWithQuery);
        if ($operation === null || ! $this->isReadOnly($method, $operation['path']) || $this->isTokenOperation($method, $operation['path'])) {
            throw new \RuntimeException("APM'de böyle bir okuma ucu yok: {$method} ".strtok($pathWithQuery, '?'));
        }

        try {
            $request = Http::withToken($this->previewToken())->timeout(60);
            $response = $method === 'POST'
                ? $request->post($this->directBaseUrl.$pathWithQuery, $body ?? [])
                : $request->get($this->directBaseUrl.$pathWithQuery);
        } catch (Throwable $e) {
            throw new \RuntimeException('APM\'ye şu an ulaşılamıyor.', previous: $e);
        }

        if (! $response->successful()) {
            throw new \RuntimeException('APM isteği reddetti ('.$response->status().'): '.mb_substr((string) preg_replace('/\s+/', ' ', $response->body()), 0, 300));
        }

        return ['operation_path' => $operation['path'], 'response' => (array) ($response->json() ?? [])];
    }

    /** Laravel'in APM'ye doğrudan erişimi (adres ve kimlik bilgisi) tanımlı mı. */
    public function isConfigured(): bool
    {
        return $this->directBaseUrl !== '' && ! in_array(null, $this->credentials, true) && ! in_array('', $this->credentials, true);
    }

    /**
     * Bağlantı kontrolü (connectors:check): önbelleksiz token alır.
     *
     * @throws Throwable
     */
    public function ping(): void
    {
        Http::asForm()->timeout(20)->post($this->directBaseUrl.'/oauth/token', ['grant_type' => $this->grantType] + $this->credentials)->throw();
    }

    /** Deneme için token (yaklaşık 50 dakika saklanır). */
    private function previewToken(): string
    {
        return Cache::remember('apm:preview-token', 3000, function () {
            $response = Http::asForm()->timeout(20)->post($this->directBaseUrl.'/oauth/token', ['grant_type' => $this->grantType] + $this->credentials);
            $response->throw();

            return (string) $response->json('access_token');
        });
    }

    /** Listeleri ilk birkaç elemana kısaltır, HATEOAS bağlantılarını çıkarır. */
    private static function trim(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        unset($value['_links']);

        if (array_is_list($value)) {
            $kept = array_map(self::trim(...), array_slice($value, 0, self::PREVIEW_ITEMS));
            if (count($value) > self::PREVIEW_ITEMS) {
                $kept[] = ['_kalan' => count($value) - self::PREVIEW_ITEMS];
            }

            return $kept;
        }

        return array_map(self::trim(...), $value);
    }

    public function enabled(): bool
    {
        return $this->kestraBaseUrl !== '' && $this->spec !== [];
    }

    public function baseUrl(): string
    {
        return $this->kestraBaseUrl;
    }

    public function allowWrites(): bool
    {
        return $this->allowWrites;
    }

    public function grantType(): string
    {
        return $this->grantType;
    }

    /** @return list<string> */
    public function requiredSecrets(): array
    {
        return $this->grantType === 'password' ? ['APM_USERNAME', 'APM_PASSWORD'] : ['APM_CLIENT_ID', 'APM_CLIENT_SECRET'];
    }

    public function isApmUrl(string $uri): bool
    {
        return $this->enabled() && (strcasecmp($uri, $this->kestraBaseUrl) === 0 || stripos($uri, $this->kestraBaseUrl.'/') === 0);
    }

    /** Adresin APM tabanından sonraki yolu (sorgu hariç). */
    public function pathOf(string $uri): string
    {
        $rest = substr($uri, strlen($this->kestraBaseUrl));

        return '/'.ltrim((string) strtok($rest, '?'), '/');
    }

    /**
     * Adresi spec'teki işlemle eşleştirir. Yol parametreleri ({legIdentifier}) ve şablonlu segmentler ({{ }}) eşleşir.
     *
     * @return array{path: string, method: string, operation: array<string, mixed>}|null
     */
    public function operationFor(string $method, string $uri): ?array
    {
        $method = strtolower($method);
        $path = (string) preg_replace('/\{\{.*?\}\}/', 'X', $this->pathOf($uri));

        foreach ($this->spec['paths'] ?? [] as $template => $operations) {
            $pattern = '#^'.preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote($template, '#')).'/?$#';

            if (isset($operations[$method]) && preg_match($pattern, $path)) {
                return ['path' => $template, 'method' => strtoupper($method), 'operation' => $operations[$method]];
            }
        }

        return null;
    }

    public function isReadOnly(string $method, string $path): bool
    {
        $method = strtoupper($method);

        return $method === 'GET' || ($method === 'POST' && in_array($path, $this->readOnlyPosts, true));
    }

    public function isTokenOperation(string $method, string $path): bool
    {
        return strtoupper($method) === 'POST' && $path === '/oauth/token';
    }

    /**
     * Claude'un kullanabileceği işlemler (yazma kapalıyken sadece okuma işlemleri), kısa özetleriyle.
     *
     * @return list<array{method: string, path: string, group: string, summary: string, parameters: list<string>}>
     */
    public function operations(): array
    {
        $list = [];
        foreach ($this->spec['paths'] ?? [] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (! in_array($method, self::METHODS, true) || $path === '/oauth/check_token') {
                    continue;
                }
                if (! $this->allowWrites && ! $this->isReadOnly($method, $path)) {
                    continue;
                }

                $list[] = [
                    'method' => strtoupper($method),
                    'path' => $path,
                    'group' => str_replace(' REST controller', '', $operation['tags'][0] ?? ''),
                    'summary' => trim((string) ($operation['summary'] ?? '')),
                    'parameters' => array_map(
                        fn (array $p) => $p['name'].(($p['required'] ?? false) ? '*' : ''),
                        $operation['parameters'] ?? [],
                    ),
                ];
            }
        }

        return $list;
    }

    /**
     * Bir işlemin parametreleri, istek gövdesi alanları ve yanıt alanları.
     *
     * @return array<string, mixed>|null
     */
    public function describe(string $method, string $path): ?array
    {
        $operation = $this->spec['paths'][$path][strtolower($method)] ?? null;

        if ($operation === null) {
            return null;
        }

        $parameters = array_map(fn (array $p) => [
            'name' => $p['name'],
            'in' => $p['in'],
            'required' => (bool) ($p['required'] ?? false),
            'type' => $p['schema']['type'] ?? null,
            'description' => $p['description'] ?? null,
        ], $operation['parameters'] ?? []);

        $body = null;
        foreach ($operation['requestBody']['content'] ?? [] as $type => $content) {
            $body = ['content_type' => $type, 'fields' => $this->fields($content['schema'] ?? [])];
            break;
        }

        $response = null;
        foreach (($operation['responses']['200'] ?? $operation['responses']['201'] ?? [])['content'] ?? [] as $type => $content) {
            $response = ['content_type' => $type, 'fields' => $this->fields($content['schema'] ?? [])];
            break;
        }

        return [
            'method' => strtoupper($method),
            'path' => $path,
            'url' => $this->kestraBaseUrl.$path,
            'summary' => $operation['summary'] ?? null,
            'description' => isset($operation['description']) ? mb_substr(strip_tags((string) $operation['description']), 0, 1500) : null,
            'read_only' => $this->isReadOnly($method, $path),
            'parameters' => $parameters,
            'request_body' => $body,
            'response' => $response,
        ];
    }

    /**
     * Şemayı "a.b[].c: tip — açıklama" satırlarına açar (en fazla 5 seviye).
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function fields(array $schema, string $prefix = '', int $depth = 0, array $seen = []): array
    {
        $schema = $this->resolve($schema, $seen);
        $lines = [];

        if (($schema['type'] ?? null) === 'array') {
            return $this->fields($schema['items'] ?? [], $prefix.'[]', $depth, $seen);
        }

        foreach ($schema['properties'] ?? [] as $name => $property) {
            if (count($lines) >= self::MAX_FIELDS) {
                $lines[] = '… (kısaltıldı)';
                break;
            }

            $ref = $property['$ref'] ?? ($property['items']['$ref'] ?? null);
            $resolved = $this->resolve($property, $seen);
            $isArray = ($property['type'] ?? null) === 'array';
            $type = $isArray ? 'array' : ($resolved['type'] ?? ($ref ? 'object' : '?'));
            $format = isset($resolved['format']) ? "({$resolved['format']})" : '';
            $description = trim((string) ($property['description'] ?? $resolved['description'] ?? ''));
            $path = ltrim("{$prefix}.{$name}", '.');

            $enum = isset($resolved['enum']) && is_array($resolved['enum']) ? ' ['.implode('|', array_slice($resolved['enum'], 0, 20)).']' : '';
            $lines[] = "{$path}: {$type}{$format}{$enum}".($description !== '' ? ' — '.mb_substr($description, 0, 120) : '');

            $child = $isArray ? $this->resolve($property['items'] ?? [], $seen) : $resolved;
            $refName = $ref !== null ? basename($ref) : null;
            if ($depth < 4 && isset($child['properties']) && ($refName === null || ! in_array($refName, $seen, true))) {
                array_push($lines, ...$this->fields($child, $path.($isArray ? '[]' : ''), $depth + 1, $refName ? [...$seen, $refName] : $seen));
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $seen
     * @return array<string, mixed>
     */
    private function resolve(array $schema, array $seen = []): array
    {
        for ($i = 0; $i < 10; $i++) {
            if (isset($schema['$ref'])) {
                $schema = $this->spec['components']['schemas'][basename($schema['$ref'])] ?? [];

                continue;
            }
            foreach (['allOf', 'oneOf', 'anyOf'] as $combinator) {
                if (isset($schema[$combinator][0])) {
                    $schema = $schema[$combinator][0];

                    continue 2;
                }
            }

            return $schema;
        }

        return $schema;
    }
}
