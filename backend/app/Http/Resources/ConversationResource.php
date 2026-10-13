<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use App\Services\Settings\AppSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'kind' => $this->kind,
            'model' => $this->model,
            'effective_model' => $this->model ?? app(AppSettings::class)->defaultModel(),
            // Model seçicide "Auto": ModelRouter her tur için seçer.
            'auto' => ! app(AppSettings::class)->isAllowedModel($this->model),
            'disabled_sources' => $this->disabledSources(),
            // Sohbetin token ve maliyeti (sohbet panelinin altında gösterilir).
            'usage' => $this->usage(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'context_flow' => $this->contextFlow === null ? null : [
                'id' => $this->contextFlow->id,
                'flow_id' => $this->contextFlow->flow_id,
                'namespace' => $this->contextFlow->namespace,
                'title' => $this->contextFlow->title,
            ],
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
        ];
    }

    /** @return array{calls: int, tokens: int, cost_usd: float} */
    private function usage(): array
    {
        $row = $this->aiCalls()
            ->selectRaw('COUNT(*) AS calls, COALESCE(SUM(input_tokens + output_tokens + cache_creation_input_tokens + cache_read_input_tokens), 0) AS tokens, COALESCE(SUM(cost_usd), 0) AS cost')
            ->first();

        return ['calls' => (int) $row->calls, 'tokens' => (int) $row->tokens, 'cost_usd' => round((float) $row->cost, 6)];
    }
}
