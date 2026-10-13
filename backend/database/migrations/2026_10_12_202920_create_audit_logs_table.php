<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Değiştirilemez denetim kaydı. Kayıtlar güncellenmez ve silinmez (bkz. AuditLog).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_type');
            $table->string('actor_label')->nullable();
            $table->string('action')->index();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('system')->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->string('result');
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
