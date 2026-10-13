<?php

namespace Tests\Feature;

use App\Ai\ModelRouter;
use App\Models\AiCall;
use App\Models\Artifact;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Artifacts\ArtifactException;
use App\Services\Artifacts\PageService;
use App\Services\Claude\ClaudeChat;
use App\Services\Usage\UsageExplorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\FakesDataSources;
use Tests\TestCase;

/**
 * Sohbette HTML sayfa (H1): create_page, kaynak ve veri kuralları, temizleme, sınırlar, uyarı, API ve kayıt.
 */
class PageTest extends TestCase
{
    use FakesDataSources, RefreshDatabase;

    private const QUERY = 'q_sayfa00001';

    private const SQL = 'SELECT flight_no, dep, seats_sold, capacity, remark, contact_email FROM ires_dim_flight_test LIMIT 10';

    private const HTML = '<h1>Doluluk panosu</h1><div id="c"></div><div id="t"></div><script>const rows = fortis.data("q:q_sayfa00001"); fortis.chart("#c", {type: "bar", data: {labels: rows.map(r => r.flight_no), datasets: [{label: "Satılan", data: rows.map(r => r.seats_sold)}]}}); fortis.table("#t", rows);</script>';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDataSources();
        Str::createRandomStringsUsing(fn () => 'sayfa00001');
        $this->user = User::factory()->admin()->create();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Str::createRandomStringsNormally();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $page */
    private function ask(array $page, string $question = 'Seferlerin doluluk panosunu hazırla', ?Conversation $conversation = null, bool $query = true): Conversation
    {
        $claude = $this->fakeClaude();
        if ($query) {
            $claude->toolCalls([['name' => 'query_warehouse', 'input' => ['sql' => self::SQL, 'purpose' => 'Sefer doluluğu']]]);
        }
        $claude->toolCalls([['name' => 'create_page', 'input' => $page]])->text('Pano hazır [^q:'.self::QUERY.'].');
        $conversation ??= Conversation::factory()->create(['user_id' => $this->user->id]);
        $this->post("/api/conversations/{$conversation->id}/messages", ['content' => $question], ['Accept' => 'text/event-stream'])->assertOk()->streamedContent();
        $this->app->forgetInstance(ClaudeChat::class);

        return $conversation;
    }

    private static function page(array $overrides = []): array
    {
        return array_merge(['title' => 'Doluluk panosu', 'description' => 'Seferlere göre satılan koltuk', 'sources' => ['q:'.self::QUERY], 'html' => self::HTML], $overrides);
    }

    /** @return array<string, mixed>|null Son create_page aracının sonucu (Claude'a giden) */
    private function toolResult(Conversation $conversation): ?array
    {
        $block = collect($conversation->messages()->where('role', 'user')->get()->flatMap(fn (Message $m) => is_array($m->content) ? $m->content : []))
            ->where('type', 'tool_result')->last();

        return $block === null ? null : json_decode($block['content'], true);
    }

    public function test_page_is_saved_with_masked_query_data_card_audit_and_page_purpose(): void
    {
        $conversation = $this->ask(self::page());

        $artifact = Artifact::query()->where('kind', Artifact::PAGE)->sole();
        $version = $artifact->versions()->sole();
        $this->assertSame(['q:'.self::QUERY], $version->sources);
        $data = $version->data['q:'.self::QUERY];
        $this->assertSame(['flight_no', 'dep', 'seats_sold', 'capacity', 'remark', 'contact_email'], $data['columns']);
        $this->assertSame(['FH1256', 'AYT', 170, 189], array_slice($data['rows'][0], 0, 4));
        $this->assertSame('Sefer doluluğu', $data['purpose']);
        $this->assertNotNull($version->data_at);
        $this->assertNull($version->warning);
        // Kişisel veri sayfa verisine girmiyor (maskeli satırlar).
        $json = json_encode($version->data, JSON_UNESCAPED_UNICODE);
        foreach (['K7X2PQ', '0532', 'veli.yolcu@ornek.com', 'Veli Yolcu'] as $personal) {
            $this->assertStringNotContainsString($personal, $json);
        }

        $card = collect(Message::query()->get()->flatMap(fn (Message $m) => $m->meta['flow_cards'] ?? []))->firstWhere('type', 'artifact');
        $this->assertSame(['page', 'Doluluk panosu', 'Seferlere göre satılan koltuk', ['q:'.self::QUERY]], [$card['kind'], $card['title'], $card['description'], $card['sources']]);
        $this->assertTrue(AuditLog::query()->where('action', 'artifact.create')->where('meta->artifact_id', $artifact->id)->exists());
        $this->assertSame(1, AiCall::query()->where('conversation_id', $conversation->id)->where('purpose', AiCall::PURPOSE_PAGE)->count());

        // API: sahibi veriyle görür (görüntüleme kayda geçer); başkası göremez; indirme kaydı.
        $this->getJson("/api/artifacts/{$artifact->id}")->assertOk()
            ->assertJsonPath('data.kind', 'page')
            ->assertJsonPath('data.sources.0', 'q:'.self::QUERY)
            ->assertJsonPath('data.data.q:'.self::QUERY.'.rows.1.0', 'FH0812');
        $this->assertTrue(AuditLog::query()->where('action', 'artifact.view')->exists());
        $this->postJson("/api/artifacts/{$artifact->id}/downloaded?version=1")->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'artifact.download')->where('meta->version', 1)->exists());
        $this->actingAs(User::factory()->create())->getJson("/api/artifacts/{$artifact->id}")->assertNotFound();
    }

    public function test_source_that_did_not_run_in_this_answer_is_refused(): void
    {
        $conversation = $this->ask(self::page(['sources' => ['q:q_uydurma999']]));

        $this->assertSame(0, Artifact::query()->count());
        $this->assertStringContainsString('q:q_uydurma999', $this->toolResult($conversation)['INVALID_INPUT'] ?? json_encode($this->toolResult($conversation)));

        // Önceki cevabın sorgusu da "bu cevap" değil: yeni sayfa için yeniden çalıştırılmalı.
        $this->ask(self::page(), conversation: $conversation, query: false);
        $this->assertSame(0, Artifact::query()->count());
    }

    public function test_revision_keeps_previous_sources_and_creates_a_new_version(): void
    {
        $conversation = $this->ask(self::page());
        $artifact = Artifact::query()->sole();

        $html = str_replace('</h1>', '</h1><select id="dep"></select>', self::HTML);
        $this->ask(self::page(['artifact_id' => $artifact->id, 'html' => $html]), 'Bu panoya meydan filtresi ekle', $conversation, query: false);

        $artifact->refresh();
        $this->assertSame(2, $artifact->latest_version);
        $v2 = $artifact->versions()->where('number', 2)->sole();
        $this->assertStringContainsString('<select id="dep">', $v2->content);
        $this->assertSame($artifact->versions()->where('number', 1)->sole()->data, $v2->data);
    }

    public function test_html_is_sanitized_and_unverified_numbers_are_flagged(): void
    {
        $dirty = '<!doctype html><html><head><meta http-equiv="Content-Security-Policy" content="default-src *"><base href="https://evil.example/"><link rel="stylesheet" href="https://cdn.example/x.css"><link rel="stylesheet" href="/vendor/x/y.css"></head><body>'
            .'<script src="https://cdn.jsdelivr.net/npm/chart.js"></script><script src="/vendor/chartjs-4.5.1/chart.umd.min.js"></script><script src="/vendor/../api/auth/me"></script>'
            .'<iframe src="https://evil.example"></iframe><object data="x"></object><p>Merhaba</p><script>fortis.data("q:x")</script></body></html>';
        $clean = PageService::sanitize($dirty);

        foreach (['<!doctype', '<html', '<head', '<body', '<meta', '<base', 'cdn.example', 'cdn.jsdelivr', 'evil.example', '<iframe', '<object', '../api'] as $gone) {
            $this->assertStringNotContainsStringIgnoringCase($gone, $clean, $gone);
        }
        $this->assertStringContainsString('<script src="/vendor/chartjs-4.5.1/chart.umd.min.js"></script>', $clean);
        $this->assertStringContainsString('<link rel="stylesheet" href="/vendor/x/y.css">', $clean);
        $this->assertStringContainsString('<p>Merhaba</p><script>fortis.data("q:x")</script>', $clean);

        $hardcoded = '<table>'.implode('', array_map(fn ($i) => "<tr><td>FH{$i}</td><td>".(100 + $i).'</td></tr>', range(1, 25))).'</table>';
        $this->assertGreaterThan(20, PageService::numberCount($hardcoded));
        $this->ask(self::page(['html' => $hardcoded]));
        $this->assertSame(PageService::WARNING_UNVERIFIED, Artifact::query()->sole()->versions()->sole()->warning);
    }

    public function test_size_limits(): void
    {
        $conversation = $this->ask(self::page(['html' => '<p>'.str_repeat('a', PageService::MAX_HTML_BYTES).'</p>']));
        $this->assertSame(0, Artifact::query()->count());
        $this->assertStringContainsString('200 KB', json_encode($this->toolResult($conversation), JSON_UNESCAPED_UNICODE));

        // 2 MB veri: büyük bir sorgu sonucu (doğrudan servis).
        $big = ['source' => 'IBS', 'query_id' => 'q_buyuk', 'columns' => ['x'], 'rows' => array_fill(0, 30000, [str_repeat('b', 80)])];
        $conversation->messages()->create(['role' => 'user', 'text' => '', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't', 'content' => json_encode($big)]]]);
        $this->expectException(ArtifactException::class);
        $this->expectExceptionMessage('2 MB');
        app(PageService::class)->save($conversation, self::page(['sources' => ['q:q_buyuk']]), ['q_buyuk' => ['source' => 'IBS', 'purpose' => 'x', 'at' => now()->toIso8601String()]]);
    }

    public function test_page_requests_use_page_model_and_usage_shows_page_type(): void
    {
        config(['ai.page_model' => 'claude-sonnet-5-5', 'ai.data_question_model' => 'claude-opus-5-5']);
        $conversation = $this->ask(self::page());
        $this->assertSame('claude-sonnet-5-5', $this->claude->requests[1]['model']);

        $chat = Conversation::factory()->create(['user_id' => $this->user->id]);
        $chat->messages()->create(['role' => 'user', 'text' => 'Dün kaç sefer vardı?', 'content' => [['type' => 'text', 'text' => 'Dün kaç sefer vardı?']]]);
        $this->assertSame('claude-opus-5-5', app(ModelRouter::class)->forTurn($chat, true)['model']);
        $this->assertTrue(ModelRouter::wantsPage($conversation));

        $types = collect(app(UsageExplorer::class)->report([])['by_type'])->pluck('type');
        $this->assertContains('page', $types);
    }
}
