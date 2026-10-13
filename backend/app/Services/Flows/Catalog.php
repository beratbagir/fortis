<?php

namespace App\Services\Flows;

use RuntimeException;

/**
 * İzinli Kestra görev tipleri (config/kestra-catalog.json) ve kullanılabilir secret isimleri.
 *
 * Katalogda olmayan hiçbir görev, tetikleyici veya koşul tipi kullanılamaz.
 */
class Catalog
{
    /** @var array<string, array<string, mixed>> */
    private array $entries;

    /**
     * @param  array{kestra_version: string, entries: list<array<string, mixed>>, forbidden: list<string>, categories: array<string, string>}  $data
     * @param  list<string>  $secrets
     */
    public function __construct(private readonly array $data, private readonly array $secrets, private readonly string $namespace)
    {
        $this->entries = [];
        foreach ($data['entries'] as $entry) {
            $this->entries[$entry['type']] = $entry;
        }
    }

    public static function fromConfig(): self
    {
        $data = json_decode((string) file_get_contents(config('kestra.catalog_path')), true);

        if (! is_array($data) || ! isset($data['entries'])) {
            throw new RuntimeException('kestra-catalog.json okunamadı.');
        }

        return new self($data, config('kestra.secrets'), config('kestra.namespace'));
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function isAllowed(string $type): bool
    {
        return isset($this->entries[$type]);
    }

    /** @return array<string, mixed>|null */
    public function entry(string $type): ?array
    {
        return $this->entries[$type] ?? null;
    }

    /** @return list<string> */
    public function secrets(): array
    {
        return $this->secrets;
    }

    public function hasSecret(string $name): bool
    {
        return in_array($name, $this->secrets, true);
    }

    /**
     * Claude'a `get_catalog` aracıyla verilen içerik. `types` verilirse sadece o tiplerin ayrıntısı döner.
     *
     * @param  list<string>|null  $types
     * @return array<string, mixed>
     */
    public function forModel(?array $types = null): array
    {
        $entries = $types === null || $types === []
            ? array_values($this->entries)
            : array_values(array_filter($this->entries, fn (array $entry) => in_array($entry['type'], $types, true)));

        return [
            'kestra_version' => $this->data['kestra_version'],
            'namespace' => $this->namespace,
            'categories' => $this->data['categories'],
            'entries' => array_map(fn (array $entry) => array_intersect_key(
                $entry,
                array_flip(['type', 'kind', 'category', 'title', 'description', 'required_secrets', 'example']),
            ), $entries),
            'available_secrets' => $this->secrets,
            'forbidden' => $this->data['forbidden'],
        ];
    }

    /**
     * Sistem prompt'una giren kısa liste: tip, tür ve kategori.
     */
    public function summaryForPrompt(): string
    {
        return implode("\n", array_map(
            fn (array $entry) => "- {$entry['type']} ({$entry['kind']}, {$entry['category']}): {$entry['title']}",
            array_values($this->entries),
        ));
    }

    public function kestraVersion(): string
    {
        return $this->data['kestra_version'];
    }
}
