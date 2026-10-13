<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Models\Execution;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Services\Flows\Blocks\FlowGraph;
use App\Services\Flows\FlowManagementException;
use App\Services\Flows\FlowManager;
use App\Services\Kestra\KestraException;
use App\Services\Usage\UsageReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

/**
 * Akışlar sayfası. Kestra'ya erişim burada da sadece Laravel üzerinden yapılır.
 */
class FlowController extends Controller
{
    /** Bu ayki maliyetler (akış kaydı id'si => USD); liste boyunca bir kez hesaplanır. */
    private ?array $monthCosts = null;

    /** @var array<int, array{total: int, success: int, failed: int}>|null Akış başına son 30 günün çalışmaları (test hariç). */
    private ?array $runStats = null;

    public function index(FlowManager $manager): JsonResponse
    {
        $overview = $manager->overview();
        $this->monthCosts = app(UsageReport::class)->flowCosts();
        $this->runStats = $this->runStats();

        return response()->json([
            'data' => array_map(fn (array $row) => $this->present($row['flow'], $row['kestra'], $row['last_execution'], $row['drift']), $overview['flows']),
            'unmanaged' => $overview['unmanaged'],
            'kestra_error' => $overview['kestra_error'],
        ]);
    }

    public function show(Flow $flow, FlowManager $manager): JsonResponse
    {
        try {
            $executions = $manager->recentExecutions($flow);
            $error = null;
        } catch (KestraException $e) {
            $executions = [];
            $error = $e->getMessage();
        }

        return response()->json([
            'data' => $this->present($flow->load(['currentVersion', 'conversation']), null, null, null) + [
                'editor' => app(FlowEditorController::class)->payload($flow),
                'versions' => $flow->versions()->reorder('number', 'desc')->get()->map(fn (FlowVersion $version) => [
                    'id' => $version->id,
                    'number' => $version->number,
                    'title' => $version->title,
                    'kestra_revision' => $version->kestra_revision,
                    'uploaded_at' => $version->uploaded_at?->toIso8601String(),
                    'is_current' => $version->id === $flow->current_version_id,
                    'created_at' => $version->created_at?->toIso8601String(),
                    'yaml' => $version->yaml,
                ]),
                'executions' => $executions,
            ],
            'kestra_error' => $error,
        ]);
    }

    public function enable(Flow $flow, FlowManager $manager): JsonResponse
    {
        return $this->toggle($flow, $manager, enabled: true);
    }

    public function disable(Flow $flow, FlowManager $manager): JsonResponse
    {
        return $this->toggle($flow, $manager, enabled: false);
    }

    public function destroy(Flow $flow, FlowManager $manager): JsonResponse|Response
    {
        try {
            $manager->delete($flow);
        } catch (FlowManagementException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->noContent();
    }

    /** "Bu akışı chat'te değiştir": akışı bağlam olarak alan yeni bir sohbet açar. */
    public function editInChat(Flow $flow, FlowManager $manager): ConversationResource
    {
        return new ConversationResource($manager->startEditConversation($flow)->load(['messages', 'contextFlow']));
    }

    private function toggle(Flow $flow, FlowManager $manager, bool $enabled): JsonResponse
    {
        try {
            $manager->setEnabled($flow, $enabled);
        } catch (FlowManagementException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($flow->load(['currentVersion', 'conversation']), null, null, null)]);
    }

    /**
     * @param  array<string, mixed>|null  $kestra
     * @param  array<string, mixed>|null  $lastExecution
     * @return array<string, mixed>
     */
    private function present(Flow $flow, ?array $kestra, ?array $lastExecution, ?string $drift): array
    {
        $version = $flow->currentVersion;
        $latest = $flow->latestVersion();

        return [
            'id' => $flow->id,
            'namespace' => $flow->namespace,
            'flow_id' => $flow->flow_id,
            'title' => $flow->title,
            'summary' => $flow->summary,
            'status' => $flow->status,
            'triggers' => $version?->card['triggers'] ?? [],
            'current_version' => $version === null ? null : [
                'number' => $version->number,
                'kestra_revision' => $version->kestra_revision,
                'uploaded_at' => $version->uploaded_at?->toIso8601String(),
            ],
            'versions_count' => $flow->versions()->count(),
            'conversation' => $flow->conversation === null ? null : ['id' => $flow->conversation->id, 'title' => $flow->conversation->title],
            'kestra' => $kestra,
            'last_execution' => $lastExecution,
            'drift' => $drift,
            'updated_at' => $flow->updated_at?->toIso8601String(),
            'latest_version' => $latest === null ? null : ['number' => $latest->number, 'source' => $latest->source, 'created_at' => $latest->created_at?->toIso8601String()],
            // Yayındaki sürümden yeni bir taslak var mı.
            'has_draft' => $latest !== null && ($version === null || ! $version->isUploaded() || $latest->number !== $version->number),
            'sources' => $latest === null ? [] : $this->sources($latest->yaml),
            'month_cost_usd' => $this->monthCosts[$flow->id] ?? 0.0,
            'runs_30d' => $this->runStats[$flow->id] ?? ['total' => 0, 'success' => 0, 'failed' => 0],
        ];
    }

    /**
     * Yerel çalışma dizininden (RunsIndex) tek sorgu; Kestra'ya gidilmez.
     *
     * @return array<int, array{total: int, success: int, failed: int}>
     */
    private function runStats(): array
    {
        return Execution::query()
            ->where('is_test', false)
            ->where('started_at', '>=', now()->subDays(30))
            ->groupBy('flow_id')
            ->select('flow_id', DB::raw('count(*) as total'), DB::raw("sum(case when state = 'SUCCESS' then 1 else 0 end) as success"), DB::raw("sum(case when state = 'FAILED' then 1 else 0 end) as failed"))
            ->get()
            ->mapWithKeys(fn (Execution $row) => [$row->flow_id => ['total' => (int) $row->total, 'success' => (int) $row->success, 'failed' => (int) $row->failed]])
            ->all();
    }

    /**
     * Akışın kullandığı kaynaklar ve kanallar (APM, IBS, E-posta...), YAML'daki bloklardan.
     *
     * @return list<string>
     */
    private function sources(string $yaml): array
    {
        $labels = [
            'data_apm' => 'APM', 'apm_token' => 'APM', 'data_ibs' => 'IBS', 'data_sf' => 'SuccessFactors', 'http_request' => 'HTTP',
            'notify_email' => 'E-posta', 'notify_teams' => 'Teams', 'notify_slack' => 'Slack', 'pause' => 'Onay',
        ];
        $flow = rescue(fn () => Yaml::parse($yaml), null, false);

        if (! is_array($flow)) {
            return [];
        }

        $kinds = array_column(FlowGraph::flatten(app(FlowGraph::class)->build($flow)), 'kind');

        return array_values(array_unique(array_filter(array_map(fn (string $kind) => $labels[$kind] ?? null, $kinds))));
    }
}
