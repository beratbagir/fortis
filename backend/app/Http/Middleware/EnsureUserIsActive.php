<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Oturum açıkken pasif yapılan kullanıcının oturumu kapatılır; istek 401 alır.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null && ! $request->user()->is_active) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
            }

            return response()->json(['message' => 'Hesabınız pasif. Yöneticinize başvurun.'], 401);
        }

        return $next($request);
    }
}
