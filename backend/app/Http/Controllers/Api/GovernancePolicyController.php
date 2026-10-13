<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Settings\Policies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Yönetişim > Politikalar (A4.4, sadece admin): veri, modeller, bütçe ve agent ayarları. Her değişiklik
 * `settings.update` denetim kaydına önceki ve yeni değerle yazılır.
 */
class GovernancePolicyController extends Controller
{
    public function __construct(private readonly Policies $policies) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->policies->present()]);
    }

    public function update(Request $request): JsonResponse
    {
        $changed = $this->policies->update($this->policies->validate($request->all()), $request->user());

        return response()->json(['data' => $this->policies->present(), 'changed' => $changed]);
    }
}
