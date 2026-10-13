<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Claude\ClaudeChat;
use App\Services\Claude\ClaudeException;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class ConversationApiTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_conversations_are_listed_newest_first(): void
    {
        $old = Conversation::factory()->create(['updated_at' => now()->subDay()]);
        $new = Conversation::factory()->create();

        $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('data.1.id', $old->id);
    }

    public function test_conversation_shows_messages_without_raw_content(): void
    {
        $conversation = Conversation::factory()->create();
        Message::factory()->for($conversation)->create(['text' => 'Merhaba']);
        Message::factory()->for($conversation)->assistant()->create([
            'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'gizli'], ['type' => 'text', 'text' => 'Selam']],
            'text' => 'Selam',
        ]);

        $this->getJson("/api/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.text', 'Merhaba')
            ->assertJsonPath('data.messages.1.text', 'Selam')
            ->assertJsonMissingPath('data.messages.1.content');
    }

    public function test_conversation_can_be_created_renamed_and_deleted(): void
    {
        $id = $this->postJson('/api/conversations')->assertCreated()->json('data.id');

        $this->patchJson("/api/conversations/{$id}", ['title' => 'Rüzgar uyarısı'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Rüzgar uyarısı');

        $this->deleteJson("/api/conversations/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('conversations', ['id' => $id]);
    }

    public function test_sending_a_message_streams_the_reply(): void
    {
        $conversation = Conversation::factory()->create(['title' => null]);

        $this->mock(ClaudeChat::class, function ($mock) {
            $mock->shouldReceive('reply')->once()->andReturnUsing(function (Conversation $conversation, Closure $emit) {
                $emit('delta', ['text' => 'Mer']);
                $emit('delta', ['text' => 'haba']);

                $message = $conversation->messages()->create([
                    'role' => Message::ROLE_ASSISTANT,
                    'content' => [['type' => 'text', 'text' => 'Merhaba']],
                    'text' => 'Merhaba',
                    'stop_reason' => 'end_turn',
                ]);
                $emit('message', $message);

                return $message;
            });
        });

        $body = $this->post("/api/conversations/{$conversation->id}/messages", ['content' => '  Her sabah rapor gönder  '])
            ->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
            ->streamedContent();

        $this->assertSame(
            ['user_message', 'delta', 'delta', 'assistant_message', 'done'],
            $this->eventNames($body),
        );
        $this->assertStringContainsString('"text":"haba"', $body);

        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'role' => 'user', 'text' => 'Her sabah rapor gönder']);
        $this->assertSame('Her sabah rapor gönder', $conversation->fresh()->title);
    }

    public function test_tool_events_and_flow_cards_are_streamed(): void
    {
        $conversation = Conversation::factory()->create();
        $card = ['title' => 'Site kontrolü', 'summary' => ['Her gün çalışır'], 'yaml' => 'id: x'];

        $this->mock(ClaudeChat::class, function ($mock) use ($card) {
            $mock->shouldReceive('reply')->once()->andReturnUsing(function (Conversation $conversation, Closure $emit) use ($card) {
                $emit('tool', ['name' => 'propose_flow', 'status' => 'running']);
                $emit('tool', ['name' => 'propose_flow', 'status' => 'done']);
                $toolMessage = $conversation->messages()->create([
                    'role' => Message::ROLE_USER,
                    'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '{}']],
                    'text' => '',
                    'meta' => ['flow_cards' => [$card]],
                ]);
                $emit('message', $toolMessage);

                return $toolMessage;
            });
        });

        $body = $this->post("/api/conversations/{$conversation->id}/messages", ['content' => 'akış'])->streamedContent();

        $this->assertSame(['user_message', 'tool', 'tool', 'assistant_message', 'done'], $this->eventNames($body));
        $this->assertStringContainsString('"flow_cards":[{"title":"Site kontrolü"', $body);

        $this->getJson("/api/conversations/{$conversation->id}")
            ->assertJsonPath('data.messages.1.flow_cards.0.title', 'Site kontrolü');
    }

    public function test_claude_errors_are_streamed_as_error_event(): void
    {
        $conversation = Conversation::factory()->create();

        $this->mock(ClaudeChat::class, fn ($mock) => $mock->shouldReceive('reply')
            ->andThrow(new ClaudeException('Claude şu an çok yoğun.')));

        $body = $this->post("/api/conversations/{$conversation->id}/messages", ['content' => 'selam'])->streamedContent();

        $this->assertSame(['user_message', 'error', 'done'], $this->eventNames($body));
        $this->assertStringContainsString('Claude şu an çok yoğun.', $body);
    }

    public function test_second_message_while_replying_is_rejected(): void
    {
        $conversation = Conversation::factory()->create();
        $lock = Cache::lock("conversation-reply:{$conversation->id}", 60);
        $lock->get();

        $this->mock(ClaudeChat::class, fn ($mock) => $mock->shouldNotReceive('reply'));

        $this->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'ikinci'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Bu sohbette bir yanıt hâlâ hazırlanıyor. Bitince tekrar deneyin.');
        $this->assertDatabaseMissing('messages', ['text' => 'ikinci']);

        $lock->release();
    }

    public function test_lock_is_released_after_reply_even_on_error(): void
    {
        $conversation = Conversation::factory()->create();
        $this->mock(ClaudeChat::class, fn ($mock) => $mock->shouldReceive('reply')->andThrow(new ClaudeException('hata')));

        $this->post("/api/conversations/{$conversation->id}/messages", ['content' => 'bir'])->streamedContent();

        $this->assertTrue(Cache::lock("conversation-reply:{$conversation->id}", 1)->get());
    }

    public function test_empty_message_is_rejected(): void
    {
        $conversation = Conversation::factory()->create();

        $this->mock(ClaudeChat::class, fn ($mock) => $mock->shouldNotReceive('reply'));

        $this->postJson("/api/conversations/{$conversation->id}/messages", ['content' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');
    }

    /**
     * @return list<string>
     */
    private function eventNames(string $body): array
    {
        preg_match_all('/^event: (\S+)$/m', $body, $matches);

        return $matches[1];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
