<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Services\Auth\Audit;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sohbetteki rapor kartı. Raporu sahibi (ve admin) görür (ReportPolicy); bağlantıyı ayrıca sadece raporun üretildiği
 * sohbet isteyebilir. Bağlantı imzalı, 15 dakika geçerli ve isteyen IP'ye bağlıdır; her indirme
 * kayıt altına alınır.
 */
class ReportController extends Controller
{
    public function show(Report $report): JsonResponse
    {
        return response()->json(['data' => self::present($report)]);
    }

    public function downloadLink(Request $request, Report $report): JsonResponse
    {
        $validated = $request->validate(['conversation_id' => ['required', 'integer']]);

        if ($report->conversation_id === null || $report->conversation_id !== (int) $validated['conversation_id']) {
            return response()->json(['message' => 'Bu rapora bu sohbetten erişilemez.'], 403);
        }

        if (! $report->isReady()) {
            return response()->json(['message' => 'Rapor indirilmeye hazır değil.'], 422);
        }

        $expires = now()->addMinutes(config('reports.link_minutes'));

        return response()->json(['data' => [
            // Göreli imza: indirme Vite proxy'si üzerinden de aynı adresle çalışır.
            'url' => URL::temporarySignedRoute('reports.download', $expires, ['report' => $report->id, 'v' => self::viewer($request)], absolute: false),
            'expires_at' => $expires->toIso8601String(),
        ]]);
    }

    public function download(Request $request, Report $report): StreamedResponse|JsonResponse
    {
        // İmza ve süre "signed:relative" ile kontrol edildi; bağlantı başka bir istemciye geçmiş olamaz.
        if (! hash_equals(self::viewer($request), (string) $request->query('v'))) {
            return response()->json(['message' => 'Bu indirme bağlantısı size ait değil.'], 403);
        }

        $disk = Storage::disk(config('reports.disk'));

        if (! $report->isReady() || ! $disk->exists($report->file_path)) {
            return response()->json(['message' => 'Raporun süresi doldu veya dosya yok.'], 410);
        }

        $report->downloads()->create([
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'downloaded_at' => now(),
        ]);
        Audit::record('report.download', $report, meta: ['format' => $report->format, 'data_source' => $report->data_source]);

        return $disk->download($report->file_path, $report->downloadName());
    }

    public function regenerate(Report $report, ReportService $reports): JsonResponse
    {
        if (! in_array($report->status, [Report::STATUS_FAILED, Report::STATUS_EXPIRED], true)) {
            return response()->json(['message' => 'Sadece hata vermiş veya süresi dolmuş rapor yeniden oluşturulabilir.'], 422);
        }

        return response()->json(['data' => self::present($reports->regenerate($report))]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Report $report): array
    {
        return [
            'id' => $report->id,
            'title' => $report->title,
            'format' => $report->format,
            'status' => $report->status,
            'row_count' => $report->row_count,
            'file_size' => $report->file_size,
            'error' => $report->error,
            'expires_at' => $report->expires_at?->toIso8601String(),
            'retention_days' => (int) config('reports.retention_days'),
            'conversation_id' => $report->conversation_id,
            'created_at' => $report->created_at?->toIso8601String(),
        ];
    }

    /** İstemciyi tanımlayan, uygulama anahtarıyla imzalı değer (IP). Bağlantı başka makineden kullanılamaz. */
    private static function viewer(Request $request): string
    {
        return substr(hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')), 0, 32);
    }
}
