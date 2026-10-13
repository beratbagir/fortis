<?php

namespace App\Services\Flows;

use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Services\Flows\Blocks\ErrorLocator;
use App\Services\Kestra\KestraClient;
use App\Services\Kestra\KestraException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

/**
 * Akış önerilerini sürüm olarak kaydeder ve onaylanan sürümü Kestra'ya yükler.
 */
class FlowService
{
    public function __construct(
        private readonly FlowValidator $validator,
        private readonly FlowCard $cards,
        private readonly KestraClient $kestra,
    ) {}

    /**
     * Doğrulanmış bir öneriyi kaydeder. YAML akışın son sürümüyle aynıysa yeni sürüm açılmaz, son sürüm döner.
     *
     * @param  list<string>  $summary
     * @param  array{canonical_yaml: string|null, secrets: list<string>, warnings: list<string>, missing_secrets: list<string>, flow: array<string, mixed>|null, plugins: list<array<string, mixed>>}  $validation
     */
    public function recordProposal(?Conversation $conversation, string $title, array $summary, array $validation): FlowVersion
    {
        $yaml = (string) $validation['canonical_yaml'];
        $hash = hash('sha256', $yaml);

        return DB::transaction(function () use ($conversation, $title, $summary, $validation, $yaml, $hash) {
            $flow = Flow::withTrashed()->lockForUpdate()->firstOrCreate(
                ['namespace' => $validation['flow']['namespace'], 'flow_id' => $validation['flow']['id']],
                ['title' => $title, 'summary' => $summary, 'status' => Flow::STATUS_DRAFT, 'conversation_id' => $conversation?->id],
            );

            // Silinmiş bir akışın id'siyle yeni öneri: kayıt taslak olarak geri gelir (eski sürümler geçmişte kalır).
            if ($flow->trashed()) {
                $flow->restore();
                $flow->update(['status' => Flow::STATUS_DRAFT, 'current_version_id' => null, 'conversation_id' => $conversation?->id]);
            }

            $latest = $flow->versions()->reorder('number', 'desc')->first();

            if ($latest !== null && $latest->yaml_hash === $hash) {
                return $latest;
            }

            $version = $flow->versions()->create([
                'number' => ($latest->number ?? 0) + 1,
                'title' => $title,
                'summary' => $summary,
                'yaml' => $yaml,
                'yaml_hash' => $hash,
                'secrets_used' => $validation['secrets'],
                'validation' => ['warnings' => $validation['warnings'], 'missing_secrets' => $validation['missing_secrets']],
                'card' => [],
                'source' => FlowVersion::SOURCE_CHAT,
            ]);

            $version->update(['card' => $this->cards->build($title, $summary, $yaml, $validation) + ['flow_version_id' => $version->id]]);

            if (! $flow->wasRecentlyCreated && $flow->status === Flow::STATUS_DRAFT) {
                $flow->update(['title' => $title, 'summary' => $summary]);
            }

            return $version;
        });
    }

    /**
     * Tuvalden, YAML editöründen, geri yüklemeden veya uygulanan chat önerisinden gelen değişikliği yeni taslak
     * sürüm olarak kaydeder. Validator'ı atlayan bir kaydetme yolu yoktur: geçersizse hiçbir şey kaydedilmez.
     *
     * YAML son sürümle aynıysa yeni sürüm açılmaz, son sürüm döner.
     *
     * @param  string|array<string, mixed>  $definition  YAML metni veya ayrıştırılmış akış
     *
     * @throws FlowEditException 409: base_version son sürüm değil; 422: doğrulama hataları (bloklara bağlı)
     */
    public function recordEdit(Flow $flow, string|array $definition, string $source, int $baseVersion, string $createdBy): FlowVersion
    {
        if ($flow->trashed()) {
            throw new FlowEditException(['Bu akış silindi.']);
        }

        $yaml = is_string($definition)
            ? $definition
            : Yaml::dump($definition, 64, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_COMPACT_NESTED_MAPPING);

        // Kestra'ya giden doğrulama dışarıda; çakışma ve kayıt kilit altında yeniden kontrol edilir.
        $latest = $flow->latestVersion();
        if ($latest === null || $latest->number !== $baseVersion) {
            throw FlowEditException::conflict($latest->number ?? 0);
        }

        $check = $this->validator->validate($yaml, FlowValidator::MODE_PROPOSAL);

        if (! $check['valid']) {
            throw new FlowEditException(ErrorLocator::locate($check['flow'], $check['errors']));
        }

        if ($check['flow']['id'] !== $flow->flow_id || $check['flow']['namespace'] !== $flow->namespace) {
            throw new FlowEditException(["Akışın id'si ({$flow->flow_id}) ve namespace'i değiştirilemez."]);
        }

        $canonical = (string) $check['canonical_yaml'];
        $hash = hash('sha256', $canonical);

        return DB::transaction(function () use ($flow, $source, $baseVersion, $createdBy, $check, $canonical, $hash) {
            $latest = $flow->versions()->reorder('number', 'desc')->lockForUpdate()->first();

            if ($latest->number !== $baseVersion) {
                throw FlowEditException::conflict($latest->number);
            }

            if ($latest->yaml_hash === $hash) {
                return $latest;
            }

            $version = $flow->versions()->create([
                'number' => $latest->number + 1,
                'title' => $latest->title,
                'summary' => $latest->summary,
                'yaml' => $canonical,
                'yaml_hash' => $hash,
                'secrets_used' => $check['secrets'],
                'validation' => ['warnings' => $check['warnings'], 'missing_secrets' => $check['missing_secrets']],
                'card' => [],
                'source' => $source,
                'created_by' => $createdBy,
            ]);

            $version->update(['card' => $this->cards->build($latest->title, $latest->summary, $canonical, $check) + ['flow_version_id' => $version->id]]);
            $flow->touch();

            return $version;
        });
    }

    /**
     * Akışın son (taslak) sürümünü yayınlar: yükleme modunda doğrular ve Kestra'ya gönderir.
     *
     * @throws FlowUploadException
     */
    public function publish(Flow $flow, string $publishedBy): FlowVersion
    {
        $latest = $flow->latestVersion();

        if ($latest === null) {
            throw new FlowUploadException(['Akışın sürümü yok.']);
        }

        if ($latest->isUploaded()) {
            throw new FlowUploadException(['Yayınlanacak taslak yok; son sürüm zaten yayında.']);
        }

        return $this->upload($latest, $publishedBy);
    }

    /**
     * Sürümü yükleme modunda yeniden doğrular ve Kestra'ya yükler. Doğrulamadan geçmeyen hiçbir şey gönderilmez.
     *
     * @throws FlowUploadException
     */
    public function upload(FlowVersion $version, string $uploadedBy): FlowVersion
    {
        $flow = $version->flow;

        if ($flow === null || $flow->trashed()) {
            throw new FlowUploadException(['Bu akış silindi.']);
        }

        if ($version->isUploaded()) {
            throw new FlowUploadException(['Bu sürüm zaten yüklendi.']);
        }

        if ($flow->versions()->where('number', '>', $version->number)->exists()) {
            throw new FlowUploadException(['Bu akışın daha yeni bir sürümü var. Son sürümü yükleyin.']);
        }

        $validation = $this->validator->validate($version->yaml, FlowValidator::MODE_UPLOAD);

        if (! $validation['valid']) {
            throw new FlowUploadException($validation['errors']);
        }

        // Saklanan metin zaten kanoniktir; değilse (elle değiştirilmiş vb.) yüklenmez.
        if ($validation['canonical_yaml'] !== $version->yaml
            || $validation['flow']['namespace'] !== $flow->namespace
            || $validation['flow']['id'] !== $flow->flow_id) {
            throw new FlowUploadException(['Saklanan akış doğrulanan akışla aynı değil. Akışı chat\'te yeniden oluşturun.']);
        }

        $keepInactive = $flow->status === Flow::STATUS_INACTIVE;

        try {
            $saved = $this->kestra->flowExists($flow->namespace, $flow->flow_id)
                ? $this->kestra->updateFlow($flow->namespace, $flow->flow_id, $version->yaml)
                : $this->kestra->createFlow($version->yaml);

            // Pasif akışın yeni sürümü de pasif kalır (yeni YAML Kestra'da akışı yeniden aktifleştirir).
            if ($keepInactive) {
                $this->kestra->setFlowsEnabled([[$flow->namespace, $flow->flow_id]], enabled: false);
            }
        } catch (KestraException $e) {
            throw new FlowUploadException([$e->getMessage()]);
        }

        DB::transaction(function () use ($version, $flow, $saved, $uploadedBy, $keepInactive) {
            $version->update([
                'kestra_revision' => $saved['revision'] ?? null,
                'uploaded_at' => now(),
                'uploaded_by' => $uploadedBy,
            ]);

            $flow->update([
                'status' => $keepInactive ? Flow::STATUS_INACTIVE : Flow::STATUS_ACTIVE,
                'current_version_id' => $version->id,
                'title' => $version->title,
                'summary' => $version->summary,
            ]);
        });

        return $version->refresh();
    }
}
