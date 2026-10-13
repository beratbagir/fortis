<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExecutionResource;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Execution;
use App\Models\Flow;
use App\Services\Auth\Audit;
use App\Services\Flows\ExecutionException;
use App\Services\Flows\ExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExecutionController extends Controller
{
    /** Bir SSE bağlantısının en uzun süresi; sonra tarayıcı (EventSource) yeniden bağlanır. */
    private const STREAM_SECONDS = 55;

    private const POLL_SECONDS = 2;

    /** Kartın "Şimdi çalıştır" butonu. */
    public function store(Request $request, Flow $flow, ExecutionService $executions): JsonResponse|ExecutionResource
    {
        $validated = $request->validate([
            'inputs' => ['sometimes', 'array'],
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
        ]);

        try {
            $execution = $executions->start(
                $flow,
                $validated['inputs'] ?? [],
                isset($validated['conversation_id']) ? Conversation::find($validated['conversation_id']) : null,
                startedBy: (string) $request->ip(),
            );
        } catch (ExecutionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new ExecutionResource($execution->load('flow'));
    }

    /** Kestra'dan güncel durumu okur. */
    public function show(Execution $execution, ExecutionService $executions): JsonResponse|ExecutionResource
    {
        try {
            $executions->refresh($execution);
        } catch (ExecutionException $e) {
            return response()->json(['message' => $e->getMessage(), 'execution' => new ExecutionResource($execution->load('flow'))], 502);
        }

        return new ExecutionResource($execution->load('flow'));
    }

    /**
     * Çalışma durumunu SSE ile akıtır: Laravel Kestra'yı birkaç saniyede bir sorgular ve değişince iletir.
     * Olaylar: `execution` (güncel durum), `error` {message}, `done` (çalışma bitti; tarayıcı bağlantıyı kapatmalı).
     */
    public function stream(Execution $execution, ExecutionService $executions): StreamedResponse
    {
        return response()->stream(function () use ($execution, $executions) {
            $send = function (string $event, mixed $data): void {
                echo "event: {$event}\n";
                echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $deadline = time() + self::STREAM_SECONDS;
            $lastHash = null;

            echo "retry: 3000\n\n";

            do {
                try {
                    $executions->refresh($execution);
                    $payload = (new ExecutionResource($execution->load('flow')))->resolve();
                    $hash = md5(json_encode([$payload['state'], $payload['task_runs'], count($payload['logs'])]));

                    if ($hash !== $lastHash) {
                        $send('execution', $payload);
                        $lastHash = $hash;
                    }
                } catch (ExecutionException $e) {
                    $send('error', ['message' => $e->getMessage()]);
                }

                if ($execution->isFinished()) {
                    $send('done', ['state' => $execution->state]);

                    return;
                }

                if (connection_aborted()) {
                    return;
                }

                sleep(self::POLL_SECONDS);
            } while (time() < $deadline);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** Onay bekleyen çalışma: "Devam et" (approved: true) veya "Reddet" (approved: false). */
    public function resume(Request $request, Execution $execution, ExecutionService $executions): JsonResponse|ExecutionResource
    {
        $validated = $request->validate([
            'approved' => ['required', 'boolean'],
            'inputs' => ['sometimes', 'array'],
        ]);

        $action = $validated['approved'] ? 'execution.resume' : 'execution.reject';

        try {
            $executions->resume($execution, (bool) $validated['approved'], $validated['inputs'] ?? []);
        } catch (ExecutionException $e) {
            Audit::record($action, $execution, AuditLog::RESULT_FAILED, ['error' => $e->getMessage()], 'Kestra');

            return response()->json(['message' => $e->getMessage()], 422);
        }

        Audit::record($action, $execution, meta: ['flow_id' => $execution->flow_id], system: 'Kestra');

        return new ExecutionResource($execution->load('flow'));
    }
}
