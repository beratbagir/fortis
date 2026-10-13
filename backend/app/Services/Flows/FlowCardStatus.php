<?php

namespace App\Services\Flows;

use App\Http\Resources\ExecutionResource;
use App\Models\Execution;
use App\Models\FlowVersion;
use App\Services\Kestra\KestraClient;

/**
 * Bir akış kartının güncel durumu: yüklendi mi, daha yeni sürüm var mı, eksik secret var mı, yüklenebilir mi.
 *
 * Kart verisi öneri anında dondurulur; durum ise her gösterimde veritabanından ve güncel secret listesinden hesaplanır.
 */
class FlowCardStatus
{
    /** Kartta gösterilen en fazla çalışma sayısı. */
    private const MAX_EXECUTIONS = 5;

    public function __construct(private readonly Catalog $catalog, private readonly KestraClient $kestra) {}

    /**
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    public function withStatus(array $card, ?FlowVersion $version, ?int $conversationId = null): array
    {
        if ($version === null) {
            return $card + [
                'status' => ['uploaded' => false, 'can_upload' => false, 'reason' => 'Bu kartın kaydı bulunamadı.'],
                'run' => ['can_run' => false, 'reason' => null, 'inputs' => [], 'webhook_url' => null],
                'executions' => [],
            ];
        }

        return array_merge($card, [
            'flow_id' => $version->flow->flow_id,
            'flow_record_id' => $version->flow_id,
            'flow_version_id' => $version->id,
            'version' => $version->number,
            'missing_secrets' => $this->missingSecrets($version),
            'status' => $this->status($version),
            'run' => $this->run($version),
            'executions' => $this->executions($version, $conversationId),
        ]);
    }

    /**
     * Çalıştırma bilgisi. Sadece Kestra'da yüklü olan güncel sürüm çalıştırılabilir.
     *
     * @return array{can_run: bool, reason: string|null, inputs: list<array<string, mixed>>, webhook_url: string|null}
     */
    public function run(FlowVersion $version): array
    {
        $flow = $version->flow;
        $isCurrent = $flow !== null && ! $flow->trashed() && $flow->isInKestra() && $flow->current_version_id === $version->id;
        $definition = FlowDefinition::fromYaml($version->yaml);
        $key = $definition->webhookKey();

        return [
            'can_run' => $isCurrent,
            'reason' => match (true) {
                $isCurrent => null,
                $flow === null || $flow->trashed() => 'Bu akış silindi.',
                ! $version->isUploaded() => 'Çalıştırmak için önce yükleyin.',
                default => 'Kestra\'daki sürüm bu değil.',
            },
            'inputs' => $definition->inputs(),
            'webhook_url' => $isCurrent && $key !== null ? $this->kestra->webhookUrl($flow->namespace, $flow->flow_id, $key) : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function executions(FlowVersion $version, ?int $conversationId): array
    {
        if ($conversationId === null) {
            return [];
        }

        return Execution::query()
            ->with('flow')
            ->where('flow_version_id', $version->id)
            ->where('conversation_id', $conversationId)
            ->latest('id')
            ->limit(self::MAX_EXECUTIONS)
            ->get()
            ->map(fn (Execution $execution) => (new ExecutionResource($execution))->resolve())
            ->all();
    }

    /** @return array<string, mixed> */
    public function status(FlowVersion $version): array
    {
        $isLatest = ! FlowVersion::query()
            ->where('flow_id', $version->flow_id)
            ->where('number', '>', $version->number)
            ->exists();

        $missing = $this->missingSecrets($version);

        $deleted = $version->flow === null || $version->flow->trashed();

        $reason = match (true) {
            $deleted => 'Bu akış silindi.',
            $version->isUploaded() => null,
            ! $isLatest => 'Bu akışın daha yeni bir sürümü var.',
            $missing !== [] => 'Tanımlı olmayan bilgiler eklenmeden yüklenemez.',
            default => null,
        };

        return [
            'uploaded' => $version->isUploaded(),
            'uploaded_at' => $version->uploaded_at?->toIso8601String(),
            'kestra_revision' => $version->kestra_revision,
            'is_latest' => $isLatest,
            'can_upload' => ! $deleted && ! $version->isUploaded() && $isLatest && $missing === [],
            'reason' => $reason,
        ];
    }

    /** @return list<string> */
    private function missingSecrets(FlowVersion $version): array
    {
        return array_values(array_diff($version->secrets_used, $this->catalog->secrets()));
    }
}
