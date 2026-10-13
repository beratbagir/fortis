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
        // Akış editöründeki sohbetin önerdiği değişiklik. Doğrulanır ama sürüm olarak kaydedilmez;
        // kullanıcı Uygula derse yeni taslak sürüm olur.
        Schema::create('flow_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('base_version');
            $table->string('title');
            $table->json('summary');
            $table->longText('yaml');
            $table->json('validation');
            // pending, applied, rejected
            $table->string('status')->default('pending');
            $table->foreignId('flow_version_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flow_proposals');
    }
};
