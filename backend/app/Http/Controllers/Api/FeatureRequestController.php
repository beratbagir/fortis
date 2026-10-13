<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeatureRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * İstek listesi: kullanıcıların istediği ama katalogda olmadığı için yapılamayanlar.
 */
class FeatureRequestController extends Controller
{
    public function index(): JsonResponse
    {
        $requests = FeatureRequest::query()->with('conversation')->latest('id')->get();

        return response()->json([
            'data' => $requests->map(fn (FeatureRequest $request) => $this->present($request)),
            // En çok istenen eksik yetenekler (katalog genişletme önceliği için).
            'top_capabilities' => $requests->where('status', 'open')
                ->groupBy(fn (FeatureRequest $request) => mb_strtolower(trim($request->missing_capability)))
                ->map(fn ($group) => ['missing_capability' => $group->sortBy('id')->first()->missing_capability, 'count' => $group->count()])
                ->sortByDesc('count')
                ->values(),
        ]);
    }

    public function update(Request $request, FeatureRequest $featureRequest): JsonResponse
    {
        $featureRequest->update($request->validate(['status' => ['required', Rule::in(FeatureRequest::STATUSES)]]));

        return response()->json(['data' => $this->present($featureRequest->load('conversation'))]);
    }

    /** @return array<string, mixed> */
    private function present(FeatureRequest $request): array
    {
        return [
            'id' => $request->id,
            'request' => $request->request,
            'missing_capability' => $request->missing_capability,
            'reason' => $request->reason,
            'status' => $request->status,
            'conversation' => $request->conversation === null ? null : ['id' => $request->conversation->id, 'title' => $request->conversation->title],
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }
}
