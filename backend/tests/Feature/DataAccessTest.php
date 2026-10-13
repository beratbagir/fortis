<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Claude\FlowTools;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesDataSources;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/**
 * Claude'un veriye bakması (Faz A1) uçtan uca: gerçek ClaudeChat ve Anthropic SDK, sahte HTTP taşıyıcısı.
 */
class DataAccessTest extends TestCase
{
    use FakesDataSources, RefreshDatabase, SignsIn;

    private function ask(Conversation $conversation, string $text): string
    {
        return $this->post("/api/conversations/{$conversation->id}/messages", ['content' => $text], ['Accept' => 'text/event-stream'])
            ->assertOk()
            ->streamedContent();
    }

    /** @return list<string> */
    private function toolNames(int $request = 0): array
    {
        return array_column($this->claude->requests[$request]['tools'] ?? [], 'name');
    }

    public function test_query_tools_are_not_offered_when_data_access_is_off(): void
    {
        $this->fakeDataSources(AppSettings::DATA_ACCESS_OFF);
        $this->fakeClaude()->text('Bu veri için rapor hazırlayabilirim.');

        $this->ask(Conversation::factory()->create(['user_id' => $this->signedIn->id]), 'Dün kaç rötar oldu?');

        $this->assertNotContains('query_warehouse', $this->toolNames());
        $this->assertNotContains('query_apm', $this->toolNames());
        $this->assertNotContains('query_successfactors', $this->toolNames());
        $this->assertContains('create_report', $this->toolNames());
        $this->assertStringContainsString('Bu veri sadece rapor', json_encode($this->claude->requests[0]['system'], JSON_UNESCAPED_UNICODE));
    }

    public function test_query_tools_are_offered_when_data_access_is_masked(): void
    {
        $this->fakeDataSources();
        $this->fakeClaude()->text('Tamam.');

        $this->ask(Conversation::factory()->create(['user_id' => $this->signedIn->id]), 'Dün kaç rötar oldu?');

        $this->assertContains('query_warehouse', $this->toolNames());
        $this->assertContains('query_apm', $this->toolNames());
        $this->assertContains('query_successfactors', $this->toolNames());
        $system = json_encode($this->claude->requests[0]['system'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Soru mu, otomasyon mu?', $system);
        $this->assertStringNotContainsString('{{#if', $system);
    }

    public function test_query_tools_refuse_when_data_access_is_turned_off_mid_conversation(): void
    {
        $this->fakeDataSources(AppSettings::DATA_ACCESS_OFF);
        $result = app(FlowTools::class)->execute('query_apm', ['path' => '/api/legs?dateFrom=2026-10-11T00:00:00Z', 'purpose' => 'x']);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('kapalı', $result->content);
    }

    public function test_personal_data_never_reaches_claude_through_any_data_tool(): void
    {
        $this->fakeDataSources();
        $this->fakeClaude()
            ->toolCalls([
                ['name' => 'preview_apm', 'input' => ['path' => '/api/legs?dateFrom=2026-10-11T00:00:00Z&dateTo=2026-10-12T00:00:00Z&includeCrewMembers=true']],
                ['name' => 'query_apm', 'input' => ['path' => '/api/legs?dateFrom=2026-10-11T00:00:00Z&dateTo=2026-10-12T00:00:00Z&includeCrewMembers=true', 'purpose' => 'Dünkü uçuşlar']],
                ['name' => 'preview_successfactors', 'input' => ['path' => 'EmpJob?$expand=userNav']],
                ['name' => 'query_successfactors', 'input' => ['path' => 'EmpJob?$expand=userNav&$filter=department%20eq%20%27FIN%27', 'purpose' => 'Finans çalışanları']],
                ['name' => 'query_warehouse', 'input' => ['sql' => 'SELECT flight_no, dep, arr, seats_sold, capacity, remark, contact_email FROM ires_dim_flight_test LIMIT 10', 'purpose' => 'Seferler']],
                ['name' => 'query_warehouse', 'input' => ['sql' => 'SELECT pax_name, email, pnr FROM ires_dim_pnr_pax LIMIT 10', 'purpose' => 'Yolcular']],
                ['name' => 'query_warehouse', 'input' => ['sql' => 'SELECT dep, COUNT(*) AS pax_count FROM ires_dim_pnr_pax GROUP BY dep LIMIT 10', 'purpose' => 'Kalkışa göre yolcu sayısı']],
            ])
            ->text('Dün en uzun rötar FH1256 seferindeydi.');

        $stream = $this->ask(Conversation::factory()->create(['user_id' => $this->signedIn->id]), 'Dün en çok rötar yapan uçuşlar?');

        $this->assertCount(2, $this->claude->requests);
        // SDK Türkçe karakterleri \u ile kodlar; karşılaştırma çözülmüş gövde üzerinde.
        $sent = implode("\n", array_map(fn (array $r) => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->claude->requests));
        if (getenv('DUMP_SENT')) {
            file_put_contents(getenv('DUMP_SENT'), json_encode(collect($this->claude->requests[1]['messages'])->last(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        foreach (self::PERSONAL_DATA as $value) {
            $this->assertStringNotContainsString($value, $sent, "Claude'a kişisel veri gitti: {$value}");
            $this->assertStringNotContainsString(json_encode($value), $sent, "Claude'a kişisel veri gitti: {$value}");
        }

        // İşe yarayan veri gidiyor: sefer no, rötar, sayım, çalışan adı (personel istisnası), kısmen maskeli e-posta.
        $this->assertStringContainsString('FH1256', $sent);
        $this->assertStringContainsString('142', $sent);
        $this->assertStringContainsString('pax_count', $sent);
        $this->assertStringContainsString('Elif', $sent);
        $this->assertStringContainsString('e***@gozendigital.com', $sent);
        $this->assertStringContainsString('[MASKED]', $sent);
        // Yolcu tablosundan kişi düzeyinde satır reddedildi.
        $this->assertStringContainsString('sadece sayım', $sent);

        // Arayüz olayları: amaç, satır sayısı, maskeleme notu.
        $this->assertStringContainsString('"purpose":"Dünkü uçuşlar"', $stream);
        $this->assertStringContainsString('"rows":2', $stream);
        $this->assertStringContainsString('"masked":true', $stream);
    }

    public function test_every_query_is_audited_with_the_query_but_without_the_data(): void
    {
        $this->fakeDataSources();
        $this->fakeClaude()
            ->toolCalls([['name' => 'query_warehouse', 'input' => ['sql' => 'SELECT flight_no, remark FROM ires_dim_flight_test WHERE dep = :dep LIMIT 10', 'parameters' => ['dep' => 'AYT'], 'purpose' => 'AYT seferleri']]])
            ->text('İki sefer var.');

        $conversation = Conversation::factory()->create(['user_id' => $this->signedIn->id]);
        $this->ask($conversation, 'AYT seferleri?');

        $log = AuditLog::query()->where('action', 'ai.query')->sole();
        $this->assertSame($this->signedIn->id, $log->user_id);
        $this->assertSame('IBS', $log->system);
        $this->assertSame(AuditLog::RESULT_ALLOWED, $log->result);
        $this->assertSame('query_warehouse', $log->meta['tool']);
        $this->assertStringContainsString('FROM ires_dim_flight_test WHERE dep = :dep', $log->meta['query']['sql']);
        $this->assertSame(['dep' => 'AYT'], $log->meta['query']['parameters']);
        $this->assertSame(2, $log->meta['rows_returned']);
        $this->assertSame(['remark'], $log->meta['masked_fields']);
        $this->assertStringNotContainsString('FH1256', json_encode($log->meta));
        $this->assertStringNotContainsString('0532', json_encode($log->meta));

        // Sorgu adımı mesajda saklanıyor (arayüz için), veri değil.
        $stored = Message::query()->whereNotNull('meta')->get()->firstWhere(fn (Message $m) => isset($m->meta['queries']));
        $this->assertSame('AYT seferleri', $stored->meta['queries'][0]['purpose']);
        $this->getJson("/api/conversations/{$conversation->id}")
            ->assertJsonPath('data.messages.2.queries.0.purpose', 'AYT seferleri')
            ->assertJsonPath('data.messages.2.queries.0.rows', 2)
            ->assertJsonPath('data.messages.2.queries.0.masked', true);
    }

    public function test_turns_after_a_data_query_use_the_data_question_model(): void
    {
        config(['ai.data_question_model' => 'claude-opus-5-5']);
        $this->fakeDataSources();
        $this->fakeClaude()
            ->toolCalls([['name' => 'query_warehouse', 'input' => ['sql' => 'SELECT flight_no FROM ires_dim_flight_test LIMIT 5', 'purpose' => 'Seferler']]])
            ->text('Cevap.')
            ->text('İkinci soru cevabı.');

        $conversation = Conversation::factory()->create(['user_id' => $this->signedIn->id]);
        $this->ask($conversation, 'Seferler?');

        $this->assertSame('claude-sonnet-5-5', $this->claude->requests[0]['model']);
        $this->assertSame('claude-opus-5-5', $this->claude->requests[1]['model']);

        // Yeni soru yine varsayılan modelle başlar.
        $this->ask($conversation, 'Teşekkürler');
        $this->assertSame('claude-sonnet-5-5', $this->claude->requests[2]['model']);
    }

    public function test_model_chosen_by_user_is_kept_after_a_data_query(): void
    {
        $this->fakeDataSources();
        $this->fakeClaude()
            ->toolCalls([['name' => 'query_warehouse', 'input' => ['sql' => 'SELECT flight_no FROM ires_dim_flight_test LIMIT 5', 'purpose' => 'Seferler']]])
            ->text('Cevap.');

        $this->ask(Conversation::factory()->create(['user_id' => $this->signedIn->id, 'model' => 'claude-sonnet-5-5']), 'Seferler?');

        $this->assertSame(['claude-sonnet-5-5', 'claude-sonnet-5-5'], array_column($this->claude->requests, 'model'));
    }

    public function test_ninth_query_in_one_answer_is_refused(): void
    {
        $this->fakeDataSources();
        $calls = array_fill(0, 9, ['name' => 'query_warehouse', 'input' => ['sql' => 'SELECT flight_no FROM ires_dim_flight_test LIMIT 1', 'purpose' => 'Sefer']]);
        $this->fakeClaude()->toolCalls($calls)->text('Elimdekiyle cevap.');

        $this->ask(Conversation::factory()->create(['user_id' => $this->signedIn->id]), 'Çok sorgu');

        $results = collect($this->claude->requests[1]['messages'])->last()['content'];
        $this->assertCount(9, $results);
        $this->assertArrayNotHasKey('is_error', $results[7]);
        $this->assertTrue($results[8]['is_error']);
        $this->assertStringContainsString('sınırı (8) doldu', json_encode($results[8], JSON_UNESCAPED_UNICODE));
        $this->assertSame(8, AuditLog::query()->where('action', 'ai.query')->count());
    }

    public function test_admin_turns_data_access_on_and_off(): void
    {
        $this->getJson('/api/settings/ai')->assertJsonPath('data.ai_data_access', 'off')->assertJsonPath('data.ai_allow_employee_names', true);

        $this->putJson('/api/settings/ai', ['ai_data_access' => 'masked', 'ai_allow_employee_names' => false])
            ->assertOk()
            ->assertJsonPath('data.ai_data_access', 'masked')
            ->assertJsonPath('data.ai_allow_employee_names', false);

        $this->putJson('/api/settings/ai', ['ai_data_access' => 'open'])->assertStatus(422);
    }
}
