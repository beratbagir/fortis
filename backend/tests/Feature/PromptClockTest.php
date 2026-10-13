<?php

namespace Tests\Feature;

use App\Agents\AgentRunner;
use App\Ai\PromptClock;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesDataSources;
use Tests\TestCase;

class PromptClockTest extends TestCase
{
    use FakesDataSources, RefreshDatabase;

    public function test_relative_dates_follow_turkey_time_even_just_after_midnight(): void
    {
        // Türkiye 13 Ekim 00:30 = UTC 12 Ekim 21:30. UTC'ye göre "dün" 11 Ekim olurdu; doğrusu 12 Ekim.
        $block = PromptClock::block(CarbonImmutable::parse('2026-10-13 00:30', 'Europe/Istanbul'));

        $this->assertStringContainsString('Şu an: **13 Ekim 2026 Salı, 00:30** (Türkiye saati, UTC+3). UTC: 2026-10-12T21:30Z.', $block);
        $this->assertStringContainsString('- dün: 12 Ekim 2026 → `2026-10-11T21:00:00Z` – `2026-10-12T21:00:00Z`', $block);
        $this->assertStringContainsString('- bugün: 13 Ekim 2026 → `2026-10-12T21:00:00Z` – `2026-10-13T21:00:00Z`', $block);
        $this->assertStringContainsString('- geçen hafta: 5–11 Ekim 2026 → `2026-10-04T21:00:00Z` – `2026-10-11T21:00:00Z`', $block);

        // Gün 23:59'da da aynı gün.
        $this->assertStringContainsString('- dün: 12 Ekim 2026', PromptClock::block(CarbonImmutable::parse('2026-10-13 23:59', 'Europe/Istanbul')));
    }

    public function test_month_and_week_boundaries(): void
    {
        // Pazartesi 1 Aralık 00:10 TR (UTC 30 Kasım 21:10).
        $block = PromptClock::block(CarbonImmutable::parse('2026-12-01 00:10', 'Europe/Istanbul'));

        $this->assertStringContainsString('- dün: 30 Kasım 2026 → `2026-11-29T21:00:00Z` – `2026-11-30T21:00:00Z`', $block);
        $this->assertStringContainsString('- bu hafta (Pzt–Paz): 30 Kasım 2026 – 6 Aralık 2026', $block);
        $this->assertStringContainsString('- geçen ay: 1–30 Kasım 2026 → `2026-10-31T21:00:00Z` – `2026-11-30T21:00:00Z`', $block);
    }

    public function test_clock_is_the_last_system_block_in_chat_and_agent_requests(): void
    {
        $this->fakeDataSources();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-13 00:30', 'Europe/Istanbul'));
        $user = User::factory()->builder()->create();
        $this->actingAs($user);

        $conversation = Conversation::create(['user_id' => $user->id]);
        $this->fakeClaude()->text('Dün (12 Ekim 2026) 56 sefer vardı.');
        $this->post("/api/conversations/{$conversation->id}/messages", ['content' => 'Dün kaç sefer vardı?'], ['Accept' => 'text/event-stream'])->assertOk()->streamedContent();

        $system = $this->claude->requests[0]['system'];
        $this->assertCount(2, $system);
        $this->assertSame(['type' => 'ephemeral'], $system[0]['cache_control']);
        $this->assertStringStartsWith('# Şimdiki zaman', $system[1]['text']);
        $this->assertStringContainsString('- dün: 12 Ekim 2026', $system[1]['text']);
        $this->assertStringNotContainsString('# Şimdiki zaman', $system[0]['text']);

        // Agent çalışmasında da aynı blok en sonda (agent bölümünden sonra).
        $agent = Agent::create(['slug' => 'a', 'name' => 'Rötar', 'owner_id' => $user->id, 'status' => Agent::STATUS_LIVE, 'instructions' => 'Dünü özetle.', 'tools' => [['name' => 'query_apm']], 'icon' => 'RÖ']);
        $run = $agent->runs()->create(['status' => AgentRun::QUEUED, 'input' => 'Dünü özetle', 'conversation_id' => Conversation::create(['kind' => 'agent', 'user_id' => $user->id])->id]);
        $this->fakeClaude()->text('Bitti.');
        app(AgentRunner::class)->execute($run->refresh());

        $agentSystem = $this->claude->requests[0]['system'];
        $this->assertStringContainsString("Sen Rötar agent'ısın", $agentSystem[0]['text']);
        $this->assertStringContainsString('- dün: 12 Ekim 2026 → `2026-10-11T21:00:00Z`', end($agentSystem)['text']);
    }
}
