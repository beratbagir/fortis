<?php

namespace Tests\Feature;

use App\Services\Apm\ApmApi;
use App\Services\Claude\FlowTools;
use App\Services\Flows\FlowCard;
use App\Services\Flows\FlowValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * APM kuralları: spec'teki uçlar, sadece okuma, token görevi kalıbı ve token'ın sızmaması.
 */
class ApmTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://172.17.0.1:14070/test-restapi';

    private const TOKEN_TASK = "  - id: apm_token\n    type: io.kestra.plugin.core.http.Request\n    uri: \"http://172.17.0.1:14070/test-restapi/oauth/token\"\n    method: POST\n    contentType: application/x-www-form-urlencoded\n    formData:\n      grant_type: client_credentials\n      client_id: \"{{ secret('APM_CLIENT_ID') }}\"\n      client_secret: \"{{ secret('APM_CLIENT_SECRET') }}\"\n";

    private const BEARER = "    options:\n      auth:\n        type: BEARER\n        token: \"{{ outputs.apm_token.body | jq('.access_token') | first }}\"\n";

    protected function setUp(): void
    {
        parent::setUp();

        config(['apm.kestra_base_url' => self::BASE, 'apm.base_url' => 'http://apm.test/test-restapi', 'apm.client_id' => 'CID', 'apm.client_secret' => 'CSECRET', 'apm.grant_type' => 'client_credentials', 'apm.allow_writes' => false]);
        $this->app->forgetInstance(ApmApi::class);
        $this->useFlowEnvironment(secrets: ['APM_CLIENT_ID', 'APM_CLIENT_SECRET']);
        Http::preventStrayRequests();
        Http::fake(['kestra.test/api/v1/main/flows/validate' => Http::response([['constraints' => null]])]);
    }

    private function flow(string $path, string $method = 'GET', string $auth = self::BEARER, string $tokenTask = self::TOKEN_TASK, string $extra = ''): string
    {
        return "id: apm-flow\nnamespace: fortis.chat\ntasks:\n{$tokenTask}  - id: call\n    type: io.kestra.plugin.core.http.Request\n    uri: ".json_encode(self::BASE.$path)."\n    method: {$method}\n{$auth}{$extra}";
    }

    /** @return array<string, mixed> */
    private function validate(string $yaml): array
    {
        return $this->app->make(FlowValidator::class)->validate($yaml, FlowValidator::MODE_UPLOAD);
    }

    private function errors(string $yaml): string
    {
        return implode("\n", $this->validate($yaml)['errors']);
    }

    public function test_token_then_read_is_valid_and_skips_internal_host_block(): void
    {
        $result = $this->validate($this->flow("/api/legs?dateFrom={{ now() | dateAdd(-1, 'DAYS') | date('yyyy-MM-dd') }}&dateTo={{ now() | date('yyyy-MM-dd') }}"));

        $this->assertTrue($result['valid'], implode("\n", $result['errors']));
        $this->assertEqualsCanonicalizing(['APM_CLIENT_ID', 'APM_CLIENT_SECRET'], $result['secrets']);
    }

    public function test_path_parameters_and_templated_segments_match_the_spec(): void
    {
        $this->assertTrue($this->validate($this->flow('/api/legs/123'))['valid']);
        $templated = str_replace('tasks:', "inputs:\n  - id: leg\n    type: STRING\ntasks:", $this->flow('/api/legs/{{ inputs.leg }}'));
        $this->assertTrue($this->validate($templated)['valid'], $this->errors($templated));
    }

    public function test_unknown_endpoint_and_traversal_are_rejected(): void
    {
        $this->assertStringContainsString("APM'de böyle bir uç yok", $this->errors($this->flow('/api/yok')));
        $this->assertStringContainsString("'..'", $this->errors($this->flow('/api/legs/../../admin')));
    }

    public function test_writes_are_rejected_while_disabled(): void
    {
        $this->assertStringContainsString('sadece okuma', $this->errors($this->flow('/api/flight-logs/123', 'PUT')));
    }

    public function test_read_only_report_posts_are_allowed(): void
    {
        $this->assertStringNotContainsString('sadece okuma', $this->errors($this->flow('/api/reports/legs/legsOnTimeAtDepartureReport', 'POST')));
    }

    public function test_requests_need_bearer_token_from_a_token_task(): void
    {
        $this->assertStringContainsString('BEARER', $this->errors($this->flow('/api/legs/1', auth: '')));
        $this->assertStringContainsString('açık yazılamaz', $this->errors($this->flow('/api/legs/1', auth: "    options:\n      auth:\n        type: BEARER\n        token: eyJhbGciOi.abc.def\n")));
        $this->assertStringContainsString('APM token görevinden', $this->errors($this->flow('/api/legs/1', tokenTask: "  - id: apm_token\n    type: io.kestra.plugin.core.log.Log\n    message: x\n")));
    }

    public function test_token_task_needs_secret_credentials_and_form_encoding(): void
    {
        $this->assertStringContainsString('client_secret sadece bir secret', $this->errors($this->flow('/api/legs/1', tokenTask: str_replace("\"{{ secret('APM_CLIENT_SECRET') }}\"", 'hunter2', self::TOKEN_TASK))));
        $this->assertStringContainsString("grant_type 'client_credentials'", $this->errors($this->flow('/api/legs/1', tokenTask: str_replace('grant_type: client_credentials', 'grant_type: password', self::TOKEN_TASK))));
        $this->assertStringContainsString('x-www-form-urlencoded', $this->errors($this->flow('/api/legs/1', tokenTask: str_replace("    contentType: application/x-www-form-urlencoded\n", '', self::TOKEN_TASK))));
    }

    public function test_token_cannot_leak_into_other_tasks(): void
    {
        $log = "  - id: leak\n    type: io.kestra.plugin.core.log.Log\n    message: \"{{ outputs.apm_token.body }}\"\n";
        $this->assertStringContainsString("APM token'ı içerir", $this->errors($this->flow('/api/legs/1', extra: $log)));

        $external = "  - id: leak\n    type: io.kestra.plugin.core.http.Request\n    uri: https://example.com/x\n    options:\n      auth:\n        type: BEARER\n        token: \"{{ outputs.apm_token.body | jq('.access_token') | first }}\"\n";
        $this->assertStringContainsString("APM token'ı içerir", $this->errors($this->flow('/api/legs/1', extra: $external)));
    }

    public function test_proxy_options_are_rejected(): void
    {
        $auth = str_replace("    options:\n", "    options:\n      proxyAddress: 1.2.3.4\n", self::BEARER);
        $this->assertStringContainsString('options.proxyAddress', $this->errors($this->flow('/api/legs/1', auth: $auth)));
    }

    public function test_flow_card_labels_apm_steps(): void
    {
        $yaml = $this->flow('/api/legs/1');
        $card = $this->app->make(FlowCard::class)->build('t', ['s'], $yaml, $this->validate($yaml));

        $this->assertSame(["APM'ye giriş yapıyor (token alır)", "APM'den veri okuyor"], array_column($card['warned_steps'], 'label'));
        $this->assertStringStartsWith('GET /api/legs/{legIdentifier}', $card['warned_steps'][1]['details']);
    }

    public function test_preview_apm_gets_token_and_trims_lists(): void
    {
        Http::fake([
            'apm.test/test-restapi/oauth/token' => Http::response(['access_token' => 'jwt-1', 'expires_in' => 3600]),
            'apm.test/test-restapi/api/legs/999' => Http::response(['error' => '', 'validationErrors' => [['field' => 'legIdentifier', 'message' => 'Leg not found']]], 400),
            'apm.test/test-restapi/api/legs*' => Http::response(['totalNumberOfItems' => 5, 'legs' => ['_links' => ['x' => 1], 'content' => [['legId' => 1], ['legId' => 2], ['legId' => 3], ['legId' => 4], ['legId' => 5]]]]),
        ]);
        $tools = $this->app->make(FlowTools::class);

        $preview = json_decode($tools->execute('preview_apm', ['path' => '/api/legs?dateFrom=2026-10-04T00:00:00Z&dateTo=2026-10-05T00:00:00Z'])->content, true);
        $this->assertSame([['legId' => 1], ['legId' => 2], ['legId' => 3], ['_kalan' => 2]], $preview['response']['legs']['content']);
        $this->assertArrayNotHasKey('_links', $preview['response']['legs']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/oauth/token') && $request['client_id'] === 'CID' && $request['grant_type'] === 'client_credentials');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/legs') && $request->hasHeader('Authorization', 'Bearer jwt-1'));

        $this->assertStringContainsString('Leg not found', $tools->execute('preview_apm', ['path' => '/api/legs/999'])->content);
        $this->assertStringContainsString('GET ucu yok', $tools->execute('preview_apm', ['path' => '/api/yok'])->content);
        $this->assertStringContainsString('şablon', $tools->execute('preview_apm', ['path' => '/api/legs?dateFrom={{ now() }}'])->content);
    }

    public function test_describe_apm_tool(): void
    {
        $tools = $this->app->make(FlowTools::class);
        $overview = json_decode($tools->execute('describe_apm', [])->content, true);

        $this->assertSame(self::BASE, $overview['base_url']);
        $this->assertSame(['APM_CLIENT_ID', 'APM_CLIENT_SECRET'], $overview['required_secrets']);
        $this->assertNotContains('PUT', array_column($overview['operations'], 'method'));

        $legs = json_decode($tools->execute('describe_apm', ['path' => '/api/legs'])->content, true);
        $this->assertContains('legs.content[].flightNumberInfo.flightNumber: string — Flight Number', $legs['response']['fields']);

        $this->assertStringContainsString('işlemi yok', $tools->execute('describe_apm', ['path' => '/api/yok'])->content);
    }
}
