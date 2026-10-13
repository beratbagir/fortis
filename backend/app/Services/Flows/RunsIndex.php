<?php

namespace App\Services\Flows;

use App\Models\Execution;
use App\Models\Flow;
use App\Services\Kestra\KestraClient;
use App\Services\Kestra\KestraException;
use App\Services\Settings\AppSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Çalışmalar ekranının yerel dizini (executions tablosu).
 *
 * Kestra 1.3'ün çalışma araması her çalışmayı görev çıktılarıyla birlikte döndürüyor (10 çalışma birkaç MB).
 * Bu yüzden liste her açılışta Kestra'dan okunmaz: biten çalışmalar değişmediği için bir kez kaydedilir; her
 * eşitlemede yalnızca son kayıttan sonra başlayan çalışmalar ve henüz bitmemiş olanların durumu çekilir.
 */
class RunsIndex
{
    /** Eşitlemeler arası en az süre (liste 15 sn'de bir yenileniyor). */
    private const THROTTLE_SECONDS = 10;

    /** Yeni çalışmaları ararken son kayıttan bu kadar geriye bakılır (aynı anda başlayanlar kaçmasın). */
    private const OVERLAP_MINUTES = 10;

    private const PAGE_SIZE = 50;

    /** Durumu yenilenen en fazla bitmemiş çalışma. */
    private const MAX_UNFINISHED = 20;

    /** Geçmiş çalışmaların bir kez tamamen alındığını gösteren ayar. */
    private const BACKFILLED = 'runs_index.backfilled_at';

    public function __construct(private readonly KestraClient $kestra, private readonly AppSettings $settings) {}

    /**
     * Yeni ve bitmemiş çalışmaları Kestra'dan alır. Kısa aralıklarla tekrar çağrılırsa bir şey yapmaz.
     *
     * @throws KestraException
     */
    public function sync(bool $force = false): void
    {
        if (! $force && ! Cache::add('runs-index:throttle', true, self::THROTTLE_SECONDS)) {
            return;
        }

        $lock = Cache::lock('runs-index:sync', 120);
        if (! $lock->get()) {
            return;
        }

        try {
            $this->pullNew();
            $this->refreshUnfinished();
        } finally {
            $lock->release();
        }
    }

    private function pullNew(): void
    {
        $prefix = (string) config('kestra.namespace');
        // İlk eşitlemede geçmişin tamamı (bir kez); sonra yalnızca son kayıttan sonra başlayanlar.
        $backfilled = $this->settings->get(self::BACKFILLED) !== null;
        $latest = $backfilled ? Execution::query()->max('started_at') : null;
        $since = $latest === null ? null : Carbon::parse($latest)->subMinutes(self::OVERLAP_MINUTES);
        $flows = Flow::withTrashed()->where('namespace', $prefix)->get()->keyBy('flow_id');

        for ($page = 1; ; $page++) {
            $result = $this->kestra->findExecutions($prefix, page: $page, size: self::PAGE_SIZE, startedAfter: $since);

            foreach ($result['results'] ?? [] as $remote) {
                $this->upsert($remote, $flows);
            }

            if ($page * self::PAGE_SIZE >= ($result['total'] ?? 0) || ($result['results'] ?? []) === []) {
                break;
            }
        }

        if (! $backfilled) {
            $this->settings->set(self::BACKFILLED, now()->toIso8601String());
        }
    }

    /** Çalışıyor veya onay bekliyor görünen kayıtların güncel durumu. */
    private function refreshUnfinished(): void
    {
        $flows = Flow::withTrashed()->where('namespace', config('kestra.namespace'))->get()->keyBy('flow_id');
        $unfinished = Execution::query()
            ->whereNotIn('state', KestraClient::TERMINAL_STATES)
            // Demo verisi (DemoSeeder, sadece yerel) Kestra'da yok.
            ->where('kestra_execution_id', 'not like', 'demo-%')
            ->latest('started_at')
            ->limit(self::MAX_UNFINISHED)
            ->get();

        foreach ($unfinished as $execution) {
            try {
                $this->upsert($this->kestra->getExecution($execution->kestra_execution_id), $flows);
            } catch (KestraException $e) {
                if ($e->status === 404) {
                    // Kestra'dan silinmiş: listede asılı kalmasın.
                    $execution->update(['state' => 'KILLED']);
                }
            }
        }
    }

    /**
     * Kestra çalışmasının özetini kaydeder. Çıktılar ve loglar burada saklanmaz; mevcut adım/log anlık görüntüsü
     * (ayrıntı ekranından) korunur.
     *
     * @param  array<string, mixed>  $remote
     * @param  Collection<string, Flow>  $flows
     */
    public function upsert(array $remote, $flows): ?Execution
    {
        $namespace = $remote['namespace'] ?? null;
        $isTest = $namespace === config('kestra.test_namespace');
        $flow = $flows->get($remote['flowId'] ?? '');

        // Fortis'in bilmediği akışların çalışmaları dizine alınmaz.
        if ($flow === null || ($namespace !== config('kestra.namespace') && ! $isTest)) {
            return null;
        }

        $state = $remote['state']['current'] ?? 'CREATED';
        $start = $remote['state']['startDate'] ?? null;
        $end = $remote['state']['endDate'] ?? null;
        $execution = Execution::query()->firstOrNew(['kestra_execution_id' => $remote['id']]);

        if (! $execution->exists) {
            $execution->fill([
                'flow_id' => $flow->id,
                'flow_version_id' => $isTest
                    ? $flow->latestVersion()?->id
                    : ($flow->versions()->where('kestra_revision', $remote['flowRevision'] ?? null)->value('id') ?? $flow->current_version_id),
                'inputs' => [],
                'started_by' => isset($remote['trigger']['type']) ? class_basename(str_replace('.', '\\', $remote['trigger']['type'])) : 'Elle',
                'is_test' => $isTest,
            ]);
        }

        $execution->fill([
            'state' => $state,
            'started_at' => $start !== null ? Carbon::parse($start) : $execution->started_at,
            'ended_at' => KestraClient::isTerminal($state) && $end !== null ? Carbon::parse($end) : null,
            'snapshot' => ['duration' => $remote['state']['duration'] ?? null] + ($execution->snapshot ?? []),
        ]);
        $execution->save();

        return $execution;
    }
}
