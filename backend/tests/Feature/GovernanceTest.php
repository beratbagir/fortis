<?php

namespace Tests\Feature;

use App\Agents\AgentRunner;
use App\Agents\Inbox;
use App\Models\Agent;
use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\AgentRunScore;
use App\Models\AiCall;
use App\Models\AuditLog;
use App\Models\BudgetLimit;
use App\Models\ConnectorCheck;
use App\Models\Conversation;
use App\Models\Execution;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\GovernanceAlert;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\Flows\ExecutionService;
use App\Services\Governance\GovernanceAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Concerns\FakesDataSources;
use Tests\TestCase;

class GovernanceTest extends TestCase
{
    use FakesDataSources, RefreshDatabase;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->admin()->create(['name' => 'Kerem Admin']);
        $this->owner = User::factory()->builder()->create(['name' => 'Selin']);
    }

    private function agent(string $name = 'Rötar Özeti', array $tools = ['query_apm']): Agent
    {
        return Agent::create(['slug' => 'a-'.uniqid(), 'name' => $name, 'owner_id' => $this->owner->id, 'status' => Agent::STATUS_LIVE, 'instructions' => 'x', 'tools' => array_map(fn ($t) => ['name' => $t], $tools), 'icon' => 'RÖ']);
    }

    private function makeRun(Agent $agent, array $attributes = []): AgentRun
    {
        return $agent->runs()->create($attributes + ['status' => AgentRun::RUNNING, 'input' => 'x', 'started_at' => now()->subMinutes(2), 'triggered_by_user_id' => $this->owner->id,
            'conversation_id' => Conversation::create(['kind' => 'agent', 'user_id' => $this->owner->id])->id]);
    }

    private function scan(): void
    {
        app(GovernanceAlerts::class)->scan();
    }

    public function test_only_admin(): void
    {
        $this->actingAs($this->owner);
        $this->getJson('/api/governance/overview')->assertForbidden();
        $this->getJson('/api/governance/alerts')->assertForbidden();
        $this->postJson('/api/governance/runs/1/stop', ['kind' => 'agent', 'reason' => 'deneme'])->assertForbidden();
    }

    public function test_overview_live_runs_and_month_totals_match_ai_calls(): void
    {
        $agent = $this->agent();
        $live = $this->makeRun($agent, ['tool_calls_count' => 4]);
        $this->makeRun($agent, ['status' => AgentRun::SUCCEEDED, 'finished_at' => now()]);
        AiCall::create(['agent_run_id' => $live->id, 'model' => 'm', 'source' => 'chat', 'cost_usd' => 0.12]);
        AiCall::create(['model' => 'm', 'source' => 'chat', 'cost_usd' => 1.00]);
        $old = AiCall::create(['model' => 'm', 'source' => 'chat', 'cost_usd' => 5.00]);
        $old->forceFill(['created_at' => now('Europe/Istanbul')->startOfMonth()->subDays(3)])->save();

        $flow = Flow::factory()->create(['title' => 'Sigorta bildirimi']);
        $version = FlowVersion::factory()->for($flow)->create();
        Execution::create(['flow_id' => $flow->id, 'flow_version_id' => $version->id, 'kestra_execution_id' => 'exec-live', 'state' => 'RUNNING', 'started_at' => now()->subMinute()]);
        Execution::create(['flow_id' => $flow->id, 'flow_version_id' => $version->id, 'kestra_execution_id' => 'exec-done', 'state' => 'SUCCESS', 'started_at' => now()->subMinute()]);

        $data = $this->actingAs($this->admin)->getJson('/api/governance/overview')->assertOk()->json('data');

        $live = collect($data['live']);
        $this->assertSame(['agent', 'flow'], $live->pluck('kind')->sort()->values()->all());
        $agentRow = $live->firstWhere('kind', 'agent');
        $this->assertSame(['Rötar Özeti', 'Selin', 4, 0.12], [$agentRow['name'], $agentRow['started_by'], $agentRow['tool_calls'], $agentRow['cost_usd']]);
        $this->assertSame('exec-live', $live->firstWhere('kind', 'flow')['id']);
        $this->assertEqualsWithDelta(1.12, $data['month']['spent_usd'], 0.0001);
        $this->assertSame(2, $data['month']['agent_runs']); // bu ayki iki çalışma (test çalışmaları sayılmaz)
    }

    public function test_admin_stops_an_agent_run_and_a_flow_run(): void
    {
        $agent = $this->agent('Sabah Özeti', ['query_apm', 'send_email']);
        $run = $this->makeRun($agent, ['status' => AgentRun::WAITING_APPROVAL]);
        $action = $run->actions()->create(['type' => 'send_email', 'payload' => ['to' => ['a@freebird.com.tr']], 'status' => AgentAction::PENDING, 'requires_approval' => true]);
        $item = app(Inbox::class)->approval($this->owner, $action);

        $this->actingAs($this->admin)->postJson("/api/governance/runs/{$run->id}/stop", ['kind' => 'agent'])->assertUnprocessable();
        $this->postJson("/api/governance/runs/{$run->id}/stop", ['kind' => 'agent', 'reason' => 'Yanlış alıcı'])->assertOk();

        $run->refresh();
        $this->assertSame([AgentRun::CANCELLED, 'Yönetici durdurdu: Yanlış alıcı'], [$run->status, $run->error]);
        $this->assertSame(AgentAction::REJECTED, $action->refresh()->status);
        $this->assertSame(InboxItem::DONE, $item->refresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'governance.run.stop')->where('user_id', $this->admin->id)->exists());
        $this->postJson("/api/governance/runs/{$run->id}/stop", ['kind' => 'agent', 'reason' => 'tekrar'])->assertConflict();

        // Kuyruktaki iş sonradan çalışsa da iptal kalır; başarısız sayılmaz.
        app(AgentRunner::class)->fail($run, 'Çalışma yönetici tarafından durduruldu.');
        $this->assertSame(AgentRun::CANCELLED, $run->refresh()->status);

        $flow = Flow::factory()->create();
        $execution = Execution::create(['flow_id' => $flow->id, 'flow_version_id' => FlowVersion::factory()->for($flow)->create()->id, 'kestra_execution_id' => 'exec-1', 'state' => 'RUNNING']);
        $service = Mockery::mock(ExecutionService::class);
        $service->shouldReceive('kill')->once()->withArgs(fn ($e) => $e->is($execution))->andReturn($execution);
        $this->app->instance(ExecutionService::class, $service);
        $this->postJson('/api/governance/runs/exec-1/stop', ['kind' => 'flow', 'reason' => 'Döngüye girdi'])->assertOk();
        $this->assertSame(2, AuditLog::query()->where('action', 'governance.run.stop')->count());
    }

    public function test_test_run_over_the_limit_shows_up_in_attention_and_admin_resolves_it(): void
    {
        $this->fakeDataSources();
        config(['agents.max_tool_calls' => 1]);
        $agent = $this->agent();
        $run = $this->makeRun($agent, ['status' => AgentRun::QUEUED, 'is_test' => true, 'started_at' => null]);
        $call = ['name' => 'query_apm', 'input' => ['path' => '/api/legs?dateFrom=2026-10-11T00:00:00Z', 'purpose' => 'x']];
        $this->fakeClaude()->toolCalls([$call])->toolCalls([$call]);

        app(AgentRunner::class)->execute($run->refresh());
        $this->assertSame(AgentRun::FAILED, $run->refresh()->status);

        $alerts = $this->actingAs($this->admin)->getJson('/api/governance/overview')->json('data.alerts');
        $this->assertCount(1, $alerts);
        $this->assertSame(['agent_limit', 'critical', "#/agents/{$agent->id}/runs/{$run->id}"], [$alerts[0]['type'], $alerts[0]['severity'], $alerts[0]['link']]);
        $this->assertStringContainsString('(test)', $alerts[0]['message']);
        $this->assertStringContainsString('Araç çağrısı sınırı (1) aşıldı', $alerts[0]['message']);

        $this->postJson("/api/governance/alerts/{$alerts[0]['id']}/resolve")->assertOk()->assertJsonPath('data.resolved_by', 'Kerem Admin');
        $this->scan();
        $this->assertSame(0, GovernanceAlert::query()->open()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'governance.alert.resolve')->exists());
    }

    public function test_each_alert_type_is_raised_once_and_closes_when_resolved(): void
    {
        $this->fakeDataSources();
        $agent = $this->agent();

        // Bağlantı: 40 dk önce son başarı, şimdi başarısız.
        ConnectorCheck::create(['connector' => 'apm', 'ok' => true, 'checked_at' => now()->subMinutes(40)]);
        ConnectorCheck::create(['connector' => 'apm', 'ok' => false, 'error' => 'x', 'checked_at' => now()]);
        // Bütçe: %90.
        BudgetLimit::org()->update(['monthly_limit_usd' => 10]);
        AiCall::create(['model' => 'm', 'source' => 'chat', 'cost_usd' => 9]);
        // Üst üste 3 başarısız + düşük puan.
        foreach (range(1, 3) as $i) {
            $failed = $this->makeRun($agent, ['status' => AgentRun::FAILED, 'finished_at' => now()]);
            AgentRunScore::create(['agent_run_id' => $failed->id, 'score' => 40, 'details' => [], 'model' => 'm']);
        }
        // İzinsiz araç denemesi.
        AuditLog::query()->create(['actor_type' => 'agent', 'action' => 'agent.tool_blocked', 'subject_type' => AgentRun::class, 'subject_id' => (string) $failed->id, 'result' => 'blocked', 'meta' => ['tool' => 'query_warehouse']]);
        // 25 saattir bekleyen onay.
        $approval = InboxItem::create(['user_id' => $this->owner->id, 'type' => InboxItem::APPROVAL, 'title' => 'E-posta onayı', 'source_label' => 'Rötar Özeti']);
        $approval->forceFill(['created_at' => now()->subHours(25)])->save();

        $this->scan();
        $this->scan();
        $types = GovernanceAlert::query()->open()->pluck('type')->sort()->values()->all();
        $this->assertSame(['agent_failing', 'agent_low_score', 'approval_overdue', 'budget_warning', 'connector_attention', 'tool_blocked'], $types);

        // Bütçe dolunca uyarı kritik olur.
        AiCall::create(['model' => 'm', 'source' => 'chat', 'cost_usd' => 2]);
        $this->scan();
        $this->assertTrue(GovernanceAlert::query()->open()->where('type', 'budget_exhausted')->where('severity', 'critical')->exists());
        $this->assertFalse(GovernanceAlert::query()->open()->where('type', 'budget_warning')->exists());

        // Koşullar düzelince kendiliğinden kapanır.
        ConnectorCheck::create(['connector' => 'apm', 'ok' => true, 'checked_at' => now()]);
        $ok = $this->makeRun($agent, ['status' => AgentRun::SUCCEEDED, 'finished_at' => now()]);
        foreach (range(1, 6) as $i) {
            AgentRunScore::create(['agent_run_id' => $ok->id, 'score' => 100, 'details' => [], 'model' => 'm']);
        }
        $approval->update(['status' => InboxItem::DONE]);
        $this->scan();
        $this->assertSame(['budget_exhausted', 'tool_blocked'], GovernanceAlert::query()->open()->pluck('type')->sort()->values()->all());
    }

    public function test_quality_shows_the_last_full_eval_and_notes_a_later_partial_run(): void
    {
        Storage::fake('local');
        $total = count(json_decode((string) file_get_contents(base_path('tests/eval/questions.json')), true)['questions']);
        Storage::disk('local')->put('eval/2026-10-12_100000.json', json_encode(['summary' => ['run_at' => '2026-10-12T10:00:00Z', 'questions' => $total, 'passed' => $total - 1, 'correct_tool_ratio' => 0.95, 'total_cost_usd' => 7.1]]));
        Storage::disk('local')->put('eval/2026-10-13_090000.json', json_encode(['summary' => ['run_at' => '2026-10-13T09:00:00Z', 'questions' => 2, 'passed' => 2, 'correct_tool_ratio' => 1, 'total_cost_usd' => 0.5]]));

        $quality = $this->actingAs($this->admin)->getJson('/api/governance/overview')->json('data.quality');

        $this->assertSame([$total, $total - 1], [$quality['eval']['questions'], $quality['eval']['passed']]);
        $this->assertSame(['run_at' => '2026-10-13T09:00:00Z', 'questions' => 2, 'passed' => 2], $quality['eval_partial']);
    }
}
