<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // source: chat (agent sayfasındaki sohbet) veya null (tetikleyiciden / elle). attempts: kuyruk denemesi (1'den fazlaysa yeniden denendi).
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('trigger_id');
            $table->unsignedSmallInteger('attempts')->default(0)->after('tool_calls_count');
        });

        Schema::table('agent_feedback', function (Blueprint $table) {
            $table->unique(['agent_run_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_feedback', function (Blueprint $table) {
            $table->dropUnique(['agent_run_id', 'user_id']);
        });
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn(['source', 'attempts']);
        });
    }
};
