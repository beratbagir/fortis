<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Başarılı girişten sonra ortak adımlar (şifre ve Entra ID): oturum kimliği yenilenir, son giriş ve denetim kaydı yazılır.
 */
class SignIn
{
    public static function completed(Request $request, User $user, string $method): void
    {
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $user->forceFill(['last_login_at' => now()])->save();

        Audit::record('auth.login', $user, meta: ['method' => $method], user: $user);
    }
}
