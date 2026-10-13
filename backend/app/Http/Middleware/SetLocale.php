<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * İstek dili: oturumdaki kullanıcının tercihi (users.locale); misafirde tarayıcının dili (en/tr), yoksa varsayılan.
 * Giriş yapan kullanıcıda dil, Authenticated olayında da ayarlanır (bkz. AppServiceProvider).
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $locale = $user instanceof User && in_array($user->locale, User::LOCALES, true)
            ? $user->locale
            : $request->getPreferredLanguage(User::LOCALES) ?? config('app.locale');

        app()->setLocale(in_array($locale, User::LOCALES, true) ? $locale : config('app.locale'));

        return $next($request);
    }
}
