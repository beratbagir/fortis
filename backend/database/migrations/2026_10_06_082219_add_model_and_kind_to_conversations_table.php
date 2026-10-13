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
        Schema::table('conversations', function (Blueprint $table) {
            // Sohbetin modeli; boşsa genel varsayılan (settings) kullanılır.
            $table->string('model')->nullable()->after('title');
            // chat: normal sohbet, editor: akış editöründeki sohbet (öneriler kaydedilmez, fark olarak gösterilir).
            $table->string('kind')->default('chat')->after('model');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['model', 'kind']);
        });
    }
};
