<?php

namespace App\Ai;

use App\Models\Conversation;
use App\Services\Settings\AppSettings;

/**
 * Hangi turda hangi model. Kullanıcı sohbette bir model seçtiyse her turda o kullanılır. Seçmediyse ("Auto"):
 * ilk tur genel varsayılan model, bir veri sorgusundan (query_*) sonraki turlar veri sorusu modeli,
 * kısa işler (sohbet başlığı) hızlı model. Sadece izinli modeller (Politikalar) döner.
 */
class ModelRouter
{
    public function __construct(private readonly AppSettings $settings) {}

    public function isAuto(Conversation $conversation): bool
    {
        return ! $this->settings->isAllowedModel($conversation->model);
    }

    /**
     * @return array{model: string, auto: bool, reason: string}
     */
    public function forTurn(Conversation $conversation, bool $afterDataQuery): array
    {
        if (! $this->isAuto($conversation)) {
            return ['model' => $conversation->model, 'auto' => false, 'reason' => 'Sohbette seçilen model'];
        }

        if ($afterDataQuery && $this->settings->isAllowedModel($this->settings->dataQuestionModel())) {
            return ['model' => $this->settings->dataQuestionModel(), 'auto' => true, 'reason' => 'Veri çekme ve çok adımlı karşılaştırma'];
        }

        return ['model' => $this->settings->defaultModel(), 'auto' => true, 'reason' => 'Genel varsayılan model'];
    }

    /** Kısa işler (sohbet başlığı): hızlı model, izinli değilse genel varsayılan. */
    public function fast(): string
    {
        $fast = $this->settings->fastModel();

        return $this->settings->isAllowedModel($fast) ? $fast : $this->settings->defaultModel();
    }

    public function label(string $model): string
    {
        return $this->settings->modelLabel($model);
    }
}
