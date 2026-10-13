<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuditLogScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['name' => 'Kerem']);
    }

    private function log(string $action, string $result = AuditLog::RESULT_ALLOWED, array $meta = [], ?User $user = null, ?string $actorType = null, ?string $atTr = null): AuditLog
    {
        $log = Audit::record($action, result: $result, meta: $meta, user: $user, actorType: $actorType, actorLabel: $actorType === AuditLog::ACTOR_AGENT ? 'Sabah Özeti' : null);
        if ($atTr !== null) {
            AuditLog::query()->whereKey($log->id)->toBase()->update(['created_at' => CarbonImmutable::parse($atTr, 'Europe/Istanbul')->utc()]);
        }

        return $log;
    }

    public function test_filters_search_and_turkey_day_range(): void
    {
        $selin = User::factory()->create(['name' => 'Selin']);
        $this->log('ai.query', meta: ['tool' => 'query_apm', 'purpose' => 'rötar özeti'], user: $selin, atTr: '2026-10-12 00:30');
        $this->log('agent.run.start', user: $selin, actorType: AuditLog::ACTOR_AGENT, atTr: '2026-10-12 08:00');
        $this->log('agent.tool_blocked', AuditLog::RESULT_BLOCKED, user: $selin, actorType: AuditLog::ACTOR_AGENT, atTr: '2026-10-12 09:00');
        $this->log('auth.login_failed', AuditLog::RESULT_FAILED, atTr: '2026-10-11 23:59');

        $this->actingAs(User::factory()->builder()->create())->getJson('/api/audit-logs')->assertForbidden();
        $this->actingAs($this->admin);

        $ids = fn (string $query) => collect($this->getJson('/api/audit-logs?'.$query)->assertOk()->json('data'))->pluck('action')->all();
        $this->assertSame(['agent.tool_blocked', 'agent.run.start'], $ids('action=agent.*'));
        $this->assertSame(['agent.tool_blocked'], $ids('result=blocked'));
        $this->assertSame(['agent.tool_blocked', 'agent.run.start'], $ids('actor=agent'));
        $this->assertSame(['auth.login_failed'], $ids('actor=system'));
        $this->assertSame(['ai.query'], $ids('q=rötar'));
        $this->assertSame(['agent.tool_blocked', 'agent.run.start', 'ai.query'], $ids('from=2026-10-12&to=2026-10-12'));
        $this->assertSame(['agent.tool_blocked', 'agent.run.start', 'ai.query'], $ids("user_id={$selin->id}"));
        $this->getJson('/api/audit-logs?action=agent.%25')->assertUnprocessable();

        $row = $this->getJson('/api/audit-logs?actor=system')->json('data.0');
        $this->assertSame(['id', 'at', 'actor_type', 'actor', 'action', 'subject', 'system', 'result', 'cost_usd', 'meta'], array_keys($row));
        $this->assertSame('Sistem', $row['actor']);
    }

    public function test_cursor_pagination_and_details_are_scrubbed_but_query_text_is_kept_for_admin(): void
    {
        config(['apm.password' => 'apm-sifre-5512']);
        for ($i = 0; $i < 55; $i++) {
            $this->log('ai.query', meta: ['n' => $i]);
        }
        $this->log('ai.query', meta: ['tool' => 'query_warehouse', 'query' => ['sql' => 'SELECT 1'], 'error' => 'https://u:apm-sifre-5512@apm.test']);

        $first = $this->actingAs($this->admin)->getJson('/api/audit-logs')->assertOk();
        $this->assertCount(50, $first->json('data'));
        $this->assertSame('SELECT 1', $first->json('data.0.meta.query.sql'));
        $this->assertStringNotContainsString('apm-sifre-5512', $first->getContent());

        $second = $this->getJson('/api/audit-logs?cursor='.$first->json('next_cursor'))->assertOk();
        $this->assertCount(6, $second->json('data'));
        $this->assertNull($second->json('next_cursor'));
        $this->assertSame(0, $second->json('data.5.meta.n'));
    }

    public function test_csv_export_is_limited_logged_and_records_cannot_be_changed(): void
    {
        $this->log('agent.create', meta: ['name' => 'Rötar']);
        $this->log('auth.login');

        $response = $this->actingAs($this->admin)->get('/api/audit-logs?format=csv&action=agent.*')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('"Zaman (UTC)","Aktör türü",Aktör,Olay', $csv);
        $this->assertStringContainsString('agent.create', $csv);
        $this->assertStringNotContainsString('auth.login', $csv);
        $export = AuditLog::query()->where('action', 'audit.export')->sole();
        $this->assertSame(['filters' => ['action' => 'agent.*'], 'rows' => 1], $export->meta);

        // Güncelleme/silme ucu yok.
        foreach (['put', 'patch', 'delete'] as $method) {
            $this->json($method, '/api/audit-logs')->assertStatus(405);
            $this->json($method, '/api/audit-logs/1')->assertNotFound();
        }
        $this->assertFalse(collect(Route::getRoutes())->contains(fn ($r) => str_starts_with($r->uri(), 'api/audit-logs') && array_intersect($r->methods(), ['PUT', 'PATCH', 'DELETE']) !== []));
    }
}
