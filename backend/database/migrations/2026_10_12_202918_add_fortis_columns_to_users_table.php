<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kullanıcının departmanı, rolü ve giriş bilgileri. Entra ID ile giren kullanıcının şifresi olmayabilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->foreignId('department_id')->nullable()->after('email')->constrained()->nullOnDelete();
            $table->string('role')->default('user')->after('department_id');
            $table->string('title')->nullable()->after('role');
            $table->string('avatar_color')->nullable()->after('title');
            $table->boolean('is_active')->default(true)->after('avatar_color');
            $table->boolean('must_change_password')->default(false)->after('is_active');
            $table->string('theme')->default('system')->after('must_change_password');
            $table->timestamp('last_login_at')->nullable()->after('theme');
            $table->string('entra_object_id')->nullable()->unique()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropUnique(['entra_object_id']);
            $table->dropColumn(['role', 'title', 'avatar_color', 'is_active', 'must_change_password', 'theme', 'last_login_at', 'entra_object_id']);
        });
    }
};
