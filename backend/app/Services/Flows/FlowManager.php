<?php

namespace App\Services\Flows;

use App\Models\Conversation;
use App\Models\Flow;
use App\Services\Kestra\KestraClient;
use App\Services\Kestra\KestraException;
use Illuminate\Support\Collection;

/**
 * Akışlar sayfası: Laravel kayıtlarını Kestra'nın gerçek durumuyla birleştirir; aktif/pasif yapma,
 * silme ve "bu akışı chat'te değiştir".
 */
class FlowManager
{
    public function __construct(private readonly KestraClient $kestra, private readonly Catalog $catalog) {}

    /**
     * @return array{
     *     flows: list<array{flow: Flow, kestra: array<string, mixed>|null, last_execution: array<string, mixed>|null, drift: string|null}>,
     *     unmanaged: list<array{flow_id: string, revision: int|null, disabled: bool}>,
     *     kestra_error: string|null,
     * }
     */
    public function overview(): array
    {
        $flows = Flow::query()->with(['currentVersion', 'conversation'])->latest('updated_at')->latest('id')->get();
        $error = null;

        try {
            $remote = $this->remoteFlows();
            $lastExecutions = $this->lastExecutions($flows->pluck('flow_id')->all());
        } catch (KestraException $e) {
            $remote = null;
            $lastExecutions = collect();
            $error = $e->getMessage();
        }

        return [
            'flows' => $flows->map(fn (Flow $flow) => [
                'flow' => $flow,
                'kestra' => $remote?->get($flow->flow_id),
                'last_execution' => $lastExecutions->get($flow->flow_id),
                'drift' => $remote === null ? null : $this->drift($flow, $remote->get($flow->flow_id)),
            ])->all(),
            'unmanaged' => $remote === null ? [] : $remote
                ->reject(fn (array $info, string $id) => $flows->contains('flow_id', $id))
                ->map(fn (array $info, string $id) => ['flow_id' => $id] + $info)
                ->values()
                ->all(),
            'kestra_error' => $error,
        ];
    }

    /**
     * Akışın son çalışmaları (zamanlayıcı ve webhook ile başlayanlar dahil), en yeniden eskiye.
     *
     * @return list<array<string, mixed>>
     *
     * @throws KestraException
     */
    public function recentExecutions(Flow $flow, int $size = 10): array
    {
        return array_map(
            fn (array $execution) => self::describeExecution($execution),
            $this->kestra->searchExecutions($flow->namespace, $flow->flow_id, $size)['results'] ?? [],
        );
    }

    /** @throws FlowManagementException */
    public function setEnabled(Flow $flow, bool $enabled): Flow
    {
        if (! $flow->isInKestra()) {
            throw new FlowManagementException('Akış henüz Kestra\'ya yüklenmedi.');
        }

        try {
            // Toplu uç, olmayan akış için hata vermeden 0 akış güncelleyebilir; durum yanlış kaydedilmesin.
            if (! $this->kestra->flowExists($flow->namespace, $flow->flow_id)) {
                throw new FlowManagementException('Akış Kestra\'da bulunamadı. Yeniden yükleyin veya silin.');
            }

            $this->kestra->setFlowsEnabled([[$flow->namespace, $flow->flow_id]], $enabled);
        } catch (KestraException $e) {
            throw new FlowManagementException($e->getMessage());
        }

        $flow->update(['status' => $enabled ? Flow::STATUS_ACTIVE : Flow::STATUS_INACTIVE]);

        return $flow;
    }

    /**
     * Akışı Kestra'dan siler (zaten yoksa sorun değil) ve kaydı yumuşak siler.
     *
     * @throws FlowManagementException
     */
    public function delete(Flow $flow): void
    {
        try {
            if ($this->kestra->flowExists($flow->namespace, $flow->flow_id)) {
                $this->kestra->deleteFlow($flow->namespace, $flow->flow_id);
            }
        } catch (KestraException $e) {
            throw new FlowManagementException($e->getMessage());
        }

        $flow->delete();
    }

    /**
     * Akışı değiştirmek için yeni bir sohbet açar. Mevcut YAML, kullanıcının ilk mesajıyla Claude'a bağlam olarak gider.
     */
    public function startEditConversation(Flow $flow): Conversation
    {
        return Conversation::create([
            'title' => "{$flow->title} — değişiklik",
            'context_flow_id' => $flow->id,
        ]);
    }

    /**
     * Sohbetin konusu olan akışın Claude'a verilecek bağlam notu.
     */
    public function contextNote(Flow $flow): string
    {
        $version = $flow->currentVersion ?? $flow->versions()->reorder('number', 'desc')->first();
        $state = match ($flow->status) {
            Flow::STATUS_ACTIVE => 'Kestra\'da aktif',
            Flow::STATUS_INACTIVE => 'Kestra\'da pasif',
            default => 'henüz yüklenmemiş taslak',
        };

        return implode("\n", [
            "[Sistem notu: Kullanıcı mevcut \"{$flow->title}\" akışını ({$flow->namespace}.{$flow->flow_id}) değiştirmek istiyor.",
            "Durum: {$state}. Sürüm: ".($version->number ?? '?').($version?->kestra_revision ? ", Kestra revision {$version->kestra_revision}" : '').'.',
            'Değişikliği aynı id ile yeni bir kart olarak öner. Mevcut YAML:',
            '```yaml',
            rtrim((string) $version?->yaml),
            '```]',
        ]);
    }

    /** @return Collection<string, array{revision: int|null, disabled: bool}> */
    private function remoteFlows(): Collection
    {
        $results = $this->kestra->searchFlows($this->catalog->namespace(), size: 500)['results'] ?? [];

        return collect($results)
            ->filter(fn (array $flow) => ($flow['namespace'] ?? null) === $this->catalog->namespace())
            ->mapWithKeys(fn (array $flow) => [$flow['id'] => [
                'revision' => $flow['revision'] ?? null,
                'disabled' => (bool) ($flow['disabled'] ?? false),
            ]]);
    }

    /**
     * Her akışın son çalışması. Kestra'nın "latest" ucu görev listesi döndürmediği için hafif (çalışma araması
     * görev çıktılarıyla birlikte MB'larca veri getiriyordu).
     *
     * @param  list<string>  $flowIds
     * @return Collection<string, array<string, mixed>>
     */
    private function lastExecutions(array $flowIds): Collection
    {
        $namespace = $this->catalog->namespace();
        $latest = $this->kestra->latestExecutions(array_map(fn (string $id) => ['namespace' => $namespace, 'id' => $id], $flowIds));

        return collect(array_is_list($latest) ? $latest : [])
            ->filter(fn (mixed $execution) => is_array($execution) && ($execution['namespace'] ?? null) === $namespace)
            ->mapWithKeys(fn (array $execution) => [$execution['flowId'] => [
                'kestra_execution_id' => $execution['id'],
                'state' => $execution['status'] ?? null,
                'start' => $execution['startDate'] ?? null,
                'end' => null,
                'duration' => null,
                'trigger' => null,
            ]]);
    }

    /** @param  array{revision: int|null, disabled: bool}|null  $remote */
    private function drift(Flow $flow, ?array $remote): ?string
    {
        return match (true) {
            $flow->isInKestra() && $remote === null => 'Kestra\'da bulunamadı. Akış Kestra\'dan silinmiş olabilir.',
            $flow->status === Flow::STATUS_DRAFT && $remote !== null => 'Kestra\'da bu id ile bir akış var; yüklemek onu değiştirir.',
            $flow->status === Flow::STATUS_ACTIVE && $remote['disabled'] => 'Kestra\'da pasif görünüyor.',
            $flow->status === Flow::STATUS_INACTIVE && ! $remote['disabled'] => 'Kestra\'da aktif görünüyor.',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $execution
     * @return array<string, mixed>
     */
    private static function describeExecution(array $execution): array
    {
        return [
            'kestra_execution_id' => $execution['id'],
            'state' => $execution['state']['current'] ?? null,
            'start' => $execution['state']['startDate'] ?? null,
            'end' => $execution['state']['endDate'] ?? null,
            'duration' => $execution['state']['duration'] ?? null,
            'trigger' => isset($execution['trigger']['type']) ? class_basename(str_replace('.', '\\', $execution['trigger']['type'])) : 'Elle',
        ];
    }
}
