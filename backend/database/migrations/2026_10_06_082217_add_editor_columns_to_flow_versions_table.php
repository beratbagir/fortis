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
        Schema::table('flow_versions', function (Blueprint $table) {
            // Sürümün nereden geldiği: chat, canvas (tuval), yaml (YAML editörü), restore (eski sürümden geri yükleme).
            $table->string('source')->default('chat')->after('validation');
            $table->string('created_by')->nullable()->after('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flow_versions', function (Blueprint $table) {
            $table->dropColumn(['source', 'created_by']);
        });
    }
};
