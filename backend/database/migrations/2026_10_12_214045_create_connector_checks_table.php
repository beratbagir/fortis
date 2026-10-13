<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bağlantı kontrollerinin sonuçları (connectors:check, 10 dakikada bir). Home'daki çipler buradan okunur;
 * sayfa açılışında dış sisteme istek atılmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_checks', function (Blueprint $table) {
            $table->id();
            $table->string('connector')->index();
            $table->boolean('ok');
            $table->string('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('checked_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_checks');
    }
};
