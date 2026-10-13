<?php

use App\Services\Auth\Ownership;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Var olan kayıtlara sahip bilgisi. Kolonlar boş bırakılabilir; mevcut kayıtlar ilk admin'e atanır
 * (admin henüz yoksa ilk admin oluşturulurken atanır, bkz. Ownership::backfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('flows', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('owner_id')->constrained()->nullOnDelete();
        });

        Schema::table('executions', function (Blueprint $table) {
            $table->foreignId('triggered_by_user_id')->nullable()->after('started_by')->constrained('users')->nullOnDelete();
        });

        Schema::table('ai_calls', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        foreach (['reports', 'artifacts'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            });
        }

        Ownership::backfill();
    }

    public function down(): void
    {
        Schema::table('conversations', fn (Blueprint $table) => $table->dropConstrainedForeignId('user_id'));
        Schema::table('flows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
            $table->dropConstrainedForeignId('department_id');
        });
        Schema::table('executions', fn (Blueprint $table) => $table->dropConstrainedForeignId('triggered_by_user_id'));
        Schema::table('ai_calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropConstrainedForeignId('department_id');
        });
        Schema::table('reports', fn (Blueprint $table) => $table->dropConstrainedForeignId('user_id'));
        Schema::table('artifacts', fn (Blueprint $table) => $table->dropConstrainedForeignId('user_id'));
    }
};
