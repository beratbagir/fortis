<?php

namespace App\Services\Connectors;

use App\Agents\Inbox;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Connector;
use App\Models\User;
use App\Services\Auth\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Bağlantıların şirket genelindeki durumu (A4.1). Admin bir kaynağı kapatınca araçları sohbette ve agentlarda Claude'a
 * sunulmaz, çağrılırsa reddedilir (A2'deki sohbet bazlı disabled_sources ile aynı mekanizma); o kaynağı kullanan
 * agentların sahiplerine Inbox uyarısı düşer. Kapsam etiketleri agent araç kataloğundan türetilir, elle yazılmaz.
 */
class ConnectorRegistry
{
    public const SCOPE_READ = 'read';

    public const SCOPE_WRITE = 'write·approval';

    public const SCOPE_EVENTS = 'events';

    private const CACHE_KEY = 'connectors.disabled';

    public function __construct(private readonly Inbox $inbox) {}

    /** @return array<string, array<string, mixed>> */
    public static function catalog(): array
    {
        return config('connectors.sources');
    }

    /**
     * Bağlantının araçları: sohbet araçları (config/connectors.php) + agent kataloğunda aynı sistemin araçları.
     *
     * @return list<string>
     */
    public static function tools(string $key): array
    {
        $source = self::catalog()[$key] ?? [];
        $system = $source['system'] ?? null;
        $agentTools = $system === null ? [] : array_keys(array_filter(config('agents.tools'), fn (array $t) => $t['system'] === $system));

        return array_values(array_unique([...($source['tools'] ?? []), ...$agentTools]));
    }

    /** @return list<string> read · write·approval · events */
    public static function scopes(string $key): array
    {
        $catalog = config('agents.tools');
        $kinds = collect(self::tools($key))->map(fn (string $tool) => $catalog[$tool]['kind'] ?? 'read')->unique();
        $events = collect(config('agents.events'))->contains(fn (array $e) => ($e['source'] ?? null) === $key);

        return array_values(array_filter([
            $kinds->contains('read') && self::tools($key) !== [] ? self::SCOPE_READ : null,
            $kinds->contains('write') ? self::SCOPE_WRITE : null,
            $events ? self::SCOPE_EVENTS : null,
        ]));
    }

    /** Katalogdaki her bağlantı için satır (yoksa oluşur) ve güncel kapsam etiketleri. */
    public function sync(): Collection
    {
        $rows = Connector::query()->get()->keyBy('key');
        foreach (array_keys(self::catalog()) as $key) {
            $scopes = self::scopes($key);
            $row = $rows->get($key) ?? Connector::query()->create(['key' => $key, 'enabled' => true, 'scopes' => $scopes]);
            if ($row->scopes !== $scopes) {
                $row->update(['scopes' => $scopes]);
            }
            $rows->put($key, $row);
        }

        return $rows;
    }

    /** @return list<string> Şirket genelinde kapalı bağlantılar (1 dk önbellek; değişince temizlenir). */
    public function disabledKeys(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, fn () => Connector::query()->where('enabled', false)->pluck('key')->all());
    }

    public function isDisabled(string $key): bool
    {
        return in_array($key, $this->disabledKeys(), true);
    }

    /** @return list<string> Kapalı bağlantıların bütün araçları. */
    public function disabledTools(): array
    {
        return array_values(array_unique(array_merge([], ...array_map(self::tools(...), $this->disabledKeys()))));
    }

    /** Aracın ait olduğu kapalı bağlantı (yoksa null). */
    public function disabledSourceOf(string $tool): ?string
    {
        foreach ($this->disabledKeys() as $key) {
            if (in_array($tool, self::tools($key), true)) {
                return $key;
            }
        }

        return null;
    }

    /** @return Collection<int, Agent> Bu bağlantının araçlarından birini kullanan agentlar (şablonlar hariç). */
    public function agentsUsing(string $key): Collection
    {
        $tools = self::tools($key);

        return Agent::query()->with('owner')->where('status', '!=', Agent::STATUS_TEMPLATE)->get()
            ->filter(fn (Agent $a) => array_intersect($a->toolNames(), $tools) !== [])->values();
    }

    public function disable(string $key, string $reason, User $admin): Connector
    {
        $row = $this->sync()->get($key);
        abort_if($row === null, 404);
        if (! $row->enabled) {
            return $row;
        }

        $row->update(['enabled' => false, 'disabled_reason' => $reason, 'disabled_by' => $admin->id, 'disabled_at' => now()]);
        Cache::forget(self::CACHE_KEY);

        $agents = $this->agentsUsing($key);
        Audit::record('connector.disable', $row, AuditLog::RESULT_ALLOWED, ['connector' => $key, 'before' => ['enabled' => true], 'after' => ['enabled' => false], 'reason' => $reason, 'affected_agents' => $agents->count()], user: $admin);

        $label = self::catalog()[$key]['label'];
        foreach ($agents->groupBy('owner_id') as $owned) {
            $owner = $owned->first()->owner;
            if ($owner === null) {
                continue;
            }
            $this->inbox->connectorAlert($owner, $row, "{$label} şirket genelinde kapatıldı",
                "Yönetici {$label} bağlantısını kapattı ({$reason}). Şu agentlar {$label} araçlarını kullanamaz: ".$owned->pluck('name')->implode(', ').'. Bağlantı açılana kadar bu agentlar eksik çalışabilir.');
        }

        return $row->refresh();
    }

    public function enable(string $key, User $admin): Connector
    {
        $row = $this->sync()->get($key);
        abort_if($row === null, 404);
        if ($row->enabled) {
            return $row;
        }

        $before = ['enabled' => false, 'reason' => $row->disabled_reason];
        $row->update(['enabled' => true, 'disabled_reason' => null, 'disabled_by' => null, 'disabled_at' => null]);
        Cache::forget(self::CACHE_KEY);
        Audit::record('connector.enable', $row, AuditLog::RESULT_ALLOWED, ['connector' => $key, 'before' => $before, 'after' => ['enabled' => true]], user: $admin);
        $this->inbox->close(Inbox::SUBJECT_CONNECTOR, $row->id);

        return $row->refresh();
    }
}
