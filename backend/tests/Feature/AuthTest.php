<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /** Girişsiz açık olan uçlar. Bu listeye ekleme yapmak bilinçli bir karar olmalı. */
    private const PUBLIC_API_ROUTES = [
        'api/auth/config',
        'api/auth/login',
        'api/auth/entra/redirect',
        'api/auth/entra/callback',
    ];

    public function test_every_api_route_requires_sign_in_except_internal_and_login(): void
    {
        $unprotected = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => Str::startsWith($route->uri(), 'api/') && ! Str::startsWith($route->uri(), 'api/internal/'))
            ->reject(fn (RoutingRoute $route) => in_array($route->uri(), self::PUBLIC_API_ROUTES, true))
            ->reject(fn (RoutingRoute $route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
            ->map(fn (RoutingRoute $route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $unprotected, 'Bu rotalar girişsiz açık: '.implode(', ', $unprotected));
    }

    public function test_requests_without_sign_in_get_401(): void
    {
        $this->getJson('/api/conversations')->assertUnauthorized();
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->getJson('/api/flows')->assertUnauthorized();
        $this->postJson('/api/conversations')->assertUnauthorized();
    }

    public function test_login_config_is_public(): void
    {
        $this->getJson('/api/auth/config')->assertOk()->assertJsonPath('data.driver', 'password')->assertJsonPath('data.show_coming_soon', false);
    }

    public function test_user_signs_in_and_sees_their_profile(): void
    {
        $department = Department::factory()->create(['name' => 'Finance', 'slug' => 'finance']);
        $user = User::factory()->builder()->create(['email' => 'ayse@freebird.test', 'password' => 'dogru-sifre-123', 'department_id' => $department->id]);

        $this->postJson('/api/auth/login', ['email' => 'AYSE@freebird.test', 'password' => 'dogru-sifre-123'])
            ->assertOk()
            ->assertJsonPath('data.email', 'ayse@freebird.test')
            ->assertJsonPath('data.role', 'builder')
            ->assertJsonPath('data.department.name', 'Finance')
            ->assertJsonPath('data.abilities.build', true)
            ->assertJsonPath('data.abilities.admin', false)
            ->assertJsonMissingPath('data.password');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->last_login_at);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);

        $log = AuditLog::query()->where('action', 'auth.login')->sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(AuditLog::RESULT_ALLOWED, $log->result);
    }

    public function test_wrong_password_gives_a_generic_error_and_locks_after_five_attempts(): void
    {
        User::factory()->create(['email' => 'ayse@freebird.test', 'password' => 'dogru-sifre-123']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'ayse@freebird.test', 'password' => 'yanlis'])
                ->assertStatus(422)
                ->assertJsonPath('errors.email.0', 'E-posta veya şifre hatalı.');
        }

        // Kilit doğru şifreyi de durdurur.
        $this->postJson('/api/auth/login', ['email' => 'ayse@freebird.test', 'password' => 'dogru-sifre-123'])->assertStatus(429);
        $this->assertGuest();

        // Bilinmeyen e-posta aynı mesajı alır.
        $this->postJson('/api/auth/login', ['email' => 'yok@freebird.test', 'password' => 'yanlis'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'E-posta veya şifre hatalı.');

        $this->assertSame(6, AuditLog::query()->where('action', 'auth.login_failed')->where('result', AuditLog::RESULT_FAILED)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.login_failed')->where('result', AuditLog::RESULT_BLOCKED)->count());

        $this->travel(61)->seconds();
        $this->postJson('/api/auth/login', ['email' => 'ayse@freebird.test', 'password' => 'dogru-sifre-123'])->assertOk();
    }

    public function test_inactive_user_cannot_sign_in_and_is_signed_out_when_deactivated(): void
    {
        User::factory()->inactive()->create(['email' => 'pasif@freebird.test', 'password' => 'dogru-sifre-123']);

        $this->postJson('/api/auth/login', ['email' => 'pasif@freebird.test', 'password' => 'dogru-sifre-123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'E-posta veya şifre hatalı.');
        $this->assertGuest();

        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/conversations')->assertOk();

        $user->update(['is_active' => false]);
        $this->getJson('/api/conversations')->assertUnauthorized();
    }

    public function test_logout_is_audited(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/auth/logout')->assertNoContent();

        $this->assertGuest('web');
        $this->assertSame($user->id, AuditLog::query()->where('action', 'auth.logout')->sole()->user_id);
    }

    public function test_temporary_password_must_be_changed_before_using_the_app(): void
    {
        $user = User::factory()->create(['password' => 'gecici-sifre-1', 'must_change_password' => true]);
        $this->actingAs($user);

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.must_change_password', true);
        $this->getJson('/api/conversations')->assertForbidden()->assertJsonPath('code', 'password_change_required');

        $this->postJson('/api/me/password', ['current_password' => 'yanlis', 'password' => 'yeni-sifre-123', 'password_confirmation' => 'yeni-sifre-123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
        $this->postJson('/api/me/password', ['current_password' => 'gecici-sifre-1', 'password' => 'kisa', 'password_confirmation' => 'kisa'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/me/password', ['current_password' => 'gecici-sifre-1', 'password' => 'yeni-sifre-123', 'password_confirmation' => 'yeni-sifre-123'])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false);

        $this->getJson('/api/conversations')->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'user.password_change')->exists());
    }

    public function test_theme_preference_is_saved(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/me/preferences', ['theme' => 'dark'])->assertOk()->assertJsonPath('data.theme', 'dark');
        $this->assertSame('dark', $user->refresh()->theme);

        $this->patchJson('/api/me/preferences', ['theme' => 'pink'])->assertStatus(422);
    }

    public function test_password_login_is_closed_when_entra_is_the_driver(): void
    {
        config(['fortis.auth_driver' => 'entra']);
        User::factory()->create(['email' => 'ayse@freebird.test', 'password' => 'dogru-sifre-123']);

        $this->postJson('/api/auth/login', ['email' => 'ayse@freebird.test', 'password' => 'dogru-sifre-123'])->assertForbidden();
        $this->assertGuest();
    }

    public function test_passwords_are_hashed(): void
    {
        $user = User::factory()->create(['password' => 'dogru-sifre-123']);

        $this->assertNotSame('dogru-sifre-123', $user->getRawOriginal('password'));
        $this->assertStringStartsWith('$2y$', $user->getRawOriginal('password'));
    }
}
