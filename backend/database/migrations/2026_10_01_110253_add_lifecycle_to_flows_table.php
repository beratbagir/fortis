<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->softDeletes();
        });

        // Faz 5: "uploaded" durumu "active" oldu (aktif/pasif ayrımı).
        DB::table('flows')->where('status', 'uploaded')->update(['status' => 'active']);

        Schema::table('flows', function (Blueprint $table) {});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        DB::table('flows')->whereIn('status', ['active', 'inactive'])->update(['status' => 'uploaded']);

        Schema::table('flows', function (Blueprint $table) {});
    }
};
