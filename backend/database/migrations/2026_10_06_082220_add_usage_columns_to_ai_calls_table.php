<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_calls', function (Blueprint $table) {
            // chat (sohbet, akış oluşturma ve düzenleme, hata açıklama) veya flow_step (akış içi AI adımı).
            $table->string('source')->default('chat')->after('purpose');
            $table->foreignId('flow_id')->nullable()->after('message_id')->constrained()->nullOnDelete();
            $table->foreignId('execution_id')->nullable()->after('flow_id')->constrained()->nullOnDelete();
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_calls', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropConstrainedForeignId('execution_id');
            $table->dropConstrainedForeignId('flow_id');
            $table->dropColumn('source');
        });
    }
};
