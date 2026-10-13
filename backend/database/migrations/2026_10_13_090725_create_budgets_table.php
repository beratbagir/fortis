<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aylık AI bütçeleri: şirket (org), departman, kullanıcı. Uyarı eşiğinde bir kez admin'e Inbox uyarısı;
        // hard_stop açıksa dolunca o kapsamdan yeni soru ve agent çalışması başlamaz (admin muaf).
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->decimal('monthly_limit_usd', 12, 2)->nullable();
            $table->unsignedTinyInteger('warn_at_percent')->default(80);
            $table->boolean('hard_stop')->default(false);
            $table->string('warn_sent_month', 7)->nullable();
            $table->string('full_sent_month', 7)->nullable();
            $table->timestamps();
            $table->unique(['scope', 'scope_id']);
        });

        // Önceki şirket bütçesi ayarı (budget.monthly_usd) buraya taşınır. Eskiden bütçe dolunca her zaman
        // duruluyordu; davranış değişmesin diye taşınan bütçede hard_stop açık başlar.
        $legacy = DB::table('settings')->where('key', 'budget.monthly_usd')->value('value');
        $legacy = is_string($legacy) ? (json_decode($legacy, true) ?? $legacy) : $legacy;
        $limit = is_numeric($legacy) && (float) $legacy > 0 ? (float) $legacy : null;
        DB::table('budgets')->insert(['scope' => 'org', 'scope_id' => null, 'monthly_limit_usd' => $limit, 'warn_at_percent' => 80, 'hard_stop' => $limit !== null, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
