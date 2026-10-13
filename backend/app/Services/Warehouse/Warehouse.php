<?php

namespace App\Services\Warehouse;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * IBS veri ambarı: hazır sorgular, tablo sözlüğü, canlı şema ve SQL kontrolü (EXPLAIN).
 *
 * Laravel ambara sadece okumak için bağlanır ve hiçbir zaman gerçek satır döndürmez (sadece şema ve plan);
 * akışlardaki sorgular Kestra'nın MySQL göreviyle çalışır.
 */
class Warehouse
{
    /** Bu kadar satırdan büyük bir tabloda tam tarama uyarı verir. */
    private const FULL_SCAN_WARNING_ROWS = 1_000_000;

    /**
     * @param  (Closure(): ConnectionInterface)|null  $connection  Ambar kapalıysa null.
     * @param  array{connection: array<string, mixed>, queries: list<array<string, mixed>>}  $queries
     * @param  array{general_notes: list<string>, tables: array<string, string>, hidden_table_patterns: list<string>, hidden_columns: list<string>}  $dictionary
     */
    public function __construct(
        private readonly ?Closure $connection,
        private readonly array $queries,
        private readonly array $dictionary,
        private readonly SqlGuard $guard,
        private readonly int $cacheSeconds = 86400,
    ) {}

    public function enabled(): bool
    {
        return $this->connection !== null;
    }

    public function guard(): SqlGuard
    {
        return $this->guard;
    }

    /** @return list<string> */
    public function requiredSecrets(): array
    {
        return $this->queries['connection']['required_secrets'] ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function queries(): array
    {
        return $this->queries['queries'];
    }

    /** SQL bir hazır sorguyla (boşluk farkları hariç) aynıysa o sorgu. */
    public function namedQueryFor(string $sql): ?array
    {
        $normalized = SqlGuard::normalize($sql);

        foreach ($this->queries() as $query) {
            if (SqlGuard::normalize($query['sql']) === $normalized) {
                return $query;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function generalNotes(): array
    {
        return $this->dictionary['general_notes'];
    }

    public function isHiddenTable(string $table): bool
    {
        foreach ($this->dictionary['hidden_table_patterns'] as $pattern) {
            if (preg_match('/'.$pattern.'/i', $table)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Görünür tablolar: ad, açıklama, yaklaşık satır sayısı, boyut.
     *
     * @return list<array{name: string, description: string|null, approx_rows: int, size_mb: int}>|null Ambara ulaşılamazsa null.
     */
    public function tables(): ?array
    {
        $rows = $this->remember('tables', fn (ConnectionInterface $db) => array_map(fn ($row) => (array) $row, $db->select(
            'SELECT table_name AS name, table_rows AS approx_rows, ROUND((data_length + index_length) / 1048576) AS size_mb
             FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name',
        )));

        if ($rows === null) {
            return null;
        }

        return array_values(array_map(fn (array $row) => [
            'name' => $row['name'],
            'description' => $this->dictionary['tables'][$row['name']] ?? null,
            'approx_rows' => (int) $row['approx_rows'],
            'size_mb' => (int) $row['size_mb'],
        ], array_filter($rows, fn (array $row) => ! $this->isHiddenTable($row['name']))));
    }

    /**
     * Tabloların sütunları (gizli sütunlar hariç) ve indeksleri.
     *
     * @param  list<string>  $names
     * @return array<string, array<string, mixed>>|null
     */
    public function describe(array $names): ?array
    {
        $visible = array_column($this->tables() ?? [], null, 'name');

        if ($visible === [] && ! $this->enabled()) {
            return null;
        }

        $result = [];
        foreach (array_unique($names) as $name) {
            if (! isset($visible[$name])) {
                $result[$name] = ['error' => 'Tablo yok veya gizli. Tablo listesi için describe_warehouse\'u tablo vermeden çağır.'];

                continue;
            }

            $details = $this->remember("table:{$name}", fn (ConnectionInterface $db) => [
                'columns' => array_map(fn ($c) => (array) $c, $db->select(
                    'SELECT column_name AS name, column_type AS type, is_nullable AS nullable
                     FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
                    [$name],
                )),
                'indexes' => array_map(fn ($i) => (array) $i, $db->select(
                    'SELECT index_name AS name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns
                     FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? GROUP BY index_name',
                    [$name],
                )),
            ]);

            if ($details === null) {
                return null;
            }

            $result[$name] = $visible[$name] + [
                'columns' => array_values(array_filter(
                    $details['columns'],
                    fn (array $column) => ! in_array(strtolower($column['name']), $this->dictionary['hidden_columns'], true),
                )),
                'indexes' => array_map(fn (array $index) => "{$index['name']}({$index['columns']})", $details['indexes']),
            ];
        }

        return $result;
    }

    /**
     * Onaylı hazır sorguyu çalıştırır (raporlar için; veriyi Laravel kendisi çeker). Parametreler :ad yer
     * tutucularına sırayla bağlanır. Sorgu metni değiştirilmez, yalnızca queries.json'daki SQL çalışır.
     *
     * @param  array<string, scalar>  $params
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException
     */
    public function runNamedQuery(string $name, array $params): array
    {
        $query = collect($this->queries())->firstWhere('name', $name) ?? throw new \RuntimeException("Hazır sorgu yok: {$name}");

        if ($this->connection === null) {
            throw new \RuntimeException('Veri ambarı bu ortamda tanımlı değil.');
        }

        [$positional, $names] = $this->guard->positional($query['sql']);
        $bindings = array_map(fn (string $param) => $params[$param] ?? throw new \RuntimeException("'{$param}' parametresi eksik."), $names);

        try {
            $rows = ($this->connection)()->select($positional, $bindings);
        } catch (Throwable $e) {
            throw new \RuntimeException('Veri ambarı sorgusu çalışmadı: '.self::cleanError($e->getMessage()), previous: $e);
        }

        return array_map(fn ($row) => (array) $row, $rows);
    }

    /**
     * Bağlantı kontrolü (connectors:check).
     *
     * @throws Throwable
     */
    public function ping(): void
    {
        if ($this->connection === null) {
            throw new \RuntimeException('Veri ambarı tanımlı değil.');
        }

        ($this->connection)()->select('SELECT 1');
    }

    /** Claude'un veri sorgusu (query_warehouse) için kontrol: daha sıkı süre ve satır sınırı, aynı gizli sütunlar. */
    public function aiGuard(): SqlGuard
    {
        return new SqlGuard($this->dictionary['hidden_columns'], (int) config('ai-data.warehouse_timeout_ms'), (int) config('ai-data.warehouse_max_limit'));
    }

    /**
     * Kontrolden geçmiş bir SELECT'i çalıştırır (Claude'un veri sorgusu). Parametreler :ad yer tutucularına sırayla
     * bağlanır. Sonuç Claude'a doğrudan gitmez; çağıran AiDataGate'ten geçirir.
     *
     * @param  array<string, scalar|null>  $params
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException
     */
    public function select(string $sql, array $params): array
    {
        if ($this->connection === null) {
            throw new \RuntimeException('Veri ambarı bu ortamda tanımlı değil.');
        }

        [$positional, $names] = $this->guard->positional($sql);
        $bindings = array_map(fn (string $param) => array_key_exists($param, $params) ? $params[$param] : throw new \RuntimeException("'{$param}' parametresi eksik."), $names);

        try {
            $rows = ($this->connection)()->select($positional, $bindings);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, '3024') || stripos($message, 'maximum statement execution time') !== false) {
                throw new \RuntimeException('Sorgu süre sınırında bitmedi. Tarih aralığını daralt veya indeksli bir sütunla filtrele.', previous: $e);
            }

            throw new \RuntimeException('Veri ambarı sorgusu çalışmadı: '.self::cleanError($message), previous: $e);
        }

        return array_map(fn ($row) => (array) $row, $rows);
    }

    /**
     * SQL'i ambarda çalıştırmadan dener (EXPLAIN). Yer tutucular bugünün tarihiyle bağlanır: NULL bağlanırsa
     * MySQL koşulun eşleşmeyeceğini görüp plan çıkarmaz. Tarih parametreleri için gerçekçi bir değerdir;
     * sayı ve metin parametrelerinde de geçerli bir plan verir.
     *
     * @return array{ok: bool, error: string|null, warnings: list<string>}|null Ambara ulaşılamazsa null.
     */
    public function explain(string $sql): ?array
    {
        if ($this->connection === null) {
            return null;
        }

        [$positional, $names] = $this->guard->positional($sql);

        try {
            $db = ($this->connection)();
            $plan = $db->select('EXPLAIN '.$positional, array_fill(0, count($names), now()->toDateString()));
        } catch (Throwable $e) {
            $message = $e->getMessage();

            // Bağlantı hatası: kontrol yapılamadı. SQL hatası: sorgu geçersiz.
            if (preg_match('/SQLSTATE\[(HY000|08\w{3})\].*(2002|2003|2006|2013|Connection refused|timed out)/', $message)) {
                return null;
            }

            return ['ok' => false, 'error' => self::cleanError($message), 'warnings' => []];
        }

        $warnings = [];
        foreach ($plan as $row) {
            $row = array_change_key_case((array) $row);
            // ALL: tam tablo taraması, index: tam indeks taraması. İkisi de büyük tabloda yavaştır.
            if (in_array($row['type'] ?? null, ['ALL', 'index'], true) && (int) ($row['rows'] ?? 0) > self::FULL_SCAN_WARNING_ROWS) {
                $warnings[] = "{$row['table']} tablosunda tam tarama (~".number_format((int) $row['rows'], 0, ',', '.').' satır); sorgu yavaş olabilir. İndeksli bir sütunla filtrele.';
            }
        }

        return ['ok' => true, 'error' => null, 'warnings' => $warnings];
    }

    /**
     * @template T
     *
     * @param  Closure(ConnectionInterface): T  $callback
     * @return T|null
     */
    private function remember(string $key, Closure $callback): mixed
    {
        if ($this->connection === null) {
            return null;
        }

        try {
            return Cache::remember("warehouse:{$key}", $this->cacheSeconds, fn () => $callback(($this->connection)()));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private static function cleanError(string $message): string
    {
        $message = (string) preg_replace('/\s*\(Connection: .*$/s', '', $message);

        return mb_substr((string) preg_replace('/^SQLSTATE\[\w+\]: [^:]+: \d+ /', '', $message), 0, 300);
    }
}
