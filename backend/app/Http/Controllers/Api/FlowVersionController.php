<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FlowVersion;
use App\Services\Auth\Audit;
use App\Services\Flows\FlowCardStatus;
use App\Services\Flows\FlowService;
use App\Services\Flows\FlowUploadException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlowVersionController extends Controller
{
    /**
     * Kullanıcının "Yükle" butonu. Sürüm yükleme modunda yeniden doğrulanır; geçmezse Kestra'ya hiçbir şey gitmez.
     */
    public function upload(Request $request, FlowVersion $flowVersion, FlowService $flows, FlowCardStatus $cards): JsonResponse
    {
        try {
            $version = $flows->upload($flowVersion, uploadedBy: (string) $request->ip());
        } catch (FlowUploadException $e) {
            Audit::record('flow.publish', $flowVersion->flow, AuditLog::RESULT_FAILED, ['version' => $flowVersion->number, 'errors' => $e->errors], 'Kestra');

            return response()->json([
                'message' => 'Akış yüklenemedi.',
                'errors' => $e->errors,
                'card' => $cards->withStatus($flowVersion->card, $flowVersion->refresh(), $flowVersion->message?->conversation_id),
            ], 422);
        }

        Audit::record('flow.publish', $version->flow, meta: ['version' => $version->number], system: 'Kestra');

        return response()->json(['card' => $cards->withStatus($version->card, $version, $version->message?->conversation_id)]);
    }
}
