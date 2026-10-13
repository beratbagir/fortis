<?php

namespace Tests\Feature;

use App\Agents\AgentDrafts;
use App\Models\Agent;
use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\AgentTrigger;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\AgentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesDataSources;
use Tests\TestCase;

class AgentPageTest extends TestCase
{
    use FakesDataSources, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->owner = User::factory()->builder()->create(['email' => 'sahip@freebird.com.tr', 'name' => 'Selin Ak', 'title' => 'OCC Müdürü']);
        $this->actingAs($this->owner);
    }

    private function agent(array $attributes = []): Agent
    {
        $agent = Agent::create($attributes + [
            'slug' => 'sabah-'.uniqid(),
            'name' => 'Sabah Özeti',
            'description' => 'Dünkü operasyonu özetler.',
            'owner_id' => $this->owner->id,
            'department_id' => Department::query()->firstOrCreate(['slug' => 'operasyon'], ['name' => 'Operasyon'])->id,
            'status' => Agent::STATUS_LIVE,
            'instructions' => 'Dünkü rötarları APM\'den al ve özetle; e-posta taslağı hazırla.',
            'tools' => [['name' => 'query_apm'], ['name' => 'query_warehouse'], ['name' => 'send_email', 'constraints' => ['recipients' => ['occ@freebird.com.tr']]]],
            'approval_policy' => ['approvers' => ['owner'], 'send_email' => ['mode' => 'always']],
            'plan' => [['type' => 'trigger', 'title' => 'Her gün 07:30'], ['type' => 'read', 'title' => 'APM'], ['type' => 'approve', 'title' => 'Onay'], ['type' => 'act', 'title' => 'Gönder']],
            'icon' => 'SÖ',
            'current_version' => 1,
        ]);
        $agent->triggers()->create(['type' => AgentTrigger::MANUAL]);
        $agent->triggers()->create(['type' => AgentTrigger::SCHEDULE, 'config' => ['cron' => '30 7 * * *'], 'input_template' => 'Dünü özetle.']);
        $agent->versions()->create(['number' => 1, 'snapshot' => [], 'created_by' => $this->owner->id, 'source' => 'architect']);

        return $agent;
    }

    private function makeRun(Agent $agent, array $attributes = []): AgentRun
    {
        return $agent->runs()->create($attributes + ['status' => AgentRun::SUCCEEDED, 'input' => 'x', 'started_at' => now()->subMinutes(2), 'finished_at' => now()->subMinutes(2)->addSeconds(48), 'cost_usd' => 0.2, 'attempts' => 1, 'triggered_by_user_id' => $this->owner->id]);
    }

    public function test_registry_lists_agents_with_card_stats_and_templates_separately(): void
    {
        $this->seed(AgentTemplateSeeder::class);
        $agent = $this->agent();
        $this->agent(['status' => Agent::STATUS_PILOT, 'name' => 'Pilot']);
        $this->makeRun($agent);
        $this->makeRun($agent, ['status' => AgentRun::FAILED]);
        $this->makeRun($agent, ['is_test' => true]);
        $old = $this->makeRun($agent);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        $data = $this->getJson('/api/agents')->assertOk()->json('data');

        $this->assertSame(['live' => 1, 'pilot' => 1, 'draft' => 0, 'paused' => 0, 'template' => 4], $data['counts']);
        $this->assertCount(4, $data['templates']);
        $card = collect($data['agents'])->firstWhere('id', $agent->id);
        $this->assertSame(['APM', 'IBS', 'E-posta'], $card['systems']);
        $this->assertSame(2, $card['runs_7d']);
        $this->assertSame(3, $card['runs_30d']);
        $this->assertNull($card['useful_pct']);
        $this->assertSame('Her gün 07:30', $card['trigger']);
        $this->assertSame(['id' => $this->owner->id, 'name' => 'Selin Ak', 'title' => 'OCC Müdürü'], $card['owner']);
        $this->assertSame('Operasyon', $card['department']);

        // Geri bildirim gelince % faydalı görünür.
        $this->postJson("/api/agent-runs/{$old->id}/feedback", ['rating' => 'useful'])->assertOk()->assertJsonPath('data.feedback.mine', 'useful');
        $this->postJson('/api/agent-runs/'.$agent->runs()->where('status', AgentRun::FAILED)->value('id').'/feedback', ['rating' => 'not_useful'])->assertOk();
        $this->assertSame(50, collect($this->getJson('/api/agents')->json('data.agents'))->firstWhere('id', $agent->id)['useful_pct']);
    }

    public function test_overview_kpis_chart_config_and_run_visibility(): void
    {
        $agent = $this->agent();
        $ok = $this->makeRun($agent);
        $ok->actions()->create(['type' => 'send_email', 'payload' => [], 'status' => AgentAction::EXECUTED, 'requires_approval' => true]);
        $this->makeRun($agent, ['attempts' => 2]);
        $this->makeRun($agent, ['status' => AgentRun::FAILED, 'cost_usd' => 0.1]);
        $other = User::factory()->create();
        $this->makeRun($agent, ['triggered_by_user_id' => $other->id]);
        $this->makeRun($agent, ['is_test' => true, 'cost_usd' => 5]);

        $data = $this->getJson("/api/agents/{$agent->id}")->assertOk()->json('data');
        $kpis = $data['overview']['kpis'];
        $this->assertSame(4, $kpis['runs']);
        $this->assertSame(1, $kpis['retried']);
        $this->assertSame(75, $kpis['success_rate']);
        $this->assertSame(48, $kpis['avg_seconds']);
        $this->assertEqualsWithDelta(0.7, $kpis['cost_usd'], 0.0001);
        $this->assertSame([1, 1], [$kpis['actions_approved'], $kpis['actions_total']]);
        $this->assertCount(30, $data['overview']['days']);
        $today = collect($data['overview']['days'])->last();
        $this->assertSame([2, 1, 1], [$today['succeeded'], $today['retried'], $today['failed']]);
        $this->assertSame('Elle · Her gün 07:30', $data['overview']['config']['trigger']);
        $this->assertSame('APM, IBS · salt okunur', $data['overview']['config']['reads']);
        $this->assertStringContainsString('occ@freebird.com.tr', $data['overview']['config']['delivery']);
        $this->assertCount(4, $data['recent_runs']);
        $this->assertTrue($data['can_manage']);
        $this->assertSame(['occ@freebird.com.tr'], $data['draft']['tools'][2]['constraints']['recipients']);

        // Sahibi olmayan sadece kendi başlattığı çalışmaları görür, taslağı göremez.
        $mine = $this->actingAs($other)->getJson("/api/agents/{$agent->id}")->assertOk()->json('data');
        $this->assertCount(1, $mine['recent_runs']);
        $this->assertNull($mine['draft']);
        $this->assertFalse($mine['can_manage']);
        $this->putJson("/api/agents/{$agent->id}", [])->assertForbidden();
    }

    public function test_runs_tab_filters_by_status_origin_and_test(): void
    {
        $agent = $this->agent();
        $schedule = $agent->triggers()->where('type', AgentTrigger::SCHEDULE)->sole();
        $this->makeRun($agent, ['trigger_id' => $schedule->id]);
        $this->makeRun($agent, ['source' => 'chat']);
        $this->makeRun($agent, ['status' => AgentRun::FAILED]);
        $this->makeRun($agent, ['is_test' => true]);

        $this->getJson("/api/agents/{$agent->id}/runs")->assertJsonCount(3, 'data');
        $this->getJson("/api/agents/{$agent->id}/runs?origin=schedule")->assertJsonCount(1, 'data')->assertJsonPath('data.0.origin', 'schedule');
        $this->getJson("/api/agents/{$agent->id}/runs?origin=chat")->assertJsonCount(1, 'data')->assertJsonPath('data.0.origin', 'chat');
        $this->getJson("/api/agents/{$agent->id}/runs?origin=manual")->assertJsonCount(1, 'data');
        $this->getJson("/api/agents/{$agent->id}/runs?status=failed")->assertJsonCount(1, 'data');
        $this->getJson("/api/agents/{$agent->id}/runs?test=1")->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_test', true);
        $this->getJson("/api/agents/{$agent->id}/runs?test=all")->assertJsonCount(4, 'data');
    }

    public function test_chat_creates_a_chat_run_and_messages_go_through_the_agent(): void
    {
        $this->fakeDataSources();
        $agent = $this->agent();
        $user = User::factory()->create();
        $this->actingAs($user);

        $run = $this->postJson("/api/agents/{$agent->id}/chats")->assertCreated()->json('data');
        $this->assertSame(['chat', false], [$run['origin'], $run['is_test']]);

        $this->fakeClaude()->text('Dün **3** rötar vardı.');
        $this->post("/api/conversations/{$run['conversation_id']}/messages", ['content' => 'Dün kaç rötar vardı?'], ['Accept' => 'text/event-stream'])->assertOk()->streamedContent();

        $this->assertStringContainsString("Sen Sabah Özeti agent'ısın", json_encode($this->claude->requests[0]['system'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(AgentRun::SUCCEEDED, AgentRun::find($run['id'])->status);
        $this->getJson("/api/agents/{$agent->id}/chats")->assertJsonPath('data.0.title', 'Dün kaç rötar vardı?');

        // Taslak agentla sadece sahibi/admin (builder) sohbet eder; test sayılır.
        $draft = $this->agent(['status' => Agent::STATUS_DRAFT]);
        $this->postJson("/api/agents/{$draft->id}/chats")->assertForbidden();
        $this->actingAs($this->owner)->postJson("/api/agents/{$draft->id}/chats")->assertCreated()->assertJsonPath('data.is_test', true);
    }

    public function test_configuration_saves_a_new_version_validates_and_detects_conflicts(): void
    {
        $agent = $this->agent();
        $draft = $this->getJson("/api/agents/{$agent->id}")->json('data.draft');

        $draft['instructions'] = 'Dünkü rötarları APM\'den al, en uzun beşini tabloyla özetle.';
        $draft['triggers'][0]['cron'] = '0 7 * * 1-5';
        $this->putJson("/api/agents/{$agent->id}", $draft + ['base_version' => 1])->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.trigger', 'Hafta içi her gün 07:00');
        $this->assertDatabaseHas('agent_versions', ['agent_id' => $agent->id, 'number' => 2, 'source' => 'manual']);

        // Başkası arada kaydettiyse eski sürüm üzerine yazılmaz.
        $this->putJson("/api/agents/{$agent->id}", $draft + ['base_version' => 1])->assertStatus(409);

        $bad = $draft;
        $bad['tools'][] = ['name' => 'send_email', 'constraints' => ['recipients' => ['dis@gmail.com']]];
        $bad['tools'] = array_values(array_filter($bad['tools'], fn ($t) => $t['name'] !== 'send_email' || isset($t['constraints']['recipients']) && $t['constraints']['recipients'] === ['dis@gmail.com']));
        $this->putJson("/api/agents/{$agent->id}", $bad + ['base_version' => 2])->assertUnprocessable()->assertJsonPath('errors.0', fn ($e) => str_contains($e, 'dis@gmail.com'));

        // Sahibi sadece admin değiştirir.
        $builder = User::factory()->builder()->create();
        $this->putJson("/api/agents/{$agent->id}", array_merge($draft, ['base_version' => 2, 'owner_id' => $builder->id]))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->putJson("/api/agents/{$agent->id}", array_merge($draft, ['base_version' => 2, 'owner_id' => $builder->id]))->assertOk();
        $this->assertSame($builder->id, $agent->refresh()->owner_id);
    }

    public function test_versions_list_and_restore_as_a_new_version(): void
    {
        $agent = $this->agent();
        $v1 = $this->getJson("/api/agents/{$agent->id}")->json('data.draft');
        $agent->versions()->where('number', 1)->update(['snapshot' => json_encode(AgentDrafts::snapshot($agent))]);
        $changed = ['instructions' => 'Tamamen farklı bir talimat: sadece iptalleri özetle ve gönder.'] + $v1;
        $this->putJson("/api/agents/{$agent->id}", $changed)->assertOk();

        $versions = $this->getJson("/api/agents/{$agent->id}/versions")->assertOk()->json('data');
        $this->assertSame([2, 1], array_column($versions, 'number'));
        $this->assertTrue($versions[0]['current']);
        $this->assertSame('Selin Ak', $versions[0]['created_by']);

        $this->postJson("/api/agents/{$agent->id}/versions/1/restore")->assertOk()->assertJsonPath('data.version', 3);
        $this->assertStringStartsWith('Dünkü rötarları', $agent->refresh()->instructions);
        $this->assertDatabaseHas('agent_versions', ['agent_id' => $agent->id, 'number' => 3, 'source' => 'restore', 'note' => 'v1 geri yüklendi']);

        $this->actingAs(User::factory()->create())->getJson("/api/agents/{$agent->id}/versions")->assertForbidden();
    }

    public function test_feedback_requires_access_to_the_run(): void
    {
        $agent = $this->agent();
        $run = $this->makeRun($agent);

        $this->actingAs(User::factory()->create())->postJson("/api/agent-runs/{$run->id}/feedback", ['rating' => 'useful'])->assertNotFound();
        $this->actingAs($this->owner)->postJson("/api/agent-runs/{$run->id}/feedback", ['rating' => 'meh'])->assertUnprocessable();
        $this->postJson("/api/agent-runs/{$run->id}/feedback", ['rating' => 'not_useful', 'comment' => 'Eksik'])->assertOk()->assertJsonPath('data.feedback.not_useful', 1);
        $this->postJson("/api/agent-runs/{$run->id}/feedback", ['rating' => 'useful'])->assertOk()->assertJsonPath('data.feedback.useful', 1)->assertJsonPath('data.feedback.not_useful', 0);
    }
}
