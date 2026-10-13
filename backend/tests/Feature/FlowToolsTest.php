<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\FlowVersion;
use App\Models\User;
use App\Services\Claude\FlowTools;
use App\Services\Claude\SystemPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class FlowToolsTest extends TestCase
{
    use RefreshDatabase;

    private const FLOW = <<<'YAML'
    id: site-check
    namespace: fortis.chat
    tasks:
      - id: check
        type: io.kestra.plugin.core.http.Request
        uri: https://example.com/health
        method: GET
      - id: mail
        type: io.kestra.plugin.email.MailSend
        host: "{{ secret('SMTP_HOST') }}"
        username: "{{ secret('SMTP_USERNAME') }}"
        password: "{{ secret('SMTP_PASSWORD') }}"
        to: ops@example.com
    triggers:
      - id: morning
        type: io.kestra.plugin.core.trigger.Schedule
        cron: "0 8 * * *"
        timezone: Europe/Istanbul
    YAML;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFlowEnvironment();
        Http::fake(['kestra.test/api/v1/main/flows/validate' => Http::response([['constraints' => null]])]);
    }

    private function tools(): FlowTools
    {
        return $this->app->make(FlowTools::class);
    }

    public function test_definitions_cover_planned_tools_and_no_write_tools(): void
    {
        $names = array_column($this->tools()->definitions(), 'name');

        $this->assertSame(['get_catalog', 'validate_flow', 'propose_flow', 'list_flows', 'get_execution', 'get_queries', 'describe_warehouse', 'describe_successfactors', 'preview_successfactors', 'describe_apm', 'preview_apm', 'record_unsupported_request', 'list_report_sources', 'create_report', 'create_artifact', 'get_flow', 'ask_user'], $names);
    }

    public function test_get_catalog_filters_types_and_lists_secrets(): void
    {
        $this->useFlowEnvironment(secrets: ['SMTP_PASSWORD']);

        $result = json_decode($this->tools()->execute('get_catalog', ['types' => ['io.kestra.plugin.core.log.Log']])->content, true);

        $this->assertSame(['io.kestra.plugin.core.log.Log'], array_column($result['entries'], 'type'));
        $this->assertSame(['SMTP_PASSWORD'], $result['available_secrets']);
    }

    public function test_propose_flow_builds_card_from_yaml(): void
    {
        $result = $this->tools()->execute('propose_flow', [
            'title' => 'Site kontrolü',
            'summary' => ['Her gün 08:00\'de çalışır'],
            'yaml' => self::FLOW,
        ]);

        $this->assertFalse($result->isError);
        $this->assertSame('site-check', $result->card['flow_id']);
        $this->assertSame(['Zamanlanmış: 0 8 * * * (Europe/Istanbul)'], $result->card['triggers']);
        $this->assertSame([
            ['task_id' => 'check', 'label' => 'Dış adrese istek atıyor', 'details' => 'GET https://example.com/health'],
            ['task_id' => 'mail', 'label' => 'E-posta gönderiyor', 'details' => 'ops@example.com'],
        ], $result->card['warned_steps']);
        $this->assertSame(['SMTP_HOST', 'SMTP_USERNAME', 'SMTP_PASSWORD'], $result->card['missing_secrets']);
        $this->assertDatabaseHas('flow_versions', ['id' => $result->card['flow_version_id'], 'number' => 1]);
    }

    public function test_bana_placeholder_becomes_the_owner_address_and_is_hidden_from_claude_again(): void
    {
        $owner = User::factory()->create(['email' => 'ayse@freebird.com.tr']);
        $tools = $this->tools();
        $tools->startTurn(Conversation::factory()->create(['user_id' => $owner->id]));

        $yaml = str_replace('to: ops@example.com', 'to: '.FlowTools::OWN_EMAIL_TOKEN."\n    subject: \"".FlowTools::OWN_EMAIL_TOKEN." için özet\"\n    htmlTextContent: \"<p>Alıcı: ".FlowTools::OWN_EMAIL_TOKEN.'</p>"', self::FLOW);
        $card = $tools->execute('propose_flow', ['title' => 'Site kontrolü', 'summary' => ['x'], 'yaml' => $yaml])->card;
        $this->assertSame('ayse@freebird.com.tr', $card['warned_steps'][1]['details']);

        // Sadece alıcı alanı değişir; konu ve gövdedeki yer tutucu olduğu gibi kalır.
        $stored = Yaml::parse(FlowVersion::query()->findOrFail($card['flow_version_id'])->yaml)['tasks'][1];
        $this->assertSame('ayse@freebird.com.tr', $stored['to']);
        $this->assertSame(FlowTools::OWN_EMAIL_TOKEN.' için özet', $stored['subject']);
        $this->assertSame('<p>Alıcı: '.FlowTools::OWN_EMAIL_TOKEN.'</p>', $stored['htmlTextContent']);

        $flow = json_decode($tools->execute('get_flow', ['flow_id' => 'site-check'])->content, true);
        $this->assertStringContainsString('to: '.FlowTools::OWN_EMAIL_TOKEN, $flow['yaml']);
        $this->assertStringNotContainsString('ayse@freebird.com.tr', $flow['yaml']);
    }

    public function test_placeholder_without_a_conversation_owner_is_left_alone(): void
    {
        $tools = $this->tools();
        $tools->startTurn(Conversation::factory()->create(['user_id' => null]));

        $card = $tools->execute('propose_flow', ['title' => 'Site kontrolü', 'summary' => ['x'], 'yaml' => str_replace('ops@example.com', FlowTools::OWN_EMAIL_TOKEN, self::FLOW)])->card;
        $this->assertSame(FlowTools::OWN_EMAIL_TOKEN, $card['warned_steps'][1]['details']);
    }

    public function test_same_yaml_reuses_version_and_changed_yaml_creates_new_one(): void
    {
        $input = ['title' => 'Site kontrolü', 'summary' => ['x'], 'yaml' => self::FLOW];

        $first = $this->tools()->execute('propose_flow', $input)->card;
        $again = $this->tools()->execute('propose_flow', $input)->card;
        $changed = $this->tools()->execute('propose_flow', ['yaml' => str_replace('0 8 * * *', '0 9 * * *', self::FLOW)] + $input)->card;

        $this->assertSame($first['flow_version_id'], $again['flow_version_id']);
        $this->assertNotSame($first['flow_version_id'], $changed['flow_version_id']);
        $this->assertDatabaseCount('flows', 1);
        $this->assertDatabaseCount('flow_versions', 2);
    }

    public function test_validation_attempts_are_limited_per_turn(): void
    {
        $bad = ['yaml' => "id: bad\nnamespace: fortis.chat\ntasks:\n  - id: s\n    type: io.kestra.plugin.scripts.python.Script\n"];
        $tools = $this->tools();
        $tools->startTurn(Conversation::factory()->create());

        foreach (range(1, FlowTools::MAX_FAILED_VALIDATIONS) as $attempt) {
            $this->assertArrayHasKey('errors', json_decode($tools->execute('validate_flow', $bad)->content, true));
        }

        $limited = json_decode($tools->execute('validate_flow', ['yaml' => self::FLOW])->content, true);
        $this->assertStringContainsString('deneme hakkı doldu', $limited['error']);

        $tools->startTurn(Conversation::factory()->create());
        $this->assertTrue(json_decode($tools->execute('validate_flow', ['yaml' => self::FLOW])->content, true)['valid']);
    }

    public function test_propose_flow_refuses_invalid_flow(): void
    {
        $result = $this->tools()->execute('propose_flow', [
            'title' => 'Kötü',
            'summary' => ['x'],
            'yaml' => "id: bad\nnamespace: fortis.chat\ntasks:\n  - id: s\n    type: io.kestra.plugin.scripts.python.Script\n",
        ]);

        $this->assertTrue($result->isError);
        $this->assertNull($result->card);
        $this->assertFalse(json_decode($result->content, true)['shown']);
    }

    public function test_incomplete_tool_input_is_not_executed(): void
    {
        $result = $this->tools()->execute('propose_flow', ['title' => 'Yarım']);

        $this->assertTrue($result->isError);
        $this->assertArrayHasKey('INVALID_INPUT', json_decode($result->content, true));
        Http::assertNothingSent();
    }

    public function test_list_flows_only_returns_chat_namespace(): void
    {
        Http::fake(['kestra.test/api/v1/main/flows/search*' => Http::response(['total' => 2, 'results' => [
            ['id' => 'a', 'namespace' => 'fortis.chat', 'revision' => 1],
            ['id' => 'b', 'namespace' => 'fortis.chat.other', 'revision' => 3],
        ]])]);

        $result = json_decode($this->tools()->execute('list_flows', [])->content, true);

        $this->assertSame(['a'], array_column($result['flows'], 'id'));
    }

    public function test_system_prompt_has_no_unfilled_placeholders(): void
    {
        $prompt = $this->app->make(SystemPrompt::class)->render();

        $this->assertStringNotContainsString('{{CATALOG}}', $prompt);
        $this->assertStringNotContainsString('{{EXAMPLES}}', $prompt);
        $this->assertStringContainsString('io.kestra.plugin.core.flow.Pause', $prompt);
        $this->assertStringContainsString('id: discount-approval', $prompt);
    }
}
