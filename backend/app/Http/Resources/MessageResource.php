<?php

namespace App\Http\Resources;

use App\Ai\AnswerTrace;
use App\Http\Controllers\Api\ReportController;
use App\Models\FlowProposal;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\MessageFeedback;
use App\Models\Report;
use App\Services\Flows\FlowCardStatus;
use App\Services\Settings\AppSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * Ham içerik blokları (düşünme blokları dahil) frontend'e gönderilmez; sadece görünen metin gider.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'role' => $this->role,
            'text' => $this->text,
            'stop_reason' => $this->stop_reason,
            'status' => $this->status,
            'flow_cards' => $this->flowCards(),
            // Soru kartı: cevap bekleniyorsa sorular.
            'ask' => $this->isAwaitingInput() ? ['questions' => $this->meta['ask']['questions'] ?? []] : null,
            'ask_answered' => isset($this->meta['ask']) && ! $this->isAwaitingInput(),
            // Cevabın izi, kaynakları, modelleri. Sorgu metni sadece admin ve builder'a.
            'answer' => $this->answer($request),
            'feedback' => $this->role === Message::ROLE_ASSISTANT && $request->user() !== null
                ? MessageFeedback::query()->where('message_id', $this->id)->where('user_id', $request->user()->id)->value('rating')
                : null,
            // Veri sorgusu adımları: amaç, kaynak, satır sayısı, kişisel bilgi gizlendi mi. Dönen veri tutulmaz.
            'queries' => array_values(array_map(
                fn (array $query) => array_intersect_key($query, array_flip(['tool', 'source', 'purpose', 'rows', 'total_rows', 'masked', 'error'])),
                $this->meta['queries'] ?? [],
            )),
            'created_at' => $this->created_at,
        ];
    }

    /** @return array<string, mixed>|null */
    private function answer(Request $request): ?array
    {
        $answer = $this->meta['answer'] ?? null;

        if ($answer === null) {
            return null;
        }

        $viewer = $request->user();
        $answer['trace'] = array_map(fn (array $step) => AnswerTrace::forViewer($step, $viewer), $answer['trace'] ?? []);
        $answer['queries'] = array_map(fn (array $query) => AnswerTrace::forViewer($query, $viewer), $answer['queries'] ?? []);
        $answer['model_labels'] = array_map(fn (string $model) => app(AppSettings::class)->modelLabel($model), $answer['models'] ?? []);

        return $answer;
    }

    /**
     * Kartlar, sürümlerinin güncel durumuyla (yüklendi mi, yüklenebilir mi) birlikte döner.
     *
     * @return list<array<string, mixed>>
     */
    private function flowCards(): array
    {
        $cards = $this->meta['flow_cards'] ?? [];

        if ($cards === []) {
            return [];
        }

        $reports = Report::query()->findMany(array_column(array_filter($cards, fn (array $card) => ($card['type'] ?? null) === 'report'), 'report_id'))->keyBy('id');
        $proposals = FlowProposal::query()->findMany(array_column(array_filter($cards, fn (array $card) => ($card['type'] ?? null) === 'proposal'), 'proposal_id'))->keyBy('id');
        $versions = FlowVersion::query()->with('flow')->findMany(array_filter(array_column($cards, 'flow_version_id')))->keyBy('id');
        $status = app(FlowCardStatus::class);

        return array_map(
            fn (array $card) => match ($card['type'] ?? null) {
                'proposal' => $card + ['status' => $proposals->get($card['proposal_id'])?->status],
                'artifact' => $card,
                'report' => ['type' => 'report'] + ($reports->has($card['report_id']) ? ReportController::present($reports->get($card['report_id'])) : ['id' => $card['report_id'], 'status' => 'missing']),
                default => $status->withStatus($card, $versions->get($card['flow_version_id'] ?? null), $this->conversation_id),
            },
            $cards,
        );
    }
}
