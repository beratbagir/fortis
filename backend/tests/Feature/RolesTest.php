<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_changes_settings_and_budget(): void
    {
        foreach ([User::factory()->create(), User::factory()->builder()->create()] as $user) {
            $this->actingAs($user)->putJson('/api/settings/ai', ['monthly_budget_usd' => 10])->assertForbidden();
        }

        $this->actingAs(User::factory()->create())->getJson('/api/settings/ai')->assertOk();

        $this->actingAs(User::factory()->admin()->create())->putJson('/api/settings/ai', ['monthly_budget_usd' => 10])
            ->assertOk()
            ->assertJsonPath('data.monthly_budget_usd', 10);

        $this->assertSame(['monthly_budget_usd' => 10], AuditLog::query()->where('action', 'settings.update')->sole()->meta['changes']);
    }

    public function test_only_admin_manages_users_and_departments(): void
    {
        foreach ([User::factory()->create(), User::factory()->builder()->create()] as $user) {
            $this->actingAs($user);
            $this->getJson('/api/admin/users')->assertForbidden();
            $this->postJson('/api/admin/users', ['name' => 'X', 'email' => 'x@freebird.test'])->assertForbidden();
            $this->getJson('/api/admin/departments')->assertForbidden();
        }
    }

    public function test_admin_adds_and_edits_users(): void
    {
        $admin = User::factory()->admin()->create();
        $finance = Department::factory()->create(['name' => 'Finance', 'slug' => 'finance']);
        $this->actingAs($admin);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Ayşe Yılmaz',
            'email' => 'Ayse@Freebird.test',
            'role' => 'builder',
            'department_id' => $finance->id,
            'title' => 'Finans Analisti',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'ayse@freebird.test')
            ->assertJsonPath('data.role', 'builder')
            ->assertJsonPath('data.department.name', 'Finance')
            ->assertJsonPath('data.must_change_password', true);

        $temporary = $response->json('temporary_password');
        $this->assertIsString($temporary);
        $user = User::query()->where('email', 'ayse@freebird.test')->sole();
        $this->assertTrue(password_verify($temporary, $user->password));

        $this->postJson('/api/admin/users', ['name' => 'Tekrar', 'email' => 'ayse@freebird.test'])->assertStatus(422);
        $this->postJson('/api/admin/users', ['name' => 'Rol', 'email' => 'rol@freebird.test', 'role' => 'owner'])->assertStatus(422);

        $this->patchJson("/api/admin/users/{$user->id}", ['role' => 'user', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.role', 'user')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('temporary_password', null);

        $this->patchJson("/api/admin/users/{$user->id}", ['reset_password' => true])->assertOk()->assertJsonPath('data.must_change_password', true);

        $this->getJson('/api/admin/users')->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame(1, AuditLog::query()->where('action', 'user.create')->count());
        $update = AuditLog::query()->where('action', 'user.update')->first();
        $this->assertEqualsCanonicalizing(['role', 'is_active'], $update->meta['changed']);
    }

    public function test_admin_cannot_lock_themselves_out(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->patchJson("/api/admin/users/{$admin->id}", ['is_active' => false])->assertStatus(422);
        $this->patchJson("/api/admin/users/{$admin->id}", ['role' => 'user'])->assertStatus(422);
        $this->assertTrue($admin->refresh()->isAdmin());
    }

    public function test_admin_adds_and_renames_departments(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $id = $this->postJson('/api/admin/departments', ['name' => 'Cargo'])->assertCreated()->assertJsonPath('data.slug', 'cargo')->json('data.id');
        $this->postJson('/api/admin/departments', ['name' => 'Cargo'])->assertStatus(422);

        $this->patchJson("/api/admin/departments/{$id}", ['name' => 'Cargo Ops'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Cargo Ops')
            ->assertJsonPath('data.slug', 'cargo');

        $this->getJson('/api/admin/departments')->assertOk()->assertJsonPath('data.0.name', 'Cargo Ops');
        $this->assertSame(['department.create', 'department.update'], AuditLog::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_user_cannot_build_flows(): void
    {
        $flow = Flow::factory()->create();
        $version = FlowVersion::factory()->for($flow)->create();
        $this->actingAs(User::factory()->create());

        $this->postJson("/api/flows/{$flow->id}/publish")->assertForbidden();
        $this->postJson("/api/flows/{$flow->id}/operations", ['base_version' => 1, 'operations' => [[]]])->assertForbidden();
        $this->putJson("/api/flows/{$flow->id}/yaml", ['base_version' => 1, 'yaml' => 'x'])->assertForbidden();
        $this->postJson("/api/flows/{$flow->id}/disable")->assertForbidden();
        $this->deleteJson("/api/flows/{$flow->id}")->assertForbidden();
        $this->postJson("/api/flow-versions/{$version->id}/upload")->assertForbidden();

        // Görmek ve çalıştırmak serbest.
        $this->getJson("/api/flows/{$flow->id}")->assertOk();
    }
}
