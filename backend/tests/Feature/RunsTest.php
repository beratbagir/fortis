<?php

namespace Tests\Feature;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;
use App\Models\AiCall;
use App\Models\Execution;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Services\Claude\CostCalculator;
use App\Services\Claude\ExecutionExplainer;
use App\Services\Settings\AppSettings;
use App\Services\Usage\Budget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class RunsTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private const FLOW = <<<'YAML'
    id: aircraft-swap-alert
    namespace: fortis.chat
    tasks:
      - id: changes
        type: io.kestra.plugin.core.http.Request
        uri: https://example.com/changes
      - id: wait_for_approval
        type: io.kestra.plugin.core.flow.Pause
        pauseDuration: PT2H
        behavior: CANCEL
        onResume:
          - id: approved
            type: BOOL
            description: Yer hizmetlerine bildirim gönderilsin mi?
            defaults: false
      - id: notify
        type: io.kestra.plugin.core.log.Log
        message: Gönderildi
    YAML;

    private string $state = 'PAUSED';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFlowEnvironment();

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/executions/search')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $state = $query['filters']['state']['EQUALS'] ?? null;

                return Http::response([
                    'total' => $state === 'FAILED' ? 1 : ($state === 'PAUSED' ? 2 : 3),
                    'results' => [[
                        'id' => 'kx1', 'namespace' => 'fortis.chat', 'flowId' => 'aircraft-swap-alert',
                        'state' => ['current' => 'PAUSED', 'startDate' => '2026-10-06T07:42:00Z', 'duration' => 'PT3M'],
                        'trigger' => ['type' => 'io.kestra.plugin.core.trigger.Schedule'],
                    ]],
                ]);
            }

            if (str_contains($path, '/logs/')) {
                return Http::response([
                    ['level' => 'INFO', 'taskId' => 'changes', 'message' => 'TC-XXA → TC-XXC, yolcu: Ayşe Yılmaz', 'timestamp' => '2026-10-06T07:42:01Z'],
                    ['level' => 'ERROR', 'taskId' => 'changes', 'message' => 'Connection refused: example.com:443', 'timestamp' => '2026-10-06T07:42:02Z'],
                ]);
            }

            if (str_ends_with($path, '/executions/kx1')) {
                return Http::response([
                    'id' => 'kx1', 'namespace' => 'fortis.chat', 'flowId' => 'aircraft-swap-alert', 'flowRevision' => 3,
                    'state' => ['current' => $this->state, 'startDate' => '2026-10-06T07:42:00Z'],
                    'trigger' => ['type' => 'io.kestra.plugin.core.trigger.Schedule'],
                    'taskRunList' => [
                        ['taskId' => 'changes', 'state' => ['current' => $this->state === 'FAILED' ? 'FAILED' : 'SUCCESS', 'startDate' => '2026-10-06T07:42:01Z', 'duration' => 'PT1S'],
                            'outputs' => ['code' => 200, 'body' => '{"crew":"Ayşe Yılmaz"}']],
                        ['taskId' => 'wait_for_approval', 'state' => ['current' => 'PAUSED', 'startDate' => '2026-10-06T07:42:03Z']],
                    ],
                ]);
            }

            return Http::response(null, 204);
        });

        $flow = Flow::factory()->create(['flow_id' => 'aircraft-swap-alert', 'title' => 'Uçak değişikliği uyarısı', 'status' => Flow::STATUS_ACTIVE]);
        $version = FlowVersion::factory()->for($flow)->create(['number' => 1, 'yaml' => self::FLOW, 'yaml_hash' => hash('sha256', self::FLOW), 'kestra_revision' => 3, 'uploaded_at' => now()]);
        $flow->update(['current_version_id' => $version->id]);
    }

    public function test_list_is_indexed_locally_from_kestra_with_flow_titles_and_filter_counts(): void
    {
        $this->getJson('/api/executions?status=paused')
            ->assertOk()
            ->assertJsonPath('data.0.kestra_execution_id', 'kx1')
            ->assertJsonPath('data.0.flow.title', 'Uçak değişikliği uyarısı')
            ->assertJsonPath('data.0.trigger', 'Schedule')
            ->assertJsonPath('data.0.state', 'PAUSED')
            ->assertJsonPath('counts', ['paused' => 1, 'failed' => 0]);

        $this->assertDatabaseHas(Execution::class, ['kestra_execution_id' => 'kx1', 'state' => 'PAUSED']);
        // Liste çıktı taşımaz; dizine sadece özet yazılır.
        $this->assertStringNotContainsString('Ayşe', json_encode(Execution::firstWhere('kestra_execution_id', 'kx1')->snapshot));

        // İkinci istekte (kısıtlama süresi içinde) Kestra'ya yeniden gidilmez; liste yerelden gelir.
        $searches = fn () => collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/executions/search'))->count();
        $before = $searches();
        $this->getJson('/api/executions')->assertOk()->assertJsonPath('total', 1);
        $this->assertSame($before, $searches());

        // Kısıtlama bitince sadece son kayıttan sonra başlayan çalışmalar istenir.
        $this->travel(11)->seconds();
        $this->getJson('/api/executions')->assertOk();
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'filters[startDate][GREATER_THAN_OR_EQUAL_TO]=2026-10-06T07:32:00.000Z'));
    }

    public function test_scheduled_execution_is_imported_with_timeline_pause_card_and_output_summaries_only(): void
    {
        $response = $this->getJson('/api/executions/kx1')
            ->assertOk()
            ->assertJsonPath('data.state', 'PAUSED')
            ->assertJsonPath('data.paused.question', 'Yer hizmetlerine bildirim gönderilsin mi?')
            ->assertJsonPath('data.paused.timeout', 'PT2H')
            ->assertJsonPath('data.paused.on_timeout', 'CANCEL')
            ->assertJsonPath('data.steps.0.task_id', 'changes')
            ->assertJsonPath('data.steps.0.output_summary', 'HTTP 200')
            ->assertJsonPath('data.steps.1.state', 'PAUSED')
            ->assertJsonPath('data.steps.2.task_id', 'notify')
            ->assertJsonPath('data.steps.2.state', null);

        $this->assertStringNotContainsString('Ayşe Yılmaz', json_encode($response->json('data.steps')));
        $this->assertStringNotContainsString('crew', json_encode(Execution::firstWhere('kestra_execution_id', 'kx1')->snapshot));
        $this->assertSame(1, Execution::count());
    }

    public function test_paused_step_can_be_resumed_and_rejected_by_kestra_id(): void
    {
        $this->postJson('/api/executions/kx1/resume', ['approved' => true])->assertOk();
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), 'kx1/resume') && str_contains($r->body(), 'true'));

        $this->postJson('/api/executions/kx1/kill')->assertOk();
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'kx1/kill'));
    }

    public function test_logs_are_paginated(): void
    {
        $this->getJson('/api/executions/kx1/logs')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('per_page', 50);
    }

    public function test_explain_sends_only_the_task_and_cleaned_error_type(): void
    {
        $this->state = 'FAILED';
        $sent = null;

        $this->app->bind(ExecutionExplainer::class, function ($app) use (&$sent) {
            return new ExecutionExplainer(
                $app->make(Client::class),
                $app->make(CostCalculator::class),
                $app->make(AppSettings::class),
                $app->make(Budget::class),
                function (array $request) use (&$sent) {
                    $sent = $request;

                    return BetaMessage::with(
                        id: 'msg_1', container: null, content: [['type' => 'text', 'text' => 'Sunucuya bağlanılamadı.']],
                        contextManagement: null, diagnostics: null, model: 'claude-sonnet-5-5', stopDetails: null, stopReason: 'end_turn',
                        stopSequence: null, usage: ['inputTokens' => 100, 'outputTokens' => 20],
                    );
                },
            );
        });

        $this->postJson('/api/executions/kx1/explain')
            ->assertOk()
            ->assertJsonPath('data.explanation', 'Sunucuya bağlanılamadı.')
            ->assertJsonPath('data.sent.0.task', 'changes')
            ->assertJsonPath('data.sent.0.type', 'CONNECTION');

        $body = json_encode($sent, JSON_UNESCAPED_UNICODE);
        // Ham hata metni değil, temizlenmiş hata türü gider.
        $this->assertStringNotContainsString('example.com', $body);
        $this->assertStringContainsString('CONNECTION', $body);
        $this->assertStringContainsString('changes', $body);
        $this->assertStringNotContainsString('Ayşe Yılmaz', $body, 'Log satırları ve çıktılar gönderilmemeli');
        $this->assertStringNotContainsString('TC-XXA', $body);
        $this->assertStringNotContainsString('crew', $body);

        $this->assertDatabaseHas(AiCall::class, ['purpose' => 'explain_error', 'source' => 'chat', 'execution_id' => Execution::first()->id]);
    }
}
