<?php

namespace App\Services\Artifacts;

use App\Ai\ChartBlocks;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use App\Models\Conversation;
use App\Services\Auth\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Sohbette HTML sayfa (create_page, H1). Modelin yazdığı HTML güvenilmeyen koddur: tarayıcıda allow-same-origin'siz
 * sandbox iframe'de, ağı kapatan CSP ile açılır (bkz. frontend pageDocument.ts). Burada savunmanın sunucu katmanı:
 *
 * - Kaynaklar bu cevapta çalışmış sorgular olmalı (güncellemede önceki sürümün kaynakları da geçerli).
 * - Veri modelden değil, o sorguların Claude'a giden (AiDataGate'ten geçmiş, maskeli) satırlarından alınır ve
 *   sayfaya window.FORTIS_DATA olarak enjekte edilir.
 * - /vendor/ dışındaki <script src>, <link href> ve belge seviyesi etiketler temizlenir.
 * - HTML en fazla 200 KB, veri en fazla 2 MB.
 * - FORTIS_DATA kullanmayıp 20'den fazla sayı gömen sayfaya "Veri kaynağı doğrulanamadı" uyarısı eklenir.
 */
class PageService
{
    public const MAX_HTML_BYTES = 200_000;

    public const MAX_DATA_BYTES = 2_000_000;

    public const MAX_SOURCES = 10;

    public const WARNING_UNVERIFIED = 'Veri kaynağı doğrulanamadı';

    /**
     * @param  array<string, mixed>  $input  title, description, sources (q:ID listesi), html, artifact_id?
     * @param  array<string, array{source?: string|null, purpose?: string|null, at?: string|null}>  $answerQueries  Bu cevapta çalışmış sorgular
     * @return array{artifact: Artifact, version: ArtifactVersion}
     *
     * @throws ArtifactException
     */
    public function save(Conversation $conversation, array $input, array $answerQueries): array
    {
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        $description = is_string($input['description'] ?? null) ? trim($input['description']) : '';
        $html = $input['html'] ?? null;
        $sources = $input['sources'] ?? null;
        $id = $input['artifact_id'] ?? null;

        if ($title === '' || mb_strlen($title) > 120) {
            throw new ArtifactException('title 1-120 karakter olmalı.');
        }
        if (mb_strlen($description) > 300) {
            throw new ArtifactException('description en fazla 300 karakter.');
        }
        if (! is_string($html) || trim($html) === '') {
            throw new ArtifactException('html boş olamaz.');
        }
        if (strlen($html) > self::MAX_HTML_BYTES) {
            throw new ArtifactException('HTML çok büyük (en fazla 200 KB). Daha sade bir sayfa üret; veriyi HTML\'e gömme, FORTIS_DATA\'dan oku.');
        }
        if (! is_array($sources) || ! array_is_list($sources) || $sources === [] || count($sources) > self::MAX_SOURCES) {
            throw new ArtifactException('sources 1–'.self::MAX_SOURCES.' sorgu kimliği ("q:ID") listesi olmalı.');
        }
        if ($id !== null && ! is_string($id)) {
            throw new ArtifactException('artifact_id metin olmalı.');
        }

        $previous = null;
        if ($id !== null) {
            $artifact = Artifact::query()->where('conversation_id', $conversation->id)->where('kind', Artifact::PAGE)->find($id)
                ?? throw new ArtifactException("Bu sohbette {$id} id'li bir sayfa yok. Yeni sayfa için artifact_id verme.");
            $previous = $artifact->versions()->where('number', $artifact->latest_version)->first();
        }

        // Kaynaklar: bu cevabın sorguları; güncellemede önceki sürümün kaynakları da (verisi anlık görüntüden).
        $ids = [];
        foreach ($sources as $source) {
            if (! is_string($source) || ! preg_match('/^q:([A-Za-z0-9_]+)$/', $source, $m)) {
                throw new ArtifactException('Her kaynak "q:ID" biçiminde olmalı.');
            }
            $ids[] = $m[1];
        }
        $ids = array_values(array_unique($ids));
        $previousData = $previous?->data ?? [];
        $unknown = array_values(array_filter($ids, fn (string $qid) => ! isset($answerQueries[$qid]) && ! isset($previousData["q:{$qid}"])));
        if ($unknown !== []) {
            throw new ArtifactException('Bu kaynaklar bu cevapta çalışmış sorgular değil: '.implode(', ', array_map(fn ($u) => "q:{$u}", $unknown)).'. Önce sorguyu çalıştır, sonra sonucundaki query_id\'yi kullan.');
        }

        $rows = ChartBlocks::sourceRows($conversation, array_values(array_filter($ids, fn (string $qid) => isset($answerQueries[$qid]))));
        $data = [];
        foreach ($ids as $qid) {
            if (isset($rows[$qid])) {
                $meta = $answerQueries[$qid];
                $data["q:{$qid}"] = [
                    'source' => $rows[$qid]['source'] ?? $meta['source'] ?? null,
                    'purpose' => $meta['purpose'] ?? null,
                    'at' => $meta['at'] ?? now()->toIso8601String(),
                    'columns' => $rows[$qid]['columns'],
                    'rows' => $rows[$qid]['rows'],
                    'total_rows' => $rows[$qid]['total_rows'] ?? count($rows[$qid]['rows']),
                    'truncated' => (bool) ($rows[$qid]['truncated'] ?? false),
                ];
            } elseif (isset($previousData["q:{$qid}"])) {
                $data["q:{$qid}"] = $previousData["q:{$qid}"];
            } else {
                throw new ArtifactException("q:{$qid} sorgusunun sonucu bulunamadı.");
            }
        }
        if (strlen((string) json_encode($data, JSON_UNESCAPED_UNICODE)) > self::MAX_DATA_BYTES) {
            throw new ArtifactException('Sayfa verisi çok büyük (en fazla 2 MB). Sorguyu veritabanında toplulaştır (GROUP BY) ve daha az satır çek.');
        }

        $clean = self::sanitize($html);
        $warning = self::usesData($clean) || self::numberCount($clean) <= 20 ? null : self::WARNING_UNVERIFIED;
        $dataAt = collect($data)->pluck('at')->filter()->sort()->last();

        $result = DB::transaction(function () use ($conversation, $title, $description, $clean, $ids, $data, $warning, $dataAt, $id) {
            if ($id !== null) {
                $artifact = Artifact::query()->lockForUpdate()->findOrFail($id);
                $artifact->update(['title' => $title, 'description' => $description ?: null, 'latest_version' => $artifact->latest_version + 1]);
            } else {
                $artifact = Artifact::create(['conversation_id' => $conversation->id, 'title' => $title, 'description' => $description ?: null, 'kind' => Artifact::PAGE, 'latest_version' => 1]);
            }

            $version = $artifact->versions()->create([
                'number' => $artifact->latest_version,
                'title' => $title,
                'content' => $clean,
                'sources' => array_map(fn (string $qid) => "q:{$qid}", $ids),
                'data' => $data,
                'data_at' => $dataAt,
                'warning' => $warning,
            ]);

            return ['artifact' => $artifact, 'version' => $version];
        });

        Audit::record('artifact.create', $result['artifact'], meta: [
            'artifact_id' => $result['artifact']->id,
            'kind' => Artifact::PAGE,
            'version' => $result['version']->number,
            'sources' => $result['version']->sources,
            'html_bytes' => strlen($clean),
            'warning' => $warning,
        ]);

        return $result;
    }

    /**
     * Belge seviyesi ve dış kaynaklı etiketleri temizler. Asıl koruma tarayıcıdaki CSP ve sandbox'tır; bu katman
     * izin listesi dışındaki adreslerin hiç istenmemesini ve kabuğun (CSP, tema, veri) bozulmamasını sağlar.
     */
    public static function sanitize(string $html): string
    {
        $vendor = fn (string $url) => (bool) preg_match('#^/vendor/[A-Za-z0-9._/-]+$#', $url) && ! str_contains($url, '..');

        // Dış <script src>: /vendor/ değilse etiket (ve içeriği) tamamen çıkar.
        $html = (string) preg_replace_callback('#<script\b([^>]*)>(.*?)</script\s*>#is', function (array $m) use ($vendor) {
            if (preg_match('#\bsrc\s*=\s*(["\']?)([^"\'\s>]+)\1#i', $m[1], $src)) {
                return $vendor($src[2]) ? '<script src="'.$src[2].'"></script>' : '';
            }

            return $m[0];
        }, $html);
        // <link>: sadece /vendor/ stil dosyası.
        $html = (string) preg_replace_callback('#<link\b[^>]*>#i', function (array $m) use ($vendor) {
            return preg_match('#\bhref\s*=\s*(["\']?)([^"\'\s>]+)\1#i', $m[0], $href) && $vendor($href[2]) && preg_match('#\brel\s*=\s*(["\']?)stylesheet\1#i', $m[0])
                ? '<link rel="stylesheet" href="'.$href[2].'">'
                : '';
        }, $html);
        // Belge iskeleti ve çerçeveyi değiştirebilecek etiketler (kabuk sunucuda/istemcide eklenir).
        $html = (string) preg_replace('#<!doctype[^>]*>|</?(html|head|body)\b[^>]*>|<(meta|base)\b[^>]*>|<(iframe|frame|frameset|object|embed|portal)\b[^>]*>(.*?</\4\s*>)?#is', '', $html);

        return trim($html);
    }

    public static function usesData(string $html): bool
    {
        return str_contains($html, 'FORTIS_DATA') || str_contains($html, 'fortis.data(');
    }

    /** Gömülü sayısal değer sayısı (script ve metin; CSS değerleri ve etiket öznitelikleri hariç). */
    public static function numberCount(string $html): int
    {
        $text = (string) preg_replace('#<style\b.*?</style>#is', '', $html);
        $text = (string) preg_replace('#<[^>]+>#', ' ', $text);

        return preg_match_all('/(?<![\w.#-])\d+(?:[.,]\d+)?(?![\w%])/u', $text);
    }
}
