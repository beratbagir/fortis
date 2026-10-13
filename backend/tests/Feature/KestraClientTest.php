<?php

namespace Tests\Feature;

use App\Services\Kestra\KestraClient;
use App\Services\Kestra\KestraException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KestraClientTest extends TestCase
{
    private function client(string $version = '2.0.4'): KestraClient
    {
        return new KestraClient('http://kestra.test', 'dev@fortis.local', 'secret', 'main', $version);
    }

    public function test_create_flow_sends_yaml_with_basic_auth(): void
    {
        Http::fake(['kestra.test/api/v1/main/flows' => Http::response(['id' => 'f', 'revision' => 1])]);

        $result = $this->client()->createFlow("id: f\nnamespace: fortis.chat\n");

        $this->assertSame(1, $result['revision']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->hasHeader('Content-Type', 'application/x-yaml')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('dev@fortis.local:secret'))
            && $request->body() === "id: f\nnamespace: fortis.chat\n");
    }

    public function test_update_flow_uses_put(): void
    {
        Http::fake(['kestra.test/api/v1/main/flows/fortis.chat/f' => Http::response(['revision' => 2])]);

        $this->client()->updateFlow('fortis.chat', 'f', 'id: f');

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT');
    }

    public function test_get_flow_returns_null_when_missing(): void
    {
        Http::fake(['kestra.test/api/v1/main/flows/fortis.chat/missing*' => Http::response(null, 404)]);

        $this->assertNull($this->client()->getFlow('fortis.chat', 'missing'));
        $this->assertFalse($this->client()->flowExists('fortis.chat', 'missing'));
    }

    public function test_create_execution_sends_inputs_as_multipart_and_labels_as_query(): void
    {
        Http::fake(['kestra.test/api/v1/main/executions/fortis.chat/f*' => Http::response(['id' => 'exec1', 'state' => ['current' => 'CREATED']])]);

        $execution = $this->client()->createExecution('fortis.chat', 'f', ['name' => 'Ali'], ['source' => 'chat']);

        $this->assertSame('exec1', $execution['id']);
        Http::assertSent(fn (Request $request) => $request->isMultipart()
            && str_contains($request->url(), 'labels=source%3Achat')
            && $request[0]['name'] === 'name'
            && $request[0]['contents'] === 'Ali');
    }

    public function test_resume_path_depends_on_kestra_version(): void
    {
        Http::fake(['kestra.test/*' => Http::response()]);

        $this->client('2.0.4')->resumeExecution('exec1');
        $this->client('1.3.41')->resumeExecution('exec1');

        Http::assertSent(fn (Request $request) => $request->url() === 'http://kestra.test/api/v1/main/executions/exec1/actions/resume');
        Http::assertSent(fn (Request $request) => $request->url() === 'http://kestra.test/api/v1/main/executions/exec1/resume');
    }

    public function test_kill_accepts_text_json(): void
    {
        Http::fake(['kestra.test/*' => Http::response()]);

        $this->client()->killExecution('exec1');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/executions/exec1/actions/kill')
            && $request->header('Accept') === ['text/json, application/json, application/problem+json']);
    }

    public function test_search_flows_filters_by_namespace_prefix(): void
    {
        Http::fake(['kestra.test/*' => Http::response(['results' => [], 'total' => 0])]);

        $this->client()->searchFlows('fortis.chat');

        Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'filters[namespace][PREFIX]=fortis.chat'));
    }

    public function test_logs_are_filtered_by_min_level(): void
    {
        Http::fake(['kestra.test/api/v1/main/logs/exec1' => Http::response([
            ['level' => 'DEBUG', 'message' => 'gürültü'],
            ['level' => 'INFO', 'message' => 'merhaba'],
            ['level' => 'ERROR', 'message' => 'hata'],
        ])]);

        $logs = $this->client()->getExecutionLogs('exec1');

        $this->assertSame(['merhaba', 'hata'], array_column($logs, 'message'));
    }

    public function test_problem_details_error_is_readable(): void
    {
        Http::fake(['kestra.test/*' => Http::response(
            ['title' => 'Bad Request', 'detail' => 'tasks: must not be empty'],
            422,
            ['Content-Type' => 'application/problem+json'],
        )]);

        try {
            $this->client()->createFlow('id: f');
            $this->fail('KestraException bekleniyordu');
        } catch (KestraException $e) {
            $this->assertSame(422, $e->status);
            $this->assertStringContainsString('tasks: must not be empty', $e->getMessage());
        }
    }

    public function test_unauthorized_error_mentions_credentials(): void
    {
        Http::fake(['kestra.test/*' => Http::response('', 401)]);

        $this->expectException(KestraException::class);
        $this->expectExceptionMessage('KESTRA_USERNAME');

        $this->client()->assertAuthenticated();
    }

    public function test_terminal_states(): void
    {
        $this->assertTrue(KestraClient::isTerminal('SUCCESS'));
        $this->assertTrue(KestraClient::isTerminal('FAILED'));
        $this->assertFalse(KestraClient::isTerminal('RUNNING'));
        $this->assertFalse(KestraClient::isTerminal('PAUSED'));
    }
}
