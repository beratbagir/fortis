<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Execution;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Services\Claude\ClaudeChat;
use App\Services\Claude\FlowTools;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class ExecutionTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private const APPROVAL_FLOW = [
        'id' => 'discount-approval',
        'namespace' => 'fortis.chat',
        'inputs' => [['id' => 'customer', 'type' => 'STRING'], ['id' => 'rate', 'type' => 'INT', 'defaults' => 10]],
        'tasks' => [[
            'id' => 'wait_for_approval',
            'type' => 'io.kestra.plugin.core.flow.Pause',
            'onResume' => [['id' => 'approved', 'type' => 'BOOL', 'defaults' => false], ['id' => 'note', 'type' => 'STRING', 'defaults' => '']],
        ]],
        'triggers' => [['id' => 'hook', 'type' => 'io.kestra.plugin.core.trigger.Webhook', 'key' => 'abcdefghijklmnopqrstuvwx']],
    ];

    /** Sahte Kestra'daki çalışma durumu. */
    private array $remote = [
        'id' => 'exec-1',
        'namespace' => 'fortis.chat',
        'flowId' => 'discount-approval',
        'flowRevision' => 1,
        'state' => ['current' => 'RUNNING', 'startDate' => '2026-10-01T10:00:00Z', 'duration' => 'PT1S'],
        'taskRunList' => [],
    ];

    private array $logs = [];

    /** @var list<Request> Kestra'ya giden istekler */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFlowEnvironment();

        Http::fake(function (Request $request) {
            $this->sent[] = $request;
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $request->method() === 'POST' && str_starts_with($path, '/api/v1/main/executions/fortis.chat/') => Http::response(['id' => 'exec-1', 'state' => ['current' => 'CREATED']]),
                $request->method() === 'GET' && str_starts_with($path, '/api/v1/main/executions/search') => Http::response(['total' => 1, 'results' => [['id' => 'exec-1']]]),
                $request->method() === 'GET' && str_starts_with($path, '/api/v1/main/executions/') => Http::response($this->remote),
                $request->method() === 'GET' && str_starts_with($path, '/api/v1/main/logs/') => Http::response($this->logs),
                default => Http::response(),
            };
        });
    }

    private function uploadedFlow(array $definition = self::APPROVAL_FLOW, ?Conversation $conversation = null): Flow
    {
        $yaml = Yaml::dump($definition, 64, 2);
        $version = FlowVersion::factory()->create([
            'yaml' => $yaml,
            'yaml_hash' => hash('sha256', $yaml),
            'uploaded_at' => now(),
            'kestra_revision' => 1,
            'flow_id' => Flow::factory()->create(['flow_id' => $definition['id'], 'status' => Flow::STATUS_ACTIVE, 'conversation_id' => $conversation?->id]),
        ]);
        $version->flow->update(['current_version_id' => $version->id]);

        return $version->flow->refresh();
    }

    private function paused(): void
    {
        $this->remote['state']['current'] = 'PAUSED';
        $this->remote['taskRunList'] = [['taskId' => 'wait_for_approval', 'state' => ['current' => 'PAUSED'], 'attempts' => [[]]]];
    }

    private function lastRequest(string $method, string $pathPrefix): ?Request
    {
        foreach (array_reverse($this->sent) as $request) {
            if ($request->method() === $method && str_starts_with(parse_url($request->url(), PHP_URL_PATH), $pathPrefix)) {
                return $request;
            }
        }

        return null;
    }

    public function test_run_starts_execution_with_inputs_and_labels(): void
    {
        $conversation = Conversation::factory()->create();
        $flow = $this->uploadedFlow();

        $this->postJson("/api/flows/{$flow->id}/executions", ['inputs' => ['customer' => 'acme'], 'conversation_id' => $conversation->id])
            ->assertCreated()
            ->assertJsonPath('data.kestra_execution_id', 'exec-1')
            ->assertJsonPath('data.state', 'RUNNING')
            ->assertJsonPath('data.inputs', ['customer' => 'acme']);

        $create = $this->lastRequest('POST', '/api/v1/main/executions/fortis.chat/discount-approval');
        $this->assertNotNull($create);
        $this->assertStringContainsString('labels=source%3Afortis-chat', $create->url());
        $this->assertStringContainsString("labels=conversation%3A{$conversation->id}", $create->url());
        $this->assertSame([['name' => 'customer', 'contents' => 'acme']], array_map(fn ($p) => ['name' => $p['name'], 'contents' => $p['contents']], $create->data()));

        $this->assertDatabaseHas('executions', ['kestra_execution_id' => 'exec-1', 'conversation_id' => $conversation->id, 'state' => 'RUNNING']);
    }

    public function test_run_is_refused_for_draft_flow_and_bad_inputs(): void
    {
        $draft = Flow::factory()->create();
        $this->postJson("/api/flows/{$draft->id}/executions")->assertUnprocessable()->assertJsonPath('message', "Akış henüz Kestra'ya yüklenmedi.");

        $flow = $this->uploadedFlow();
        $this->postJson("/api/flows/{$flow->id}/executions")->assertUnprocessable()->assertJsonPath('message', "'customer' girdisi zorunlu.");
        $this->postJson("/api/flows/{$flow->id}/executions", ['inputs' => ['customer' => 'a', 'evil' => 'x']])
            ->assertUnprocessable()->assertJsonPath('message', "Akışta 'evil' adında bir girdi yok.");

        $this->assertNull($this->lastRequest('POST', '/api/v1/main/executions/fortis.chat/'));
    }

    public function test_show_refreshes_steps_and_logs_from_kestra(): void
    {
        $execution = Execution::factory()->create(['kestra_execution_id' => 'exec-1', 'flow_id' => $this->uploadedFlow()->id]);
        $this->remote['state'] = ['current' => 'FAILED', 'startDate' => '2026-10-01T10:00:00Z', 'endDate' => '2026-10-01T10:00:05Z', 'duration' => 'PT5S'];
        $this->remote['taskRunList'] = [['taskId' => 'check', 'state' => ['current' => 'FAILED', 'duration' => 'PT2S'], 'attempts' => [[], []]]];
        $this->logs = [['level' => 'ERROR', 'taskId' => 'check', 'message' => 'Connection refused', 'timestamp' => '2026-10-01T10:00:04Z'], ['level' => 'DEBUG', 'message' => 'gürültü']];

        $this->getJson("/api/executions/{$execution->id}")
            ->assertOk()
            ->assertJsonPath('data.state', 'FAILED')
            ->assertJsonPath('data.finished', true)
            ->assertJsonPath('data.task_runs.0.attempts', 2)
            ->assertJsonPath('data.logs.0.message', 'Connection refused')
            ->assertJsonCount(1, 'data.logs');

        $this->assertNotNull($execution->refresh()->ended_at);
    }

    public function test_paused_execution_shows_resume_inputs_from_yaml(): void
    {
        $flow = $this->uploadedFlow();
        $execution = Execution::factory()->create(['kestra_execution_id' => 'exec-1', 'flow_id' => $flow->id, 'flow_version_id' => $flow->current_version_id]);
        $this->paused();

        $this->getJson("/api/executions/{$execution->id}")
            ->assertJsonPath('data.paused.task_id', 'wait_for_approval')
            ->assertJsonPath('data.paused.approval_input', 'approved')
            ->assertJsonPath('data.paused.inputs.1.id', 'note');
    }

    public function test_continue_and_reject_send_the_approval_input(): void
    {
        $flow = $this->uploadedFlow();
        $execution = Execution::factory()->create(['kestra_execution_id' => 'exec-1', 'flow_id' => $flow->id, 'flow_version_id' => $flow->current_version_id]);
        $this->paused();

        $this->postJson("/api/executions/{$execution->id}/resume", ['approved' => true, 'inputs' => ['note' => 'tamam']])->assertOk();
        $resume = $this->lastRequest('POST', '/api/v1/main/executions/exec-1/');
        $this->assertStringEndsWith('/resume', parse_url($resume->url(), PHP_URL_PATH));
        $this->assertEqualsCanonicalizing(['approved' => 'true', 'note' => 'tamam'], array_column($resume->data(), 'contents', 'name'));

        $this->postJson("/api/executions/{$execution->id}/resume", ['approved' => false])->assertOk();
        $this->assertSame(['approved' => 'false'], array_column($this->lastRequest('POST', '/api/v1/main/executions/exec-1/')->data(), 'contents', 'name'));
    }

    public function test_approve_and_reject_are_audited(): void
    {
        $flow = $this->uploadedFlow();
        $execution = Execution::factory()->create(['kestra_execution_id' => 'exec-1', 'flow_id' => $flow->id, 'flow_version_id' => $flow->current_version_id]);
        $this->paused();

        $this->postJson("/api/executions/{$execution->id}/resume", ['approved' => true])->assertOk();
        $this->postJson("/api/executions/{$execution->id}/resume", ['approved' => false])->assertOk();

        $this->assertSame(['execution.resume', 'execution.reject'], AuditLog::query()->orderBy('id')->pluck('action')->all());
        $this->assertSame($this->signedIn->id, AuditLog::query()->first()->user_id);
    }

    public function test_reject_kills_when_there_is_no_approval_input(): void
    {
        $definition = self::APPROVAL_FLOW;
        $definition['tasks'][0]['onResume'] = [];
        $flow = $this->uploadedFlow($definition);
        $execution = Execution::factory()->create(['kestra_execution_id' => 'exec-1', 'flow_id' => $flow->id, 'flow_version_id' => $flow->current_version_id]);
        $this->paused();

        $this->postJson("/api/executions/{$execution->id}/resume", ['approved' => false])->assertOk();

        $this->assertNotNull($this->lastRequest('DELETE', '/api/v1/main/executions/exec-1/'));
        $this->assertNull($this->lastRequest('POST', '/api/v1/main/executions/exec-1/'));
    }

    public function test_resume_is_refused_when_not_paused(): void
    {
        $execution = Execution::factory()->create(['kestra_execution_id' => 'exec-1']);

        $this->postJson("/api/executions/{$execution->id}/resume", ['approved' => true])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Çalışma şu an onay beklemiyor.');
    }

    public function test_stream_sends_state_and_done_for_finished_execution(): void
    {
        $execution = Execution::factory()->create(['kestra_execution_id' => 'exec-1']);
        $this->remote['state'] = ['current' => 'SUCCESS', 'startDate' => '2026-10-01T10:00:00Z', 'endDate' => '2026-10-01T10:00:01Z'];

        $body = $this->get("/api/executions/{$execution->id}/stream")
            ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
            ->streamedContent();

        preg_match_all('/^event: (\S+)$/m', $body, $events);
        $this->assertSame(['execution', 'done'], $events[1]);
        $this->assertStringContainsString('"state":"SUCCESS"', $body);
    }

    public function test_card_shows_webhook_url_run_inputs_and_conversation_executions(): void
    {
        $conversation = Conversation::factory()->create();
        $flow = $this->uploadedFlow(conversation: $conversation);
        $version = $flow->currentVersion;
        $message = $conversation->messages()->create([
            'role' => Message::ROLE_USER,
            'content' => [['type' => 'tool_result', 'tool_use_id' => 't', 'content' => '{}']],
            'text' => '',
            'meta' => ['flow_cards' => [['title' => 'Onay', 'flow_version_id' => $version->id]]],
        ]);
        Execution::factory()->create(['kestra_execution_id' => 'exec-1', 'flow_id' => $flow->id, 'flow_version_id' => $version->id, 'conversation_id' => $conversation->id, 'state' => 'SUCCESS']);
        Execution::factory()->create(['flow_id' => $flow->id, 'flow_version_id' => $version->id, 'conversation_id' => null]);

        $this->getJson("/api/conversations/{$conversation->id}")
            ->assertJsonPath('data.messages.0.flow_cards.0.run.can_run', true)
            ->assertJsonPath('data.messages.0.flow_cards.0.run.webhook_url', 'http://kestra.test/api/v1/main/executions/webhook/fortis.chat/discount-approval/abcdefghijklmnopqrstuvwx')
            ->assertJsonPath('data.messages.0.flow_cards.0.run.inputs.0.id', 'customer')
            ->assertJsonPath('data.messages.0.flow_cards.0.run.inputs.0.required', true)
            ->assertJsonPath('data.messages.0.flow_cards.0.run.inputs.1.required', false)
            ->assertJsonCount(1, 'data.messages.0.flow_cards.0.executions')
            ->assertJsonPath('data.messages.0.flow_cards.0.executions.0.state', 'SUCCESS');
    }

    public function test_get_execution_tool_reads_by_flow_and_hides_other_namespaces(): void
    {
        $tools = $this->app->make(FlowTools::class);
        $this->remote['state'] = ['current' => 'FAILED', 'startDate' => '2026-10-01T10:00:00Z', 'endDate' => '2026-10-01T10:00:05Z'];
        $this->logs = [
            ['level' => 'INFO', 'message' => 'başladı'],
            ['level' => 'ERROR', 'taskId' => 'check', 'message' => 'HTTP 503'],
        ];

        $result = json_decode($tools->execute('get_execution', ['flow_id' => 'discount-approval'])->content, true);

        $this->assertSame('FAILED', $result['status']);
        $this->assertArrayNotHasKey('logs', $result);
        $this->assertSame('HTTP_503', $result['error']['type']);
        $this->assertNotNull($this->lastRequest('GET', '/api/v1/main/executions/search'));

        $this->remote['namespace'] = 'company.finance';
        $hidden = $tools->execute('get_execution', ['execution_id' => 'exec-1']);
        $this->assertTrue($hidden->isError);
        $this->assertStringContainsString('bulunamadı', $hidden->content);

        $this->assertTrue($tools->execute('get_execution', [])->isError);
    }

    public function test_next_message_tells_claude_about_started_executions(): void
    {
        $conversation = Conversation::factory()->create();
        $conversation->messages()->create(['role' => Message::ROLE_USER, 'content' => [['type' => 'text', 'text' => 'hazırla']], 'text' => 'hazırla']);
        $flow = $this->uploadedFlow(conversation: $conversation);
        Execution::factory()->create(['kestra_execution_id' => 'exec-9', 'flow_id' => $flow->id, 'conversation_id' => $conversation->id, 'state' => 'FAILED', 'ended_at' => now()]);

        $this->mock(ClaudeChat::class, fn ($mock) => $mock->shouldReceive('reply')->andReturnUsing(
            fn (Conversation $c, Closure $emit) => $c->messages()->latest('id')->first(),
        ));

        $this->post("/api/conversations/{$conversation->id}/messages", ['content' => 'Neden hata verdi?'])->streamedContent();

        $last = $conversation->messages()->where('text', 'Neden hata verdi?')->firstOrFail();
        $this->assertStringContainsString('Çalışma id: exec-9. Son bilinen durum: FAILED.', $last->content[0]['text']);
    }
}
