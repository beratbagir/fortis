<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Connectors\Connectors;
use Illuminate\Http\JsonResponse;

/**
 * Bağlı sistemlerin durumu (son kontrolden; dış sisteme istek atılmaz).
 */
class ConnectorController extends Controller
{
    public function status(Connectors $connectors): JsonResponse
    {
        return response()->json(['data' => $connectors->status()]);
    }
}
