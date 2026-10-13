<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Zamanlama tetikleyicisinin Kestra'daki akışla eşitlenme durumu (Kestra kapalıyken hata burada kalır, sonra yeniden denenir).
        Schema::table('agent_triggers', function (Blueprint $table) {
            $table->timestamp('synced_at')->nullable()->after('enabled');
            $table->text('sync_error')->nullable()->after('synced_at');
        });

        // Olay tetikleyicileri için APM uçuş durumlarının son hali: değişiklik (rötar arttı, iptal edildi) buradan anlaşılır.
        Schema::create('flight_watch', function (Blueprint $table) {
            $table->id();
            $table->string('leg_id')->unique();
            $table->string('flight_no')->nullable();
            $table->string('dep', 8)->nullable();
            $table->string('arr', 8)->nullable();
            $table->string('std_utc')->nullable();
            $table->unsignedInteger('dep_delay_min')->default(0);
            $table->boolean('cancelled')->default(false);
            $table->timestamp('seen_at');
            $table->index('seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_watch');
        Schema::table('agent_triggers', function (Blueprint $table) {
            $table->dropColumn(['synced_at', 'sync_error']);
        });
    }
};
