<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Architect'in ürettiği açıklayıcı plan (tetikleyici, oku, ara, karar, taslak, onay, gönder adımları).
        Schema::table('agents', function (Blueprint $table) {
            $table->json('plan')->nullable()->after('suggested_prompts');
            $table->string('template_key')->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['plan', 'template_key']);
        });
    }
};
