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
        Schema::create('flows', function (Blueprint $table) {
            $table->id();
            $table->string('namespace');
            $table->string('flow_id');
            $table->string('title');
            $table->json('summary');
            // draft: hiç yüklenmedi, uploaded: Kestra'da. (aktif/pasif Faz 5'te)
            $table->string('status')->default('draft');
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();

            $table->unique(['namespace', 'flow_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flows');
    }
};
