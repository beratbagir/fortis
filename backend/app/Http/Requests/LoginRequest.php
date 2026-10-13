<?php

namespace App\Http\Requests;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\Audit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * E-posta + şifre girişi. Hatalı denemeler e-posta + IP başına sayılır; sınır aşılınca 429.
 * Hata mesajı hangi alanın yanlış olduğunu (veya hesabın pasif olduğunu) söylemez.
 */
class LoginRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function authenticate(): User
    {
        $key = $this->throttleKey();

        if (RateLimiter::tooManyAttempts($key, (int) config('fortis.login.max_attempts'))) {
            Audit::record('auth.login_failed', result: AuditLog::RESULT_BLOCKED, meta: ['email' => $this->string('email')->lower()->value(), 'reason' => 'throttled']);

            throw new ThrottleRequestsException(__('Çok fazla hatalı deneme. :seconds saniye sonra tekrar deneyin.', ['seconds' => RateLimiter::availableIn($key)]));
        }

        $credentials = [
            'email' => $this->string('email')->lower()->value(),
            'password' => $this->string('password')->value(),
            fn ($query) => $query->where('is_active', true),
        ];

        if (! Auth::guard('web')->attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($key, (int) config('fortis.login.lockout_seconds'));
            Audit::record('auth.login_failed', result: AuditLog::RESULT_FAILED, meta: ['email' => $credentials['email']]);

            throw ValidationException::withMessages(['email' => 'E-posta veya şifre hatalı.']);
        }

        RateLimiter::clear($key);

        /** @var User */
        return Auth::guard('web')->user();
    }

    private function throttleKey(): string
    {
        return 'login:'.Str::transliterate(Str::lower($this->string('email')->value())).'|'.$this->ip();
    }
}
