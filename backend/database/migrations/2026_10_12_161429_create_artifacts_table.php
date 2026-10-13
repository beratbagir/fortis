<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sohbette Claude'un ürettiği arayüz, diyagram ve belgeler. Her değişiklik yeni bir sürümdür.
     * Kullanıcı sistemi olmadığı için sahiplik sohbet üzerinden.
     */
    public function up(): void
    {
        Schema::create('artifacts', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            // html, svg, mermaid, markdown
            $table->string('kind');
            $table->unsignedInteger('latest_version')->default(1);
            $table->timestamps();
        });

        Schema::create('artifact_versions', function (Blueprint $table) {
            $table->id();
            $table->string('artifact_id');
            $table->foreign('artifact_id')->references('id')->on('artifacts')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->longText('content');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['artifact_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifact_versions');
        Schema::dropIfExists('artifacts');
    }
};
