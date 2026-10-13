<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\Audit;
use App\Services\Auth\SignIn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Giriş, çıkış ve oturumdaki kullanıcı. Arayüz Sanctum'un SPA (çerez) oturumunu kullanır:
 * önce /sanctum/csrf-cookie, sonra POST /api/auth/login.
 */
class AuthController extends Controller
{
    /** Giriş ekranının ihtiyacı (girişsiz açık): yöntem ve arayüz dili. */
    public function config(): JsonResponse
    {
        return response()->json(['data' => [
            'driver' => config('fortis.auth_driver'),
            'locale' => in_array(config('app.locale'), config('fortis.locales'), true) ? config('app.locale') : 'tr',
        ]]);
    }

    public function login(LoginRequest $request): UserResource|JsonResponse
    {
        if (config('fortis.auth_driver') !== 'password') {
            return response()->json(['message' => 'Şifreyle giriş kapalı. Microsoft ile giriş yapın.'], 403);
        }

        $user = $request->authenticate();
        SignIn::completed($request, $user, 'password');

        return new UserResource($user->load('department'));
    }

    public function logout(Request $request): Response
    {
        Audit::record('auth.logout');

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('department'));
    }
}
