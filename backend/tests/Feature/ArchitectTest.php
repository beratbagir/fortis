<?php

namespace Tests\Feature;

use App\Agents\Architect;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentTrigger;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\AgentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\FakesDataSources;
use Tests\TestCase;

class ArchitectTest extends TestCase
{
    use FakesDataSources, RefreshDatabase;

    private User $builder;

    /** @var list<array{method: string, path: string, body: string}> */
    private array $kestra = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFlowEnvironment(secrets: ['FORTIS_INTERNAL_KEY']);
        config(['agents.internal_url' => 'https://fortis.test', 'agents.teams_channels' => ['occ-operasyon' => 'https://example.webhook.office.com/x']]);
        Queue::fake();
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->kestra[] = ['method' => $request->method(), 'path' => $path, 'body' => $request->body()];

            return match (true) {
                str_ends_with($path, '/flows/validate') => Http::response([['flow' => 'x']]),
                $request->method() === 'GET' => Http::response('', 404),
                default => Http::response(['id' => 'x']),
            };
        });

        $this->builder = User::factory()->builder()->create(['email' => 'kerem@freebird.com.tr']);
        $this->actingAs($this->builder);
    }

    /** @return array<string, mixed> */
    private function proposal(array $override = []): array
    {
        return array_replace([
            'name' => 'Sabah Rötar Özeti',
            'description' => 'Dünkü rötarları özetleyip sahibine e-postayla gönderir.',
            'icon' => 'sr',
            'instructions' => 'Dünkü rötarları APM\'den al, en uzun beşini nedenleriyle tabloya koy ve e-posta taslağı hazırla.',
            'tools' => [['name' => 'query_apm'], ['name' => 'send_email', 'constraints' => ['recipients' => ['KULLANICI_EPOSTASI'], 'max_per_run' => 1]]],
            'triggers' => [['type' => 'schedule', 'cron' => '0 6 * * *', 'input_template' => 'Dünkü rötarları özetle.']],
            'approval_policy' => ['send_email' => ['mode' => 'always']],
            'plan' => [
                ['type' => 'trigger', 'title' => 'Her gün 06:00'],
                ['type' => 'read', 'title' => 'APM · dünkü uçuşlar', 'detail' => 'salt okunur'],
                ['type' => 'decide', 'title' => 'Rötar 30 dk üstü mü?', 'no' => 'Dahil etme'],
                ['type' => 'draft', 'title' => 'Özet e-postası'],
                ['type' => 'approve', 'title' => 'Sahibi onaylar'],
                ['type' => 'act', 'title' => 'E-postayla gönder'],
            ],
            'suggested_prompts' => ['Dün en uzun rötar hangisiydi?'],
        ], $override);
    }

    private function newAgent(): Agent
    {
        $id = $this->postJson('/api/agents', ['mode' => 'blank'])->assertCreated()->json('data.id');

        return Agent::query()->findOrFail($id);
    }

    public function test_architect_conversation_offers_only_architect_tools_and_propose_agent_saves_a_version(): void
    {
        $this->fakeDataSources();
        $agent = $this->newAgent();
        $this->assertSame(Conversation::KIND_ARCHITECT, Conversation::find($agent->conversation_id)->kind);

        $this->fakeClaude()->toolCalls([['name' => 'propose_agent', 'input' => $this->proposal()]])->text('Taslağı hazırladım.');
        $this->post("/api/conversations/{$agent->conversation_id}/messages", ['content' => 'Her sabah dünkü rötarları bana gönderen bir agent'], ['Accept' => 'text/event-stream'])->assertOk()->streamedContent();

        $names = array_column($this->claude->requests[0]['tools'], 'name');
        $this->assertContains('propose_agent', $names);
        $this->assertContains('query_apm', $names);
        $this->assertNotContains('propose_flow', $names);
        $this->assertNotContains('create_artifact', $names);
        $this->assertStringContainsString('Architect kipi', json_encode($this->claude->requests[0]['system'], JSON_UNESCAPED_UNICODE));

        $agent->refresh();
        $this->assertSame(['Sabah Rötar Özeti', 'SR', 1, Agent::STATUS_DRAFT, 'sabah-rotar-ozeti'], [$agent->name, $agent->icon, $agent->current_version, $agent->status, $agent->slug]);
        // "Bana" yer tutucusu sahibin adresi olur…
        $this->assertSame(['kerem@freebird.com.tr'], $agent->toolConstraints('send_email')['recipients']);
        // …ama Claude'a giden taslakta yine yer tutucudur.
        $this->assertStringNotContainsString('kerem@freebird.com.tr', json_encode($this->claude->requests[1]['system'], JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('KULLANICI_EPOSTASI', json_encode($this->claude->requests[1]['system'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(['manual', 'schedule'], $agent->triggers()->orderBy('id')->pluck('type')->all());
        $this->assertSame('decide', $agent->plan[2]['type']);
        $this->assertSame('Dahil etme', $agent->plan[2]['no']);
        $this->assertDatabaseHas('agent_versions', ['agent_id' => $agent->id, 'number' => 1, 'source' => 'architect']);
        // Taslağın zamanlaması Kestra'ya pilota alınınca gider.
        $this->assertFalse(collect($this->kestra)->contains(fn (array $r) => $r['method'] === 'POST' && str_ends_with($r['path'], '/flows')));

        $this->getJson("/api/agents/{$agent->id}/architect")->assertOk()
            ->assertJsonPath('data.reads', ['APM'])
            ->assertJsonPath('data.writes', ['E-posta'])
            ->assertJsonPath('data.estimated_cost_usd', null)
            ->assertJsonPath('data.plan.0.title', 'Her gün 06:00');
    }

    public function test_propose_agent_rejects_unknown_tools_bad_recipients_and_invalid_triggers(): void
    {
        $agent = $this->newAgent();
        $architect = app(Architect::class);

        $outcome = $architect->propose($agent, $this->proposal([
            'tools' => [['name' => 'run_shell'], ['name' => 'send_email', 'constraints' => ['recipients' => ['dis@gmail.com']]], ['name' => 'post_teams', 'constraints' => ['channels' => ['genel']]]],
            'triggers' => [['type' => 'schedule', 'cron' => 'her sabah'], ['type' => 'event', 'event' => 'flight.exploded'], ['type' => 'webhook']],
            'plan' => [['type' => 'trigger', 'title' => 'x'], ['type' => 'teleport', 'title' => 'y']],
        ]), $this->builder);

        $this->assertFalse($outcome['ok']);
        $errors = implode("\n", $outcome['result']['errors']);
        foreach (["'run_shell' katalogda yok", "alıcı 'dis@gmail.com' izinli değil", 'tanımlı olmayan Teams kanalı: genel', "geçersiz cron ifadesi 'her sabah'", "bilinmeyen olay 'flight.exploded'", "tür 'schedule' veya 'event'", 'plan[1]', 'onay (approve) adımı'] as $expected) {
            $this->assertStringContainsString($expected, $errors);
        }
        $this->assertSame(0, $agent->refresh()->current_version);
    }

    public function test_architect_can_never_set_unapproved_sending_and_keeps_a_manual_choice(): void
    {
        $agent = $this->newAgent();
        $architect = app(Architect::class);
        $admin = User::factory()->admin()->create();
        $tools = [['name' => 'query_apm'], ['name' => 'send_email', 'constraints' => ['recipients' => ['KULLANICI_EPOSTASI']]], ['name' => 'post_teams', 'constraints' => ['channels' => ['occ-operasyon']]]];

        // Admin'in sohbeti bile olsa Architect'in önerdiği onaysız mod yok sayılır; Teams dahil her şey onaylı.
        $outcome = $architect->propose($agent, $this->proposal(['tools' => $tools, 'approval_policy' => ['send_email' => ['mode' => 'never'], 'post_teams' => ['mode' => 'internal_only_auto']]]), $admin);
        $this->assertTrue($outcome['ok']);
        $this->assertStringContainsString('Onaysız gönderim Architect\'te ayarlanamaz', $outcome['result']['approval_note']);
        $this->assertSame(['mode' => 'always'], $agent->refresh()->approval_policy['send_email']);
        $this->assertSame(['mode' => 'always'], $agent->approval_policy['post_teams']);

        // Mod belirtilmezse de (ayar varsayılanı dahil) her zaman onay.
        $architect->propose($agent, $this->proposal(['tools' => $tools]), $this->builder);
        $this->assertSame(['always', 'always'], [$agent->refresh()->approval_policy['send_email']['mode'], $agent->approval_policy['post_teams']['mode']]);

        // Builder Yapılandırma'dan elle "iç alıcılara onaysız" seçer; "onaysız" (never) sadece admin.
        $draft = $this->getJson("/api/agents/{$agent->id}")->json('data.draft');
        $draft['approval_policy']['post_teams'] = ['mode' => 'never'];
        $this->putJson("/api/agents/{$agent->id}", $draft)->assertUnprocessable();
        $draft['approval_policy']['post_teams'] = ['mode' => 'internal_only_auto'];
        $this->putJson("/api/agents/{$agent->id}", $draft)->assertOk();

        // Architect sonradan kaydetse de insanın seçimi korunur; Architect'in kendi önerisi yine yok sayılır.
        $outcome = $architect->propose($agent, $this->proposal(['tools' => $tools, 'approval_policy' => ['send_email' => ['mode' => 'internal_only_auto']]]), $this->builder);
        $this->assertSame('internal_only_auto', $agent->refresh()->approval_policy['post_teams']['mode']);
        $this->assertSame('always', $agent->approval_policy['send_email']['mode']);
        $this->assertArrayHasKey('approval_note', $outcome['result']);
    }

    public function test_pilot_requires_a_saved_draft_and_uploads_schedules_and_live_requires_a_successful_pilot_run(): void
    {
        $agent = $this->newAgent();
        $this->postJson("/api/agents/{$agent->id}/status", ['status' => 'pilot'])->assertUnprocessable();

        app(Architect::class)->propose($agent, $this->proposal(), $this->builder);
        $this->postJson("/api/agents/{$agent->id}/status", ['status' => 'live'])->assertConflict();

        $this->postJson("/api/agents/{$agent->id}/status", ['status' => 'pilot'])->assertOk()->assertJsonPath('data.status', 'pilot')->assertJsonPath('data.can_go_live', false);
        $upload = collect($this->kestra)->last(fn (array $r) => $r['method'] === 'POST' && str_ends_with($r['path'], '/flows'));
        $this->assertNotNull($upload);
        $this->assertFalse(Yaml::parse($upload['body'])['disabled']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.status']);

        // Test çalışması sayılmaz.
        $agent->runs()->create(['status' => AgentRun::SUCCEEDED, 'input' => 'x', 'is_test' => true]);
        $this->postJson("/api/agents/{$agent->id}/status", ['status' => 'live'])->assertUnprocessable();

        $agent->runs()->create(['status' => AgentRun::SUCCEEDED, 'input' => 'x', 'is_test' => false]);
        $this->getJson("/api/agents/{$agent->id}/architect")->assertJsonPath('data.can_go_live', true);
        $this->postJson("/api/agents/{$agent->id}/status", ['status' => 'live'])->assertOk()->assertJsonPath('data.status', 'live');

        // user rolü Architect'e ve durum geçişlerine giremez; başkasının agentını göremez.
        $this->actingAs(User::factory()->create())->postJson("/api/agents/{$agent->id}/status", ['status' => 'paused'])->assertForbidden();
        $this->actingAs(User::factory()->builder()->create())->getJson("/api/agents/{$agent->id}/architect")->assertNotFound();
    }

    public function test_templates_and_copies_become_drafts_of_the_new_owner(): void
    {
        $this->seed(AgentTemplateSeeder::class);

        $build = $this->getJson('/api/build')->assertOk()->json('data');
        $this->assertCount(4, $build['templates']);
        $morning = collect($build['templates'])->firstWhere('key', 'morning-brief');
        $this->assertSame(['APM', 'IBS', 'E-posta'], $morning['systems']);
        $this->assertSame('Zamanlama: 0 6 * * *', $morning['trigger']);

        $id = $this->postJson('/api/agents', ['mode' => 'template', 'template_key' => 'long-delay-alert'])->assertCreated()->json('data.id');
        $copy = Agent::query()->findOrFail($id);
        $this->assertSame([Agent::STATUS_DRAFT, $this->builder->id, 'Uzun Rötar Bildirimi', 1], [$copy->status, $copy->owner_id, $copy->name, $copy->current_version]);
        $this->assertSame(['event' => 'flight.delayed', 'min_delay_minutes' => 120], $copy->triggers()->where('type', AgentTrigger::EVENT)->sole()->config);
        $this->assertNotNull($copy->conversation_id);
        $this->assertSame(1, Agent::query()->where('template_key', 'long-delay-alert')->where('status', Agent::STATUS_TEMPLATE)->count());

        // Şablondan kurulan taslak doğrudan pilota alınabilir (geçerli taslak).
        $this->postJson("/api/agents/{$id}/status", ['status' => 'pilot'])->assertOk();

        $this->postJson('/api/agents', ['mode' => 'copy', 'source_agent_id' => $id])->assertCreated()->assertJsonPath('data.name', 'Uzun Rötar Bildirimi (kopya)')->assertJsonPath('data.status', 'draft');
        $this->actingAs(User::factory()->builder()->create())->postJson('/api/agents', ['mode' => 'copy', 'source_agent_id' => $id])->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson('/api/build')->assertForbidden();
    }

    public function test_agent_from_conversation_seeds_architect_with_questions_and_queries(): void
    {
        $source = Conversation::create(['user_id' => $this->builder->id, 'title' => 'Rötarlar']);
        $source->messages()->create(['role' => Message::ROLE_USER, 'content' => [['type' => 'text', 'text' => 'Dün en çok rötar yapan 5 uçuş?']], 'text' => 'Dün en çok rötar yapan 5 uçuş?']);
        $source->messages()->create(['role' => Message::ROLE_ASSISTANT, 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'query_apm', 'input' => ['path' => '/api/legs', 'purpose' => 'Dünkü uçuşlar']]], 'text' => '']);

        $data = $this->postJson('/api/agents/from-conversation', ['conversation_id' => $source->id, 'action_title' => 'Bunu her sabah otomatik al'])->assertCreated()->json('data');

        $this->assertStringContainsString('Bunu her sabah otomatik al', $data['seed']);
        $this->assertStringContainsString('- Dün en çok rötar yapan 5 uçuş?', $data['seed']);
        $this->assertStringContainsString('- query_apm: Dünkü uçuşlar', $data['seed']);
        $this->assertSame('draft', $data['status']);

        $this->actingAs(User::factory()->builder()->create())->postJson('/api/agents/from-conversation', ['conversation_id' => $source->id])->assertNotFound();
    }

    public function test_test_run_from_architect_is_queued_and_shown_as_last_test(): void
    {
        $agent = $this->newAgent();
        app(Architect::class)->propose($agent, $this->proposal(), $this->builder);

        $runId = $this->postJson("/api/agents/{$agent->id}/runs", ['test' => true, 'input' => 'Dünkü rötarları özetle.'])->assertCreated()->json('data.id');

        $this->getJson("/api/agents/{$agent->id}/architect")->assertJsonPath('data.last_test_run.id', $runId)->assertJsonPath('data.last_test_run.is_test', true);
        $this->postJson("/api/agents/{$agent->id}/runs", ['test' => false])->assertConflict();
    }

    public function test_architect_placeholder_is_resolved_only_in_recipient_constraints_and_only_to_the_owner(): void
    {
        $agent = $this->newAgent();
        $admin = User::factory()->admin()->create(['email' => 'admin@freebird.com.tr']);

        // Admin başkasının agentını düzenlese de yer tutucu agent sahibinin adresi olur; talimat ve açıklamada çevrilmez.
        $this->assertTrue(app(Architect::class)->propose($agent, $this->proposal([
            'description' => 'KULLANICI_EPOSTASI adresine özet gönderir.',
            'instructions' => 'Özeti KULLANICI_EPOSTASI adresine gönder; konu satırında KULLANICI_EPOSTASI yazmasın.',
        ]), $admin)['ok']);

        $agent->refresh();
        $this->assertSame(['kerem@freebird.com.tr'], $agent->toolConstraints('send_email')['recipients']);
        $this->assertStringContainsString('KULLANICI_EPOSTASI adresine gönder', $agent->instructions);
        $this->assertSame('KULLANICI_EPOSTASI adresine özet gönderir.', $agent->description);
    }

    public function test_architect_sees_flat_apm_fields_and_raw_apm_paths_are_rejected(): void
    {
        $agent = $this->newAgent();
        $prompt = app(Architect::class)->promptSection($agent);
        $this->assertStringContainsString('`GET /api/legs` → `leg_id`, `flight_no`', $prompt);
        $this->assertStringContainsString('`dep_delay_min`', $prompt);

        $outcome = app(Architect::class)->propose($agent, $this->proposal([
            'instructions' => 'Rötarı delaysInfo.departureDelay1..4.delayDurationInMinutes toplamından hesapla; statusInfo.cancelled olanları ayır.',
        ]), $this->builder);
        $this->assertFalse($outcome['ok']);
        $this->assertStringContainsString('delaysInfo.departureDelay1', implode(' ', $outcome['result']['errors']));
        $this->assertStringContainsString('statusInfo.cancelled', implode(' ', $outcome['result']['errors']));

        $this->assertTrue(app(Architect::class)->propose($agent, $this->proposal([
            'instructions' => 'dep_delay_min 15 ve üzeri olan bacakları al; cancelled olanları ayrıca say; delay_codes ile nedenleri grupla.',
        ]), $this->builder)['ok']);
    }
}
