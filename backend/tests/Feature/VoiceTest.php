<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\VoiceTranscription;
use App\Services\Claude\ClaudeChat;
use App\Services\Claude\ConversationHistory;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class VoiceTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = storage_path('framework/testing/voice-'.getmypid());
        config(['voice.tmp_dir' => $this->tmpDir, 'voice.enabled' => true, 'voice.whisper_url' => 'http://whisper.test']);
        File::deleteDirectory($this->tmpDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmpDir);

        parent::tearDown();
    }

    public function test_audio_is_sent_to_whisper_with_vocabulary_and_text_is_returned(): void
    {
        Http::fake(['whisper.test/transcribe' => Http::response(['text' => '  Eşiği 3 saate indir.  ', 'duration_s' => 4.2, 'processing_ms' => 1830, 'language' => 'tr'])]);

        $this->transcribe()
            ->assertOk()
            ->assertJson(['text' => 'Eşiği 3 saate indir.', 'duration_s' => 4.2, 'processing_ms' => 1830]);

        Http::assertSent(function (Request $request) {
            $parts = collect($request->data())->keyBy('name');

            return $request->url() === 'http://whisper.test/transcribe'
                && $parts->has('file')
                && str_contains($parts['prompt']['contents'], 'SuccessFactors');
        });

        $this->assertDatabaseHas('voice_transcriptions', ['processing_ms' => 1830, 'char_count' => 20, 'was_edited' => null]);
    }

    public function test_disallowed_type_and_files_over_5_mb_are_rejected(): void
    {
        Http::fake();

        $this->postJson('/api/voice/transcribe', ['audio' => UploadedFile::fake()->createWithContent('a.txt', 'bu bir ses değil')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('audio');

        $this->postJson('/api/voice/transcribe', ['audio' => UploadedFile::fake()->create('a.wav', 5 * 1024 + 1, 'audio/wav')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('audio');

        Http::assertNothingSent();
    }

    public function test_whisper_down_returns_503_with_readable_message(): void
    {
        Http::fake(['whisper.test/*' => Http::failedConnection()]);

        $this->transcribe()
            ->assertStatus(503)
            ->assertJson(['message' => 'Sesli komut şu an kullanılamıyor, yazarak devam edebilirsin.']);

        Http::fake(['whisper.test/*' => Http::response(['detail' => 'boom'], 500)]);
        $this->transcribe()->assertStatus(503);
    }

    public function test_temp_folder_is_empty_after_success_and_failure(): void
    {
        Http::fakeSequence('whisper.test/*')
            ->push(['text' => 'tamam', 'duration_s' => 1, 'processing_ms' => 10])
            ->push(['detail' => 'too long'], 413)
            ->pushFailedConnection();

        $this->transcribe()->assertOk();
        $this->assertSame([], File::files($this->tmpDir));

        $this->transcribe()->assertStatus(413);
        $this->assertSame([], File::files($this->tmpDir));

        $this->transcribe()->assertStatus(503);
        $this->assertSame([], File::files($this->tmpDir));
    }

    public function test_known_hallucinations_become_empty_text(): void
    {
        Http::fakeSequence('whisper.test/*')
            ->push(['text' => 'Altyazı M.K.', 'duration_s' => 3, 'processing_ms' => 900])
            ->push(['text' => ' İzlediğiniz için teşekkürler! ', 'duration_s' => 3, 'processing_ms' => 900])
            ->push(['text' => '', 'duration_s' => 1, 'processing_ms' => 300]);

        $this->transcribe()->assertOk()->assertExactJson(['text' => '', 'reason' => 'no_speech', 'transcription_id' => 1]);
        $this->transcribe()->assertOk()->assertJson(['text' => '', 'reason' => 'no_speech']);
        $this->transcribe()->assertOk()->assertJson(['text' => '', 'reason' => 'no_speech']);
    }

    public function test_21st_request_in_a_minute_is_429(): void
    {
        Http::fake(['whisper.test/*' => Http::response(['text' => 'tamam', 'duration_s' => 1, 'processing_ms' => 10])]);

        foreach (range(1, 20) as $i) {
            $this->transcribe()->assertOk();
        }

        $this->transcribe()->assertStatus(429);
    }

    public function test_disabled_voice_hides_button_and_endpoint(): void
    {
        $this->getJson('/api/voice/config')->assertOk()->assertJson(['enabled' => true, 'max_seconds' => 30]);

        config(['voice.enabled' => false]);
        Http::fake();

        $this->getJson('/api/voice/config')->assertOk()->assertJson(['enabled' => false]);
        $this->transcribe()->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_voice_transcriptions_table_stores_only_measurements(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'duration_s', 'processing_ms', 'char_count', 'was_edited', 'created_at'],
            Schema::getColumnListing('voice_transcriptions'),
        );
    }

    public function test_sending_the_message_records_whether_the_text_was_edited(): void
    {
        $conversation = Conversation::factory()->create();
        $transcription = VoiceTranscription::create(['duration_s' => 2, 'processing_ms' => 900, 'char_count' => 12]);
        $this->fakeClaude();

        $this->post("/api/conversations/{$conversation->id}/messages", [
            'content' => 'Eşiği 3 saat yap',
            'voice' => ['transcription_id' => $transcription->id, 'edited' => true],
        ])->assertOk()->streamedContent();

        $this->assertTrue($transcription->refresh()->was_edited);
    }

    public function test_selected_block_is_sent_to_claude_with_the_message(): void
    {
        $yaml = <<<'YAML'
        id: long-delay-notice
        namespace: fortis.chat
        tasks:
          - id: get_delays
            type: io.kestra.plugin.core.http.Request
            description: Rötarlı uçuşları getir
            uri: https://example.com/delays?min=240
        YAML;
        $flow = Flow::factory()->create(['flow_id' => 'long-delay-notice', 'title' => 'Uzun rötar']);
        FlowVersion::factory()->for($flow)->create(['number' => 1, 'yaml' => $yaml, 'yaml_hash' => hash('sha256', $yaml), 'title' => 'Uzun rötar']);
        $conversation = Conversation::create(['title' => 'editör', 'context_flow_id' => $flow->id, 'kind' => Conversation::KIND_EDITOR]);

        $sent = null;
        $this->fakeClaude(function (Conversation $conversation) use (&$sent) {
            $sent = app(ConversationHistory::class)->forConversation($conversation);
        });

        $this->post("/api/conversations/{$conversation->id}/messages", [
            'content' => 'Bunu 180 dakika yap',
            // İstemcinin gönderdiği tür ve başlık kullanılmaz; akıştan okunur.
            'context' => ['selected_task_id' => 'get_delays', 'selected_kind' => 'x', 'selected_title' => 'uydurma'],
        ])->assertOk()->streamedContent();

        $texts = array_column(end($sent)['content'], 'text');

        $this->assertContains('[Kullanıcı tuvalde şu bloğu seçmiş: get_delays (HTTP isteği, "Rötarlı uçuşları getir")]', $texts);
        $this->assertSame('Bunu 180 dakika yap', end($texts));
        $this->assertStringNotContainsString('uydurma', json_encode($sent, JSON_UNESCAPED_UNICODE));
        $this->assertSame('Bunu 180 dakika yap', $conversation->messages()->where('role', Message::ROLE_USER)->value('text'));

        // Akışta olmayan blok için not eklenmez
        $this->post("/api/conversations/{$conversation->id}/messages", [
            'content' => 'Bunu sil',
            'context' => ['selected_task_id' => 'yok_boyle_blok'],
        ])->assertOk()->streamedContent();

        $this->assertStringNotContainsString('yok_boyle_blok', json_encode(end($sent)['content'], JSON_UNESCAPED_UNICODE));
    }

    private function transcribe(): TestResponse
    {
        $wav = UploadedFile::fake()->createWithContent('voice.wav', file_get_contents(base_path('tests/fixtures/voice/sample.wav')));

        return $this->postJson('/api/voice/transcribe', ['audio' => $wav]);
    }

    private function fakeClaude(?Closure $inspect = null): void
    {
        $this->mock(ClaudeChat::class, function ($mock) use ($inspect) {
            $mock->shouldReceive('reply')->andReturnUsing(function (Conversation $conversation, Closure $emit) use ($inspect) {
                $inspect && $inspect($conversation);

                return $conversation->messages()->create(['role' => Message::ROLE_ASSISTANT, 'content' => [['type' => 'text', 'text' => 'Tamam']], 'text' => 'Tamam', 'stop_reason' => 'end_turn']);
            });
        });
    }
}
