<?php

namespace Tests\Feature;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;
use App\Models\Conversation;
use App\Models\Execution;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Services\Claude\ConversationHistory;
use App\Services\Claude\CostCalculator;
use App\Services\Claude\ExecutionExplainer;
use App\Services\Claude\ExecutionSummaryForAi;
use App\Services\Claude\FlowTools;
use App\Services\Settings\AppSettings;
use App\Services\Usage\Budget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Akış sonuçlarındaki satır verisi Claude'a gitmez (rapor dokümanı, bölüm 5; test 1 ve 11).
 */
class ExecutionSummaryForAiTest extends TestCase
{
    use RefreshDatabase;

    /** Fixture'daki satır düzeyi bilgiler: hiçbiri Claude'a giden içerikte olmamalı. */
    private const PRIVATE = ['FH1256', 'FH1193', 'Ayşe Yılmaz', 'Mehmet Demir', 'ayse.yilmaz@example.com', 'mehmet@example.com', 'TC-XXA'];

    private array $remote;

    private array $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFlowEnvironment();

        $rows = [
            ['flight' => 'FH1256', 'dep' => 'AYT', 'crew' => 'Ayşe Yılmaz', 'email' => 'ayse.yilmaz@example.com'],
            ['flight' => 'FH1193', 'dep' => 'IST', 'crew' => 'Mehmet Demir', 'email' => 'mehmet@example.com'],
        ];

        $this->remote = [
            'id' => 'exec-1', 'namespace' => 'fortis.chat', 'flowId' => 'cancelled-flights', 'flowRevision' => 1,
            'state' => ['current' => 'SUCCESS', 'startDate' => '2026-10-06T07:00:00Z', 'endDate' => '2026-10-06T07:00:04Z', 'duration' => 'PT4.2S'],
            'inputs' => ['contact' => 'ayse.yilmaz@example.com'],
            'taskRunList' => [
                ['taskId' => 'legs', 'state' => ['current' => 'SUCCESS'], 'outputs' => ['code' => 200, 'body' => json_encode($rows)]],
                ...array_map(fn (array $row) => ['taskId' => 'log_leg', 'state' => ['current' => 'SUCCESS'], 'value' => json_encode($row)], $rows),
            ],
        ];

        $this->logs = [
            ['level' => 'INFO', 'taskId' => 'log_leg', 'message' => 'FH1256 AYT Ayşe Yılmaz ayse.yilmaz@example.com'],
            ['level' => 'INFO', 'taskId' => 'log_leg', 'message' => 'FH1193 IST Mehmet Demir mehmet@example.com'],
        ];

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_contains($path, '/executions/search') => Http::response(['total' => 1, 'results' => [['id' => 'exec-1']]]),
                str_contains($path, '/logs/') => Http::response($this->logs),
                str_contains($path, '/executions/') => Http::response($this->remote),
                default => Http::response(),
            };
        });
    }

    private function assertNoPrivateData(string $content): void
    {
        foreach (self::PRIVATE as $value) {
            $this->assertStringNotContainsString($value, $content, "'{$value}' Claude'a giden içerikte olmamalı");
        }
    }

    /**
     * Claude'a giden istek gövdesindeki messages: araç sonucu sohbete kaydedilir, sonraki API isteği
     * ConversationHistory ile bu kayıttan kurulur.
     */
    private function requestMessagesAfterGetExecution(): string
    {
        $conversation = Conversation::factory()->create();
        $conversation->messages()->create(['role' => Message::ROLE_USER, 'content' => [['type' => 'text', 'text' => 'Dünkü iptaller ne oldu?']], 'text' => 'Dünkü iptaller ne oldu?']);
        $conversation->messages()->create(['role' => Message::ROLE_ASSISTANT, 'content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'get_execution', 'input' => ['execution_id' => 'exec-1']]], 'text' => '']);

        $tools = $this->app->make(FlowTools::class);
        $tools->startTurn($conversation);
        $result = $tools->execute('get_execution', ['execution_id' => 'exec-1']);

        $conversation->messages()->create(['role' => Message::ROLE_USER, 'content' => [['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => $result->content]], 'text' => '']);

        return json_encode($this->app->make(ConversationHistory::class)->forConversation($conversation->refresh()), JSON_UNESCAPED_UNICODE);
    }

    public function test_row_data_never_reaches_claude_only_counts(): void
    {
        $body = $this->requestMessagesAfterGetExecution();

        $this->assertNoPrivateData($body);
        $this->assertStringContainsString('\"row_count\":2', $body);
        $this->assertStringContainsString('\"status\":\"SUCCESS\"', $body);
        $this->assertStringContainsString('\"duration_seconds\":4', $body);
        $this->assertStringContainsString('aggregates', $body);
        $this->assertStringNotContainsString('inputs', $body);
        $this->assertStringNotContainsString('logs', $body);
    }

    public function test_kestra_error_text_is_cleaned_before_reaching_claude(): void
    {
        $this->remote['state'] = ['current' => 'FAILED', 'duration' => 'PT2S'];
        $this->remote['taskRunList'] = [['taskId' => 'legs', 'state' => ['current' => 'FAILED']]];
        $this->logs = [['level' => 'ERROR', 'taskId' => 'legs', 'message' => "Failed http request with response code '400' and body: {\"error\":\"FH1256 için Ayşe Yılmaz bulunamadı\",\"validationErrors\":[{\"field\":\"size\",\"message\":\"Unknown parameter FH1193\"}]}"]];

        $body = $this->requestMessagesAfterGetExecution();

        $this->assertNoPrivateData($body);
        $this->assertStringContainsString('HTTP_400', $body);
        $this->assertStringContainsString('size', $body);
        $this->assertStringContainsString('\"task\":\"legs\"', $body);

        // "AI'a hatayı açıklat" da aynı temizlenmiş hatayı gönderir.
        $flow = Flow::factory()->create(['flow_id' => 'cancelled-flights']);
        $version = FlowVersion::factory()->for($flow)->create();
        $execution = Execution::create(['flow_id' => $flow->id, 'flow_version_id' => $version->id, 'kestra_execution_id' => 'exec-1', 'state' => 'FAILED', 'snapshot' => [
            'task_runs' => [['task_id' => 'legs', 'state' => 'FAILED']],
            'logs' => [['level' => 'ERROR', 'task_id' => 'legs', 'message' => $this->logs[0]['message'], 'timestamp' => null]],
        ]]);

        $sent = null;
        $explainer = new ExecutionExplainer(
            $this->app->make(Client::class), $this->app->make(CostCalculator::class), $this->app->make(AppSettings::class), $this->app->make(Budget::class),
            function (array $request) use (&$sent) {
                $sent = $request;

                return BetaMessage::with(id: 'msg_1', container: null, content: [['type' => 'text', 'text' => 'Parametre hatası.']], contextManagement: null, diagnostics: null,
                    model: 'claude-sonnet-5-5', stopDetails: null, stopReason: 'end_turn', stopSequence: null, usage: ['inputTokens' => 10, 'outputTokens' => 5]);
            },
        );
        $explainer->explain($execution);

        $request = json_encode($sent, JSON_UNESCAPED_UNICODE);
        $this->assertNoPrivateData($request);
        $this->assertStringContainsString('HTTP_400', $request);
    }

    public function test_error_classification(): void
    {
        $this->assertSame('CONNECTION', ExecutionSummaryForAi::classify('java.net.ConnectException: Connection refused')['type']);
        $this->assertSame('QUERY', ExecutionSummaryForAi::classify("SQLSTATE[42S22]: Column not found: 'Ayşe'")['type']);
        $this->assertStringNotContainsString('Ayşe', ExecutionSummaryForAi::classify("SQLSTATE[42S22]: Column not found: 'Ayşe'")['message']);
        $this->assertSame('EXPRESSION', ExecutionSummaryForAi::classify('Unable to find `outputs.legs.body` used in the expression')['type']);
        $this->assertStringContainsString('outputs.legs.body', ExecutionSummaryForAi::classify('Unable to find `outputs.legs.body` used in the expression')['message']);
        // Değer içeren değişken adı alıntılanmaz
        $this->assertStringNotContainsString('FH1256', ExecutionSummaryForAi::classify('Unable to find `outputs.x["FH1256"]` used')['message']);
        $this->assertSame('UNKNOWN', ExecutionSummaryForAi::classify('Ayşe Yılmaz için kayıt yok')['type']);
        $this->assertStringNotContainsString('Ayşe', ExecutionSummaryForAi::classify('Ayşe Yılmaz için kayıt yok')['message']);
    }
}
