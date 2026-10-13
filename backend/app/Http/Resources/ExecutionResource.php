<?php

namespace App\Http\Resources;

use App\Models\Execution;
use App\Services\Flows\Blocks\ExecutionTimeline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Execution
 */
class ExecutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->snapshot ?? [];

        return [
            'id' => $this->id,
            'flow_id' => $this->flow_id,
            'flow_version_id' => $this->flow_version_id,
            'conversation_id' => $this->conversation_id,
            'flow' => [
                'namespace' => $this->flow->namespace,
                'flow_id' => $this->flow->flow_id,
                'title' => $this->flow->title,
            ],
            'kestra_execution_id' => $this->kestra_execution_id,
            'state' => $this->state,
            'finished' => $this->isFinished(),
            'inputs' => $this->inputs ?? [],
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'duration' => $snapshot['duration'] ?? null,
            'task_runs' => $snapshot['task_runs'] ?? [],
            'logs' => $snapshot['logs'] ?? [],
            'paused' => $snapshot['paused'] ?? null,
            'is_test' => (bool) $this->is_test,
            'steps' => app(ExecutionTimeline::class)->build($this->flowVersion?->yaml, $snapshot['task_runs'] ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
