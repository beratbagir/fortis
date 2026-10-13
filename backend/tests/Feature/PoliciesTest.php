<?php

namespace Tests\Feature;

use App\Agents\AgentActions;
use App\Ai\ModelRouter;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PoliciesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.default_model' => 'claude-sonnet-5-5',
            'ai.data_question_model' => 'claude-opus-5-5',
            'ai.fast_model' => 'claude-sonnet-5-5',
            'agents.allowed_recipient_domains' => ['freebird.com.tr'],
            'agents.allow_external_recipients' => false,
            'agents.max_tool_calls' => 20,
        ]);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_only_admin_reads_and_changes_policies(): void
    {
        foreach ([User::factory()->create(), User::factory()->builder()->create()] as $user) {
            $this->actingAs($user)->getJson('/api/governance/policies')->assertForbidden();
            $this->actingAs($user)->putJson('/api/governance/policies', ['max_tool_calls' => 5])->assertForbidden();
        }

        $data = $this->actingAs($this->admin)->getJson('/api/governance/policies')->assertOk()->json('data');
        $this->assertSame(['claude-sonnet-5-5', 'claude-opus-5-5'], $data['allowed_models']);
        $this->assertSame(200, $data['max_rows']);
        $this->assertSame(['send_email', 'post_teams', 'deliver_report'], collect($data['approval_policy'])->pluck('tool')->all());
        $this->assertSame(['always'], collect($data['approval_policy'])->pluck('mode')->unique()->values()->all());
        $this->assertTrue($data['company_budget']['hard_stop']);
    }

    public function test_every_change_is_logged_with_before_and_after_and_takes_effect(): void
    {
        $response = $this->actingAs($this->admin)->putJson('/api/governance/policies', [
            'allowed_models' => ['claude-sonnet-5-5'],
            'data_question_model' => 'claude-sonnet-5-5',
            'allow_external_recipients' => true,
            'allowed_recipient_domains' => ['Freebird.com.tr', 'gozendigital.com'],
            'max_tool_calls' => 8,
            'max_cost_usd' => 1.5,
            'ai_data_access' => 'masked',
            'default_model' => 'claude-sonnet-5-5', // değişmedi: kayıt yok
        ])->assertOk();
        $this->assertEqualsCanonicalizing(['allowed_models', 'data_question_model', 'allow_external_recipients', 'allowed_recipient_domains', 'max_tool_calls', 'max_cost_usd', 'ai_data_access'], $response->json('changed'));

        $logs = AuditLog::query()->where('action', 'settings.update')->get()->keyBy(fn (AuditLog $l) => $l->meta['setting']);
        $this->assertCount(7, $logs);
        $this->assertSame(['setting' => 'agents.max_tool_calls', 'before' => 20, 'after' => 8], $logs['agents.max_tool_calls']->meta);
        $this->assertSame(['claude-sonnet-5-5', 'claude-opus-5-5'], $logs['ai.allowed_models']->meta['before']);
        $this->assertSame(['freebird.com.tr', 'gozendigital.com'], $logs['agents.allowed_recipient_domains']->meta['after']);
        $this->assertSame('off', $logs['ai.data_access']->meta['before']);
        $this->assertSame($this->admin->id, $logs['agents.max_tool_calls']->user_id);

        // Etkisi: izinli modeller, alıcı kuralları, sınırlar.
        $settings = app(AppSettings::class);
        $this->assertSame(['claude-sonnet-5-5'], array_keys($settings->models()));
        $this->assertSame(8, $settings->maxToolCalls());
        $this->assertTrue(AgentActions::isInternal('ayse@gozendigital.com'));
        $this->assertSame('claude-sonnet-5-5', app(ModelRouter::class)->forTurn(Conversation::create(['user_id' => $this->admin->id]), true)['model']);
        $this->getJson('/api/settings/ai')->assertOk()->assertJsonCount(1, 'data.models');
        $conversation = Conversation::create(['user_id' => $this->admin->id]);
        $this->patchJson("/api/conversations/{$conversation->id}", ['model' => 'claude-opus-5-5'])->assertUnprocessable();
    }

    public function test_invalid_values_are_rejected_and_nothing_is_saved(): void
    {
        $this->actingAs($this->admin);
        foreach ([
            ['allowed_models' => []],
            ['allowed_models' => ['gpt-5']],
            ['allowed_models' => ['claude-opus-5-5']],                          // varsayılan model listede değil
            ['allowed_models' => ['claude-opus-5-5'], 'default_model' => 'claude-opus-5-5', 'fast_model' => 'claude-sonnet-5-5'],
            ['default_model' => 'claude-haiku-9'],
            ['allowed_recipient_domains' => ['not a domain']],
            ['max_tool_calls' => 0],
            ['max_cost_usd' => 500],
            ['max_seconds' => 10],
            ['ai_data_access' => 'full'],
        ] as $input) {
            $this->putJson('/api/governance/policies', $input)->assertUnprocessable();
        }
        $this->assertSame(0, AuditLog::query()->where('action', 'settings.update')->count());
    }

    public function test_legacy_settings_endpoint_goes_through_the_same_rules(): void
    {
        $this->actingAs($this->admin)->putJson('/api/settings/ai', ['default_model' => 'claude-opus-5-5', 'ai_allow_employee_names' => false])->assertOk()
            ->assertJsonPath('data.default_model', 'claude-opus-5-5');
        $this->assertEqualsCanonicalizing(['ai.default_model', 'ai.allow_employee_names'], AuditLog::query()->where('action', 'settings.update')->get()->map(fn (AuditLog $l) => $l->meta['setting'])->all());
        $this->assertSame(['setting' => 'ai.default_model', 'before' => 'claude-sonnet-5-5', 'after' => 'claude-opus-5-5'], AuditLog::query()->where('meta->setting', 'ai.default_model')->sole()->meta);

        $this->putJson('/api/governance/policies', ['allowed_models' => ['claude-opus-5-5']])->assertOk();
        $this->putJson('/api/settings/ai', ['default_model' => 'claude-sonnet-5-5'])->assertUnprocessable();
    }
}
