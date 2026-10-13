<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Services\Claude\ClaudeChat;
use App\Services\Claude\FlowTools;
use App\Services\Flows\FlowCardStatus;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class FlowUploadTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private const FLOW = <<<'YAML'
    id: site-check
    namespace: fortis.chat
    tasks:
      - id: check
        type: io.kestra.plugin.core.http.Request
        uri: https://example.com/health
    triggers:
      - id: morning
        type: io.kestra.plugin.core.trigger.Schedule
        cron: "0 8 * * *"
        timezone: Europe/Istanbul
    YAML;

    /** @var list<string> Kestra'ya giden yazma istekleri ("METHOD path") */
    private array $writes = [];

    private bool $existsInKestra = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFlowEnvironment(secrets: ['API_TOKEN']);

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/flows/validate')) {
                return Http::response([['constraints' => null]]);
            }

            if ($request->method() === 'GET' && str_starts_with($path, '/api/v1/main/flows/fortis.chat/')) {
                return $this->existsInKestra ? Http::response(['id' => 'site-check', 'revision' => 4]) : Http::response(null, 404);
            }

            $this->writes[] = "{$request->method()} {$path}";

            return Http::response(['id' => 'site-check', 'namespace' => 'fortis.chat', 'revision' => $request->method() === 'PUT' ? 5 : 1]);
        });
    }

    private function propose(string $yaml = self::FLOW, ?Conversation $conversation = null): FlowVersion
    {
        $tools = $this->app->make(FlowTools::class);
        $tools->startTurn($conversation ?? Conversation::factory()->create());
        $result = $tools->execute('propose_flow', ['title' => 'Site kontrolü', 'summary' => ['Her gün 08:00'], 'yaml' => $yaml]);

        $this->assertFalse($result->isError, $result->content);

        return FlowVersion::findOrFail($result->card['flow_version_id']);
    }

    public function test_upload_creates_flow_in_kestra_and_records_revision(): void
    {
        $version = $this->propose();

        $this->postJson("/api/flow-versions/{$version->id}/upload")
            ->assertOk()
            ->assertJsonPath('card.status.uploaded', true)
            ->assertJsonPath('card.status.kestra_revision', 1)
            ->assertJsonPath('card.status.can_upload', false);

        $this->assertSame(['POST /api/v1/main/flows'], $this->writes);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/main/flows')
            && $request->body() === $version->yaml);

        $version->refresh();
        $this->assertNotNull($version->uploaded_at);
        $this->assertSame('127.0.0.1', $version->uploaded_by);
        $this->assertSame(Flow::STATUS_ACTIVE, $version->flow->status);
        $this->assertSame($version->id, $version->flow->current_version_id);
    }

    public function test_changed_flow_is_uploaded_as_new_revision(): void
    {
        $conversation = Conversation::factory()->create();
        $first = $this->propose(conversation: $conversation);
        $this->postJson("/api/flow-versions/{$first->id}/upload")->assertOk();

        $this->existsInKestra = true;
        $second = $this->propose(str_replace('0 8 * * *', '0 9 * * *', self::FLOW), $conversation);

        $this->assertSame(2, $second->number);
        $this->postJson("/api/flow-versions/{$second->id}/upload")
            ->assertOk()
            ->assertJsonPath('card.status.kestra_revision', 5);

        $this->assertSame(['POST /api/v1/main/flows', 'PUT /api/v1/main/flows/fortis.chat/site-check'], $this->writes);
    }

    public function test_tampered_version_never_reaches_kestra(): void
    {
        $version = $this->propose();
        $version->update(['yaml' => str_replace('io.kestra.plugin.core.http.Request', 'io.kestra.plugin.scripts.shell.Commands', $version->yaml)]);

        $this->postJson("/api/flow-versions/{$version->id}/upload")
            ->assertUnprocessable()
            ->assertJsonPath('errors.0', fn (string $error) => str_contains($error, 'izinli değil'));

        $this->assertSame([], $this->writes);
    }

    public function test_reformatted_version_is_rejected(): void
    {
        $version = $this->propose();
        $version->update(['yaml' => "# değiştirildi\n".$version->yaml]);

        $this->postJson("/api/flow-versions/{$version->id}/upload")->assertUnprocessable();

        $this->assertSame([], $this->writes);
    }

    public function test_missing_secrets_block_upload(): void
    {
        $version = $this->propose(str_replace(
            "uri: https://example.com/health\n",
            "uri: https://example.com/health\n    headers:\n      Authorization: \"Bearer {{ secret('UNDEFINED_TOKEN') }}\"\n",
            self::FLOW,
        ));

        $this->getJson("/api/conversations/{$version->flow->conversation_id}")->assertOk();
        $this->assertFalse($this->app->make(FlowCardStatus::class)->status($version)['can_upload']);

        $this->postJson("/api/flow-versions/{$version->id}/upload")
            ->assertUnprocessable()
            ->assertJsonPath('errors.0', fn (string $error) => str_contains($error, 'UNDEFINED_TOKEN'));

        $this->assertSame([], $this->writes);
    }

    public function test_old_version_and_already_uploaded_version_cannot_be_uploaded(): void
    {
        $conversation = Conversation::factory()->create();
        $first = $this->propose(conversation: $conversation);
        $second = $this->propose(str_replace('0 8 * * *', '0 9 * * *', self::FLOW), $conversation);

        $this->postJson("/api/flow-versions/{$first->id}/upload")
            ->assertUnprocessable()
            ->assertJsonPath('errors.0', 'Bu akışın daha yeni bir sürümü var. Son sürümü yükleyin.');

        $this->postJson("/api/flow-versions/{$second->id}/upload")->assertOk();
        $this->postJson("/api/flow-versions/{$second->id}/upload")
            ->assertUnprocessable()
            ->assertJsonPath('errors.0', 'Bu sürüm zaten yüklendi.');

        $this->assertSame(['POST /api/v1/main/flows'], $this->writes);
    }

    public function test_kestra_unreachable_blocks_upload(): void
    {
        $version = $this->propose();

        Http::swap(new Factory);
        Http::fake(['*' => Http::response('', 503)]);

        $this->postJson("/api/flow-versions/{$version->id}/upload")
            ->assertUnprocessable()
            ->assertJsonPath('errors.0', fn (string $error) => str_contains($error, 'Kestra doğrulaması yapılamadı'));

        $this->assertNull($version->refresh()->uploaded_at);
    }

    public function test_cards_in_conversation_show_live_status(): void
    {
        $conversation = Conversation::factory()->create();
        $version = $this->propose(conversation: $conversation);
        $conversation->messages()->create([
            'role' => Message::ROLE_USER,
            'content' => [['type' => 'tool_result', 'tool_use_id' => 't', 'content' => '{}']],
            'text' => '',
            'meta' => ['flow_cards' => [$version->card]],
        ]);

        $this->getJson("/api/conversations/{$conversation->id}")
            ->assertJsonPath('data.messages.0.flow_cards.0.status.can_upload', true)
            ->assertJsonPath('data.messages.0.flow_cards.0.status.uploaded', false);

        $this->postJson("/api/flow-versions/{$version->id}/upload")->assertOk();

        $this->getJson("/api/conversations/{$conversation->id}")
            ->assertJsonPath('data.messages.0.flow_cards.0.status.uploaded', true);
    }

    public function test_next_user_message_tells_claude_about_the_upload(): void
    {
        $conversation = Conversation::factory()->create();
        $conversation->messages()->create(['role' => Message::ROLE_USER, 'content' => [['type' => 'text', 'text' => 'akış']], 'text' => 'akış']);
        $version = $this->propose(conversation: $conversation);
        $toolMessage = $conversation->messages()->create(['role' => Message::ROLE_USER, 'content' => [['type' => 'tool_result', 'tool_use_id' => 't', 'content' => '{}']], 'text' => '']);
        $version->update(['message_id' => $toolMessage->id]);
        $this->postJson("/api/flow-versions/{$version->id}/upload")->assertOk();

        $this->mock(ClaudeChat::class, fn ($mock) => $mock->shouldReceive('reply')->andReturnUsing(
            fn (Conversation $c, Closure $emit) => $c->messages()->latest('id')->first(),
        ));

        $this->post("/api/conversations/{$conversation->id}/messages", ['content' => 'Yükledim, çalışır mı?'])->streamedContent();

        $last = $conversation->messages()->where('text', 'Yükledim, çalışır mı?')->firstOrFail();
        $this->assertCount(2, $last->content);
        $this->assertStringContainsString('Sistem notu', $last->content[0]['text']);
        $this->assertStringContainsString('fortis.chat.site-check', $last->content[0]['text']);
        $this->assertSame('Yükledim, çalışır mı?', $last->content[1]['text']);
    }
}
