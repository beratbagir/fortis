<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExecutionResource;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Services\Auth\Audit;
use App\Services\Flows\Blocks\BlockCatalog;
use App\Services\Flows\Blocks\ErrorLocator;
use App\Services\Flows\Blocks\FlowEditor;
use App\Services\Flows\Blocks\FlowGraph;
use App\Services\Flows\DryRun;
use App\Services\Flows\ExecutionException;
use App\Services\Flows\ExecutionService;
use App\Services\Flows\FlowEditException;
use App\Services\Flows\FlowService;
use App\Services\Flows\FlowUploadException;
use App\Services\Flows\FlowValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Yaml\Yaml;

/**
 * Görsel akış editörü. Tuval YAML'ın bir görünümüdür: her değişiklik önce YAML'a çevrilir, FlowValidator'dan geçer,
 * sonra yeni taslak sürüm olarak kaydedilir. Kestra'ya sadece "Yayınla" ile gider.
 */
class FlowEditorController extends Controller
{
    public function __construct(
        private readonly FlowService $flows,
        private readonly FlowGraph $graph,
    ) {}

    /** Palet ve formlar için blok kataloğu ile uygulamalar (bağlantı durumlarıyla). */
    public function catalog(BlockCatalog $blocks): JsonResponse
    {
        return response()->json(['blocks' => $blocks->blocks(), 'apps' => $blocks->apps()]);
    }

    public function show(Flow $flow): JsonResponse
    {
        return response()->json(['data' => $this->payload($flow)]);
    }

    /** Tuval düzenlemeleri: işlem listesi YAML'a uygulanır, doğrulanır, yeni taslak sürüm olur. */
    public function operations(Request $request, Flow $flow, FlowEditor $editor): JsonResponse
    {
        $validated = $request->validate([
            'base_version' => ['required', 'integer'],
            'operations' => ['required', 'array', 'min:1', 'max:100'],
            'operations.*' => ['array'],
        ]);

        return $this->edit($flow, function (FlowVersion $base) use ($editor, $validated, $flow, $request) {
            $result = $editor->apply(Yaml::parse($base->yaml), array_values($validated['operations']));
            $version = $this->flows->recordEdit($flow, $result['flow'], FlowVersion::SOURCE_CANVAS, (int) $validated['base_version'], (string) $request->ip());

            return [$version, $result['created']];
        });
    }

    /** YAML editöründen doğrudan kaydetme. Aynı doğrulayıcı. */
    public function updateYaml(Request $request, Flow $flow): JsonResponse
    {
        $validated = $request->validate([
            'base_version' => ['required', 'integer'],
            'yaml' => ['required', 'string', 'max:100000'],
        ]);

        return $this->edit($flow, fn () => [
            $this->flows->recordEdit($flow, $validated['yaml'], FlowVersion::SOURCE_YAML, (int) $validated['base_version'], (string) $request->ip()),
            [],
        ]);
    }

    /** Eski bir sürümü yeni taslak olarak geri yükler (doğrulayıcıdan yeniden geçer). */
    public function restore(Request $request, Flow $flow, int $number): JsonResponse
    {
        $validated = $request->validate(['base_version' => ['required', 'integer']]);
        $old = $flow->versions()->where('number', $number)->firstOrFail();

        return $this->edit($flow, fn () => [
            $this->flows->recordEdit($flow, $old->yaml, FlowVersion::SOURCE_RESTORE, (int) $validated['base_version'], (string) $request->ip()),
            [],
        ]);
    }

    public function publish(Request $request, Flow $flow): JsonResponse
    {
        try {
            $this->flows->publish($flow, (string) $request->ip());
        } catch (FlowUploadException $e) {
            Audit::record('flow.publish', $flow, AuditLog::RESULT_FAILED, ['errors' => $e->errors], 'Kestra');

            $latest = $flow->latestVersion();
            $parsed = $latest === null ? null : rescue(fn () => Yaml::parse($latest->yaml), null, false);

            return response()->json(['message' => implode(' ', $e->errors), 'errors' => ErrorLocator::locate(is_array($parsed) ? $parsed : null, $e->errors)], 422);
        }

        Audit::record('flow.publish', $flow, meta: ['version' => $flow->refresh()->currentVersion?->number], system: 'Kestra');

        return response()->json(['data' => $this->payload($flow)]);
    }

    /**
     * Son sürümü (taslak veya yayında) test namespace'inde bir kez, kuru çalıştırma olarak çalıştırır:
     * e-posta, Teams, Slack ve GET dışı istekler gerçekten gönderilmez.
     */
    public function testRun(Request $request, Flow $flow, FlowValidator $validator, DryRun $dryRun, ExecutionService $executions): JsonResponse|ExecutionResource
    {
        $validated = $request->validate(['inputs' => ['sometimes', 'array']]);
        $version = $flow->latestVersion() ?? abort(404);

        $check = $validator->validate($version->yaml, FlowValidator::MODE_UPLOAD);
        if (! $check['valid']) {
            return response()->json(['message' => 'Akış test edilemez: '.implode(' ', $check['errors']), 'errors' => ErrorLocator::locate($check['flow'], $check['errors'])], 422);
        }

        $namespace = (string) config('kestra.test_namespace');
        $dry = $dryRun->transform($check['flow'], $namespace);
        $dryCheck = $validator->validate(Yaml::dump($dry['flow'], 64, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_COMPACT_NESTED_MAPPING), FlowValidator::MODE_UPLOAD, $namespace);

        if (! $dryCheck['valid']) {
            return response()->json(['message' => 'Test kopyası doğrulanamadı: '.implode(' ', $dryCheck['errors'])], 422);
        }

        try {
            $execution = $executions->startTest($flow, $version, (string) $dryCheck['canonical_yaml'], $namespace, $validated['inputs'] ?? [], (string) $request->ip());
        } catch (ExecutionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new ExecutionResource($execution->load(['flow', 'flowVersion'])))->additional(['skipped' => $dry['skipped']]);
    }

    public function versions(Flow $flow): JsonResponse
    {
        return response()->json([
            'data' => $flow->versions()->reorder('number', 'desc')->get()->map(fn (FlowVersion $version) => [
                'number' => $version->number,
                'source' => $version->source,
                'created_by' => $version->created_by,
                'created_at' => $version->created_at?->toIso8601String(),
                'uploaded_at' => $version->uploaded_at?->toIso8601String(),
                'kestra_revision' => $version->kestra_revision,
                'is_published' => $version->id === $flow->current_version_id && $version->isUploaded(),
            ]),
        ]);
    }

    /** İki sürümün YAML'ı ve grafları (fark arayüzde hesaplanır). */
    public function diff(Request $request, Flow $flow, int $number): JsonResponse
    {
        $version = $flow->versions()->where('number', $number)->firstOrFail();
        $against = $request->has('against')
            ? $flow->versions()->where('number', (int) $request->query('against'))->firstOrFail()
            : $flow->latestVersion();

        return response()->json(['data' => [
            'version' => ['number' => $version->number, 'yaml' => $version->yaml, 'graph' => $this->graph->build(Yaml::parse($version->yaml))],
            'against' => ['number' => $against->number, 'yaml' => $against->yaml, 'graph' => $this->graph->build(Yaml::parse($against->yaml))],
        ]]);
    }

    /** Editördeki sohbet paneli: kullanıcının bu akıştaki açık editör sohbeti, yoksa yenisi. */
    public function conversation(Request $request, Flow $flow): JsonResponse
    {
        $conversation = Conversation::query()
            ->where('context_flow_id', $flow->id)
            ->where('kind', Conversation::KIND_EDITOR)
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->first()
            ?? Conversation::create(['title' => "{$flow->title} — editör", 'context_flow_id' => $flow->id, 'kind' => Conversation::KIND_EDITOR]);

        return response()->json(['data' => ['id' => $conversation->id]]);
    }

    /**
     * @param  callable(FlowVersion): array{0: FlowVersion, 1: list<string>}  $change
     */
    private function edit(Flow $flow, callable $change): JsonResponse
    {
        $base = $flow->latestVersion() ?? abort(404);

        try {
            [$version, $created] = $change($base);
        } catch (FlowEditException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors, 'latest_version' => $e->latestVersion], $e->status);
        }

        return response()->json(['data' => $this->payload($flow->refresh()), 'created' => $created, 'changed' => $version->number !== $base->number]);
    }

    /**
     * Editörün ihtiyacı: son sürümün graf modeli ve YAML'ı, yayındaki sürüm, doğrulama uyarıları.
     *
     * @return array<string, mixed>
     */
    public function payload(Flow $flow): array
    {
        $latest = $flow->latestVersion() ?? abort(404);
        $published = $flow->currentVersion?->isUploaded() ? $flow->currentVersion : null;

        return [
            'id' => $flow->id,
            'flow_id' => $flow->flow_id,
            'namespace' => $flow->namespace,
            'title' => $flow->title,
            'status' => $flow->status,
            'version' => $latest->number,
            'version_source' => $latest->source,
            'published_version' => $published?->number,
            'has_draft' => $published === null || $published->number !== $latest->number,
            'yaml' => $latest->yaml,
            'graph' => $this->graph->build(Yaml::parse($latest->yaml)),
            'validation' => [
                'ok' => true,
                'errors' => [],
                'warnings' => $latest->validation['warnings'] ?? [],
                'missing_secrets' => $latest->validation['missing_secrets'] ?? [],
            ],
        ];
    }
}
