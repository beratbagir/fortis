<?php

namespace Tests\Feature;

use App\Http\Resources\MessageResource;
use App\Models\Artifact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Claude\FlowTools;
use App\Services\Claude\SystemPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class ArtifactTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private const HTML = '<!doctype html><html><body><h1>Rötar paneli</h1></body></html>';

    public function test_create_artifact_saves_it_and_shows_a_card_without_echoing_content(): void
    {
        $conversation = Conversation::factory()->create();
        $tools = $this->tools($conversation);

        $result = $tools->execute('create_artifact', ['title' => 'Rötar paneli', 'kind' => 'html', 'content' => self::HTML]);

        $this->assertFalse($result->isError, $result->content);
        $artifact = Artifact::sole();
        $this->assertSame(['type' => 'artifact', 'artifact_id' => $artifact->id, 'version' => 1, 'title' => 'Rötar paneli', 'kind' => 'html'], $result->card);
        $this->assertStringStartsWith('art_', $artifact->id);
        $this->assertSame($conversation->id, $artifact->conversation_id);
        // İçerik zaten araç girdisinde; sonuçta tekrar gönderilmez.
        $this->assertStringNotContainsString('<h1>', $result->content);
        $this->assertSame(['artifact_id' => $artifact->id, 'version' => 1], array_intersect_key(json_decode($result->content, true), ['artifact_id' => 1, 'version' => 1]));
    }

    public function test_same_id_creates_a_new_version_and_old_versions_stay_readable(): void
    {
        $tools = $this->tools($conversation = Conversation::factory()->create());
        $id = $tools->execute('create_artifact', ['title' => 'Panel', 'kind' => 'html', 'content' => self::HTML])->card['artifact_id'];

        $second = $tools->execute('create_artifact', ['artifact_id' => $id, 'title' => 'Panel (koyu)', 'kind' => 'html', 'content' => '<p>v2</p>']);

        $this->assertSame(2, $second->card['version']);
        $this->assertSame(1, Artifact::count());

        $this->getJson("/api/artifacts/{$id}")
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.latest_version', 2)
            ->assertJsonPath('data.title', 'Panel (koyu)')
            ->assertJsonPath('data.content', '<p>v2</p>')
            ->assertJsonPath('data.conversation_id', $conversation->id)
            ->assertJsonCount(2, 'data.versions');

        $this->getJson("/api/artifacts/{$id}?version=1")->assertOk()->assertJsonPath('data.content', self::HTML)->assertJsonPath('data.title', 'Panel');
        $this->getJson("/api/artifacts/{$id}?version=9")->assertNotFound();
        $this->getJson('/api/artifacts/art_yok')->assertNotFound();
    }

    public function test_artifact_of_another_conversation_cannot_be_updated(): void
    {
        $id = $this->tools(Conversation::factory()->create())->execute('create_artifact', ['title' => 'A', 'kind' => 'svg', 'content' => '<svg/>'])->card['artifact_id'];

        $result = $this->tools(Conversation::factory()->create())->execute('create_artifact', ['artifact_id' => $id, 'title' => 'B', 'kind' => 'svg', 'content' => '<svg/>']);

        $this->assertTrue($result->isError);
        $this->assertSame(1, Artifact::findOrFail($id)->latest_version);
    }

    public function test_invalid_input_is_rejected_without_saving(): void
    {
        $tools = $this->tools(Conversation::factory()->create());

        foreach ([
            ['title' => '', 'kind' => 'html', 'content' => 'x'],
            ['title' => 'x', 'kind' => 'exe', 'content' => 'x'],
            ['title' => 'x', 'kind' => 'html', 'content' => '  '],
            ['title' => 'x', 'kind' => 'html', 'content' => str_repeat('a', 400_001)],
        ] as $input) {
            $result = $tools->execute('create_artifact', $input);
            $this->assertTrue($result->isError);
            $this->assertLessThan(2000, strlen($result->content), 'Hata içeriği geri göndermemeli');
        }

        $this->assertSame(0, Artifact::count());
    }

    public function test_message_resource_returns_artifact_cards(): void
    {
        $conversation = Conversation::factory()->create();
        $card = ['type' => 'artifact', 'artifact_id' => 'art_x', 'version' => 1, 'title' => 'Panel', 'kind' => 'html'];
        $message = $conversation->messages()->create(['role' => Message::ROLE_USER, 'content' => [['type' => 'text', 'text' => '-']], 'text' => '', 'meta' => ['flow_cards' => [$card]]]);

        $this->assertSame([$card], (new MessageResource($message))->resolve()['flow_cards']);
    }

    public function test_tool_and_prompt_describe_general_help_and_artifacts(): void
    {
        $names = array_column($this->app->make(FlowTools::class)->definitions(), 'name');
        $prompt = $this->app->make(SystemPrompt::class)->render();

        $this->assertContains('create_artifact', $names);
        $this->assertStringContainsString('Genel yardım', $prompt);
        $this->assertStringContainsString('create_artifact', $prompt);
    }

    private function tools(Conversation $conversation): FlowTools
    {
        $tools = $this->app->make(FlowTools::class);
        $tools->startTurn($conversation);

        return $tools;
    }
}
