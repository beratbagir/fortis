<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Execution;
use App\Models\FeatureRequest;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Services\Claude\ClaudeChat;
use App\Services\Claude\FlowTools;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class FlowManagementTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    /** Kestra'daki akışlar: id => disabled */
    private array $remoteFlows = [];

    /** @var list<string> */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFlowEnvironment();

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() !== 'GET') {
                $this->writes[] = "{$request->method()} {$path} ".$request->body();
            }

            return match (true) {
                $path === '/api/v1/main/flows/search' => Http::response(['total' => count($this->remoteFlows), 'results' => array_map(
                    fn (string $id, bool $disabled) => ['id' => $id, 'namespace' => 'fortis.chat', 'revision' => 3, 'disabled' => $disabled],
                    array_keys($this->remoteFlows), $this->remoteFlows,
                )]),
                $path === '/api/v1/main/executions/latest' => Http::response([
                    ['id' => 'e2', 'flowId' => 'site-check', 'namespace' => 'fortis.chat', 'startDate' => '2026-10-01T09:00:00Z', 'status' => 'FAILED'],
                ]),
                $path === '/api/v1/main/executions/search' => Http::response(['total' => 2, 'results' => [
                    ['id' => 'e2', 'flowId' => 'site-check', 'state' => ['current' => 'FAILED', 'startDate' => '2026-10-01T09:00:00Z'], 'trigger' => ['type' => 'io.kestra.plugin.core.trigger.Schedule']],
                    ['id' => 'e1', 'flowId' => 'site-check', 'state' => ['current' => 'SUCCESS', 'startDate' => '2026-10-01T08:00:00Z']],
                ]]),
                $request->method() === 'GET' && str_starts_with($path, '/api/v1/main/flows/fortis.chat/') => isset($this->remoteFlows[basename($path)])
                    ? Http::response(['id' => basename($path), 'revision' => 3])
                    : Http::response(null, 404),
                default => Http::response(['revision' => 4]),
            };
        });
    }

    private function flow(string $flowId, string $status = Flow::STATUS_ACTIVE): Flow
    {
        $version = FlowVersion::factory()->create([
            'uploaded_at' => $status === Flow::STATUS_DRAFT ? null : now(),
            'kestra_revision' => $status === Flow::STATUS_DRAFT ? null : 3,
            'card' => ['triggers' => ['Zamanlanmış: 0 8 * * * (Europe/Istanbul)']],
            'flow_id' => Flow::factory()->create(['flow_id' => $flowId, 'status' => $status, 'title' => "Akış {$flowId}"]),
        ]);
        $version->flow->update(['current_version_id' => $version->id]);

        return $version->flow->refresh();
    }

    public function test_list_merges_kestra_state_last_execution_and_drift(): void
    {
        $this->flow('site-check');
        $this->flow('gone');
        $this->flow('paused-elsewhere');
        $this->flow('draft-taken', Flow::STATUS_DRAFT);
        $this->remoteFlows = ['site-check' => false, 'paused-elsewhere' => true, 'draft-taken' => false, 'made-in-ui' => false];

        $response = $this->getJson('/api/flows')->assertOk()->assertJsonPath('kestra_error', null);
        $flows = collect($response->json('data'))->keyBy('flow_id');

        $this->assertNull($flows['site-check']['drift']);
        $this->assertSame(['revision' => 3, 'disabled' => false], $flows['site-check']['kestra']);
        $this->assertSame('FAILED', $flows['site-check']['last_execution']['state']);
        $this->assertSame('2026-10-01T09:00:00Z', $flows['site-check']['last_execution']['start']);
        // Son çalışmalar hafif "latest" ucundan; ağır çalışma araması kullanılmaz.
        Http::assertNotSent(fn ($request) => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/executions/search'));
        $this->assertSame(['Zamanlanmış: 0 8 * * * (Europe/Istanbul)'], $flows['site-check']['triggers']);
        $this->assertStringContainsString('bulunamadı', $flows['gone']['drift']);
        $this->assertStringContainsString('pasif görünüyor', $flows['paused-elsewhere']['drift']);
        $this->assertStringContainsString('bu id ile bir akış var', $flows['draft-taken']['drift']);
        $this->assertSame([['flow_id' => 'made-in-ui', 'revision' => 3, 'disabled' => false]], $response->json('unmanaged'));
    }

    public function test_list_counts_last_30_days_runs_without_test_runs(): void
    {
        $flow = $this->flow('site-check');
        $this->remoteFlows = ['site-check' => false];
        $run = fn (string $state, int $daysAgo, bool $test = false) => Execution::factory()->for($flow)->create(['state' => $state, 'started_at' => now()->subDays($daysAgo), 'is_test' => $test]);
        $run('SUCCESS', 1);
        $run('SUCCESS', 3);
        $run('FAILED', 5);
        $run('RUNNING', 0);
        $run('SUCCESS', 40);
        $run('FAILED', 2, test: true);

        $flows = collect($this->getJson('/api/flows')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(['total' => 4, 'success' => 2, 'failed' => 1], $flows[$flow->id]['runs_30d']);
    }

    public function test_list_still_works_when_kestra_is_down(): void
    {
        $this->flow('site-check');
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('', 503)]);

        $this->getJson('/api/flows')
            ->assertOk()
            ->assertJsonPath('data.0.flow_id', 'site-check')
            ->assertJsonPath('data.0.drift', null)
            ->assertJsonPath('kestra_error', fn ($error) => str_contains($error, '503'));
    }

    public function test_disable_and_enable_call_kestra_and_update_status(): void
    {
        $flow = $this->flow('site-check');
        $this->remoteFlows = ['site-check' => false];

        $this->postJson("/api/flows/{$flow->id}/disable")->assertOk()->assertJsonPath('data.status', Flow::STATUS_INACTIVE);
        $this->postJson("/api/flows/{$flow->id}/enable")->assertOk()->assertJsonPath('data.status', Flow::STATUS_ACTIVE);

        $this->assertSame([
            'POST /api/v1/main/flows/disable/by-ids [{"namespace":"fortis.chat","id":"site-check"}]',
            'POST /api/v1/main/flows/enable/by-ids [{"namespace":"fortis.chat","id":"site-check"}]',
        ], $this->writes);
    }

    public function test_flow_missing_in_kestra_cannot_be_toggled(): void
    {
        $flow = $this->flow('gone');

        $this->postJson("/api/flows/{$flow->id}/disable")
            ->assertUnprocessable()
            ->assertJsonPath('message', "Akış Kestra'da bulunamadı. Yeniden yükleyin veya silin.");

        $this->assertSame(Flow::STATUS_ACTIVE, $flow->refresh()->status);
        $this->assertSame([], $this->writes);
    }

    public function test_draft_cannot_be_toggled(): void
    {
        $flow = $this->flow('draft', Flow::STATUS_DRAFT);

        $this->postJson("/api/flows/{$flow->id}/disable")->assertUnprocessable();
        $this->assertSame([], $this->writes);
    }

    public function test_delete_removes_from_kestra_and_soft_deletes_keeping_old_cards(): void
    {
        $flow = $this->flow('site-check');
        $this->remoteFlows = ['site-check' => false];
        $conversation = Conversation::factory()->create();
        $conversation->messages()->create([
            'role' => Message::ROLE_USER, 'content' => [['type' => 'text', 'text' => '-']], 'text' => '',
            'meta' => ['flow_cards' => [['title' => 'x', 'flow_version_id' => $flow->current_version_id]]],
        ]);

        $this->deleteJson("/api/flows/{$flow->id}")->assertNoContent();

        $this->assertSame(['DELETE /api/v1/main/flows/fortis.chat/site-check '], $this->writes);
        $this->assertSoftDeleted($flow);
        $this->getJson('/api/flows')->assertJsonCount(0, 'data');

        $this->getJson("/api/conversations/{$conversation->id}")
            ->assertJsonPath('data.messages.0.flow_cards.0.status.can_upload', false)
            ->assertJsonPath('data.messages.0.flow_cards.0.status.reason', 'Bu akış silindi.')
            ->assertJsonPath('data.messages.0.flow_cards.0.run.can_run', false);
    }

    public function test_delete_of_flow_missing_in_kestra_still_soft_deletes(): void
    {
        $flow = $this->flow('gone');

        $this->deleteJson("/api/flows/{$flow->id}")->assertNoContent();

        $this->assertSame([], $this->writes);
        $this->assertSoftDeleted($flow);
    }

    public function test_proposing_a_deleted_flow_id_restores_it_as_draft(): void
    {
        $flow = $this->flow('site-check');
        $flow->delete();

        $tools = $this->app->make(FlowTools::class);
        $tools->startTurn(Conversation::factory()->create());
        $result = $tools->execute('propose_flow', [
            'title' => 'Yeniden', 'summary' => ['x'],
            'yaml' => "id: site-check\nnamespace: fortis.chat\ntasks:\n  - id: hello\n    type: io.kestra.plugin.core.log.Log\n    message: tekrar\n",
        ]);

        $this->assertFalse($result->isError, $result->content);
        $flow->refresh();
        $this->assertFalse($flow->trashed());
        $this->assertSame(Flow::STATUS_DRAFT, $flow->status);
        $this->assertSame(2, $flow->versions()->count());
    }

    public function test_new_version_of_inactive_flow_stays_inactive(): void
    {
        $flow = $this->flow('site-check', Flow::STATUS_INACTIVE);
        $this->remoteFlows = ['site-check' => true];

        $tools = $this->app->make(FlowTools::class);
        $tools->startTurn(Conversation::factory()->create());
        $card = $tools->execute('propose_flow', [
            'title' => 'Yeni', 'summary' => ['x'],
            'yaml' => "id: site-check\nnamespace: fortis.chat\ntasks:\n  - id: hello\n    type: io.kestra.plugin.core.log.Log\n    message: yeni\n",
        ])->card;

        $this->postJson("/api/flow-versions/{$card['flow_version_id']}/upload")->assertOk();

        $this->assertSame(Flow::STATUS_INACTIVE, $flow->refresh()->status);
        $this->assertContains('POST /api/v1/main/flows/disable/by-ids [{"namespace":"fortis.chat","id":"site-check"}]', $this->writes);
    }

    public function test_edit_in_chat_sends_current_yaml_with_first_message_only(): void
    {
        $flow = $this->flow('site-check');

        $conversationId = $this->postJson("/api/flows/{$flow->id}/edit-in-chat")
            ->assertCreated()
            ->assertJsonPath('data.context_flow.flow_id', 'site-check')
            ->json('data.id');

        $this->mock(ClaudeChat::class, fn ($mock) => $mock->shouldReceive('reply')->andReturnUsing(
            fn (Conversation $c, Closure $emit) => $c->messages()->latest('id')->first(),
        ));

        $this->post("/api/conversations/{$conversationId}/messages", ['content' => 'Saati 09:00 yap'])->streamedContent();
        $this->post("/api/conversations/{$conversationId}/messages", ['content' => 'Teşekkürler'])->streamedContent();

        $messages = Conversation::find($conversationId)->messages;
        $this->assertStringContainsString('mevcut "Akış site-check" akışını (fortis.chat.site-check) değiştirmek istiyor', $messages[0]->content[0]['text']);
        $this->assertStringContainsString('```yaml', $messages[0]->content[0]['text']);
        $this->assertSame('Saati 09:00 yap', $messages[0]->content[1]['text']);
        $this->assertCount(1, $messages[1]->content);
    }

    public function test_unsupported_requests_are_recorded_and_ranked(): void
    {
        $tools = $this->app->make(FlowTools::class);
        $tools->startTurn($conversation = Conversation::factory()->create());

        $tools->execute('record_unsupported_request', ['request' => 'Python ile rapor', 'missing_capability' => 'Python script', 'reason' => 'yasak']);
        $tools->execute('record_unsupported_request', ['request' => 'Script çalıştır', 'missing_capability' => 'python script']);
        $tools->execute('record_unsupported_request', ['request' => 'Sheets oku', 'missing_capability' => 'Google Sheets']);
        $this->assertTrue($tools->execute('record_unsupported_request', ['request' => 'x'])->isError);

        $this->assertDatabaseCount('feature_requests', 3);
        $this->assertDatabaseHas('feature_requests', ['conversation_id' => $conversation->id, 'missing_capability' => 'Google Sheets']);

        $this->getJson('/api/feature-requests')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('top_capabilities.0', ['missing_capability' => 'Python script', 'count' => 2]);

        $id = FeatureRequest::where('missing_capability', 'Google Sheets')->value('id');
        $this->patchJson("/api/feature-requests/{$id}", ['status' => 'planned'])->assertOk()->assertJsonPath('data.status', 'planned');
        $this->patchJson("/api/feature-requests/{$id}", ['status' => 'whatever'])->assertUnprocessable();
    }
}
