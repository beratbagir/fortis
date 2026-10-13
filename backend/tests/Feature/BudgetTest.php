<?php

namespace Tests\Feature;

use App\Agents\AgentRunner;
use App\Agents\AgentTriggers;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentTrigger;
use App\Models\AiCall;
use App\Models\AuditLog;
use App\Models\BudgetLimit;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\Claude\ClaudeChat;
use App\Services\Usage\Budget;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesDataSources;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use FakesDataSources, RefreshDatabase;

    private User $admin;

    private Department $ops;

    private Department $finance;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        $this->admin = User::factory()->admin()->create();
        $this->ops = Department::query()->create(['name' => 'Operasyon', 'slug' => 'operasyon']);
        $this->finance = Department::query()->create(['name' => 'Finans', 'slug' => 'finans']);
    }

    private function user(Department $department, string $role = 'user'): User
    {
        return User::factory()->create(['department_id' => $department->id, 'role' => $role]);
    }

    private function spend(User $user, float $cost, ?CarbonImmutable $at = null): AiCall
    {
        $call = AiCall::create(['user_id' => $user->id, 'model' => 'claude-sonnet-5-5', 'source' => 'chat', 'cost_usd' => $cost]);
        if ($at !== null) {
            $call->forceFill(['created_at' => $at->utc()])->save();
        }

        return $call;
    }

    public function test_warning_threshold_and_full_budget_notify_admins_once(): void
    {
        $user = $this->user($this->ops);
        BudgetLimit::query()->create(['scope' => BudgetLimit::DEPARTMENT, 'scope_id' => $this->ops->id, 'monthly_limit_usd' => 10, 'warn_at_percent' => 80]);

        $this->spend($user, 7);
        $this->assertSame(0, InboxItem::count());
        $this->spend($user, 1.5);
        $this->spend($user, 0.2);
        $alerts = InboxItem::query()->where('user_id', $this->admin->id)->get();
        $this->assertCount(1, $alerts);
        $this->assertSame("Operasyon departmanı AI bütçesinin %80'i kullanıldı", $alerts[0]->title);

        $this->spend($user, 2);
        $this->spend($user, 1);
        $this->assertSame(['Operasyon departmanı AI bütçesinin %80\'i kullanıldı', 'Operasyon departmanı AI bütçesi doldu'], InboxItem::query()->orderBy('id')->pluck('title')->all());
        $this->assertStringContainsString('Dolunca durdur kapalı', InboxItem::query()->latest('id')->value('body'));
        // Uyarı sadece admin'e (departman yöneticisi kavramı yok).
        $this->assertSame(0, InboxItem::query()->where('user_id', '!=', $this->admin->id)->count());
    }

    public function test_hard_stop_blocks_questions_and_agent_runs_of_that_department_only_and_admin_is_exempt(): void
    {
        $this->fakeDataSources();
        $opsUser = $this->user($this->ops);
        $financeUser = $this->user($this->finance);
        $opsAdmin = $this->user($this->ops, User::ROLE_ADMIN);
        BudgetLimit::query()->create(['scope' => BudgetLimit::DEPARTMENT, 'scope_id' => $this->ops->id, 'monthly_limit_usd' => 5, 'hard_stop' => true]);
        $this->spend($opsUser, 5);

        $conversation = Conversation::create(['user_id' => $opsUser->id]);
        $this->actingAs($opsUser)->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'Dün kaç sefer vardı?'])
            ->assertStatus(402)->assertJsonPath('message', 'Departman bütçesi bu ay doldu; yöneticinize başvurun.');
        $this->assertSame(0, $conversation->messages()->count());

        // Diğer departman ve aynı departmandaki admin etkilenmez.
        $this->fakeClaude()->text('Tamam.')->text('Tamam.');
        $this->actingAs($financeUser)->post('/api/conversations/'.Conversation::create(['user_id' => $financeUser->id])->id.'/messages', ['content' => 'Merhaba'], ['Accept' => 'text/event-stream'])->assertOk();
        $this->actingAs($opsAdmin)->post('/api/conversations/'.Conversation::create(['user_id' => $opsAdmin->id])->id.'/messages', ['content' => 'Merhaba'], ['Accept' => 'text/event-stream'])->assertOk();

        // Agent: elle başlatma 402, tetikleyici atlanır ve kayda geçer.
        $agent = Agent::create(['slug' => 'a', 'name' => 'Rötar', 'owner_id' => $opsUser->id, 'status' => Agent::STATUS_LIVE, 'instructions' => 'x', 'tools' => [['name' => 'query_apm']], 'icon' => 'RÖ']);
        $this->actingAs($opsUser)->postJson("/api/agents/{$agent->id}/runs", [])->assertStatus(402);
        $trigger = $agent->triggers()->create(['type' => AgentTrigger::SCHEDULE, 'config' => ['cron' => '0 6 * * *'], 'enabled' => true]);
        $this->assertSame(AgentTriggers::SKIP_BUDGET, app(AgentTriggers::class)->fire($trigger->setRelation('agent', $agent)));
        $this->assertTrue(AuditLog::query()->where('action', 'agent.run.skipped')->where('meta->reason', 'budget')->exists());
        $this->assertSame(0, $agent->runs()->count());
    }

    public function test_queued_run_does_not_start_but_an_ongoing_run_is_finished(): void
    {
        $this->fakeDataSources();
        $owner = $this->user($this->ops, User::ROLE_BUILDER);
        $agent = Agent::create(['slug' => 'a', 'name' => 'Rötar', 'owner_id' => $owner->id, 'status' => Agent::STATUS_LIVE, 'instructions' => 'x', 'tools' => [['name' => 'query_apm']], 'icon' => 'RÖ']);
        $queued = $agent->runs()->create(['status' => AgentRun::QUEUED, 'input' => 'x', 'conversation_id' => Conversation::create(['kind' => 'agent', 'user_id' => $owner->id])->id]);
        $ongoing = $agent->runs()->create(['status' => AgentRun::QUEUED, 'input' => 'x', 'started_at' => now()->subMinute(), 'conversation_id' => Conversation::create(['kind' => 'agent', 'user_id' => $owner->id])->id]);
        BudgetLimit::org()->update(['monthly_limit_usd' => 1, 'hard_stop' => true]);
        $this->spend($owner, 1);

        $this->mock(ClaudeChat::class)->shouldNotReceive('reply');
        app(AgentRunner::class)->execute($queued->refresh());
        $this->assertSame(AgentRun::FAILED, $queued->refresh()->status);
        $this->assertStringContainsString('bütçesi doldu', $queued->error);

        $this->app->forgetInstance(ClaudeChat::class);
        $this->fakeClaude()->text('Bitti.');
        app(AgentRunner::class)->execute($ongoing->refresh());
        $this->assertSame(AgentRun::SUCCEEDED, $ongoing->refresh()->status);
    }

    public function test_cached_month_total_is_incremented_on_each_call_and_matches_the_database(): void
    {
        $user = $this->user($this->ops);
        $budget = app(Budget::class);
        $dept = BudgetLimit::query()->create(['scope' => BudgetLimit::DEPARTMENT, 'scope_id' => $this->ops->id, 'monthly_limit_usd' => 100]);
        $this->spend($user, 1.25);
        $this->assertSame(1.25, $budget->spentFor($dept));

        $this->spend($user, 0.333333);
        $this->spend($this->user($this->finance), 9);
        // Geçen ay ve Türkiye saatine göre ay sınırı: UTC 30 Eylül 21:30 = 1 Ekim 00:30 TR (bu ay).
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00', 'Europe/Istanbul'));
        Budget::forget();
        $this->spend($user, 2, CarbonImmutable::parse('2026-09-30 21:30', 'UTC'));
        $this->spend($user, 4, CarbonImmutable::parse('2026-09-30 20:30', 'UTC'));
        Budget::forget();

        $this->assertEqualsWithDelta(1.25 + 0.333333 + 2, $budget->spentFor($dept), 0.000001);
        $this->assertEqualsWithDelta(1.25 + 0.333333 + 9 + 2, $budget->spent(), 0.000001);
    }

    public function test_budgets_api_admin_only_validates_and_logs_before_and_after(): void
    {
        $this->actingAs($this->user($this->ops, User::ROLE_BUILDER))->getJson('/api/governance/budgets')->assertForbidden();
        $this->actingAs($this->admin);

        $this->putJson('/api/governance/budgets', ['budgets' => [['scope' => 'department', 'monthly_limit_usd' => 5]]])->assertUnprocessable();
        $this->putJson('/api/governance/budgets', ['budgets' => [['scope' => 'org', 'monthly_limit_usd' => -1]]])->assertUnprocessable();

        $data = $this->putJson('/api/governance/budgets', [
            'budgets' => [
                ['scope' => 'org', 'monthly_limit_usd' => 500, 'warn_at_percent' => 75, 'hard_stop' => false],
                ['scope' => 'department', 'scope_id' => $this->ops->id, 'monthly_limit_usd' => 100, 'hard_stop' => true],
            ],
            'credit_usd_value' => 0.02,
        ])->assertOk()->json('data');

        $this->assertEquals([500, 75, false], [$data['org']['monthly_limit_usd'], $data['org']['warn_at_percent'], $data['org']['hard_stop']]);
        $ops = collect($data['departments'])->firstWhere('id', $this->ops->id);
        $this->assertEquals([100, true], [$ops['budget']['monthly_limit_usd'], $ops['budget']['hard_stop']]);
        $this->assertSame(0.02, $data['credit_usd_value']);
        $log = AuditLog::query()->where('action', 'settings.update')->where('meta->setting', "budget.department.{$this->ops->id}")->sole();
        $this->assertNull($log->meta['before']);
        $this->assertSame(100.0, (float) $log->meta['after']['monthly_limit_usd']);

        // Boş limit departman bütçesini kaldırır (kayda geçer).
        $this->putJson('/api/governance/budgets', ['budgets' => [['scope' => 'department', 'scope_id' => $this->ops->id, 'monthly_limit_usd' => null]]])->assertOk();
        $this->assertSame(0, BudgetLimit::query()->where('scope', 'department')->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'settings.update')->where('meta->setting', "budget.department.{$this->ops->id}")->count());
        $this->assertTrue(AuditLog::query()->where('meta->setting', 'usage.credit_usd_value')->exists());
    }
}
