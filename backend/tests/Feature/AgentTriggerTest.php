<?php

namespace Tests\Feature;

use App\Agents\FlightEvents;
use App\Jobs\RunAgentJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentTrigger;
use App\Models\AuditLog;
use App\Models\FlightWatch;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\Apm\ApmApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class AgentTriggerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    /** @var list<array{method: string, path: string, body: string}> */
    private array $kestra = [];

    /** @var array<string, true> Kestra'da var olan akışlar */
    private array $flows = [];

    private bool $kestraDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFlowEnvironment(secrets: ['FORTIS_INTERNAL_KEY']);
        config(['agents.internal_url' => 'https://fortis.test', 'agents.internal_key' => 'iç-anahtar-test']);
        Queue::fake();

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->kestra[] = ['method' => $request->method(), 'path' => $path, 'body' => $request->body()];

            if ($this->kestraDown) {
                return Http::response('', 503);
            }
            if (str_ends_with($path, '/flows/validate')) {
                return Http::response([['flow' => 'x']]);
            }
            if (preg_match('#/flows/(fortis\.agents)/([a-z0-9-]+)$#', $path, $m)) {
                $key = "{$m[1]}/{$m[2]}";

                return match ($request->method()) {
                    'GET' => isset($this->flows[$key]) ? Http::response(['id' => $m[2]]) : Http::response('', 404),
                    'DELETE' => tap(Http::response('', 204), fn () => $this->flows = array_diff_key($this->flows, [$key => true])),
                    default => Http::response(['id' => $m[2]]),
                };
            }
            if (str_ends_with($path, '/flows') && $request->method() === 'POST') {
                $flow = Yaml::parse($request->body());
                $this->flows["{$flow['namespace']}/{$flow['id']}"] = true;

                return Http::response(['id' => $flow['id']]);
            }

            return Http::response('', 404);
        });

        $this->owner = User::factory()->builder()->create();
        $this->actingAs($this->owner);
    }

    private function agent(string $status = Agent::STATUS_PILOT): Agent
    {
        return Agent::create(['slug' => 'sabah-ozeti', 'name' => 'Sabah Özeti', 'owner_id' => $this->owner->id, 'status' => $status, 'instructions' => 'x', 'tools' => [['name' => 'query_apm']], 'icon' => 'SO']);
    }

    /** @return array<string, mixed> Kestra'ya son yüklenen akış */
    private function uploadedFlow(): array
    {
        $upload = collect($this->kestra)->last(fn (array $r) => in_array($r['method'], ['POST', 'PUT'], true) && ! str_ends_with($r['path'], '/validate'));

        return Yaml::parse($upload['body']);
    }

    public function test_schedule_trigger_uploads_a_validated_flow_that_calls_the_internal_endpoint(): void
    {
        $agent = $this->agent();

        $this->postJson("/api/agents/{$agent->id}/triggers", ['type' => 'schedule', 'config' => ['cron' => '0 6 * * 1-5'], 'input_template' => 'Dünkü rötarları özetle.'])
            ->assertCreated()
            ->assertJsonPath('data.kestra_flow_id', fn ($id) => str_starts_with($id, 'agent-sabah-ozeti-'))
            ->assertJsonPath('data.sync_error', null);

        $trigger = AgentTrigger::query()->sole();
        $flow = $this->uploadedFlow();
        $this->assertSame('fortis.agents', $flow['namespace']);
        $this->assertSame("agent-sabah-ozeti-{$trigger->id}", $flow['id']);
        $this->assertFalse($flow['disabled']);
        $this->assertSame(['id' => 'schedule', 'type' => 'io.kestra.plugin.core.trigger.Schedule', 'cron' => '0 6 * * 1-5', 'timezone' => 'Europe/Istanbul'], $flow['triggers'][0]);
        $task = $flow['tasks'][0];
        $this->assertSame("https://fortis.test/api/internal/agents/{$agent->id}/runs", $task['uri']);
        $this->assertSame('POST', $task['method']);
        $this->assertSame("{{ secret('FORTIS_INTERNAL_KEY') }}", $task['headers']['X-Fortis-Key']);
        $this->assertSame(['trigger_id' => $trigger->id], json_decode($task['body'], true));
        // Kestra'nın kendi doğrulamasından da geçti.
        $this->assertTrue(collect($this->kestra)->contains(fn (array $r) => str_ends_with($r['path'], '/flows/validate')));
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.trigger.sync', 'result' => AuditLog::RESULT_ALLOWED]);
    }

    public function test_paused_agent_disables_the_flow_and_deleting_the_trigger_deletes_it(): void
    {
        $agent = $this->agent();
        $id = $this->postJson("/api/agents/{$agent->id}/triggers", ['type' => 'schedule', 'config' => ['cron' => '0 6 * * *']])->json('data.id');

        $agent->update(['status' => Agent::STATUS_PAUSED]);
        $this->assertSame('PUT', collect($this->kestra)->last()['method']);
        $this->assertTrue($this->uploadedFlow()['disabled']);

        $agent->update(['status' => Agent::STATUS_LIVE]);
        $this->assertFalse($this->uploadedFlow()['disabled']);

        $this->patchJson("/api/agent-triggers/{$id}", ['enabled' => false])->assertOk();
        $this->assertTrue($this->uploadedFlow()['disabled']);

        $this->deleteJson("/api/agent-triggers/{$id}")->assertNoContent();
        $this->assertSame('DELETE', collect($this->kestra)->last()['method']);
        $this->assertSame([], $this->flows);
        $this->assertDatabaseMissing('agent_triggers', ['id' => $id]);
    }

    public function test_kestra_down_keeps_the_trigger_and_warns_the_owner_once_until_resynced(): void
    {
        $agent = $this->agent();
        $this->kestraDown = true;

        $id = $this->postJson("/api/agents/{$agent->id}/triggers", ['type' => 'schedule', 'config' => ['cron' => '0 6 * * *']])
            ->assertCreated()
            ->assertJsonPath('data.kestra_flow_id', null)
            ->json('data.id');
        $this->assertNotNull(AgentTrigger::find($id)->sync_error);

        $alert = InboxItem::query()->sole();
        $this->assertSame([$this->owner->id, InboxItem::ALERT, 'agent_trigger'], [$alert->user_id, $alert->type, $alert->subject_type]);
        $this->assertStringContainsString("Kestra'ya aktarılamadı", $alert->title);

        $this->artisan('fortis:agent:sync-triggers')->assertFailed();
        $this->assertSame(1, InboxItem::count());

        // Kestra kapalıyken tetikleyici silinemez (akış Kestra'da kalmasın).
        $this->deleteJson("/api/agent-triggers/{$id}")->assertNoContent(); // henüz yüklenmemişti; silinecek akış yok
        $id = $this->postJson("/api/agents/{$agent->id}/triggers", ['type' => 'schedule', 'config' => ['cron' => '0 7 * * *']])->json('data.id');

        $this->kestraDown = false;
        $this->artisan('fortis:agent:sync-triggers')->assertSuccessful();
        $this->assertNull(AgentTrigger::find($id)->sync_error);
        $this->assertSame(0, InboxItem::query()->where('status', InboxItem::OPEN)->count());

        $this->kestraDown = true;
        $this->deleteJson("/api/agent-triggers/{$id}")->assertStatus(502);
        $this->assertDatabaseHas('agent_triggers', ['id' => $id]);
    }

    public function test_trigger_validation_and_permissions(): void
    {
        $agent = $this->agent();

        $this->postJson("/api/agents/{$agent->id}/triggers", ['type' => 'schedule', 'config' => ['cron' => 'her sabah']])->assertUnprocessable()->assertJsonValidationErrors('config.cron');
        $this->postJson("/api/agents/{$agent->id}/triggers", ['type' => 'event', 'config' => ['event' => 'flight.exploded']])->assertUnprocessable();
        $this->postJson("/api/agents/{$agent->id}/triggers", ['type' => 'webhook', 'config' => []])->assertUnprocessable();
        $this->postJson("/api/agents/{$agent->id}/triggers", ['type' => 'event', 'config' => ['event' => 'flight.delayed']])
            ->assertCreated()->assertJsonPath('data.config', ['event' => 'flight.delayed', 'min_delay_minutes' => 60]);

        $this->actingAs(User::factory()->builder()->create())->getJson("/api/agents/{$agent->id}/triggers")->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->getJson("/api/agents/{$agent->id}/triggers")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_internal_endpoint_requires_the_key_an_active_agent_and_no_running_run(): void
    {
        $agent = $this->agent(Agent::STATUS_DRAFT);
        $trigger = $agent->triggers()->create(['type' => 'schedule', 'config' => ['cron' => '0 6 * * *'], 'input_template' => 'Özetle.']);
        $url = "/api/internal/agents/{$agent->id}/runs";
        auth()->forgetGuards();

        $this->postJson($url, ['trigger_id' => $trigger->id])->assertUnauthorized();
        $this->postJson($url, ['trigger_id' => $trigger->id], ['X-Fortis-Key' => 'yanlış'])->assertUnauthorized();
        config(['agents.internal_key' => null]);
        $this->postJson($url, ['trigger_id' => $trigger->id], ['X-Fortis-Key' => ''])->assertUnauthorized();
        config(['agents.internal_key' => 'iç-anahtar-test']);
        $key = ['X-Fortis-Key' => 'iç-anahtar-test'];

        $this->postJson($url, ['trigger_id' => $trigger->id], $key)->assertConflict()->assertJsonPath('reason', 'inactive');

        Agent::withoutEvents(fn () => $agent->update(['status' => Agent::STATUS_LIVE]));
        $this->postJson($url, ['trigger_id' => 999], $key)->assertNotFound();
        $runId = $this->postJson($url, ['trigger_id' => $trigger->id], $key)->assertAccepted()->json('data.run_id');

        $run = AgentRun::find($runId);
        $this->assertSame([$trigger->id, null, 'Özetle.'], [$run->trigger_id, $run->triggered_by_user_id, $run->input]);
        Queue::assertPushed(RunAgentJob::class, 1);

        // Önceki çalışma sürerken ikinci tetikleme açılmaz ve kaydedilir.
        $this->postJson($url, ['trigger_id' => $trigger->id], $key)->assertConflict()->assertJsonPath('reason', 'busy');
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.run.skipped', 'result' => AuditLog::RESULT_BLOCKED]);
        Queue::assertPushed(RunAgentJob::class, 1);

        $run->update(['status' => AgentRun::SUCCEEDED]);
        $this->postJson($url, ['trigger_id' => $trigger->id], $key)->assertAccepted();
    }

    /** @param  list<array{id: string, delay: int, cancelled?: bool}>  $legs */
    private function apmReturns(array $legs): void
    {
        $content = array_map(fn (array $l) => [
            'legId' => $l['id'],
            'flightNumberInfo' => ['designator' => 'FH', 'flightNumber' => substr($l['id'], 1)],
            'airportsInfo' => ['scheduledDepartureAirportCode' => 'AYT', 'scheduledArrivalAirportCode' => 'HAJ'],
            'flightTimes' => ['scheduledTimeDeparture' => '2026-10-13T08:00:00Z'],
            'delaysInfo' => $l['delay'] > 0 ? ['departureDelay1' => ['delayCode' => '93', 'delayReason' => 'Reactionary', 'delayDurationInMinutes' => $l['delay']]] : [],
            'statusInfo' => ['cancelled' => $l['cancelled'] ?? false],
        ], $legs);

        $apm = Mockery::mock(ApmApi::class);
        $apm->shouldReceive('read')->andReturnUsing(fn (string $m, string $path) => [
            'operation_path' => '/api/legs',
            'response' => str_contains($path, 'page=0') ? ['legs' => ['content' => $content], 'totalNumberOfItems' => count($content)] : ['legs' => ['content' => []], 'totalNumberOfItems' => count($content)],
        ]);
        $this->app->instance(ApmApi::class, $apm);
        $this->app->forgetInstance(FlightEvents::class);
    }

    public function test_flight_delayed_event_starts_a_run_only_when_the_threshold_is_crossed(): void
    {
        $agent = $this->agent(Agent::STATUS_LIVE);
        $delayed = $agent->triggers()->create(['type' => 'event', 'config' => ['event' => 'flight.delayed', 'min_delay_minutes' => 60], 'input_template' => 'Rötarı incele: {{event}}']);
        $cancelled = $agent->triggers()->create(['type' => 'event', 'config' => ['event' => 'flight.cancelled']]);

        // İlk tarama: sadece kayıt, olay yok (zaten rötarlı uçuş için çalışma açılmaz).
        $this->apmReturns([['id' => 'F101', 'delay' => 20], ['id' => 'F102', 'delay' => 90], ['id' => 'F103', 'delay' => 0]]);
        $this->artisan('fortis:agent:watch-flights')->assertSuccessful();
        $this->assertSame(3, FlightWatch::count());
        $this->assertSame(0, AgentRun::count());

        // F101 20→45: eşik altı, çalışma yok. F102 90→120: eşik zaten geçilmişti, çalışma yok. F103 iptal.
        $this->apmReturns([['id' => 'F101', 'delay' => 45], ['id' => 'F102', 'delay' => 120], ['id' => 'F103', 'delay' => 0, 'cancelled' => true]]);
        $this->artisan('fortis:agent:watch-flights')->assertSuccessful();
        $this->assertSame(0, $delayed->runs()->count());
        $this->assertSame(1, $cancelled->runs()->count());

        // F101 45→75: eşiği bu taramada geçti.
        $this->apmReturns([['id' => 'F101', 'delay' => 75], ['id' => 'F102', 'delay' => 120], ['id' => 'F103', 'delay' => 0, 'cancelled' => true]]);
        $this->artisan('fortis:agent:watch-flights')->assertSuccessful();
        $run = $delayed->runs()->sole();
        $this->assertStringContainsString('Rötarı incele: Olay: flight.delayed', $run->input);
        $this->assertStringContainsString('FH101 AYT→HAJ', $run->input);
        $this->assertStringContainsString('75 dk (önceki tarama: 45 dk)', $run->input);
        $this->assertStringContainsString('Rötar kodları: 93', $run->input);
        $this->assertSame(1, $cancelled->runs()->count());
    }

    public function test_event_trigger_is_rate_limited_and_apm_outage_warns_the_owner(): void
    {
        config(['agents.max_event_runs_per_hour' => 1]);
        $agent = $this->agent(Agent::STATUS_LIVE);
        $trigger = $agent->triggers()->create(['type' => 'event', 'config' => ['event' => 'flight.cancelled']]);
        FlightWatch::create(['leg_id' => 'F1', 'seen_at' => now()]);
        FlightWatch::create(['leg_id' => 'F2', 'seen_at' => now()]);

        $this->apmReturns([['id' => 'F1', 'delay' => 0, 'cancelled' => true], ['id' => 'F2', 'delay' => 0, 'cancelled' => true]]);
        $this->artisan('fortis:agent:watch-flights')->expectsOutputToContain('1 çalışma başlatıldı, 1 atlandı')->assertSuccessful();
        $this->assertSame(1, $trigger->runs()->count());

        $apm = Mockery::mock(ApmApi::class);
        $apm->shouldReceive('read')->andThrow(new \RuntimeException('APM\'ye şu an ulaşılamıyor.'));
        $this->app->instance(ApmApi::class, $apm);
        $this->app->forgetInstance(FlightEvents::class);
        $this->artisan('fortis:agent:watch-flights')->assertFailed();
        $this->artisan('fortis:agent:watch-flights')->assertFailed();
        $this->assertSame(1, InboxItem::query()->where('type', InboxItem::ALERT)->where('subject_type', 'agent_trigger')->count());

        // Taslak agentın tetikleyicisi için APM hiç okunmaz.
        $agent->update(['status' => Agent::STATUS_DRAFT]);
        $this->artisan('fortis:agent:watch-flights')->expectsOutputToContain('Açık olay tetikleyicisi yok')->assertSuccessful();
    }
}
