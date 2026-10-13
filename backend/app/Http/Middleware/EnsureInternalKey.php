<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kestra'nın çağırdığı /api/internal/* uçları: X-Fortis-Key başlığı .env'deki anahtarla aynı olmalı.
 * Anahtar tanımlı değilse uç kapalıdır (her istek 401).
 */
class EnsureInternalKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('agents.internal_key');
        $given = (string) $request->header('X-Fortis-Key', '');

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Geçersiz veya eksik iç anahtar.'], 401);
        }

        return $next($request);
    }
}
