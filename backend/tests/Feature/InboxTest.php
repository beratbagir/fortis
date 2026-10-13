<?php

namespace Tests\Feature;

use App\Agents\Inbox;
use App\Models\Agent;
use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Execution;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\Flows\ExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class InboxTest extends TestCase
{
    use RefreshDatabase;

    private function pendingAction(User $owner): InboxItem
    {
        $agent = Agent::create(['slug' => 'a-'.uniqid(), 'name' => 'Rötar Özeti', 'owner_id' => $owner->id, 'status' => Agent::STATUS_LIVE, 'instructions' => 'x', 'tools' => [['name' => 'send_email']], 'icon' => 'RO']);
        $run = $agent->runs()->create(['status' => AgentRun::WAITING_APPROVAL, 'input' => 'x', 'conversation_id' => Conversation::create(['kind' => 'agent', 'user_id' => $owner->id])->id]);
        $action = $run->actions()->create(['type' => 'send_email', 'payload' => ['to' => ['occ@freebird.com.tr'], 'subject' => 'Özet', 'body_markdown' => 'Metin'], 'status' => AgentAction::PENDING, 'requires_approval' => true, 'reason' => 'Günlük özet']);

        return app(Inbox::class)->approval($owner, $action);
    }

    public function test_users_only_see_their_own_items(): void
    {
        $ayse = User::factory()->create();
        $mehmet = User::factory()->create();
        $item = $this->pendingAction($ayse);
        InboxItem::create(['user_id' => $ayse->id, 'type' => 'update', 'title' => 'Bilgi']);

        $this->actingAs($ayse)->getJson('/api/inbox')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('counts.action', 1)
            ->assertJsonPath('counts.updates', 1);
        $this->getJson('/api/inbox/count')->assertJsonPath('data.action', 1);
        $this->getJson("/api/inbox/{$item->id}")
            ->assertJsonPath('data.detail.action.payload.subject', 'Özet')
            ->assertJsonPath('data.detail.can_decide', true)
            ->assertJsonPath('data.detail.what_happens.1', 'Bu işlem senin adınla denetim kaydına yazılır.');

        $this->actingAs($mehmet)->getJson('/api/inbox')->assertJsonCount(0, 'data');
        $this->getJson("/api/inbox/{$item->id}")->assertNotFound();
        $this->postJson("/api/inbox/{$item->id}/approve")->assertNotFound();
    }

    public function test_bulk_approve_only_works_on_approvals_the_user_may_decide(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $a = $this->pendingAction($owner);
        $b = $this->pendingAction($owner);
        $update = InboxItem::create(['user_id' => $owner->id, 'type' => 'update', 'title' => 'Bilgi']);
        $this->actingAs($owner);

        $this->postJson('/api/inbox/bulk', ['ids' => [$a->id, $update->id], 'op' => 'approve'])->assertStatus(422);
        Mail::assertNothingSent();

        // Onay yetkisi olmayan (onaylayıcı listesinde değil) kullanıcıya düşmüş öğe
        $stranger = User::factory()->create();
        $foreign = InboxItem::create(['user_id' => $stranger->id, 'type' => 'approval', 'title' => 'x', 'subject_type' => 'agent_action', 'subject_id' => AgentAction::query()->first()->id]);
        $this->actingAs($stranger)->postJson('/api/inbox/bulk', ['ids' => [$foreign->id], 'op' => 'approve'])->assertForbidden();

        $this->actingAs($owner)->postJson('/api/inbox/bulk', ['ids' => [$a->id, $b->id], 'op' => 'approve'])->assertOk()->assertJsonPath('data.updated', 2);
        Mail::assertSentCount(2);
        $this->assertSame(2, AgentAction::query()->where('status', AgentAction::EXECUTED)->count());

        $this->postJson('/api/inbox/bulk', ['ids' => [$update->id], 'op' => 'done'])->assertOk();
        $this->assertSame('done', $update->refresh()->status);
    }

    public function test_snoozed_item_comes_back_when_the_time_is_up(): void
    {
        $owner = User::factory()->create();
        $item = $this->pendingAction($owner);
        $this->actingAs($owner);

        $this->postJson("/api/inbox/{$item->id}/snooze", ['until' => '1h'])->assertOk();
        $this->getJson('/api/inbox')->assertJsonCount(0, 'data');
        $this->getJson('/api/inbox/count')->assertJsonPath('data.action', 0);

        $this->travel(61)->minutes();
        $this->getJson('/api/inbox')->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'open');
        $this->getJson('/api/inbox/count')->assertJsonPath('data.action', 1);
    }

    public function test_draft_can_be_edited_only_with_allowed_recipients(): void
    {
        config(['agents.allowed_recipient_domains' => ['freebird.com.tr']]);
        $owner = User::factory()->create();
        $item = $this->pendingAction($owner);
        $this->actingAs($owner);

        $this->patchJson("/api/inbox/{$item->id}/draft", ['subject' => 'Yeni konu', 'to' => ['ops@freebird.com.tr']])->assertOk()
            ->assertJsonPath('data.detail.action.payload.subject', 'Yeni konu')
            ->assertJsonPath('data.detail.action.payload.to', ['ops@freebird.com.tr']);
        $this->patchJson("/api/inbox/{$item->id}/draft", ['to' => ['dis@gmail.com']])->assertStatus(422);
    }

    public function test_paused_flow_run_becomes_an_approval_and_decides_through_the_flow(): void
    {
        $owner = User::factory()->create();
        $flow = Flow::factory()->create(['owner_id' => $owner->id, 'title' => 'Sigorta bildirimi']);
        $execution = Execution::create(['flow_id' => $flow->id, 'flow_version_id' => FlowVersion::factory()->for($flow)->create()->id, 'kestra_execution_id' => 'exec-1', 'state' => 'RUNNING']);
        $this->assertSame(0, InboxItem::count());

        $execution->update(['state' => 'PAUSED']);
        $item = InboxItem::query()->sole();
        $this->assertSame($owner->id, $item->user_id);
        $this->assertSame('approval', $item->type);
        // Aynı duraklama için ikinci öğe açılmaz.
        $execution->touch();
        $this->assertSame(1, InboxItem::count());

        $service = Mockery::mock(ExecutionService::class);
        $service->shouldReceive('resume')->once()->withArgs(fn ($e, $approved) => $e->is($execution) && $approved === true);
        $this->app->instance(ExecutionService::class, $service);

        $this->actingAs($owner)->postJson("/api/inbox/{$item->id}/approve")->assertOk();
        $this->assertSame('done', $item->refresh()->status);

        // Reddet = durdur
        $execution->update(['state' => 'RUNNING']);
        $execution->update(['state' => 'PAUSED']);
        $second = InboxItem::query()->where('status', 'open')->sole();
        $service = Mockery::mock(ExecutionService::class);
        $service->shouldReceive('resume')->once()->withArgs(fn ($e, $approved) => $approved === false);
        $this->app->instance(ExecutionService::class, $service);
        $this->postJson("/api/inbox/{$second->id}/reject")->assertOk();

        // Çalışma PAUSED'dan çıkınca öğe kapanır.
        $execution->update(['state' => 'PAUSED']);
        $third = InboxItem::query()->where('status', 'open')->sole();
        $execution->update(['state' => 'SUCCESS']);
        $this->assertSame('done', $third->refresh()->status);
    }

    public function test_home_waiting_list_comes_from_the_inbox(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $item = $this->pendingAction($owner);

        $this->actingAs($owner)->getJson('/api/home')
            ->assertJsonPath('data.waiting_total', 1)
            ->assertJsonPath('data.waiting.0.id', $item->id)
            ->assertJsonPath('data.waiting.0.kind', 'approval');
    }
}
