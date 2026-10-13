<?php

namespace Tests\Feature;

use App\Agents\AgentRunner;
use App\Mail\AgentEmail;
use App\Models\Agent;
use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\AiCall;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\FakesDataSources;
use Tests\TestCase;

/**
 * Faz A3.1: agent çalıştırıcı. Gerçek ClaudeChat ve Anthropic SDK, sahte HTTP taşıyıcısı; kuyruk testte senkron.
 */
class AgentRunTest extends TestCase
{
    use FakesDataSources, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDataSources();
        Mail::fake();
        config(['agents.allowed_recipient_domains' => ['freebird.com.tr'], 'agents.allow_external_recipients' => false]);
        $this->owner = User::factory()->builder()->create(['email' => 'sahip@freebird.com.tr']);
        $this->actingAs($this->owner);
    }

    /** @param  array<string, mixed>  $attributes */
    private function agent(array $attributes = []): Agent
    {
        return Agent::create($attributes + [
            'slug' => 'rotar-ozeti-'.uniqid(),
            'name' => 'Rötar Özeti',
            'description' => 'Dünkü rötarları özetler',
            'owner_id' => $this->owner->id,
            'status' => Agent::STATUS_LIVE,
            'instructions' => 'Dünkü rötarları incele ve OCC yöneticilerine e-postayla gönder.',
            'tools' => [['name' => 'query_apm'], ['name' => 'send_email']],
            'icon' => 'RO',
        ]);
    }

    private function email(array $override = []): array
    {
        return ['name' => 'send_email', 'input' => $override + ['to' => ['occ@freebird.com.tr'], 'subject' => 'Rötar özeti', 'body_markdown' => 'Dün **3** uzun rötar vardı.', 'reason' => 'Talimat gereği günlük özet.']];
    }

    private function runAgent(Agent $agent, bool $test = false): AgentRun
    {
        $id = $this->postJson("/api/agents/{$agent->id}/runs", ['input' => 'Dünkü rötarları özetle.', 'test' => $test])->assertCreated()->json('data.id');

        return AgentRun::query()->findOrFail($id);
    }

    public function test_only_the_agent_tools_are_offered_and_others_are_refused(): void
    {
        $this->fakeClaude()
            ->toolCalls([['name' => 'query_warehouse', 'input' => ['sql' => 'SELECT flight_no FROM ires_dim_flight_test LIMIT 1', 'purpose' => 'x']]])
            ->text('Bitti.');

        $run = $this->runAgent($this->agent());

        $names = array_column($this->claude->requests[0]['tools'], 'name');
        sort($names);
        $this->assertSame(['ask_user', 'query_apm', 'send_email'], $names);
        $this->assertStringContainsString("Sen Rötar Özeti agent'ısın", json_encode($this->claude->requests[0]['system'], JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('izinli bir araç değil', json_encode(collect($this->claude->requests[1]['messages'])->last(), JSON_UNESCAPED_UNICODE));
        $this->assertTrue(AuditLog::query()->where('action', 'agent.tool_blocked')->where('actor_type', 'agent')->exists());
        $this->assertSame(AgentRun::SUCCEEDED, $run->refresh()->status);
        $this->assertSame('Bitti.', $run->summary);
    }

    public function test_personal_data_never_reaches_claude_in_agent_runs(): void
    {
        $agent = $this->agent(['tools' => [['name' => 'query_apm'], ['name' => 'query_warehouse'], ['name' => 'query_successfactors']]]);
        $this->fakeClaude()
            ->toolCalls([
                ['name' => 'query_apm', 'input' => ['path' => '/api/legs?dateFrom=2026-10-11T00:00:00Z&dateTo=2026-10-12T00:00:00Z', 'purpose' => 'Uçuşlar']],
                ['name' => 'query_warehouse', 'input' => ['sql' => 'SELECT flight_no, remark, contact_email FROM ires_dim_flight_test LIMIT 5', 'purpose' => 'Seferler']],
                ['name' => 'query_successfactors', 'input' => ['path' => 'EmpJob?$expand=userNav', 'purpose' => 'Çalışanlar']],
            ])
            ->text('Özet.');

        $this->runAgent($agent);

        $sent = implode("\n", array_map(fn (array $r) => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->claude->requests));
        foreach (self::PERSONAL_DATA as $value) {
            $this->assertStringNotContainsString($value, $sent, "Claude'a kişisel veri gitti: {$value}");
        }
        $this->assertStringContainsString('FH1256', $sent);
    }

    public function test_data_tools_are_not_given_when_data_access_is_off(): void
    {
        app(AppSettings::class)->set(AppSettings::DATA_ACCESS, AppSettings::DATA_ACCESS_OFF);
        $this->fakeClaude()->text('Veri olmadan yapamam.');

        $this->runAgent($this->agent());

        $names = array_column($this->claude->requests[0]['tools'], 'name');
        sort($names);
        $this->assertSame(['ask_user', 'send_email'], $names);
    }

    public function test_write_action_waits_for_approval_and_runs_once(): void
    {
        $this->fakeClaude()->toolCalls([$this->email()])->text('Özeti onaya gönderdim.');

        $run = $this->runAgent($this->agent());

        $this->assertSame(AgentRun::WAITING_APPROVAL, $run->refresh()->status);
        $action = AgentAction::query()->sole();
        $this->assertSame(AgentAction::PENDING, $action->status);
        $this->assertStringContainsString('onaya gönderildi', json_encode(collect($this->claude->requests[1]['messages'])->last(), JSON_UNESCAPED_UNICODE));
        Mail::assertNothingSent();

        $item = InboxItem::query()->where('user_id', $this->owner->id)->where('type', 'approval')->sole();
        $this->assertSame('Rötar Özeti', $item->source_label);

        $this->postJson("/api/inbox/{$item->id}/approve")->assertOk()->assertJsonPath('data.detail.action.status', 'executed');
        $this->postJson("/api/inbox/{$item->id}/approve")->assertStatus(409);

        Mail::assertSent(AgentEmail::class, 1);
        Mail::assertSent(AgentEmail::class, fn (AgentEmail $mail) => $mail->hasTo('occ@freebird.com.tr') && $mail->subjectLine === 'Rötar özeti');
        $this->assertSame(AgentRun::SUCCEEDED, $run->refresh()->status);
        $this->assertSame('done', $item->refresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'agent.action.execute')->where('actor_type', 'agent')->exists());
        $this->assertSame($this->owner->id, AuditLog::query()->where('action', 'agent.action.approve')->sole()->user_id);
    }

    public function test_rejected_action_is_not_sent(): void
    {
        $this->fakeClaude()->toolCalls([$this->email()])->text('Onaya gönderdim.');
        $run = $this->runAgent($this->agent());
        $item = InboxItem::query()->sole();

        $this->postJson("/api/inbox/{$item->id}/reject", ['reason' => 'Rakamlar eksik'])->assertOk();

        Mail::assertNothingSent();
        $this->assertSame(AgentAction::REJECTED, AgentAction::query()->sole()->status);
        $this->assertSame(AgentRun::SUCCEEDED, $run->refresh()->status);
        $this->assertStringContainsString('Rakamlar eksik', $run->notes[0]['text']);
    }

    public function test_pilot_always_asks_and_sends_only_to_the_owner(): void
    {
        $agent = $this->agent(['status' => Agent::STATUS_PILOT, 'approval_policy' => ['send_email' => ['mode' => 'never']]]);
        $this->fakeClaude()->toolCalls([$this->email()])->text('Tamam.');

        $this->runAgent($agent);

        $action = AgentAction::query()->sole();
        $this->assertTrue($action->requires_approval);
        $this->assertSame(['sahip@freebird.com.tr'], $action->payload['to']);
        $this->assertSame(['occ@freebird.com.tr'], $action->payload['intended']['to']);

        $this->postJson('/api/inbox/'.InboxItem::query()->sole()->id.'/approve')->assertOk();
        Mail::assertSent(AgentEmail::class, fn (AgentEmail $mail) => $mail->hasTo('sahip@freebird.com.tr') && ! $mail->hasTo('occ@freebird.com.tr'));
    }

    public function test_recipients_are_limited_by_domain_and_policy(): void
    {
        // Şirket dışı: kapalıyken reddedilir.
        $this->fakeClaude()->toolCalls([$this->email(['to' => ['birisi@gmail.com']])])->text('Gönderemedim.');
        $this->runAgent($this->agent());
        $this->assertSame(0, AgentAction::count());
        $this->assertStringContainsString('Şirket dışı', json_encode(collect($this->claude->requests[1]['messages'])->last(), JSON_UNESCAPED_UNICODE));

        // Açıkken bile onaya zorlanır; iç alıcı "internal_only_auto" ile onaysız gider.
        config(['agents.allow_external_recipients' => true]);
        $agent = $this->agent(['approval_policy' => ['send_email' => ['mode' => 'internal_only_auto']]]);
        $this->fakeClaude()->toolCalls([$this->email(['to' => ['birisi@gmail.com']])])->text('A.');
        $this->runAgent($agent);
        $this->assertTrue(AgentAction::query()->latest('id')->first()->requires_approval);

        $this->fakeClaude()->toolCalls([$this->email()])->text('B.');
        $this->runAgent($agent);
        $internal = AgentAction::query()->latest('id')->first();
        $this->assertFalse($internal->requires_approval);
        $this->assertSame(AgentAction::EXECUTED, $internal->status);
        Mail::assertSent(AgentEmail::class, 1);
    }

    public function test_tool_call_limit_fails_the_run_and_alerts_the_owner(): void
    {
        config(['agents.max_tool_calls' => 20]);
        $query = ['name' => 'query_apm', 'input' => ['path' => '/api/legs?dateFrom=2026-10-11T00:00:00Z', 'purpose' => 'x']];
        $this->fakeClaude()->toolCalls(array_fill(0, 8, $query))->toolCalls(array_fill(0, 8, $query))->toolCalls(array_fill(0, 5, $query));

        $run = $this->runAgent($this->agent());

        $this->assertSame(AgentRun::FAILED, $run->refresh()->status);
        $this->assertStringContainsString('Araç çağrısı sınırı (20)', $run->error);
        $this->assertSame(21, $run->tool_calls_count);
        $this->assertSame('alert', InboxItem::query()->where('user_id', $this->owner->id)->sole()->type);
    }

    public function test_cost_and_time_limits_fail_the_run(): void
    {
        config(['agents.max_cost_usd' => 0.0001]);
        $this->fakeClaude()->toolCalls([['name' => 'query_apm', 'input' => ['path' => '/api/legs?dateFrom=2026-10-11T00:00:00Z', 'purpose' => 'x']]]);
        $run = $this->runAgent($this->agent());
        $this->assertSame(AgentRun::FAILED, $run->refresh()->status);
        $this->assertStringContainsString('Maliyet sınırı', $run->error);
        $this->assertGreaterThan(0, AiCall::query()->where('agent_run_id', $run->id)->count());

        config(['agents.max_cost_usd' => 2]);
        $this->travelTo(now());
        $this->fakeClaude()->toolCalls([['name' => 'query_apm', 'input' => ['path' => '/api/legs?dateFrom=2026-10-11T00:00:00Z', 'purpose' => 'x']]])->text('x');
        $agent = $this->agent();
        $run = $agent->runs()->create(['status' => AgentRun::RUNNING, 'input' => 'x', 'started_at' => now()->subMinutes(11), 'conversation_id' => Conversation::create(['kind' => 'agent', 'user_id' => $this->owner->id])->id]);
        app(AgentRunner::class)->execute($run);
        $this->assertStringContainsString('süre sınırını', $run->refresh()->error);
    }

    public function test_question_waits_for_answer_and_continues_with_history(): void
    {
        $this->fakeClaude()
            ->toolCalls([['name' => 'ask_user', 'input' => ['questions' => [['question' => 'Hangi eşik?', 'options' => [['label' => '30 dk'], ['label' => '60 dk']]]]]]])
            ->text('30 dakikanın üstündeki rötarları özetledim.');

        $run = $this->runAgent($this->agent());

        $this->assertSame(AgentRun::WAITING_INPUT, $run->refresh()->status);
        $item = InboxItem::query()->where('type', 'question')->sole();
        $this->getJson("/api/inbox/{$item->id}")->assertJsonPath('data.detail.questions.0.question', 'Hangi eşik?');

        $this->postJson("/api/inbox/{$item->id}/answer", ['answer' => ['answers' => [['question' => 'Hangi eşik?', 'selected' => ['30 dk'], 'other' => null]]]])->assertOk();

        $this->assertSame(AgentRun::SUCCEEDED, $run->refresh()->status);
        $messages = $this->claude->requests[1]['messages'];
        $this->assertSame('Dünkü rötarları özetle.', $messages[0]['content'][0]['text']);
        $this->assertStringContainsString('30 dk', json_encode(end($messages), JSON_UNESCAPED_UNICODE));
        $this->assertSame('done', $item->refresh()->status);
    }

    public function test_test_run_never_executes_write_actions(): void
    {
        $agent = $this->agent(['status' => Agent::STATUS_DRAFT, 'approval_policy' => ['send_email' => ['mode' => 'internal_only_auto']]]);
        $this->fakeClaude()->toolCalls([$this->email()])->text('Test bitti.');

        $run = $this->runAgent($agent, test: true);

        $this->assertSame(AgentAction::PREVIEW, AgentAction::query()->sole()->status);
        Mail::assertNothingSent();
        $this->assertSame(0, InboxItem::count());
        $this->assertSame(AgentRun::SUCCEEDED, $run->refresh()->status);
        $this->assertStringContainsString('TEST', json_encode($this->claude->requests[0]['system'], JSON_UNESCAPED_UNICODE));
    }

    public function test_draft_agent_cannot_be_run_for_real_and_users_cannot_test(): void
    {
        $agent = $this->agent(['status' => Agent::STATUS_DRAFT]);
        $this->postJson("/api/agents/{$agent->id}/runs", ['input' => 'x'])->assertStatus(409);

        $this->actingAs(User::factory()->create());
        $this->postJson("/api/agents/{$agent->id}/runs", ['input' => 'x', 'test' => true])->assertForbidden();
    }

    public function test_ask_the_agent_continues_in_the_run_conversation_and_replaces_the_draft(): void
    {
        $this->fakeClaude()->toolCalls([$this->email()])->text('Onaya gönderdim.');
        $run = $this->runAgent($this->agent());
        $first = AgentAction::query()->sole();

        $this->fakeClaude()->toolCalls([$this->email(['subject' => 'Rötar özeti (düzeltilmiş)'])])->text('Yeni taslak onayda.');
        $this->post("/api/conversations/{$run->conversation_id}/messages", ['content' => 'Konuya düzeltilmiş yaz.'], ['Accept' => 'text/event-stream'])->assertOk()->streamedContent();

        $this->assertSame(AgentAction::REJECTED, $first->refresh()->status);
        $this->assertSame('Rötar özeti (düzeltilmiş)', AgentAction::query()->latest('id')->first()->payload['subject']);
        $this->assertContains('send_email', array_column($this->claude->requests[0]['tools'], 'name'));
        $this->assertSame(1, InboxItem::query()->where('status', 'open')->count());
        $this->assertSame(AgentRun::WAITING_APPROVAL, $run->refresh()->status);
    }

    public function test_own_email_placeholder_becomes_the_run_starter_only_in_recipient_fields(): void
    {
        Mail::fake();
        $agent = $this->agent(['tools' => [['name' => 'send_email', 'constraints' => ['recipients' => ['sahip@freebird.com.tr', 'baslatan@freebird.com.tr']]]]]);
        $starter = User::factory()->create(['email' => 'baslatan@freebird.com.tr']);
        $this->actingAs($starter);

        $this->fakeClaude()->toolCalls([$this->email(['to' => ['KULLANICI_EPOSTASI'], 'subject' => 'KULLANICI_EPOSTASI için özet', 'body_markdown' => 'Merhaba KULLANICI_EPOSTASI'])])->text('Bitti.');
        $run = $this->runAgent($agent);

        // Claude sadece yer tutucuyu görür; başlatanın adresi ona gitmez.
        $system = json_encode($this->claude->requests[0]['system'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('İzinli e-posta alıcıları: sahip@freebird.com.tr, KULLANICI_EPOSTASI', $system);
        $this->assertStringNotContainsString('baslatan@freebird.com.tr', $system);

        $payload = $run->actions()->sole()->payload;
        $this->assertSame(['baslatan@freebird.com.tr'], $payload['to']);
        $this->assertSame('KULLANICI_EPOSTASI için özet', $payload['subject']);
        $this->assertSame('Merhaba KULLANICI_EPOSTASI', $payload['body_markdown']);

        // Başlatan yoksa (zamanlama) agent sahibi.
        $scheduled = $agent->runs()->create(['status' => AgentRun::QUEUED, 'input' => 'x', 'conversation_id' => Conversation::create(['kind' => 'agent', 'user_id' => $this->owner->id])->id]);
        $this->fakeClaude()->toolCalls([$this->email(['to' => ['KULLANICI_EPOSTASI']])])->text('Bitti.');
        app(AgentRunner::class)->execute($scheduled->refresh());
        $this->assertSame(['sahip@freebird.com.tr'], $scheduled->actions()->sole()->payload['to']);
    }
}
