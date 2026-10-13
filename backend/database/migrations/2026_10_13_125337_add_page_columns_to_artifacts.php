<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sohbette HTML sayfalar (H1): sayfa türü artifact'ın açıklaması; her sürümde kaynak sorgular, sayfaya enjekte
     * edilen maskeli veri (anlık görüntü), verinin zamanı ve doğrulama uyarısı.
     */
    public function up(): void
    {
        Schema::table('artifacts', function (Blueprint $table) {
            $table->string('description', 300)->nullable()->after('title');
        });

        Schema::table('artifact_versions', function (Blueprint $table) {
            $table->json('sources')->nullable();
            $table->longText('data')->nullable();
            $table->timestamp('data_at')->nullable();
            $table->string('warning')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('artifact_versions', function (Blueprint $table) {
            $table->dropColumn(['sources', 'data', 'data_at', 'warning']);
        });

        Schema::table('artifacts', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
