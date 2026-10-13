<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konuşma ekranları (Faz A2): kapatılan kaynaklar, üretilen başlık, soru kartı durumu, kaynak numarası uyarısı.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->json('disabled_sources')->nullable()->after('model');
            $table->timestamp('title_generated_at')->nullable()->after('title');
        });

        Schema::table('messages', function (Blueprint $table) {
            // awaiting_input: Claude ask_user ile soru sordu, kullanıcının cevabı bekleniyor.
            $table->string('status')->nullable()->after('stop_reason');
        });

        Schema::table('ai_calls', function (Blueprint $table) {
            $table->string('warning')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', fn (Blueprint $table) => $table->dropColumn(['disabled_sources', 'title_generated_at']));
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('status'));
        Schema::table('ai_calls', fn (Blueprint $table) => $table->dropColumn('warning'));
    }
};
