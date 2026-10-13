<?php

namespace Tests\Feature;

use App\Models\ConnectorCheck;
use App\Models\Execution;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\User;
use App\Services\Apm\ApmApi;
use App\Services\Settings\AppSettings;
use App\Services\SuccessFactors\SuccessFactors;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesDataSources;
use Tests\TestCase;

class HomeScreenTest extends TestCase
{
    use FakesDataSources, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDataSources(AppSettings::DATA_ACCESS_OFF);
        config(['kestra.secrets' => []]);
    }

    public function test_connector_status_comes_from_the_last_check_without_calling_external_systems(): void
    {
        ConnectorCheck::create(['connector' => 'ibs', 'ok' => true, 'checked_at' => now()->subMinutes(3)]);
        ConnectorCheck::create(['connector' => 'apm', 'ok' => true, 'checked_at' => now()->subHours(2)]);
        ConnectorCheck::create(['connector' => 'apm', 'ok' => false, 'error' => 'timeout', 'checked_at' => now()->subMinutes(2)]);
        ConnectorCheck::create(['connector' => 'successfactors', 'ok' => true, 'checked_at' => now()->subMinutes(40)]);
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/connectors/status')
            ->assertOk()
            ->assertJsonPath('data.0', ['key' => 'ibs', 'label' => 'IBS', 'abbr' => 'IB', 'status' => 'connected', 'detail' => null])
            ->assertJsonPath('data.1.status', 'attention')
            ->assertJsonPath('data.1.detail', 'Son başarılı kontrol 2 saat önce.')
            ->assertJsonPath('data.2.status', 'attention')
            ->assertJsonPath('data.3', ['key' => 'outlook', 'label' => 'Outlook', 'abbr' => 'OL', 'status' => 'not_configured', 'detail' => null])
            ->assertJsonPath('data.4.status', 'not_configured');

        Http::assertNothingSent();
    }

    public function test_unconfigured_source_is_not_configured_even_with_old_checks(): void
    {
        config(['apm.base_url' => '']);
        $this->app->forgetInstance(ApmApi::class);
        $this->app->singleton(ApmApi::class, fn () => new ApmApi(spec: [], kestraBaseUrl: '', grantType: 'client_credentials', allowWrites: false, readOnlyPosts: [], directBaseUrl: '', credentials: ['client_id' => null, 'client_secret' => null]));
        ConnectorCheck::create(['connector' => 'apm', 'ok' => true, 'checked_at' => now()]);

        $this->actingAs(User::factory()->create())->getJson('/api/connectors/status')->assertJsonPath('data.1.status', 'not_configured');
    }

    public function test_check_command_records_results(): void
    {
        $this->app->singleton(SuccessFactors::class, fn () => new SuccessFactors(catalog: [], baseUrl: 'https://sf-down.test/odata/v2/', username: 'u', password: 'p'));
        Http::fake(['sf-down.test/*' => Http::response(['error' => 'down'], 503)]);

        $this->artisan('connectors:check')->assertSuccessful();

        $this->assertTrue(ConnectorCheck::query()->where('connector', 'ibs')->sole()->ok);
        $this->assertTrue(ConnectorCheck::query()->where('connector', 'apm')->sole()->ok);
        $this->assertFalse(ConnectorCheck::query()->where('connector', 'successfactors')->sole()->ok);
        $this->assertSame(0, ConnectorCheck::query()->whereIn('connector', ['outlook', 'teams'])->count());
    }

    private function pausedRun(User $owner, string $id): Execution
    {
        $flow = Flow::factory()->create(['owner_id' => $owner->id, 'title' => "Akış {$id}"]);

        return Execution::create(['flow_id' => $flow->id, 'flow_version_id' => FlowVersion::factory()->for($flow)->create()->id, 'kestra_execution_id' => $id, 'state' => 'PAUSED', 'started_at' => now()->subMinutes(12)]);
    }

    public function test_home_shows_only_runs_the_user_can_act_on(): void
    {
        Http::fake();
        $ayse = User::factory()->create(['name' => 'Ayşe Yılmaz']);
        $mehmet = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->pausedRun($ayse, 'run-ayse');
        $this->pausedRun($mehmet, 'run-mehmet');

        $this->actingAs($ayse)->getJson('/api/home')
            ->assertOk()
            ->assertJsonPath('data.greeting_name', 'Ayşe')
            ->assertJsonPath('data.waiting_total', 1)
            ->assertJsonPath('data.waiting.0.title', 'Akış run-ayse onay bekliyor')
            ->assertJsonCount(2, 'data.activity');

        // Bekleyenler Inbox'tan gelir: her duraklama akış sahibinin kutusuna düşer, admin başkasınınkini görmez.
        $this->actingAs($admin)->getJson('/api/home')->assertJsonPath('data.waiting_total', 0);
    }

    public function test_home_is_empty_without_waiting_runs_and_hides_data_questions_when_data_access_is_off(): void
    {
        Http::fake();
        ConnectorCheck::create(['connector' => 'apm', 'ok' => true, 'checked_at' => now()]);

        $data = $this->actingAs(User::factory()->create())->getJson('/api/home')->assertOk()->json('data');

        $this->assertSame([], $data['waiting']);
        $this->assertSame([], $data['activity']);
        $this->assertNotEmpty($data['suggestions']);
        $this->assertNotContains('question', array_column($data['suggestions'], 'type'));

        app(AppSettings::class)->set(AppSettings::DATA_ACCESS, AppSettings::DATA_ACCESS_MASKED);
        $types = array_column($this->getJson('/api/home')->json('data.suggestions'), 'type');
        $this->assertContains('question', $types);
        $this->assertLessThanOrEqual(4, count($types));
    }

    public function test_demo_seeder_refuses_to_run_outside_local(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);
        (new DemoSeeder)->run();
    }
}
