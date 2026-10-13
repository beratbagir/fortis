<?php

namespace Tests\Feature;

use App\Mail\BudgetAlert;
use App\Models\AiCall;
use App\Models\Conversation;
use App\Models\Flow;
use App\Services\Claude\ClaudeChat;
use App\Services\Claude\CostCalculator;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class UsageTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFlowEnvironment();
        Http::fake(['*' => Http::response(['results' => [], 'total' => 0])]);
        Mail::fake();
    }

    private function aiCall(array $attributes = []): AiCall
    {
        // created_at doldurulabilir değil; geçmiş tarihli kayıtlar için forceFill.
        $call = (new AiCall)->forceFill($attributes + [
            'model' => 'claude-sonnet-5-5',
            'input_tokens' => 1000,
            'output_tokens' => 100,
            'cost_usd' => 0.01,
        ]);
        $call->save();

        return $call;
    }

    public function test_cost_includes_cache_tokens(): void
    {
        $costs = $this->app->make(CostCalculator::class);

        // Sonnet 5.5: 2 girdi, 10 çıktı, 2.5 önbellek yazma, 0.2 önbellek okuma ($/1M)
        $this->assertSame(0.0067, $costs->cost('claude-sonnet-5-5', 1000, 200, 1000, 1000));
        // Opus 5.5: 4 / 20 / 5 / 0.2
        $this->assertSame(0.0132, $costs->cost('claude-opus-5-5', 1000, 200, 1000, 1000));
    }

    public function test_stored_costs_do_not_change_when_prices_change(): void
    {
        $call = $this->aiCall(['cost_usd' => $this->app->make(CostCalculator::class)->cost('claude-sonnet-5-5', 1_000_000, 0)]);

        config(['ai.models.claude-sonnet-5-5.input_per_mtok' => 9.0]);
        $this->app->forgetInstance(CostCalculator::class);

        $this->assertSame('2.000000', $call->refresh()->cost_usd);
        $this->getJson('/api/usage/summary')->assertJsonPath('data.cost_usd', 2);
    }

    public function test_usage_totals_match_ai_calls_and_are_split_by_flow_model_and_day(): void
    {
        $flow = Flow::factory()->create(['title' => 'Uzun rötar bildirimi']);
        $created = Conversation::factory()->create(['title' => 'Akış oluşturma']);
        $flow->update(['conversation_id' => $created->id]);
        $editor = Conversation::create(['title' => 'editör', 'context_flow_id' => $flow->id, 'kind' => 'editor']);
        $plain = Conversation::factory()->create(['title' => 'Soru']);

        $this->aiCall(['conversation_id' => $created->id, 'cost_usd' => 0.10, 'created_at' => now()->startOfMonth()->addDay()]);
        $this->aiCall(['conversation_id' => $editor->id, 'model' => 'claude-opus-5-5', 'cost_usd' => 0.20]);
        $this->aiCall(['conversation_id' => $plain->id, 'cost_usd' => 0.05]);
        $this->aiCall(['flow_id' => $flow->id, 'source' => 'flow_step', 'cost_usd' => 0.30]);
        $this->aiCall(['cost_usd' => 9.99, 'created_at' => now()->subMonthNoOverflow()]);

        $total = round((float) AiCall::query()->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('cost_usd'), 6);

        $this->getJson('/api/usage/summary')
            ->assertOk()
            ->assertJsonPath('data.cost_usd', $total)
            ->assertJsonPath('data.calls', 4)
            ->assertJsonPath('data.input_tokens', 4000)
            ->assertJsonPath('data.previous_cost_usd', 9.99);

        $byFlow = $this->getJson('/api/usage/by-flow')->assertOk()->json('data');
        $this->assertEqualsWithDelta($total, array_sum(array_column($byFlow, 'cost_usd')), 0.000001);
        $this->assertSame('Uzun rötar bildirimi', $byFlow[0]['name']);
        $this->assertEqualsWithDelta(0.60, $byFlow[0]['cost_usd'], 0.000001);
        $this->assertSame(1, $byFlow[0]['flow_step_calls']);
        $this->assertSame('Sohbet: Soru', $byFlow[1]['name']);

        $byModel = collect($this->getJson('/api/usage/by-model')->json('data'))->keyBy('model');
        $this->assertEqualsWithDelta(0.20, $byModel['claude-opus-5-5']['cost_usd'], 0.000001);
        $this->assertSame('Claude Opus 5.5', $byModel['claude-opus-5-5']['label']);

        $daily = $this->getJson('/api/usage/daily')->json('data');
        $this->assertCount(now()->daysInMonth, $daily);
        $this->assertEqualsWithDelta($total, array_sum(array_column($daily, 'chat_cost')) + array_sum(array_column($daily, 'flow_step_cost')), 0.000001);
        $this->assertEqualsWithDelta(0.30, array_sum(array_column($daily, 'flow_step_cost')), 0.000001);

        $this->get('/api/usage/export.csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertSee('Uzun rötar bildirimi');

        $this->getJson('/api/flows')->assertJsonPath('data.0.month_cost_usd', 0.6);
    }

    public function test_chat_stops_when_budget_is_exhausted(): void
    {
        $this->app->make(AppSettings::class)->set(AppSettings::MONTHLY_BUDGET, 1.0);
        $this->aiCall(['cost_usd' => 1.0]);
        $conversation = Conversation::factory()->create();

        $this->mock(ClaudeChat::class)->shouldNotReceive('reply');

        $this->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'Merhaba'])
            ->assertStatus(402)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'bütçesi doldu'));
        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_budget_alert_is_sent_once_a_month_at_80_percent(): void
    {
        config(['ai.admin_emails' => ['admin@example.com']]);
        $this->app->make(AppSettings::class)->set(AppSettings::MONTHLY_BUDGET, 10.0);

        $this->aiCall(['cost_usd' => 7.0]);
        Mail::assertNothingSent();

        $this->aiCall(['cost_usd' => 1.5]);
        $this->aiCall(['cost_usd' => 1.0]);
        Mail::assertSent(BudgetAlert::class, 1);
        Mail::assertSent(BudgetAlert::class, fn (BudgetAlert $mail) => $mail->hasTo('admin@example.com'));
    }

    public function test_only_allowed_models_can_be_selected(): void
    {
        $conversation = Conversation::factory()->create();

        $this->patchJson("/api/conversations/{$conversation->id}", ['model' => 'gpt-5'])->assertStatus(422);
        $this->patchJson("/api/conversations/{$conversation->id}", ['model' => 'claude-opus-5-5'])
            ->assertOk()
            ->assertJsonPath('data.model', 'claude-opus-5-5')
            ->assertJsonPath('data.effective_model', 'claude-opus-5-5');

        $this->putJson('/api/settings/ai', ['default_model' => 'claude-haiku-4-5'])->assertStatus(422);
        $this->putJson('/api/settings/ai', ['default_model' => 'claude-opus-5-5', 'monthly_budget_usd' => 100])
            ->assertOk()
            ->assertJsonPath('data.default_model', 'claude-opus-5-5')
            ->assertJsonPath('data.monthly_budget_usd', 100);
    }

    public function test_editor_conversations_are_hidden_from_the_chat_list_and_report_their_usage(): void
    {
        $flow = Flow::factory()->create();
        $editor = Conversation::create(['title' => 'editör', 'context_flow_id' => $flow->id, 'kind' => 'editor']);
        Conversation::factory()->create();
        $this->aiCall(['conversation_id' => $editor->id, 'input_tokens' => 1000, 'output_tokens' => 500, 'cost_usd' => 0.06]);

        $this->assertCount(1, $this->getJson('/api/conversations')->json('data'));
        $this->getJson("/api/conversations/{$editor->id}")
            ->assertJsonPath('data.usage.tokens', 1500)
            ->assertJsonPath('data.usage.cost_usd', 0.06);
    }
}
