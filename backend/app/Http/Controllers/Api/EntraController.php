<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\Audit;
use App\Services\Auth\SignIn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Microsoft Entra ID ile giriş (OpenID Connect). Kullanıcı Fortis'te yoksa oluşturulmaz: sadece admin'in eklediği
 * e-postalar girebilir. İlk girişte Entra nesne id'si kullanıcıya bağlanır; sonraki girişlerde ona göre eşleşir.
 *
 * Tarayıcı yönlendirmeleri olduğu için `web` ara katmanıyla (oturum) routes/web.php'de tanımlı.
 */
class EntraController extends Controller
{
    public function redirect(): SymfonyRedirect
    {
        abort_unless(config('fortis.auth_driver') === 'entra', 404);

        return Socialite::driver('microsoft')->scopes(['openid', 'profile', 'email'])->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(config('fortis.auth_driver') === 'entra', 404);

        try {
            $remote = Socialite::driver('microsoft')->user();
        } catch (Throwable $e) {
            report($e);

            return redirect('/#/login?error=entra_failed');
        }

        $email = mb_strtolower((string) $remote->getEmail());
        $user = User::query()->where('entra_object_id', $remote->getId())->first()
            ?? User::query()->whereRaw('lower(email) = ?', [$email])->first();

        $linkedElsewhere = $user?->entra_object_id !== null && $user->entra_object_id !== (string) $remote->getId();

        if ($user === null || ! $user->is_active || $linkedElsewhere) {
            Audit::record('auth.login_failed', result: AuditLog::RESULT_BLOCKED, meta: ['email' => $email, 'method' => 'entra', 'reason' => $user === null ? 'not_invited' : 'inactive']);

            return redirect('/#/login?error=no_access');
        }

        $user->entra_object_id ??= (string) $remote->getId();
        $user->save();

        Auth::guard('web')->login($user);
        SignIn::completed($request, $user, 'entra');

        return redirect('/#/');
    }
}
