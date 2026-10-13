<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz A3: agentlar (talimat + izinli araçlar + model + tetikleyiciler + onay kuralları), çalışmaları, yazan
 * aksiyonları ve Inbox. Değerlendirme ve geri bildirim tabloları sonraki alt fazlarda kullanılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->text('instructions');
            $table->string('model')->default('auto');
            $table->json('tools');
            $table->json('approval_policy')->nullable();
            $table->json('suggested_prompts')->nullable();
            $table->string('icon', 4)->nullable();
            $table->unsignedInteger('current_version')->default(1);
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('agent_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source')->default('manual');
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['agent_id', 'number']);
        });

        Schema::create('agent_triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->json('config')->nullable();
            $table->text('input_template')->nullable();
            $table->string('kestra_flow_id')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('agent_version')->default(1);
            $table->foreignId('trigger_id')->nullable()->constrained('agent_triggers')->nullOnDelete();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Çalışmanın konuşması (ClaudeChat bu sohbet üzerinden çalışır; geçmiş messages tablosunda).
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('queued')->index();
            $table->text('input');
            $table->text('summary')->nullable();
            $table->longText('output')->nullable();
            $table->unsignedInteger('tool_calls_count')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->text('error')->nullable();
            $table->json('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamps();
        });

        Schema::create('agent_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->json('payload');
            $table->text('reason')->nullable();
            $table->string('status')->default('pending')->index();
            $table->boolean('requires_approval')->default(true);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
        });

        Schema::create('inbox_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('source_label')->nullable();
            $table->string('icon', 4)->nullable();
            $table->string('status')->default('open');
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('agent_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('rating');
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('agent_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->json('criteria');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('agent_run_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->json('details');
            $table->string('model');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('ai_calls', function (Blueprint $table) {
            $table->foreignId('agent_run_id')->nullable()->after('execution_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_calls', fn (Blueprint $table) => $table->dropConstrainedForeignId('agent_run_id'));

        foreach (['agent_run_scores', 'agent_evaluations', 'agent_feedback', 'inbox_items', 'agent_actions', 'agent_runs', 'agent_triggers', 'agent_versions', 'agents'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
