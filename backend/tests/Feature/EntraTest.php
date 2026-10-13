<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class EntraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fortis.auth_driver' => 'entra']);
    }

    private function fakeMicrosoftUser(string $id, string $email): void
    {
        Socialite::fake('microsoft', (new SocialiteUser)->map(['id' => $id, 'name' => 'Ayşe Yılmaz', 'email' => $email]));
    }

    public function test_email_not_added_by_admin_is_refused(): void
    {
        $this->fakeMicrosoftUser('entra-1', 'yabanci@freebird.test');

        $this->get('/api/auth/entra/callback')->assertRedirect('/#/login?error=no_access');

        $this->assertGuest();
        $this->assertSame(0, User::count());
        $this->assertSame(AuditLog::RESULT_BLOCKED, AuditLog::query()->where('action', 'auth.login_failed')->sole()->result);
    }

    public function test_invited_user_signs_in_and_is_linked_to_entra(): void
    {
        $user = User::factory()->create(['email' => 'ayse@freebird.test', 'password' => null]);
        $this->fakeMicrosoftUser('entra-1', 'Ayse@Freebird.test');

        $this->get('/api/auth/entra/callback')->assertRedirect('/#/');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('entra-1', $user->refresh()->entra_object_id);
        $this->assertSame('entra', AuditLog::query()->where('action', 'auth.login')->sole()->meta['method']);
    }

    public function test_inactive_user_and_other_entra_account_are_refused(): void
    {
        User::factory()->inactive()->create(['email' => 'pasif@freebird.test']);
        $this->fakeMicrosoftUser('entra-1', 'pasif@freebird.test');
        $this->get('/api/auth/entra/callback')->assertRedirect('/#/login?error=no_access');

        User::factory()->create(['email' => 'bagli@freebird.test', 'entra_object_id' => 'entra-asil']);
        $this->fakeMicrosoftUser('entra-baska', 'bagli@freebird.test');
        $this->get('/api/auth/entra/callback')->assertRedirect('/#/login?error=no_access');

        $this->assertGuest();
    }

    public function test_entra_routes_are_closed_for_password_driver(): void
    {
        config(['fortis.auth_driver' => 'password']);

        $this->get('/api/auth/entra/redirect')->assertNotFound();
        $this->get('/api/auth/entra/callback')->assertNotFound();
    }
}
