<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Kullanıcının kendi ayarları: tema, dil (en/tr) ve şifre.
 */
class PreferencesController extends Controller
{
    public function update(Request $request): UserResource
    {
        $validated = $request->validate([
            'theme' => ['required_without:locale', 'string', Rule::in(User::THEMES)],
            'locale' => ['required_without:theme', 'string', Rule::in(User::LOCALES)],
        ]);

        $request->user()->update($validated);

        return new UserResource($request->user()->load('department'));
    }

    /** Şifre değiştirme. İlk girişte (geçici şifreyle) zorunlu. */
    public function password(Request $request): UserResource
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min((int) config('fortis.password_min_length'))],
        ], [
            'current_password.current_password' => 'Mevcut şifre hatalı.',
            'password.different' => 'Yeni şifre eskisinden farklı olmalı.',
        ]);

        $request->user()->update(['password' => $request->string('password')->value(), 'must_change_password' => false]);

        Audit::record('user.password_change', $request->user());

        return new UserResource($request->user()->load('department'));
    }
}
