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
        // Laravel'in ürettiği rapor dosyaları. Kullanıcı sistemi olmadığı için sahiplik sohbet üzerinden.
        Schema::create('reports', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flow_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('data_source');
            $table->json('params');
            $table->string('format');
            $table->string('title');
            $table->json('columns');
            $table->string('sort_by')->nullable();
            $table->string('sort_dir')->nullable();
            $table->string('group_by')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->json('aggregates')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            // queued, ready, failed, expired
            $table->string('status')->default('queued');
            $table->text('error')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        Schema::create('report_downloads', function (Blueprint $table) {
            $table->id();
            $table->string('report_id');
            $table->foreign('report_id')->references('id')->on('reports')->cascadeOnDelete();
            $table->string('ip')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('downloaded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_downloads');
        Schema::dropIfExists('reports');
    }
};
