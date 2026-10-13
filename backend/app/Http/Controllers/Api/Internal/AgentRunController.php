<?php

namespace App\Http\Controllers\Api\Internal;

use App\Agents\AgentTriggers;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentTrigger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kestra'daki zamanlama akışının çağırdığı uç (X-Fortis-Key). Agent pilot/canlı değilse veya aynı tetikleyicinin
 * önceki çalışması sürüyorsa 409.
 */
class AgentRunController extends Controller
{
    public function store(Request $request, Agent $agent, AgentTriggers $triggers): JsonResponse
    {
        $validated = $request->validate(['trigger_id' => ['required', 'integer']]);

        $trigger = AgentTrigger::query()->where('agent_id', $agent->id)->where('type', AgentTrigger::SCHEDULE)->find($validated['trigger_id']);
        abort_if($trigger === null, 404, 'Bu agentta böyle bir zamanlama tetikleyicisi yok.');

        $result = $triggers->fire($trigger->setRelation('agent', $agent), meta: ['source' => 'kestra']);

        if (! $result instanceof AgentRun) {
            return response()->json(['message' => AgentTriggers::message($result), 'reason' => $result], 409);
        }

        return response()->json(['data' => ['run_id' => $result->id, 'status' => $result->status]], 202);
    }
}
