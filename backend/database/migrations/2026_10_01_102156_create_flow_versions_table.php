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
        Schema::create('flow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->json('summary');
            // Doğrulanmış ve yeniden yazılmış (kanonik) YAML: kullanıcının gördüğü ve Kestra'ya giden metin.
            $table->longText('yaml');
            $table->string('yaml_hash', 64);
            $table->json('secrets_used');
            $table->json('card');
            $table->json('validation');
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('kestra_revision')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->string('uploaded_by')->nullable();
            $table->timestamps();

            $table->unique(['flow_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flow_versions');
    }
};
