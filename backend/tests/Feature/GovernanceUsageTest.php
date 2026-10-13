<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AiCall;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GovernanceUsageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private function aiCall(array $attributes, ?string $atTr = null): AiCall
    {
        $call = AiCall::create($attributes + ['model' => 'claude-sonnet-5-5', 'source' => 'chat', 'input_tokens' => 100, 'output_tokens' => 50]);
        if ($atTr !== null) {
            $call->forceFill(['created_at' => CarbonImmutable::parse($atTr, 'Europe/Istanbul')->utc()])->save();
        }

        return $call;
    }

    public function test_totals_breakdowns_filters_and_turkey_day_boundaries(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'Europe/Istanbul'));
        $this->admin = User::factory()->admin()->create();
        $ops = Department::query()->create(['name' => 'Operasyon', 'slug' => 'operasyon']);
        $selin = User::factory()->create(['name' => 'Selin', 'department_id' => $ops->id]);
        $agent = Agent::create(['slug' => 'a', 'name' => 'Rötar', 'owner_id' => $selin->id, 'status' => Agent::STATUS_LIVE, 'instructions' => 'x', 'tools' => [], 'icon' => 'RÖ']);
        $run = $agent->runs()->create(['status' => AgentRun::SUCCEEDED, 'input' => 'x']);
        $eval = Conversation::create(['title' => '[eval] apm-top-delays']);

        $this->aiCall(['user_id' => $selin->id, 'cost_usd' => 1.0], '2026-10-13 00:30');           // TR 13 Ekim (UTC 12 Ekim)
        $this->aiCall(['user_id' => $selin->id, 'cost_usd' => 0.5, 'agent_run_id' => $run->id], '2026-10-14 10:00');
        $this->aiCall(['user_id' => $selin->id, 'cost_usd' => 0.01, 'purpose' => 'title'], '2026-10-14 10:00');
        $this->aiCall(['cost_usd' => 2.0, 'conversation_id' => $eval->id, 'model' => 'claude-opus-5-5'], '2026-10-15 09:00');
        $this->aiCall(['user_id' => $selin->id, 'cost_usd' => 9.0], '2026-09-30 23:00');           // geçen ay

        $data = $this->actingAs($this->admin)->getJson('/api/governance/usage')->assertOk()->json('data');
        $this->assertEqualsWithDelta(3.51, $data['totals']['cost_usd'], 0.0001);
        $this->assertEqualsWithDelta((float) AiCall::query()->where('created_at', '>=', CarbonImmutable::parse('2026-10-01', 'Europe/Istanbul')->utc())->sum('cost_usd'), $data['totals']['cost_usd'], 0.0001);
        $this->assertSame(4, $data['totals']['calls']);
        $this->assertEquals(['eval' => 2.0, 'chat' => 1.0, 'agent' => 0.5, 'title' => 0.01], collect($data['by_type'])->pluck('cost_usd', 'type')->all());
        $this->assertEquals(['Operasyon' => 1.51, 'Departmansız' => 2.0], collect($data['by_department'])->pluck('cost_usd', 'name')->sortKeys()->reverse()->all());
        $this->assertSame([['id' => $agent->id, 'name' => 'Rötar', 'runs' => 1, 'cost_usd' => 0.5]], $data['top_agents']);
        $this->assertSame('Selin', $data['top_users'][0]['name']);
        $days = collect($data['daily'])->keyBy('date');
        $this->assertEquals(1.0, $days['2026-10-13']['chat']);
        $this->assertEquals(0.0, $days['2026-10-12']['chat']);
        $this->assertNull($data['credit_usd_value']);

        $this->getJson("/api/governance/usage?department_id={$ops->id}")->assertJsonPath('data.totals.calls', 3);
        $this->getJson('/api/governance/usage?type=agent')->assertJsonPath('data.totals.cost_usd', 0.5);
        $this->getJson('/api/governance/usage?model=claude-opus-5-5')->assertJsonPath('data.totals.calls', 1);
        $this->getJson("/api/governance/usage?user_id={$selin->id}&from=2026-10-13&to=2026-10-13")->assertJsonPath('data.totals.cost_usd', 1);
        $this->getJson('/api/governance/usage?from=2026-09-01&to=2026-09-30')->assertJsonPath('data.totals.cost_usd', 9);
        $this->getJson('/api/governance/usage?type=nope')->assertUnprocessable();
    }

    public function test_csv_export_is_logged_and_admin_only(): void
    {
        $this->admin = User::factory()->admin()->create(['name' => 'Kerem']);
        $this->aiCall(['user_id' => $this->admin->id, 'cost_usd' => 0.123456, 'purpose' => 'title']);

        $this->actingAs(User::factory()->builder()->create())->getJson('/api/governance/usage')->assertForbidden();

        $csv = $this->actingAs($this->admin)->get('/api/governance/usage?format=csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringContainsString('"Zaman (UTC)",Kullanıcı,Departman,Tür,Amaç,Model', $csv);
        $this->assertStringContainsString('Kerem,,Başlık,title,claude-sonnet-5-5,100,50,0.123456', $csv);
        $this->assertTrue(AuditLog::query()->where('action', 'usage.export')->where('meta->rows', 1)->exists());
    }
}
