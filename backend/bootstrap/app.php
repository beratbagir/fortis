<?php

use App\Http\Middleware\EnsureInternalKey;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Arayüz Sanctum'un SPA oturumunu (çerez) kullanır.
        $middleware->statefulApi();
        // İstek dili: kullanıcının tercihi (en/tr), misafirde tarayıcı dili.
        $middleware->api(append: [SetLocale::class]);
        $middleware->web(append: [SetLocale::class]);
        $middleware->redirectGuestsTo('/#/login');
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'password.changed' => EnsurePasswordIsChanged::class,
            'internal.key' => EnsureInternalKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
