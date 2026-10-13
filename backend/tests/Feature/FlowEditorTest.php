<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowProposal;
use App\Models\FlowVersion;
use App\Models\User;
use App\Services\Claude\FlowTools;
use App\Services\Flows\Blocks\FlowEditor;
use App\Services\Flows\Blocks\FlowGraph;
use App\Services\Flows\DryRun;
use App\Services\Flows\FlowEditException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class FlowEditorTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private const FLOW = <<<'YAML'
    id: long-delay-notice
    namespace: fortis.chat
    tasks:
      - id: get_delays
        type: io.kestra.plugin.core.http.Request
        uri: https://example.com/delays?min=240
        retry:
          type: constant
          interval: PT1M
          maxAttempt: 3
        timeout: PT30S
      - id: notify_each
        type: io.kestra.plugin.core.flow.ForEach
        values: "{{ outputs.get_delays.body }}"
        tasks:
          - id: log_delay
            type: io.kestra.plugin.core.log.Log
            message: "{{ taskrun.value }}"
      - id: remember
        type: io.kestra.plugin.core.kv.Set
        key: last_run
        value: "{{ execution.startDate }}"
    triggers:
      - id: every_15_min
        type: io.kestra.plugin.core.trigger.Schedule
        cron: "*/15 * * * *"
        timezone: Europe/Istanbul
    YAML;

    /** @var list<string> Kestra'ya giden yazma istekleri ("METHOD path") */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFlowEnvironment(secrets: ['TEAMS_WEBHOOK_URL', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM']);

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/flows/validate')) {
                return Http::response([['constraints' => null]]);
            }

            if ($request->method() === 'GET' && str_contains($path, '/flows/')) {
                return Http::response(null, 404);
            }

            if ($request->method() === 'GET' && str_contains($path, '/logs/')) {
                return Http::response([]);
            }

            if ($request->method() === 'GET' && str_contains($path, '/executions/')) {
                return Http::response(['id' => 'exec123', 'namespace' => 'fortis.chat.test', 'flowId' => 'long-delay-notice', 'state' => ['current' => 'RUNNING'], 'taskRunList' => []]);
            }

            $this->writes[] = "{$request->method()} {$path}";

            if (str_contains($path, '/executions/')) {
                return Http::response(['id' => 'exec123', 'state' => ['current' => 'CREATED']]);
            }

            return Http::response(['id' => 'long-delay-notice', 'namespace' => 'fortis.chat', 'revision' => 1]);
        });
    }

    public function test_builder_publishes_user_cannot_and_publishing_is_audited(): void
    {
        $flow = $this->flow();

        $this->actingAs(User::factory()->create())->postJson("/api/flows/{$flow->id}/publish")->assertForbidden();

        $builder = User::factory()->builder()->create();
        $this->actingAs($builder);
        $this->operate($flow, [['op' => 'delete', 'task_id' => 'remember']])->assertOk();
        $this->postJson("/api/flows/{$flow->id}/publish")->assertOk();

        $log = AuditLog::query()->where('action', 'flow.publish')->sole();
        $this->assertSame($builder->id, $log->user_id);
        $this->assertSame((string) $flow->id, $log->subject_id);
        $this->assertSame('Kestra', $log->system);
        $this->assertSame(AuditLog::RESULT_ALLOWED, $log->result);
    }

    private function flow(string $yaml = self::FLOW): Flow
    {
        $flow = Flow::factory()->create(['flow_id' => Yaml::parse($yaml)['id'], 'title' => 'Uzun rötar bildirimi']);
        FlowVersion::factory()->for($flow)->create(['number' => 1, 'yaml' => $yaml, 'yaml_hash' => hash('sha256', $yaml), 'title' => 'Uzun rötar bildirimi']);

        return $flow;
    }

    /** @param  list<array<string, mixed>>  $operations */
    private function operate(Flow $flow, array $operations, int $base = 1): TestResponse
    {
        return $this->postJson("/api/flows/{$flow->id}/operations", ['base_version' => $base, 'operations' => $operations]);
    }

    private function latestTask(Flow $flow, string $id): ?array
    {
        $yaml = Yaml::parse($flow->latestVersion()->yaml);
        $path = $this->app->make(FlowEditor::class)->findTask($yaml, $id);

        return $path === null ? null : data_get($yaml, implode('.', $path));
    }

    /** @return iterable<string, array{string}> */
    public static function fixtures(): iterable
    {
        foreach (glob(__DIR__.'/../fixtures/flows/*.yml') as $file) {
            yield basename($file) => [$file];
        }
    }

    #[DataProvider('fixtures')]
    public function test_yaml_to_graph_to_yaml_round_trip_is_lossless(string $file): void
    {
        $flow = Yaml::parseFile($file);
        $graph = $this->app->make(FlowGraph::class)->build($flow);
        $editor = $this->app->make(FlowEditor::class);

        // Değişiklik yapılmadan YAML'a dönüş: aynı yapı.
        $this->assertSame($flow, $editor->apply($flow, [])['flow']);

        // Her tanınan blok, mevcut ayarlarıyla yeniden yazıldığında görev birebir aynı kalır.
        foreach (FlowGraph::flatten($graph) as $node) {
            if ($node['readonly']) {
                continue;
            }

            $result = $editor->apply($flow, [['op' => 'update_settings', 'task_id' => $node['id'], 'settings' => $node['settings']]]);
            $this->assertEquals($flow, $result['flow'], "{$node['id']} ({$node['kind']}) yeniden yazılınca değişti");
        }

        foreach ($graph['triggers'] as $trigger) {
            if (! $trigger['readonly']) {
                $this->assertEquals($flow, $editor->apply($flow, [['op' => 'update_trigger', 'trigger_id' => $trigger['id'], 'settings' => $trigger['settings']]])['flow']);
            }
        }
    }

    public function test_graph_model_shows_blocks_containers_and_unknown_tasks_as_advanced(): void
    {
        $flow = $this->flow();

        $this->getJson("/api/flows/{$flow->id}/editor")
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.graph.triggers.0.kind', 'trigger_schedule')
            ->assertJsonPath('data.graph.triggers.0.settings.cron', '*/15 * * * *')
            ->assertJsonPath('data.graph.tasks.0.kind', 'http_request')
            ->assertJsonPath('data.graph.tasks.1.kind', 'foreach')
            ->assertJsonPath('data.graph.tasks.1.children.0.id', 'log_delay')
            ->assertJsonPath('data.graph.tasks.2.kind', 'advanced')
            ->assertJsonPath('data.graph.tasks.2.readonly', true)
            ->assertJsonPath('data.graph.tasks.2.raw_type', 'io.kestra.plugin.core.kv.Set');
    }

    public function test_update_settings_keeps_id_and_unknown_fields_and_creates_canvas_draft(): void
    {
        $flow = $this->flow();

        $this->operate($flow, [['op' => 'update_settings', 'task_id' => 'get_delays', 'settings' => ['uri' => 'https://example.com/delays?min=180']]])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.version_source', 'canvas')
            ->assertJsonPath('changed', true);

        $task = $this->latestTask($flow, 'get_delays');
        $this->assertSame('https://example.com/delays?min=180', $task['uri']);
        $this->assertSame(['type' => 'constant', 'interval' => 'PT1M', 'maxAttempt' => 3], $task['retry']);
        $this->assertSame('PT30S', $task['timeout']);
        $this->assertSame(FlowVersion::SOURCE_CANVAS, $flow->latestVersion()->source);
        $this->assertSame([], $this->writes, 'Taslak Kestra\'ya gitmemeli');
    }

    public function test_advanced_block_survives_other_edits_and_cannot_be_edited(): void
    {
        $flow = $this->flow();
        $before = $this->latestTask($flow, 'remember');

        $this->operate($flow, [['op' => 'update_settings', 'task_id' => 'log_delay', 'settings' => ['message' => 'Rötar: {{ taskrun.value }}']]])->assertOk();
        $this->assertSame($before, $this->latestTask($flow, 'remember'));

        $this->operate($flow, [['op' => 'update_settings', 'task_id' => 'remember', 'settings' => ['key' => 'x']]], base: 2)
            ->assertStatus(422)
            ->assertJsonPath('errors.0.task_id', 'remember');
        $this->assertSame(2, $flow->latestVersion()->number);
    }

    public function test_inserting_a_forbidden_or_unknown_block_is_rejected_without_a_version(): void
    {
        $flow = $this->flow();

        foreach (['advanced', 'http_request', 'apm_token', 'scripts_shell', 'ai_summarize', 'trigger_schedule'] as $kind) {
            $this->operate($flow, [['op' => 'insert', 'parent_id' => null, 'after_id' => 'get_delays', 'kind' => $kind, 'settings' => []]])
                ->assertStatus(422);
        }

        $this->assertSame(1, $flow->versions()->count());
    }

    public function test_teams_notification_can_be_inserted_into_a_loop_with_unique_ids(): void
    {
        $flow = $this->flow();

        $this->operate($flow, [
            ['op' => 'insert', 'parent_id' => 'notify_each', 'after_id' => 'log_delay', 'kind' => 'notify_teams', 'settings' => ['message' => 'Rötar {{ taskrun.value }}']],
            ['op' => 'insert', 'parent_id' => 'notify_each', 'after_id' => 'notify_teams', 'kind' => 'notify_teams', 'settings' => ['message' => 'İkinci']],
        ])->assertOk()->assertJsonPath('created', ['notify_teams', 'notify_teams_2']);

        $loop = $this->latestTask($flow, 'notify_each');
        $this->assertSame(['log_delay', 'notify_teams', 'notify_teams_2'], array_column($loop['tasks'], 'id'));
        $this->assertSame("{{ secret('TEAMS_WEBHOOK_URL') }}", $loop['tasks'][1]['url']);
        $this->assertSame('{"text":"Rötar {{ taskrun.value }}"}', $loop['tasks'][1]['payload']);
    }

    public function test_delete_move_and_trigger_operations(): void
    {
        $flow = $this->flow();

        $this->operate($flow, [
            ['op' => 'move', 'task_id' => 'remember', 'parent_id' => null, 'after_id' => null],
            ['op' => 'delete', 'task_id' => 'log_delay'],
            ['op' => 'insert', 'parent_id' => 'notify_each', 'after_id' => null, 'kind' => 'log', 'settings' => ['message' => 'Yeni']],
            ['op' => 'update_trigger', 'trigger_id' => 'every_15_min', 'settings' => ['cron' => '*/10 * * * *']],
        ])->assertOk();

        $yaml = Yaml::parse($flow->latestVersion()->yaml);
        $this->assertSame(['remember', 'get_delays', 'notify_each'], array_column($yaml['tasks'], 'id'));
        $this->assertSame(['log'], array_column($yaml['tasks'][2]['tasks'], 'id'));
        $this->assertSame('*/10 * * * *', $yaml['triggers'][0]['cron']);
        $this->assertSame('Europe/Istanbul', $yaml['triggers'][0]['timezone']);
    }

    public function test_a_block_cannot_be_moved_into_itself(): void
    {
        $flow = $this->flow();

        $this->operate($flow, [['op' => 'move', 'task_id' => 'notify_each', 'parent_id' => 'notify_each', 'after_id' => null]])->assertStatus(422);
    }

    public function test_invalid_values_are_rejected_and_bound_to_the_block(): void
    {
        $flow = $this->flow();

        $this->operate($flow, [['op' => 'update_settings', 'task_id' => 'get_delays', 'settings' => ['uri' => 'http://127.0.0.1/admin']]])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.task_id', 'get_delays');

        $this->assertSame(1, $flow->versions()->count());
    }

    public function test_stale_base_version_returns_conflict(): void
    {
        $flow = $this->flow();
        $this->operate($flow, [['op' => 'update_trigger', 'trigger_id' => 'every_15_min', 'settings' => ['cron' => '0 * * * *']]])->assertOk();

        $this->operate($flow, [['op' => 'delete', 'task_id' => 'remember']], base: 1)
            ->assertStatus(409)
            ->assertJsonPath('latest_version', 2);
        $this->assertSame(2, $flow->versions()->count());
    }

    public function test_yaml_editor_restore_and_proposal_paths_all_go_through_the_validator(): void
    {
        $flow = $this->flow();
        $forbidden = str_replace('io.kestra.plugin.core.kv.Set', 'io.kestra.plugin.scripts.shell.Commands', self::FLOW);

        // YAML editörü
        $this->putJson("/api/flows/{$flow->id}/yaml", ['base_version' => 1, 'yaml' => $forbidden])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.task_id', 'remember');

        // Geri yükleme: veritabanında bozulmuş eski bir sürüm
        FlowVersion::factory()->for($flow)->create(['number' => 2, 'yaml' => $forbidden, 'yaml_hash' => hash('sha256', $forbidden)]);
        $this->postJson("/api/flows/{$flow->id}/versions/2/restore", ['base_version' => 2])->assertStatus(422);

        // Chat önerisi: kaydedilmiş öneri sonradan değiştirilmiş olsa bile uygulanırken yeniden doğrulanır
        $proposal = FlowProposal::create(['flow_id' => $flow->id, 'base_version' => 2, 'title' => 'x', 'summary' => [], 'yaml' => $forbidden, 'validation' => [], 'status' => 'pending']);
        $this->postJson("/api/proposals/{$proposal->id}/apply")->assertStatus(422);
        $this->assertSame('pending', $proposal->refresh()->status);

        // İşlemler: yasaklı tipte görev eklenemez (yukarıda), geçersiz değer reddedilir
        $this->operate($flow, [['op' => 'update_settings', 'task_id' => 'get_delays', 'settings' => ['uri' => 'http://169.254.169.254/latest']]], base: 2)->assertStatus(422);

        $this->assertSame(2, $flow->versions()->count());
        $this->assertSame([], $this->writes);
    }

    public function test_valid_yaml_edit_creates_a_yaml_draft(): void
    {
        $flow = $this->flow();

        $this->putJson("/api/flows/{$flow->id}/yaml", ['base_version' => 1, 'yaml' => str_replace('min=240', 'min=180', self::FLOW)])
            ->assertOk()
            ->assertJsonPath('data.version_source', 'yaml');

        $this->putJson("/api/flows/{$flow->id}/yaml", ['base_version' => 2, 'yaml' => str_replace('long-delay-notice', 'renamed', self::FLOW)])
            ->assertStatus(422);
    }

    public function test_publish_sends_the_draft_to_kestra_and_restore_brings_back_an_old_version(): void
    {
        $flow = $this->flow();
        $this->operate($flow, [['op' => 'delete', 'task_id' => 'remember']])->assertOk();

        $this->postJson("/api/flows/{$flow->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.published_version', 2)
            ->assertJsonPath('data.has_draft', false);
        $this->assertContains('POST /api/v1/main/flows', $this->writes);

        $this->postJson("/api/flows/{$flow->id}/publish")->assertStatus(422);

        $this->postJson("/api/flows/{$flow->id}/versions/1/restore", ['base_version' => 2])
            ->assertOk()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.version_source', 'restore')
            ->assertJsonPath('data.has_draft', true);
        $this->assertNotNull($this->latestTask($flow, 'remember'));

        $this->getJson("/api/flows/{$flow->id}/versions")
            ->assertOk()
            ->assertJsonPath('data.0.number', 3)
            ->assertJsonPath('data.1.is_published', true);

        $this->getJson("/api/flows/{$flow->id}/versions/1/diff?against=2")
            ->assertOk()
            ->assertJsonPath('data.version.number', 1)
            ->assertJsonPath('data.against.number', 2);
    }

    public function test_dry_run_replaces_writing_steps_removes_triggers_and_uses_test_namespace(): void
    {
        $flow = Yaml::parse(<<<'YAML'
        id: notify
        namespace: fortis.chat
        tasks:
          - id: check
            type: io.kestra.plugin.core.http.Request
            uri: https://example.com/health
          - id: post_it
            type: io.kestra.plugin.core.http.Request
            uri: https://example.com/hook
            method: POST
          - id: each
            type: io.kestra.plugin.core.flow.ForEach
            values: ["a"]
            tasks:
              - id: mail
                type: io.kestra.plugin.email.MailSend
                to: ops@example.com
                subject: Uyarı
              - id: teams
                type: io.kestra.plugin.microsoft365.teams.TeamsIncomingWebhook
                url: "{{ secret('TEAMS_WEBHOOK_URL') }}"
                payload: '{"text":"x"}'
        triggers:
          - id: hourly
            type: io.kestra.plugin.core.trigger.Schedule
            cron: "0 * * * *"
        YAML);

        $result = $this->app->make(DryRun::class)->transform($flow, 'fortis.chat.test');

        $this->assertSame(['post_it', 'mail', 'teams'], $result['skipped']);
        $this->assertSame('fortis.chat.test', $result['flow']['namespace']);
        $this->assertArrayNotHasKey('triggers', $result['flow']);
        $this->assertSame($flow['tasks'][0], $result['flow']['tasks'][0]);
        $this->assertSame('io.kestra.plugin.core.log.Log', $result['flow']['tasks'][1]['type']);
        $this->assertSame('io.kestra.plugin.core.log.Log', $result['flow']['tasks'][2]['tasks'][0]['type']);
        $this->assertStringContainsString('ops@example.com', $result['flow']['tasks'][2]['tasks'][0]['message']);
    }

    public function test_test_run_deploys_the_dry_copy_to_the_test_namespace(): void
    {
        $flow = $this->flow();

        $this->postJson("/api/flows/{$flow->id}/test-run")
            ->assertCreated()
            ->assertJsonPath('data.is_test', true);

        $this->assertContains('POST /api/v1/main/flows', $this->writes);
        $this->assertContains('POST /api/v1/main/executions/fortis.chat.test/long-delay-notice', $this->writes);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/flows')
            && str_contains($r->body(), 'namespace: fortis.chat.test') && ! str_contains($r->body(), 'triggers'));
    }

    public function test_editor_chat_proposal_is_validated_but_not_saved_until_applied(): void
    {
        $flow = $this->flow();
        $conversation = Conversation::create(['title' => 'editör', 'context_flow_id' => $flow->id, 'kind' => Conversation::KIND_EDITOR]);
        $tools = $this->app->make(FlowTools::class);
        $tools->startTurn($conversation);

        $result = $tools->execute('propose_flow', ['title' => 'Uzun rötar bildirimi', 'summary' => ['Eşik 180'], 'yaml' => str_replace('min=240', 'min=180', self::FLOW)]);

        $this->assertFalse($result->isError, $result->content);
        $this->assertSame('proposal', $result->card['type']);
        $this->assertSame(1, $flow->versions()->count(), 'Öneri sürüm olarak kaydedilmemeli');

        $proposal = FlowProposal::findOrFail($result->card['proposal_id']);
        $this->getJson("/api/proposals/{$proposal->id}")
            ->assertOk()
            ->assertJsonPath('data.base_version', 1)
            ->assertJsonPath('data.graph.tasks.0.settings.uri', 'https://example.com/delays?min=180')
            ->assertJsonPath('data.base_graph.tasks.0.settings.uri', 'https://example.com/delays?min=240');

        $this->postJson("/api/proposals/{$proposal->id}/apply")->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame(FlowVersion::SOURCE_CHAT, $flow->latestVersion()->source);
        $this->assertSame('applied', $proposal->refresh()->status);

        // Başka id'li akış bu sohbette önerilemez
        $other = $tools->execute('propose_flow', ['title' => 'x', 'summary' => ['x'], 'yaml' => str_replace('long-delay-notice', 'another-flow', self::FLOW)]);
        $this->assertTrue($other->isError);
    }

    public function test_rejected_proposal_creates_nothing(): void
    {
        $flow = $this->flow();
        $proposal = FlowProposal::create(['flow_id' => $flow->id, 'base_version' => 1, 'title' => 'x', 'summary' => [], 'yaml' => self::FLOW, 'validation' => [], 'status' => 'pending']);

        $this->postJson("/api/proposals/{$proposal->id}/reject")->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson("/api/proposals/{$proposal->id}/apply")->assertStatus(422);
        $this->assertSame(1, $flow->versions()->count());
    }

    public function test_get_flow_returns_the_latest_draft_for_fortis_flows(): void
    {
        $flow = $this->flow();
        $this->operate($flow, [['op' => 'delete', 'task_id' => 'remember']])->assertOk();

        $tools = $this->app->make(FlowTools::class);
        $tools->startTurn(Conversation::factory()->create());
        $result = json_decode($tools->execute('get_flow', ['flow_id' => 'long-delay-notice'])->content, true);

        $this->assertSame(2, $result['version']);
        $this->assertStringNotContainsString('remember', $result['yaml']);
    }

    public function test_catalog_lists_only_allowed_blocks_and_app_connections_without_credentials(): void
    {
        $response = $this->getJson('/api/catalog/blocks')->assertOk();

        $kinds = array_column($response->json('blocks'), 'kind');
        $this->assertContains('notify_teams', $kinds);
        $this->assertNotContains('scripts_shell', $kinds);

        $apps = collect($response->json('apps'))->keyBy('app');
        $this->assertTrue($apps['teams']['connected']);
        $this->assertFalse($apps['slack']['connected']);
        $this->assertSame(['SLACK_WEBHOOK_URL'], $apps['slack']['missing_secrets']);
    }

    public function test_editor_exception_carries_block_ids(): void
    {
        $e = FlowEditException::forTask('x', 'hata');

        $this->assertSame([['message' => 'hata', 'task_id' => 'x']], $e->errors);
    }
}
