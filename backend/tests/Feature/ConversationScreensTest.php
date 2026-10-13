<?php

namespace Tests\Feature;

use Anthropic\Client;
use App\Ai\ModelRouter;
use App\Models\AiCall;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Claude\CostCalculator;
use App\Services\Claude\FlowTools;
use App\Services\Claude\TitleGenerator;
use App\Services\Usage\Budget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\FakesDataSources;
use Tests\TestCase;

/**
 * Faz A2 konuşma ekranları: Auto model, başlık, kaynak seçici, soru kartı, kaynak numaraları, iz, geri bildirim.
 */
class ConversationScreensTest extends TestCase
{
    use FakesDataSources, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDataSources();
        $this->user = User::factory()->admin()->create();
        $this->actingAs($this->user);
    }

    private function conversation(array $attributes = []): Conversation
    {
        return Conversation::factory()->create(['user_id' => $this->user->id] + $attributes);
    }

    private function ask(Conversation $conversation, array $body): string
    {
        return $this->post("/api/conversations/{$conversation->id}/messages", $body, ['Accept' => 'text/event-stream'])->assertOk()->streamedContent();
    }

    /** @return list<array<string, mixed>> */
    private function events(string $stream, string $name): array
    {
        preg_match_all('/event: '.$name.'\ndata: (.*)\n/', $stream, $m);

        return array_map(fn (string $json) => json_decode($json, true), $m[1]);
    }

    public function test_auto_uses_default_model_then_data_model_and_fast_model_for_title(): void
    {
        config(['ai.fast_model' => 'claude-opus-5-5']);
        $this->fakeClaude()
            ->toolCalls([['name' => 'query_warehouse', 'input' => ['sql' => 'SELECT flight_no FROM ires_dim_flight_test LIMIT 5', 'purpose' => 'Seferler']]])
            ->text('İki sefer var.');

        $conversation = $this->conversation(['title' => null]);
        $stream = $this->ask($conversation, ['content' => 'Dün kaç sefer vardı acaba, bir bakar mısın?']);

        $this->assertSame(['claude-sonnet-5-5', 'claude-opus-5-5'], array_column($this->claude->requests, 'model'));
        $models = array_values(array_filter($this->events($stream, 'step'), fn (array $s) => $s['kind'] === 'model'));
        $this->assertSame(['Model seçildi · Auto → Claude Sonnet 5.5', 'Model seçildi · Auto → Claude Opus 5.5'], array_column($models, 'label'));

        // Başlık: hızlı modelle, bir kez, ai_calls'ta purpose = title.
        $this->assertSame('claude-opus-5-5', $this->claude->otherRequests[0]['model']);
        $this->assertSame('Test sohbeti başlığı', $conversation->refresh()->title);
        $this->assertSame(1, AiCall::query()->where('purpose', 'title')->count());
        $this->assertStringContainsString('"title":"Test sohbeti başlığı"', $stream);

        $answer = Message::query()->where('role', 'assistant')->latest('id')->first()->meta['answer'];
        $this->assertSame(['claude-sonnet-5-5', 'claude-opus-5-5'], $answer['models']);
        $this->assertTrue($answer['auto']);
        $this->assertCount(1, $answer['queries']);
        $this->assertSame([], $answer['writes']);
    }

    public function test_chosen_model_is_used_every_turn(): void
    {
        $this->fakeClaude()
            ->toolCalls([['name' => 'query_warehouse', 'input' => ['sql' => 'SELECT flight_no FROM ires_dim_flight_test LIMIT 5', 'purpose' => 'Seferler']]])
            ->text('Cevap.');

        $conversation = $this->conversation();
        $this->patchJson("/api/conversations/{$conversation->id}", ['model' => 'claude-opus-5-5'])->assertJsonPath('data.auto', false);
        $this->ask($conversation, ['content' => 'Seferler?']);

        $this->assertSame(['claude-opus-5-5', 'claude-opus-5-5'], array_column($this->claude->requests, 'model'));

        $this->patchJson("/api/conversations/{$conversation->id}", ['model' => 'auto'])->assertJsonPath('data.auto', true)->assertJsonPath('data.model', null);
    }

    public function test_title_falls_back_to_first_message_when_the_call_fails(): void
    {
        $conversation = $this->conversation(['title' => null]);
        $this->fakeClaude()->text('Cevap.');
        // Başlık isteği için sahte taşıyıcıyı devre dışı bırak: istemci çevrimdışı.
        $this->app->forgetInstance(TitleGenerator::class);
        $this->app->bind(TitleGenerator::class, fn ($app) => new TitleGenerator(
            $app->make(Client::class), $app->make(ModelRouter::class), $app->make(CostCalculator::class), $app->make(Budget::class),
            fn () => throw new \RuntimeException('bağlantı yok'),
        ));

        $this->ask($conversation, ['content' => 'Her sabah dünkü rötarları bana e-postayla gönderen bir otomasyon kurmak istiyorum']);

        $this->assertSame(Str::limit('Her sabah dünkü rötarları bana e-postayla gönderen bir otomasyon kurmak istiyorum', 60), $conversation->refresh()->title);
        $this->assertNotNull(AiCall::query()->where('purpose', 'title')->sole()->error);
    }

    public function test_renamed_conversation_title_is_not_replaced(): void
    {
        $conversation = $this->conversation();
        $this->patchJson("/api/conversations/{$conversation->id}", ['title' => 'Benim başlığım'])->assertOk();
        $this->fakeClaude()->text('Cevap.');

        $this->ask($conversation, ['content' => 'Merhaba']);

        $this->assertSame('Benim başlığım', $conversation->refresh()->title);
        $this->assertSame([], $this->claude->otherRequests);
    }

    public function test_disabled_source_tools_are_not_offered_to_claude(): void
    {
        $conversation = $this->conversation();
        $this->patchJson("/api/conversations/{$conversation->id}", ['disabled_sources' => ['apm']])->assertJsonPath('data.disabled_sources', ['apm']);
        $this->fakeClaude()->text('Tamam.');

        $stream = $this->ask($conversation, ['content' => 'Dünkü rötarlar?']);

        $names = array_column($this->claude->requests[0]['tools'], 'name');
        $this->assertNotContains('query_apm', $names);
        $this->assertNotContains('describe_apm', $names);
        $this->assertNotContains('preview_apm', $names);
        $this->assertContains('query_warehouse', $names);

        $scope = collect($this->events($stream, 'step'))->firstWhere('kind', 'scope');
        $this->assertFalse(collect($scope['sources'])->firstWhere('key', 'apm')['in_scope']);
        $this->assertTrue(collect($scope['sources'])->firstWhere('key', 'ibs')['in_scope']);

        $tools = $this->app->make(FlowTools::class);
        $tools->startTurn($conversation->refresh());
        $this->assertTrue($tools->execute('query_apm', ['path' => '/api/legs', 'purpose' => 'x'])->isError);

        $this->patchJson("/api/conversations/{$conversation->id}", ['disabled_sources' => ['unknown']])->assertStatus(422);
    }

    public function test_ask_user_pauses_the_turn_and_the_answer_continues_it(): void
    {
        $question = ['question' => '"Kötü performans" nasıl tanımlansın?', 'context' => 'Dün 212 uçuş buldum.', 'multi_select' => true, 'options' => [['label' => 'Yük faktörü %82 hedefinin altında', 'source' => 'Tanım'], ['label' => 'Rötar oranı ortalamanın üstünde', 'source' => 'APM']], 'allow_other' => true];
        $this->fakeClaude()
            ->toolCalls([['name' => 'ask_user', 'input' => ['questions' => [$question]]]])
            ->text('Yük faktörüne göre baktım.');

        $conversation = $this->conversation();
        $stream = $this->ask($conversation, ['content' => 'Dün hangi rotalar kötüydü?']);

        $this->assertCount(1, $this->claude->requests);
        $ask = $this->events($stream, 'ask')[0];
        $this->assertSame('"Kötü performans" nasıl tanımlansın?', $ask['questions'][0]['question']);
        $pending = Message::findOrFail($ask['message_id']);
        $this->assertSame(Message::STATUS_AWAITING_INPUT, $pending->status);
        $this->assertSame('waiting', collect($this->events($stream, 'step'))->last()['status']);

        // Sohbet yeniden açılınca kart yine görünür.
        $this->getJson("/api/conversations/{$conversation->id}")
            ->assertJsonPath('data.messages.1.status', 'awaiting_input')
            ->assertJsonPath('data.messages.1.ask.questions.0.options.0.label', 'Yük faktörü %82 hedefinin altında');

        $this->ask($conversation, ['answer' => ['answers' => [['question' => $question['question'], 'selected' => ['Yük faktörü %82 hedefinin altında'], 'other' => 'charter hariç']]]]);

        $sent = collect($this->claude->requests[1]['messages'])->last();
        $this->assertSame('user', $sent['role']);
        $this->assertSame('tool_result', $sent['content'][0]['type']);
        $this->assertStringContainsString('Yük faktörü %82 hedefinin altında; charter hariç', json_encode($sent['content'][0], JSON_UNESCAPED_UNICODE));
        $this->assertNull($pending->refresh()->status);
        $this->assertStringStartsWith("Cevaplarım:\n— \"Kötü performans\" nasıl tanımlansın? = Yük faktörü", Message::query()->where('role', 'user')->latest('id')->value('text'));
    }

    public function test_answer_after_a_question_keeps_earlier_queries_for_citations(): void
    {
        $this->fakeClaude()
            ->toolCalls([
                ['name' => 'query_warehouse', 'input' => ['sql' => 'SELECT flight_no FROM ires_dim_flight_test LIMIT 5', 'purpose' => 'Seferler']],
                ['name' => 'ask_user', 'input' => ['questions' => [['question' => 'Kapsam?', 'options' => [['label' => 'Tarifeli']]]]]],
            ]);

        $conversation = $this->conversation();
        $this->ask($conversation, ['content' => 'Doluluk?']);

        $asked = Message::query()->where('status', Message::STATUS_AWAITING_INPUT)->sole();
        $queryId = $asked->meta['answer']['queries'][0]['query_id'];
        // Sorgunun sonucu soru kartı beklerken bekletilir, cevapla birlikte gider.
        $this->assertCount(1, $asked->meta['ask']['pending_results']);

        $this->claude->text("İki sefer var [^q:{$queryId}].");
        $stream = $this->ask($conversation, ['answer' => ['answers' => [['question' => 'Kapsam?', 'selected' => ['Tarifeli'], 'other' => null]]]]);

        $sent = collect($this->claude->requests[1]['messages'])->last()['content'];
        $this->assertSame(['tool_result', 'tool_result'], array_column(array_slice($sent, 0, 2), 'type'));

        $answer = Message::query()->where('role', 'assistant')->latest('id')->first()->meta['answer'];
        $this->assertSame([], $answer['citation_mismatch']);
        $this->assertSame($queryId, $answer['queries'][0]['query_id']);
        $this->assertContains('Cevabın alındı', array_column($answer['trace'], 'label'));
        // Veri sorgusu yapılmıştı: devam turu veri sorusu modeliyle.
        $this->assertSame('claude-opus-5-5', $this->claude->requests[1]['model']);
        $this->assertStringContainsString('"kind":"query"', $stream);
    }

    public function test_skipped_question_asks_claude_to_assume(): void
    {
        $this->fakeClaude()
            ->toolCalls([['name' => 'ask_user', 'input' => ['questions' => [['question' => 'Kapsam?', 'options' => [['label' => 'Tarifeli']]]]]]])
            ->text('Varsayımım: tarifeli seferler.');

        $conversation = $this->conversation();
        $this->ask($conversation, ['content' => 'Doluluk?']);
        $this->ask($conversation, ['answer' => ['skipped' => true]]);

        $this->assertStringContainsString('soruyu atladı', json_encode(collect($this->claude->requests[1]['messages'])->last(), JSON_UNESCAPED_UNICODE));
        $this->post("/api/conversations/{$conversation->id}/messages", ['answer' => ['skipped' => true]], ['Accept' => 'text/event-stream'])->assertStatus(422);
    }

    public function test_ask_user_with_too_many_questions_or_options_is_refused(): void
    {
        $option = ['label' => 'Seçenek'];
        $this->fakeClaude()
            ->toolCalls([['name' => 'ask_user', 'input' => ['questions' => array_fill(0, 4, ['question' => 'Soru?', 'options' => [$option]])]]])
            ->toolCalls([['name' => 'ask_user', 'input' => ['questions' => [['question' => 'Soru?', 'options' => array_fill(0, 6, $option)]]]]])
            ->text('Sormadan devam ediyorum.');

        $conversation = $this->conversation();
        $this->ask($conversation, ['content' => 'Bir şey']);

        $this->assertCount(3, $this->claude->requests);
        $this->assertStringContainsString('En fazla 3 soru', json_encode(collect($this->claude->requests[1]['messages'])->last(), JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('en fazla 5 seçenek', json_encode(collect($this->claude->requests[2]['messages'])->last(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, Message::query()->where('status', Message::STATUS_AWAITING_INPUT)->count());
    }

    public function test_citation_to_a_query_that_did_not_run_is_flagged(): void
    {
        $this->fakeClaude()
            ->toolCalls([['name' => 'query_warehouse', 'input' => ['sql' => 'SELECT flight_no FROM ires_dim_flight_test LIMIT 5', 'purpose' => 'Seferler']]])
            ->text('İki sefer var [^q:q_uydurma123].');

        $this->ask($this->conversation(), ['content' => 'Seferler?']);

        $answer = Message::query()->where('role', 'assistant')->latest('id')->first();
        $this->assertSame(['q_uydurma123'], $answer->meta['answer']['citation_mismatch']);
        $this->assertSame('citation_mismatch: q_uydurma123', AiCall::query()->whereNotNull('warning')->sole()->warning);
    }

    public function test_query_text_is_shown_only_to_admin_and_builder(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $this->fakeClaude()
            ->toolCalls([['name' => 'query_warehouse', 'input' => ['sql' => 'SELECT flight_no FROM ires_dim_flight_test LIMIT 5', 'purpose' => 'Seferler']]])
            ->text('Cevap.');

        $stream = $this->ask($conversation, ['content' => 'Seferler?']);

        $query = collect($this->events($stream, 'step'))->firstWhere('kind', 'query');
        $this->assertSame('Seferler', $query['purpose']);
        $this->assertArrayNotHasKey('query', $query);
        $this->assertStringNotContainsString('ires_dim_flight_test', $stream);

        $message = Message::query()->where('role', 'assistant')->latest('id')->first();
        $this->getJson("/api/conversations/{$conversation->id}")->assertJsonMissingPath('data.messages.3.answer.queries.0.query');
        $this->getJson("/api/messages/{$message->id}/audit")->assertOk()->assertJsonPath('data.0.purpose', 'Seferler')->assertJsonMissingPath('data.0.query');

        $this->actingAs($this->user);
        $this->getJson("/api/conversations/{$conversation->id}")->assertJsonPath('data.messages.3.answer.queries.0.query.sql', 'SELECT flight_no FROM ires_dim_flight_test LIMIT 5');
        $this->getJson("/api/messages/{$message->id}/audit")->assertJsonPath('data.0.query.sql', 'SELECT flight_no FROM ires_dim_flight_test LIMIT 5');

        $this->actingAs(User::factory()->create())->getJson("/api/messages/{$message->id}/audit")->assertNotFound();
    }

    public function test_only_the_conversation_owner_gives_feedback(): void
    {
        $conversation = $this->conversation();
        $message = $conversation->messages()->create(['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Cevap']], 'text' => 'Cevap']);

        $this->postJson("/api/messages/{$message->id}/feedback", ['rating' => 'down', 'comment' => 'Tablo eksik'])->assertOk()->assertJsonPath('data.rating', 'down');
        $this->postJson("/api/messages/{$message->id}/feedback", ['rating' => 'up'])->assertOk();
        $this->assertDatabaseHas('message_feedback', ['message_id' => $message->id, 'user_id' => $this->user->id, 'rating' => 'up']);
        $this->getJson("/api/conversations/{$conversation->id}")->assertJsonPath('data.messages.0.feedback', 'up');

        $this->postJson("/api/messages/{$message->id}/feedback", ['rating' => 'meh'])->assertStatus(422);
        $this->actingAs(User::factory()->admin()->create())->postJson("/api/messages/{$message->id}/feedback", ['rating' => 'up'])->assertForbidden();
    }
}
