<?php

namespace Tests\Feature;

use App\Agents\AgentSession;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\ConnectorCheck;
use App\Models\Conversation;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\Claude\FlowTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesDataSources;
use Tests\TestCase;

class ConnectorsTest extends TestCase
{
    use FakesDataSources, RefreshDatabase;

    private User $admin;

    /** Bilinen gizli değerler: hiçbir yanıtta geçmemeli. */
    private const SECRETS = [
        'apm.client_secret' => 'apm-client-secret-7781',
        'apm.password' => 'apm-sifre-5512',
        'successfactors.password' => 'sf-sifre-9921',
        'database.connections.ibs.password' => 'ibs-db-sifre-3344',
        'mail.mailers.smtp.password' => 'smtp-sifre-6677',
        'agents.teams_channels.occ-operasyon' => 'https://freebird.webhook.office.com/webhookb2/gizli-yol-8899',
        'services.anthropic.api_key' => 'sk-ant-api03-gercekmis-gibi-1122',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDataSources();
        config(['apm.base_url' => 'https://apm.test', 'apm.client_id' => 'fortis-client']);
        foreach (self::SECRETS as $key => $value) {
            config([$key => $value]);
        }
        $this->admin = User::factory()->admin()->create();
    }

    private function agent(User $owner, array $tools, string $status = Agent::STATUS_LIVE, string $name = 'Rötar Özeti'): Agent
    {
        return Agent::create(['slug' => 'a-'.uniqid(), 'name' => $name, 'owner_id' => $owner->id, 'status' => $status, 'instructions' => 'x', 'tools' => array_map(fn ($t) => ['name' => $t], $tools), 'icon' => 'AG']);
    }

    /** @return list<string> */
    private function chatTools(): array
    {
        $tools = app(FlowTools::class);
        $tools->startTurn(Conversation::create(['user_id' => $this->admin->id]));

        return array_column($tools->definitions(), 'name');
    }

    public function test_no_secret_value_appears_in_list_or_detail(): void
    {
        // Kontrol hatasında bile (ör. adres içinde şifre) temizlenmiş görünür.
        ConnectorCheck::create(['connector' => 'apm', 'ok' => false, 'error' => 'RuntimeException: https://fortis:'.self::SECRETS['apm.password'].'@apm.test token '.self::SECRETS['apm.client_secret'], 'duration_ms' => 120, 'checked_at' => now()]);

        $responses = [$this->actingAs($this->admin)->getJson('/api/connectors')->assertOk()->getContent()];
        foreach (['apm', 'ibs', 'successfactors', 'email', 'teams', 'outlook'] as $key) {
            $responses[] = $this->getJson("/api/connectors/{$key}")->assertOk()->getContent();
        }
        $responses[] = $this->getJson('/api/connectors/status')->getContent();

        foreach ($responses as $body) {
            foreach (self::SECRETS as $secret) {
                $this->assertStringNotContainsString($secret, $body);
                $this->assertStringNotContainsString(substr($secret, -4), json_encode(json_decode($body, true)['data']['env'] ?? []));
            }
        }

        $apm = $this->getJson('/api/connectors/apm')->json('data');
        $this->assertStringContainsString('[gizli]', $apm['last_error']['message']);
        $this->assertContains(['name' => 'APM_CLIENT_SECRET', 'defined' => true], $apm['env']);
        $this->assertContains(['name' => 'APM_BASE_URL', 'defined' => true], $apm['env']);
        // Ortam değişkeni adları sadece admin'e.
        $this->actingAs(User::factory()->create())->getJson('/api/connectors/apm')->assertOk()->assertJsonPath('data.env', null);
    }

    public function test_list_shows_statuses_scopes_and_setup_cards(): void
    {
        ConnectorCheck::create(['connector' => 'apm', 'ok' => true, 'duration_ms' => 90, 'checked_at' => now()]);

        $data = collect($this->actingAs(User::factory()->create())->getJson('/api/connectors')->assertOk()->json('data'))->keyBy('key');

        $this->assertSame('connected', $data['apm']['status']);
        $this->assertSame(['read', 'events'], $data['apm']['scopes']);
        $this->assertSame(['read'], $data['successfactors']['scopes']);
        $this->assertSame(['write·approval'], $data['email']['scopes']);
        $this->assertSame(['write·approval'], $data['teams']['scopes']);
        $this->assertSame('not_configured', $data['outlook']['status']);
        $this->assertSame('setup', $data['airrm']['category']);
        $this->assertNotEmpty($this->getJson('/api/connectors/airrm')->json('data.needs'));
        // Yerelde MAIL_MAILER=log: e-posta "kurulum gerekli", Connected görünmez.
        config(['mail.default' => 'log']);
        $this->assertSame('not_configured', collect($this->getJson('/api/connectors')->json('data'))->firstWhere('key', 'email')['status']);
    }

    public function test_only_admin_disables_with_a_reason_and_checks(): void
    {
        $user = User::factory()->builder()->create();
        $this->actingAs($user)->postJson('/api/connectors/apm/disable', ['reason' => 'Bakım'])->assertForbidden();
        $this->postJson('/api/connectors/apm/check')->assertForbidden();
        $this->actingAs($this->admin)->postJson('/api/connectors/apm/disable', [])->assertUnprocessable();
        $this->postJson('/api/connectors/nope/disable', ['reason' => 'x x x'])->assertNotFound();
    }

    public function test_disabling_a_source_removes_it_from_chat_and_agents_and_warns_owners_once(): void
    {
        $this->assertContains('query_apm', $this->chatTools());
        $ayse = User::factory()->builder()->create();
        $mehmet = User::factory()->builder()->create();
        $a1 = $this->agent($ayse, ['query_apm', 'send_email'], name: 'Sabah Özeti');
        $this->agent($ayse, ['describe_apm'], Agent::STATUS_DRAFT, 'Taslak');
        $this->agent($mehmet, ['query_apm'], name: 'Uzun Rötar');
        $this->agent($mehmet, ['query_warehouse'], name: 'Doluluk');
        Agent::create(['slug' => 'sablon', 'name' => 'Şablon', 'status' => Agent::STATUS_TEMPLATE, 'instructions' => 'x', 'tools' => [['name' => 'query_apm']], 'icon' => 'ŞB']);

        $detail = $this->actingAs($this->admin)->getJson('/api/connectors/apm')->json('data');
        $this->assertSame(3, $detail['affected_agents']);

        $this->postJson('/api/connectors/apm/disable', ['reason' => 'APM bakımda'])->assertOk()
            ->assertJsonPath('data.status', 'disabled')->assertJsonPath('data.disabled.reason', 'APM bakımda');
        $this->postJson('/api/connectors/apm/disable', ['reason' => 'tekrar'])->assertOk();

        // Sohbette APM araçları yok; çağrılırsa reddedilir.
        $chat = $this->chatTools();
        $this->assertNotContains('query_apm', $chat);
        $this->assertNotContains('describe_apm', $chat);
        $this->assertContains('query_warehouse', $chat);
        $tools = app(FlowTools::class);
        $tools->startTurn(Conversation::create(['user_id' => $this->admin->id]));
        $result = $tools->execute('query_apm', ['path' => '/api/legs', 'purpose' => 'x']);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('şirket genelinde kapatıldı', $result->content);
        $this->assertSame('apm', $this->getJson('/api/connectors/status')->json('data.1.key'));
        $this->assertSame('disabled', $this->getJson('/api/connectors/status')->json('data.1.status'));

        // Agent'ta da: araç listesinde yok, çağrı reddedilir ve kayda geçer.
        $run = $a1->runs()->create(['status' => AgentRun::RUNNING, 'input' => 'x', 'conversation_id' => Conversation::create(['kind' => 'agent', 'user_id' => $ayse->id])->id]);
        $agentTools = app(FlowTools::class);
        $agentTools->useAgent(new AgentSession($a1, $run));
        $agentTools->startTurn($run->conversation);
        $this->assertSame(['ask_user', 'send_email'], collect($agentTools->definitions())->pluck('name')->sort()->values()->all());
        $this->assertTrue($agentTools->execute('query_apm', ['path' => '/api/legs', 'purpose' => 'x'])->isError);
        $this->assertTrue(AuditLog::query()->where('action', 'connector.tool_blocked')->where('actor_type', AuditLog::ACTOR_AGENT)->exists());

        // Sahip başına tek uyarı; şablon ve APM kullanmayan agent yok.
        $this->assertSame(1, InboxItem::query()->where('user_id', $ayse->id)->where('subject_type', 'connector')->count());
        $this->assertStringContainsString('Sabah Özeti, Taslak', InboxItem::query()->where('user_id', $ayse->id)->sole()->body);
        $this->assertSame(1, InboxItem::query()->where('user_id', $mehmet->id)->count());
        $log = AuditLog::query()->where('action', 'connector.disable')->sole();
        $this->assertSame([['enabled' => true], ['enabled' => false], 'APM bakımda'], [$log->meta['before'], $log->meta['after'], $log->meta['reason']]);

        // Açınca araçlar geri gelir, uyarılar kapanır.
        $this->postJson('/api/connectors/apm/enable')->assertOk()->assertJsonPath('data.enabled', true);
        $this->assertContains('query_apm', $this->chatTools());
        $this->assertSame(0, InboxItem::query()->where('subject_type', 'connector')->where('status', InboxItem::OPEN)->count());
        $this->assertTrue(AuditLog::query()->where('action', 'connector.enable')->exists());
    }

    public function test_check_now_is_rate_limited(): void
    {
        Http::fake(['apm.test/*' => Http::response(['access_token' => 'tok'])]);
        $this->actingAs($this->admin);

        $this->postJson('/api/connectors/apm/check')->assertOk()->assertJsonPath('data.status', 'connected');
        $this->postJson('/api/connectors/apm/check')->assertStatus(429);
        $this->postJson('/api/connectors/outlook/check')->assertUnprocessable();
        $this->assertSame(1, ConnectorCheck::query()->where('connector', 'apm')->count());
        $this->assertTrue(AuditLog::query()->where('action', 'connector.check')->exists());
    }
}
