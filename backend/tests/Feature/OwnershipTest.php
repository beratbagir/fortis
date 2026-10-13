<?php

namespace Tests\Feature;

use App\Models\AiCall;
use App\Models\Artifact;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Flow;
use App\Models\Report;
use App\Models\User;
use App\Services\Auth\Audit;
use App\Services\Auth\Ownership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class OwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_only_see_their_own_conversations(): void
    {
        $ayse = User::factory()->create();
        $mehmet = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $mine = Conversation::factory()->create(['user_id' => $ayse->id]);
        $theirs = Conversation::factory()->create(['user_id' => $mehmet->id]);

        $this->actingAs($ayse);
        $this->getJson('/api/conversations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->getJson("/api/conversations/{$mine->id}")->assertOk();
        $this->getJson("/api/conversations/{$theirs->id}")->assertNotFound();
        $this->patchJson("/api/conversations/{$theirs->id}", ['title' => 'x'])->assertNotFound();
        $this->deleteJson("/api/conversations/{$theirs->id}")->assertNotFound();
        $this->postJson("/api/conversations/{$theirs->id}/messages", ['content' => 'merhaba'])->assertNotFound();
        // ?all=1 sadece admin için
        $this->getJson('/api/conversations?all=1')->assertJsonCount(1, 'data');

        $this->actingAs($admin);
        $this->getJson("/api/conversations/{$theirs->id}")->assertOk();
        $this->getJson('/api/conversations')->assertJsonCount(0, 'data');
        $this->getJson('/api/conversations?all=1')->assertJsonCount(2, 'data');
    }

    public function test_reports_and_artifacts_are_visible_to_their_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $owner->id]);
        $report = Report::create(['conversation_id' => $conversation->id, 'data_source' => 'x', 'params' => [], 'format' => 'xlsx', 'title' => 'R', 'columns' => ['a'], 'status' => Report::STATUS_READY]);
        $artifact = Artifact::create(['conversation_id' => $conversation->id, 'title' => 'A', 'kind' => 'markdown', 'latest_version' => 1]);
        $artifact->versions()->create(['number' => 1, 'title' => 'A', 'content' => '# A']);

        $this->assertSame($owner->id, $report->user_id);
        $this->assertSame($owner->id, $artifact->user_id);

        $this->actingAs($other);
        $this->getJson("/api/reports/{$report->id}")->assertNotFound();
        $this->getJson("/api/artifacts/{$artifact->id}")->assertNotFound();

        $this->actingAs($owner);
        $this->getJson("/api/reports/{$report->id}")->assertOk();
        $this->getJson("/api/artifacts/{$artifact->id}")->assertOk();
    }

    public function test_new_records_belong_to_the_signed_in_user(): void
    {
        $department = Department::factory()->create();
        $user = User::factory()->builder()->create(['department_id' => $department->id]);
        $this->actingAs($user);

        $id = $this->postJson('/api/conversations')->assertCreated()->json('data.id');
        $this->assertSame($user->id, Conversation::find($id)->user_id);

        $flow = Flow::factory()->create();
        $this->assertSame($user->id, $flow->owner_id);
        $this->assertSame($department->id, $flow->department_id);

        $call = AiCall::create(['conversation_id' => $id, 'source' => AiCall::SOURCE_CHAT, 'model' => 'claude-sonnet-5-5']);
        $this->assertSame($user->id, $call->user_id);
        $this->assertSame($department->id, $call->department_id);
    }

    public function test_records_made_without_a_session_belong_to_the_conversation_owner(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $call = AiCall::create(['conversation_id' => $conversation->id, 'source' => AiCall::SOURCE_CHAT, 'model' => 'claude-sonnet-5-5']);
        $this->assertSame($user->id, $call->user_id);

        // Akış adımı: ne oturum ne sohbet var.
        $step = AiCall::create(['source' => AiCall::SOURCE_FLOW_STEP, 'model' => 'claude-sonnet-5-5']);
        $this->assertNull($step->user_id);
    }

    public function test_records_from_before_users_are_assigned_to_the_first_admin(): void
    {
        $conversation = Conversation::factory()->create();
        $flow = Flow::factory()->create();
        $chatCall = AiCall::create(['conversation_id' => $conversation->id, 'source' => AiCall::SOURCE_CHAT, 'model' => 'm']);
        $stepCall = AiCall::create(['source' => AiCall::SOURCE_FLOW_STEP, 'model' => 'm']);

        $department = Department::factory()->create();
        $admin = User::factory()->admin()->create(['department_id' => $department->id]);
        Ownership::backfill();

        $this->assertSame($admin->id, $conversation->refresh()->user_id);
        $this->assertSame($admin->id, $flow->refresh()->owner_id);
        $this->assertSame($department->id, $flow->department_id);
        $this->assertSame($admin->id, $chatCall->refresh()->user_id);
        $this->assertNull($stepCall->refresh()->user_id);
    }

    public function test_audit_log_cannot_be_changed_or_deleted(): void
    {
        $log = Audit::record('auth.login', meta: ['x' => 1]);

        $this->assertSame(AuditLog::ACTOR_SYSTEM, $log->actor_type);

        try {
            $log->update(['result' => AuditLog::RESULT_FAILED]);
            $this->fail('Denetim kaydı güncellendi.');
        } catch (LogicException) {
        }

        try {
            $log->delete();
            $this->fail('Denetim kaydı silindi.');
        } catch (LogicException) {
        }

        $this->assertSame(AuditLog::RESULT_ALLOWED, $log->refresh()->result);
        $this->assertSame(1, AuditLog::count());
    }
}
