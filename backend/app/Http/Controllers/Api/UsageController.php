<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Usage\UsageReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Kullanım ve maliyet ekranı. Tüm toplamlar ai_calls tablosundan.
 */
class UsageController extends Controller
{
    public function __construct(private readonly UsageReport $report) {}

    public function summary(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->report->summary($this->month($request))]);
    }

    public function daily(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->report->daily($this->month($request))]);
    }

    public function byModel(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->report->byModel($this->month($request))]);
    }

    public function byFlow(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->report->byFlow($this->month($request))]);
    }

    public function export(Request $request): Response
    {
        $month = $this->month($request) ?? now()->format('Y-m');

        return response(UsageReport::csv($this->report->byFlow($month)), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"fortis-kullanim-{$month}.csv\"",
        ]);
    }

    private function month(Request $request): ?string
    {
        return $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? null;
    }
}
