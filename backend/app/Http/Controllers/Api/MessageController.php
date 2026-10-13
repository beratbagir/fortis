<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Message;
use App\Models\MessageFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bir cevap üzerindeki işlemler: geri bildirim (👍/👎) ve cevabın denetim kaydı.
 */
class MessageController extends Controller
{
    /** Sadece sohbet sahibi yazabilir. rating null: geri bildirimi kaldırır. */
    public function feedback(Request $request, Message $message): JsonResponse
    {
        abort_unless($message->conversation?->user_id === $request->user()->id, 403, 'Sadece kendi sohbetindeki cevaplara geri bildirim verebilirsin.');
        abort_unless($message->role === Message::ROLE_ASSISTANT, 422, 'Sadece cevaplara geri bildirim verilebilir.');

        $validated = $request->validate([
            'rating' => ['present', 'nullable', Rule::in(MessageFeedback::RATINGS)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validated['rating'] === null) {
            MessageFeedback::query()->where('message_id', $message->id)->where('user_id', $request->user()->id)->delete();

            return response()->json(['data' => null]);
        }

        $feedback = MessageFeedback::query()->updateOrCreate(
            ['message_id' => $message->id, 'user_id' => $request->user()->id],
            ['rating' => $validated['rating'], 'comment' => $validated['comment'] ?? null],
        );

        return response()->json(['data' => ['rating' => $feedback->rating, 'comment' => $feedback->comment]]);
    }

    /** Cevapta çalışan veri sorgularının denetim kayıtları. Admin ve sohbet sahibi; sorgu metni admin ve builder'a. */
    public function audit(Request $request, Message $message): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $message->conversation?->user_id === $user->id, 404);

        $ids = array_column($message->meta['answer']['queries'] ?? [], 'query_id');

        $logs = AuditLog::query()
            ->where('action', 'ai.query')
            ->orderBy('id')
            ->get()
            ->filter(fn (AuditLog $log) => in_array($log->meta['query_id'] ?? null, $ids, true))
            ->map(fn (AuditLog $log) => array_filter([
                'at' => $log->created_at?->toIso8601String(),
                'actor' => $log->actor_label,
                'system' => $log->system,
                'result' => $log->result,
                'tool' => $log->meta['tool'] ?? null,
                'purpose' => $log->meta['purpose'] ?? null,
                'rows' => $log->meta['rows_returned'] ?? null,
                'total_rows' => $log->meta['total_rows'] ?? null,
                'masked_fields' => $log->meta['masked_fields'] ?? [],
                'query' => $user->canBuild() ? ($log->meta['query'] ?? null) : null,
            ], fn ($v) => $v !== null))
            ->values();

        return response()->json(['data' => $logs]);
    }
}
