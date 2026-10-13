<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bir artifact'ın içeriği ve sürüm listesi. İçerik tarayıcıda yalıtılmış bir iframe'de gösterilir.
 * Sahibi (ve admin) görür (ArtifactPolicy); id tahmin edilemez (ulid) ve sohbetteki karttan gelir.
 */
class ArtifactController extends Controller
{
    public function show(Request $request, Artifact $artifact): JsonResponse
    {
        $number = $request->integer('version') ?: $artifact->latest_version;
        $version = $artifact->versions()->where('number', $number)->firstOrFail();

        return response()->json(['data' => [
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
}
