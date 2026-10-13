<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use App\Services\Auth\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bir artifact'ın içeriği ve sürüm listesi. İçerik tarayıcıda yalıtılmış bir iframe'de gösterilir. Sayfa türünde
 * (create_page) ayrıca maskeli veri anlık görüntüsü, kaynaklar ve uyarı; görüntüleme ve indirme kayda geçer.
 * Sahibi (ve admin) görür (ArtifactPolicy); id tahmin edilemez (ulid) ve sohbetteki karttan gelir.
 */
class ArtifactController extends Controller
{
    public function show(Request $request, Artifact $artifact): JsonResponse
    {
        $number = $request->integer('version') ?: $artifact->latest_version;
        $version = $artifact->versions()->where('number', $number)->firstOrFail();

        // Sayfa: veri ve kaynaklarla; görüntüleme denetim kaydına (sürüm başına değil her açılışta).
        $page = $artifact->kind === Artifact::PAGE ? [
            'description' => $artifact->description,
            'sources' => $version->sources ?? [],
            'data' => (object) ($version->data ?? []),
            'data_at' => $version->data_at?->toIso8601String(),
            'warning' => $version->warning,
        ] : [];
        if ($page !== []) {
            Audit::record('artifact.view', $artifact, meta: ['artifact_id' => $artifact->id, 'version' => $version->number]);
        }

        return response()->json(['data' => $page + [
            'id' => $artifact->id,
            'conversation_id' => $artifact->conversation_id,
            'kind' => $artifact->kind,
            'title' => $version->title,
            'version' => $version->number,
            'latest_version' => $artifact->latest_version,
            'content' => $version->content,
            'versions' => $artifact->versions->map(fn (ArtifactVersion $v) => [
                'number' => $v->number,
                'title' => $v->title,
                'created_at' => $v->created_at?->toIso8601String(),
            ])->values(),
        ]]);
    }

    /** Sayfa indirildi (dosya tarayıcıda, aynı veriyle üretilir): denetim kaydı. */
    public function downloaded(Request $request, Artifact $artifact): JsonResponse
    {
        abort_unless($artifact->kind === Artifact::PAGE, 404);
        $version = (int) ($request->integer('version') ?: $artifact->latest_version);
        Audit::record('artifact.download', $artifact, meta: ['artifact_id' => $artifact->id, 'version' => $version]);

        return response()->json(['data' => ['ok' => true]]);
    }
}
