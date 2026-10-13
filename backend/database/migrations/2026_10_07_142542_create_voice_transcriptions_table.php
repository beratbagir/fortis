<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesli komut ölçümleri. Metin ve ses bilerek saklanmaz. Kullanıcı sistemi olmadığı için user_id yok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_transcriptions', function (Blueprint $table) {
            $table->id();
            $table->decimal('duration_s', 5, 1);
            $table->unsignedInteger('processing_ms');
            $table->unsignedInteger('char_count');
            // Kullanıcı göndermeden önce metni değiştirdi mi; gönderilmediyse null.
            $table->boolean('was_edited')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_transcriptions');
    }
};
