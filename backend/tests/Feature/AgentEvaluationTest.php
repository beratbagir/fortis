<?php

namespace Tests\Feature;

use App\Agents\AgentRunner;
use App\Agents\RunScorer;
use App\Jobs\ScoreAgentRunJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentRunScore;
use App\Models\AiCall;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\FakesDataSources;
use Tests\TestCase;

class AgentEvaluationTest extends TestCase
{
    use FakesDataSources, RefreshDatabase;

    private User $owner;

    private const RULES = ['Her rötar nedeni bir rötar koduna dayanıyor', 'Özet 200 kelimeyi geçmiyor', 'Rakamlar kaynak sorgularla tutarlı'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->owner = User::factory()->builder()->create();
        $this->actingAs($this->owner);
    }

    private function agent(bool $evaluation = true): Agent
    {
        $agent = Agent::create([
            'slug' => 'rotar-'.uniqid(), 'name' => 'Rötar Özeti', 'description' => 'Dünkü rötarları özetler.', 'owner_id' => $this->owner->id,
            'status' => Agent::STATUS_LIVE, 'instructions' => 'Dünkü rötarları özetle.', 'tools' => [['name' => 'query_apm']], 'icon' => 'RÖ',
        ]);
        if ($evaluation) {
            $agent->evaluation()->create(['criteria' => self::RULES, 'enabled' => true]);
        }

        return $agent;
    }

    /** Çalışma konuşmasında sorgu sonucu (ham veri) olan bitmiş çalışma. */
    private function finishedRun(Agent $agent, array $attributes = []): AgentRun
    {
        $conversation = Conversation::create(['kind' => 'agent', 'user_id' => $this->owner->id]);
        $conversation->messages()->create(['role' => Message::ROLE_ASSISTANT, 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'query_apm', 'input' => ['path' => '/api/legs', 'purpose' => 'x']]], 'text' => '']);
        $conversation->messages()->create(['role' => Message::ROLE_USER, 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '{"rows":[["FH1256","HAM_SORGU_VERISI_42"]]}']], 'text' => '']);

        return $agent->runs()->create($attributes + [
            'status' => AgentRun::SUCCEEDED, 'input' => 'Dünü özetle', 'output' => 'Dün **3** uzun rötar vardı; en uzunu FH1256 (142 dk, kod 93).', 'conversation_id' => $conversation->id,
            'started_at' => now()->subMinute(), 'finished_at' => now(), 'triggered_by_user_id' => $this->owner->id,
        ]);
    }

    /** Puanlama modelinin yapılandırılmış (JSON) cevabı. */
    private function scores(array $passed): string
    {
        return (string) json_encode(['results' => array_map(fn (bool $p) => ['passed' => $p, 'reason' => $p ? 'Uygun.' : 'Uygun değil.'], $passed)], JSON_UNESCAPED_UNICODE);
    }

    public function test_only_the_output_text_and_rules_go_to_the_scoring_model(): void
    {
        $run = $this->finishedRun($this->agent());
        $this->fakeClaude()->text($this->scores([true, true, false]));

        $score = app(RunScorer::class)->score($run);

        $this->assertSame(67, $score->score);
        $this->assertSame(['rule' => self::RULES[2], 'passed' => false, 'reason' => 'Uygun değil.'], $score->details[2]);
        $request = json_encode($this->claude->otherRequests[0], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('en uzunu FH1256 (142 dk, kod 93)', $request);
        $this->assertStringContainsString('Özet 200 kelimeyi geçmiyor', $request);
        // Ham sorgu sonucu, araç girdileri ve çalışma konuşması gitmez; tek mesaj vardır.
        $this->assertStringNotContainsString('HAM_SORGU_VERISI_42', $request);
        $this->assertStringNotContainsString('/api/legs', $request);
        $this->assertCount(1, $this->claude->otherRequests[0]['messages']);
        $this->assertSame('json_schema', $this->claude->otherRequests[0]['output_config']['format']['type']);
        $this->assertTrue(AiCall::query()->where('purpose', 'evaluation')->where('agent_run_id', $run->id)->exists());

        // Kural sayısıyla uyuşmayan cevap puan yazmaz (iş yeniden dener).
        $this->fakeClaude()->text($this->scores([true]));
        $this->expectException(RuntimeException::class);
        app(RunScorer::class)->score($run);
    }

    public function test_finished_runs_are_queued_for_scoring_only_when_evaluation_is_active(): void
    {
        $this->fakeDataSources();
        $runner = app(AgentRunner::class);

        foreach ([[true, null, 1], [false, null, 0], [true, 'chat', 0]] as [$enabled, $source, $expected]) {
            Queue::fake();
            $agent = $this->agent($enabled);
            $run = $agent->runs()->create(['status' => AgentRun::QUEUED, 'input' => 'x', 'source' => $source, 'conversation_id' => Conversation::create(['kind' => 'agent', 'user_id' => $this->owner->id])->id]);
            $this->fakeClaude()->text('Dün 3 rötar vardı.');
            $runner->execute($run->refresh());

            Queue::assertPushed(ScoreAgentRunJob::class, $expected);
        }
    }

    public function test_evaluation_settings_permissions_and_validation(): void
    {
        $agent = $this->agent(false);

        $this->getJson("/api/agents/{$agent->id}/evaluation")->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.can_edit', true);
        $this->putJson("/api/agents/{$agent->id}/evaluation", ['criteria' => [], 'enabled' => true])->assertUnprocessable();
        $this->putJson("/api/agents/{$agent->id}/evaluation", ['criteria' => array_fill(0, 11, 'Bir kural metni'), 'enabled' => true])->assertUnprocessable();
        $this->putJson("/api/agents/{$agent->id}/evaluation", ['criteria' => self::RULES, 'enabled' => true])->assertOk()
            ->assertJsonPath('data.criteria', self::RULES)->assertJsonPath('data.enabled', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.evaluation.update']);

        $other = User::factory()->builder()->create();
        $this->actingAs($other)->getJson("/api/agents/{$agent->id}/evaluation")->assertOk()->assertJsonPath('data.can_edit', false);
        $this->putJson("/api/agents/{$agent->id}/evaluation", ['criteria' => self::RULES, 'enabled' => false])->assertForbidden();
    }

    public function test_scores_show_in_runs_overview_cards_and_evaluation_tab(): void
    {
        $agent = $this->agent();
        $a = $this->finishedRun($agent);
        $b = $this->finishedRun($agent);
        AgentRunScore::create(['agent_run_id' => $a->id, 'score' => 100, 'details' => array_map(fn ($r) => ['rule' => $r, 'passed' => true, 'reason' => 'x'], self::RULES), 'model' => 'm']);
        AgentRunScore::create(['agent_run_id' => $b->id, 'score' => 67, 'details' => array_map(fn ($r, $i) => ['rule' => $r, 'passed' => $i < 2, 'reason' => 'x'], self::RULES, [0, 1, 2]), 'model' => 'm']);

        $this->getJson("/api/agent-runs/{$b->id}")->assertJsonPath('data.score.score', 67)->assertJsonPath('data.score.details.2.passed', false);
        $this->getJson("/api/agents/{$agent->id}/runs")->assertJsonPath('data.0.score', 67);
        $overview = $this->getJson("/api/agents/{$agent->id}")->json('data.overview.kpis');
        $this->assertSame([84, 2], [$overview['score_avg'], $overview['scored_runs']]);

        // Geri bildirim yokken kartta ortalama puan.
        $card = collect($this->getJson('/api/agents')->json('data.agents'))->firstWhere('id', $agent->id);
        $this->assertSame([null, 84], [$card['useful_pct'], $card['score_avg']]);

        $tab = $this->getJson("/api/agents/{$agent->id}/evaluation")->json('data');
        $this->assertSame(84, $tab['avg_score_30d']);
        $this->assertSame(['rule' => self::RULES[2], 'checked' => 2, 'pass_pct' => 50], $tab['rule_pass_rates'][2]);
        $this->assertSame($b->id, $tab['recent'][0]['run_id']);
    }

    public function test_manual_rescore_by_owner(): void
    {
        $agent = $this->agent();
        $run = $this->finishedRun($agent);
        $this->fakeClaude()->text($this->scores([true, false, false]));

        $this->postJson("/api/agent-runs/{$run->id}/score")->assertOk()->assertJsonPath('data.score.score', 33);
        $this->assertSame(1, AgentRunScore::query()->where('agent_run_id', $run->id)->count());

        $this->actingAs(User::factory()->builder()->create())->postJson("/api/agent-runs/{$run->id}/score")->assertNotFound();
        $agent->evaluation()->update(['enabled' => false]);
        $this->actingAs($this->owner)->postJson("/api/agent-runs/{$run->id}/score")->assertUnprocessable();
    }
}
