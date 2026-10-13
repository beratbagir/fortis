<?php

namespace App\Services\SuccessFactors;

use App\Services\Privacy\NationalIdMasker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * SAP SuccessFactors: hazır istekler ve canlı şema ($metadata).
 *
 * Laravel SuccessFactors'tan şema ve (Claude'un isteği denemesi için) birkaç örnek kayıt okur;
 * akışlar Kestra'nın HTTP göreviyle çalışır.
 */
class SuccessFactors
{
    /**
     * @param  array{connection: array<string, mixed>, requests: list<array<string, mixed>>, notes: list<string>}  $catalog
     */
    public function __construct(
        private readonly array $catalog,
        private readonly string $baseUrl,
        private readonly ?string $username,
        private readonly ?string $password,
        private readonly bool $allowWrites = false,
        private readonly int $cacheSeconds = 86400,
        private readonly int $timeout = 30,
    ) {}

    public function enabled(): bool
    {
        return $this->baseUrl !== '/' && $this->baseUrl !== '';
    }

    public function host(): ?string
    {
        return $this->enabled() ? parse_url($this->baseUrl, PHP_URL_HOST) : null;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function allowWrites(): bool
    {
        return $this->allowWrites;
    }

    /** @return list<string> */
    public function requiredSecrets(): array
    {
        return $this->catalog['connection']['required_secrets'] ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function requests(): array
    {
        return array_map(fn (array $request) => $request + ['url' => $this->baseUrl.$request['path']], $this->catalog['requests']);
    }

    /** @return list<string> */
    public function notes(): array
    {
        return $this->catalog['notes'];
    }

    /** Bir adres SuccessFactors'a mı gidiyor. */
    public function isSuccessFactorsUrl(string $uri): bool
    {
        $host = $this->host();

        return $host !== null && strtolower((string) parse_url($uri, PHP_URL_HOST)) === strtolower($host);
    }

    /** Adresin hedeflediği varlık adı (ör. .../odata/v2/EmpJob?... → EmpJob). */
    public function entityOf(string $uri): ?string
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);

        return preg_match('#/odata/v2/([A-Za-z_][A-Za-z0-9_]*)#', $path, $m) ? $m[1] : null;
    }

    /** Bir adres hazır isteklerden birinin aynısıysa (parametreler hariç) o istek. */
    public function namedRequestFor(string $uri): ?array
    {
        foreach ($this->requests() as $request) {
            $pattern = '#^'.preg_replace('/\\\\\{\w+\\\\\}/', '.+', preg_quote($request['url'], '#')).'$#';

            if (preg_match($pattern, $uri)) {
                return $request;
            }
        }

        return null;
    }

    /**
     * Varlığın alanları ve ilişkileri ($metadata, SAP etiketleriyle). Ulaşılamazsa null.
     *
     * @return array{entity: string, properties: list<array{name: string, type: string, label: string|null}>, navigations: list<string>}|array{error: string}|null
     */
    public function describe(string $entity): ?array
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $entity)) {
            return ['error' => 'Geçersiz varlık adı.'];
        }

        if (self::mentionsNationalId($entity)) {
            return ['error' => 'TC kimlik bilgisi Fortis\'te kullanılamaz.'];
        }

        $described = $this->fetchDescription($entity);

        if (! is_array($described) || isset($described['error'])) {
            return $described;
        }

        // TC kimlik alanları ve ilişkileri Claude'a hiç gösterilmez.
        $described['properties'] = array_values(array_filter($described['properties'], fn (array $p) => ! self::mentionsNationalId($p['name'])));
        $described['navigations'] = array_values(array_filter($described['navigations'], fn (string $n) => ! self::mentionsNationalId($n)));

        return $described;
    }

    /**
     * Bir okuma isteğini gerçek SuccessFactors'ta dener: toplam kayıt sayısı ve ilk birkaç kayıt
     * (meta veriler çıkarılmış, TC kimlik maskeli). Hata varsa SuccessFactors'un mesajı döner.
     *
     * @return array<string, mixed>
     */
    public function preview(string $path, int $limit = 5): array
    {
        $path = trim($path);
        if (str_starts_with($path, $this->baseUrl)) {
            $path = substr($path, strlen($this->baseUrl));
        }
        $path = ltrim($path, '/');

        if (str_contains($path, '{{') || str_contains($path, '{%')) {
            return ['ok' => false, 'error' => 'Denemede şablon ({{ }}) olamaz; tarih gibi değerleri somut yaz (ör. 2026-10-05).'];
        }
        if (self::mentionsNationalId($path)) {
            return ['ok' => false, 'error' => 'TC kimlik bilgisi Fortis\'te kullanılamaz.'];
        }
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\([^)\/]*\))?(\/\$count)?(\?|$)/', $path)) {
            return ['ok' => false, 'error' => 'Yol bir varlık adıyla başlamalı, ör. EmpJob?$filter=... veya EmpJob/$count?$filter=...'];
        }

        [$resource, $query] = array_pad(explode('?', $path, 2), 2, '');
        $isCount = str_ends_with($resource, '/$count');

        $params = array_values(array_filter(
            $query === '' ? [] : explode('&', $query),
            fn (string $p) => ! preg_match('/^\$(top|inlinecount|format)=/i', $p),
        ));
        if (! $isCount) {
            array_push($params, '$format=JSON', '$top='.$limit, '$inlinecount=allpages');
        }

        try {
            $response = Http::withBasicAuth((string) $this->username, (string) $this->password)
                ->timeout(max($this->timeout, 60))
                ->get($this->baseUrl.$resource.($params === [] ? '' : '?'.implode('&', $params)));
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'SuccessFactors\'a ulaşılamadı: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! $response->successful()) {
            $message = $response->json('error.message.value') ?? mb_substr(strip_tags($response->body()), 0, 400);

            return ['ok' => false, 'status' => $response->status(), 'error' => $message];
        }

        if ($isCount) {
            return ['ok' => true, 'count' => (int) trim($response->body())];
        }

        $data = $response->json('d') ?? [];
        $records = $data['results'] ?? [$data];

        return [
            'ok' => true,
            'total' => isset($data['__count']) ? (int) $data['__count'] : count($records),
            'shown' => min(count($records), $limit),
            'records' => NationalIdMasker::maskArray(array_map(self::clean(...), array_slice($records, 0, $limit))),
            'note' => 'Kayıtlar Kestra\'daki yanıtın d.results[] elemanlarıyla aynı yapıdadır (meta alanlar çıkarıldı). Tarihler /Date(ms)/ biçimindedir. Genişletilmeyen ilişkiler gösterilmez.',
        ];
    }

    /**
     * Bir okuma isteğinin tüm kayıtlarını çeker (sunucu tarafı sayfalama: d.__next). Raporlar için.
     * TC kimlik içeren istekler reddedilir; kayıtlar maskelenir.
     *
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException
     */
    public function fetchAll(string $path, int $maxRecords): array
    {
        $path = ltrim(trim($path), '/');

        if (! $this->enabled()) {
            throw new \RuntimeException('SuccessFactors bu ortamda tanımlı değil.');
        }
        if (self::mentionsNationalId($path)) {
            throw new \RuntimeException('TC kimlik bilgisi Fortis\'te kullanılamaz.');
        }

        $url = $this->baseUrl.$path;
        $records = [];

        while ($url !== null) {
            try {
                $response = Http::withBasicAuth((string) $this->username, (string) $this->password)->timeout(max($this->timeout, 90))->get($url);
            } catch (Throwable $e) {
                throw new \RuntimeException('SuccessFactors\'a ulaşılamadı.', previous: $e);
            }

            if (! $response->successful()) {
                throw new \RuntimeException('SuccessFactors isteği reddetti ('.$response->status().').');
            }

            $data = $response->json('d') ?? [];
            array_push($records, ...array_map(self::clean(...), $data['results'] ?? [$data]));

            if (count($records) > $maxRecords) {
                break;
            }

            $next = $data['__next'] ?? null;
            $url = is_string($next) && str_starts_with($next, $this->baseUrl) ? $next : null;
        }

        return NationalIdMasker::maskArray($records);
    }

    /**
     * Bağlantı kontrolü (connectors:check): tek bir departman kaydı ister.
     *
     * @throws Throwable
     */
    public function ping(): void
    {
        Http::withBasicAuth((string) $this->username, (string) $this->password)->timeout(30)->get($this->baseUrl.'FODepartment?$top=1&$format=JSON')->throw();
    }

    /** Kayıttan __metadata ve genişletilmemiş (__deferred) ilişkileri çıkarır. */
    private static function clean(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        unset($value['__metadata']);

        $cleaned = [];
        foreach ($value as $key => $item) {
            if (is_array($item) && array_keys($item) === ['__deferred']) {
                continue;
            }
            $cleaned[$key] = self::clean($item);
        }

        return $cleaned;
    }

    /** Adres, varlık veya alan adı TC kimlik bilgisine (PerNationalId, nationalIdNav, nationalId) değiyor mu. */
    public static function mentionsNationalId(string $text): bool
    {
        return stripos(rawurldecode($text), 'nationalid') !== false;
    }

    /** @return array<string, mixed>|null */
    private function fetchDescription(string $entity): ?array
    {
        try {
            return Cache::remember("successfactors:metadata:{$entity}", $this->cacheSeconds, function () use ($entity) {
                $response = Http::withBasicAuth((string) $this->username, (string) $this->password)
                    ->timeout($this->timeout)
                    ->get($this->baseUrl.$entity.'/$metadata');

                if ($response->status() === 404 || $response->status() === 400) {
                    return ['error' => "'{$entity}' adında bir varlık yok."];
                }

                $response->throw();

                $described = $this->parseMetadata($entity, $response->body());

                if (! isset($described['error'])) {
                    $described['navigations'] = $this->navigations($entity);
                }

                return $described;
            });
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Varlığın ilişkileri ($expand ile getirilebilen ...Nav alanları). Varlığa özel $metadata ilişkileri içermez;
     * bir kayıt çekilip `__deferred` bağlantılarının sadece adları alınır, kaydın değerleri kullanılmaz.
     *
     * @return list<string>
     */
    private function navigations(string $entity): array
    {
        $response = Http::withBasicAuth((string) $this->username, (string) $this->password)
            ->timeout($this->timeout)
            ->get($this->baseUrl.$entity, ['$format' => 'JSON', '$top' => 1]);

        $record = $response->json('d.results.0') ?? [];

        return array_values(array_keys(array_filter(
            is_array($record) ? $record : [],
            fn ($value) => is_array($value) && isset($value['__deferred']),
        )));
    }

    /**
     * @return array{entity: string, properties: list<array{name: string, type: string, label: string|null}>, navigations: list<string>}|array{error: string}
     */
    private function parseMetadata(string $entity, string $xml): array
    {
        $doc = new SimpleXMLElement($xml);
        $doc->registerXPathNamespace('edm', 'http://schemas.microsoft.com/ado/2008/09/edm');
        $types = $doc->xpath("//*[local-name()='EntityType'][@Name='{$entity}']") ?: [];

        if ($types === []) {
            return ['error' => "'{$entity}' şemada bulunamadı."];
        }

        $type = $types[0];
        $properties = [];
        foreach ($type->xpath("*[local-name()='Property']") ?: [] as $property) {
            $sap = $property->attributes('http://www.successfactors.com/edm/sap');
            $properties[] = [
                'name' => (string) $property['Name'],
                'type' => str_replace('Edm.', '', (string) $property['Type']),
                'label' => isset($sap['label']) ? (string) $sap['label'] : null,
            ];
        }

        $navigations = array_map(
            fn (SimpleXMLElement $nav) => (string) $nav['Name'],
            $type->xpath("*[local-name()='NavigationProperty']") ?: [],
        );

        return ['entity' => $entity, 'properties' => $properties, 'navigations' => $navigations];
    }
}
