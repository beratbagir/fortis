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
        Schema::table('executions', function (Blueprint $table) {
            // Editörden test namespace'inde kuru çalıştırma (dış sistemlere yazan adımlar gönderilmez).
            $table->boolean('is_test')->default(false)->after('started_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->dropColumn('is_test');
        });
    }
};
