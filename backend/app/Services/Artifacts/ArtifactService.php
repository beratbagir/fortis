<?php

namespace App\Services\Artifacts;

use App\Models\Artifact;
use App\Models\Conversation;
use Illuminate\Support\Facades\DB;

/**
 * Claude'un create_artifact aracıyla ürettiği içerikleri saklar. Aynı artifact_id ile gelen içerik yeni bir sürümdür.
 * İçerik çalıştırılmaz; tarayıcıda Fortis'ten yalıtılmış bir iframe'de gösterilir.
 */
class ArtifactService
{
    /** Tek bir sürümün en fazla boyutu (bayt). */
    public const MAX_BYTES = 400_000;

    /**
     * @param  array<string, mixed>  $input
     * @return array{artifact: Artifact, version: int}
     *
     * @throws ArtifactException
     */
    public function save(Conversation $conversation, array $input): array
    {
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        $kind = $input['kind'] ?? null;
        $content = $input['content'] ?? null;
        $id = $input['artifact_id'] ?? null;

        if ($title === '' || mb_strlen($title) > 120) {
            throw new ArtifactException('title 1-120 karakter olmalı.');
        }
        if (! in_array($kind, Artifact::KINDS, true)) {
            throw new ArtifactException('kind şunlardan biri olmalı: '.implode(', ', Artifact::KINDS).'.');
        }
        if (! is_string($content) || trim($content) === '') {
            throw new ArtifactException('content boş olamaz.');
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw new ArtifactException('İçerik çok büyük (en fazla 400 KB). Daha sade bir sürüm üret.');
        }
        if ($id !== null && ! is_string($id)) {
            throw new ArtifactException('artifact_id metin olmalı.');
        }

        return DB::transaction(function () use ($conversation, $title, $kind, $content, $id) {
            if ($id !== null) {
                $artifact = Artifact::query()->where('conversation_id', $conversation->id)->lockForUpdate()->find($id)
                    ?? throw new ArtifactException("Bu sohbette {$id} id'li bir artifact yok. Yeni bir artifact için artifact_id verme.");
                $artifact->update(['title' => $title, 'kind' => $kind, 'latest_version' => $artifact->latest_version + 1]);
            } else {
                $artifact = Artifact::create(['conversation_id' => $conversation->id, 'title' => $title, 'kind' => $kind, 'latest_version' => 1]);
            }

            $artifact->versions()->create(['number' => $artifact->latest_version, 'title' => $title, 'content' => $content]);

            return ['artifact' => $artifact, 'version' => $artifact->latest_version];
        });
    }
}
