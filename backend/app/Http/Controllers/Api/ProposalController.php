<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FlowProposal;
use App\Models\FlowVersion;
use App\Services\Flows\Blocks\FlowGraph;
use App\Services\Flows\FlowEditException;
use App\Services\Flows\FlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Yaml\Yaml;

/**
 * Editör sohbetinin önerileri: tuvalde fark olarak gösterilir; Uygula yeni taslak sürüm (source = chat) açar,
 * Geri al reddeder. Uygulama da FlowValidator'dan geçer.
 */
class ProposalController extends Controller
{
    public function show(FlowProposal $proposal, FlowGraph $graph): JsonResponse
    {
        $base = $proposal->flow->versions()->where('number', $proposal->base_version)->first();

        return response()->json(['data' => [
            'id' => $proposal->id,
            'flow_id' => $proposal->flow_id,
            'status' => $proposal->status,
            'base_version' => $proposal->base_version,
            'title' => $proposal->title,
            'summary' => $proposal->summary,
            'yaml' => $proposal->yaml,
            'base_yaml' => $base?->yaml,
            'graph' => $graph->build(Yaml::parse($proposal->yaml)),
            'base_graph' => $base === null ? null : $graph->build(Yaml::parse($base->yaml)),
            'warnings' => $proposal->validation['warnings'] ?? [],
            'missing_secrets' => $proposal->validation['missing_secrets'] ?? [],
        ]]);
    }

    public function apply(Request $request, FlowProposal $proposal, FlowService $flows, FlowEditorController $editor): JsonResponse
    {
        if ($proposal->status !== FlowProposal::STATUS_PENDING) {
            return response()->json(['message' => 'Bu öneri artık geçerli değil.'], 422);
        }

        try {
            $version = $flows->recordEdit($proposal->flow, $proposal->yaml, FlowVersion::SOURCE_CHAT, $proposal->base_version, (string) $request->ip());
        } catch (FlowEditException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors, 'latest_version' => $e->latestVersion], $e->status);
        }

        $proposal->update(['status' => FlowProposal::STATUS_APPLIED, 'flow_version_id' => $version->id]);

        return response()->json(['data' => $editor->payload($proposal->flow->refresh())]);
    }

    public function reject(FlowProposal $proposal): JsonResponse
    {
        if ($proposal->status === FlowProposal::STATUS_PENDING) {
            $proposal->update(['status' => FlowProposal::STATUS_REJECTED]);
        }

        return response()->json(['data' => ['id' => $proposal->id, 'status' => $proposal->status]]);
    }
}
