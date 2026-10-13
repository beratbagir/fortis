<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Yönetişim "Dikkat gerekenler": governance:scan üretir; aynı konu için açık uyarı varken yenisi açılmaz.
        Schema::create('governance_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('severity', 10);
            $table->string('subject_type', 40)->nullable();
            $table->string('subject_id', 40)->nullable();
            $table->string('message', 500);
            $table->json('meta')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['type', 'subject_type', 'subject_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_alerts');
    }
};
