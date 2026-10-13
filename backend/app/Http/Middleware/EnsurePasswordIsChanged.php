<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Geçici şifreyle giren kullanıcı şifresini değiştirmeden diğer uçları kullanamaz.
 */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return response()->json(['message' => 'Devam etmek için şifrenizi değiştirin.', 'code' => 'password_change_required'], 403);
        }

        return $next($request);
    }
}
