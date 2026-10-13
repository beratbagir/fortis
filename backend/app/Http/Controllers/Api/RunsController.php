<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExecutionResource;
use App\Models\Execution;
use App\Services\Claude\ClaudeException;
use App\Services\Claude\ExecutionExplainer;
use App\Services\Flows\ExecutionException;
use App\Services\Flows\ExecutionService;
use App\Services\Flows\RunsIndex;
use App\Services\Kestra\KestraException;
use App\Services\Usage\BudgetExceededException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Çalışmalar ekranı. Liste Kestra'dan (Laravel üzerinden) okunur; zamanlayıcı ve webhook ile başlayanlar dahil.
 * Görev çıktıları gönderilmez; sadece özetleri (kayıt sayısı, HTTP kodu).
 */
class RunsController extends Controller
{
    private const STATES = ['paused' => 'PAUSED', 'failed' => 'FAILED'];

    private const PAGE_SIZE = 20;

    /**
     * Liste yerel dizinden (RunsIndex) gelir; önce Kestra'daki yeni ve bitmemiş çalışmalar eşitlenir.
     * Kestra'ya ulaşılamazsa son bilinen liste uyarıyla döner.
     */
    public function index(Request $request, RunsIndex $index): JsonResponse
    {
        $validated = $request->validate([
            'flow_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:all,paused,failed'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $warning = null;
        try {
            $index->sync();
        } catch (KestraException $e) {
            $warning = "Kestra'ya ulaşılamadı; liste son bilinen durumu gösteriyor. ({$e->getMessage()})";
        }

        $base = Execution::query()
            ->with('flow')
            ->when(isset($validated['flow_id']), fn ($q) => $q->where('flow_id', $validated['flow_id']));
        $page = (int) ($validated['page'] ?? 1);
        $state = self::STATES[$validated['status'] ?? 'all'] ?? null;

        $list = (clone $base)
            ->when($state, fn ($q) => $q->where('state', $state))
            ->orderByRaw('started_at IS NULL')
            ->latest('started_at')
            ->latest('id')
            ->paginate(self::PAGE_SIZE, page: $page);

        return response()->json([
            'data' => $list->getCollection()->map(fn (Execution $execution) => [
                'kestra_execution_id' => $execution->kestra_execution_id,
                'namespace' => $execution->is_test ? config('kestra.test_namespace') : config('kestra.namespace'),
                'is_test' => (bool) $execution->is_test,
                'flow' => ['id' => $execution->flow_id, 'flow_id' => $execution->flow->flow_id, 'title' => $execution->flow->title],
                'state' => $execution->state,
                'start' => $execution->started_at?->toIso8601String(),
                'end' => $execution->ended_at?->toIso8601String(),
                'duration' => $execution->snapshot['duration'] ?? null,
                'trigger' => in_array($execution->started_by, ['Schedule', 'Webhook', 'Flow'], true) ? $execution->started_by : 'Elle',
            ])->values(),
            'total' => $list->total(),
            'counts' => [
                'paused' => (clone $base)->where('state', 'PAUSED')->count(),
                'failed' => (clone $base)->where('state', 'FAILED')->count(),
            ],
            'warning' => $warning,
        ]);
    }

    /** Loglar, sayfalı (en yeniden eskiye değil, zaman sırasıyla). */
    public function logs(Request $request, Execution $execution, ExecutionService $executions): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 50;

        if (! $execution->isFinished() || $execution->snapshot === null) {
            rescue(fn () => $executions->refresh($execution), report: false);
        }

        $logs = $execution->snapshot['logs'] ?? [];

        return response()->json([
            'data' => array_slice($logs, ($page - 1) * $perPage, $perPage),
            'total' => count($logs),
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /** Reddet veya durdur. */
    public function kill(Execution $execution, ExecutionService $executions): JsonResponse|ExecutionResource
    {
        try {
            $executions->kill($execution);
        } catch (ExecutionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new ExecutionResource($execution->load('flow'));
    }

    /** Hatayı AI'a açıklatır. Sadece hata mesajı ve görev adı gönderilir. */
    public function explain(Execution $execution, ExecutionService $executions, ExecutionExplainer $explainer): JsonResponse
    {
        rescue(fn () => $executions->refresh($execution), report: false);

        try {
            return response()->json(['data' => ['explanation' => $explainer->explain($execution), 'sent' => ExecutionExplainer::failures($execution)]]);
        } catch (BudgetExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 402);
        } catch (ClaudeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
