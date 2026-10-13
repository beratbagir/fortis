<?php

namespace App\Services\Auth;

use App\Models\AiCall;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kullanıcı sistemi gelmeden önce oluşan kayıtlar (sohbet, akış, rapor...) ilk admin'e atanır.
 * Migration'da admin varsa hemen, yoksa ilk admin oluşturulurken çalışır. Sadece boş kolonları doldurur.
 */
class Ownership
{
    public static function backfill(): void
    {
        $admin = DB::table('users')->where('role', User::ROLE_ADMIN)->orderBy('id')->first(['id', 'department_id']);

        if ($admin === null || ! Schema::hasColumn('conversations', 'user_id')) {
            return;
        }

        foreach (['conversations' => 'user_id', 'reports' => 'user_id', 'artifacts' => 'user_id', 'flows' => 'owner_id'] as $table => $column) {
            DB::table($table)->whereNull($column)->update([$column => $admin->id]);
        }

        // Akış adımlarının AI çağrılarını kimse başlatmadı; sadece sohbet çağrıları atanır.
        DB::table('ai_calls')->whereNull('user_id')->where('source', AiCall::SOURCE_CHAT)->update(['user_id' => $admin->id]);

        if ($admin->department_id !== null) {
            DB::table('flows')->whereNull('department_id')->update(['department_id' => $admin->department_id]);
            DB::table('ai_calls')->where('user_id', $admin->id)->whereNull('department_id')->update(['department_id' => $admin->department_id]);
        }
    }
}
