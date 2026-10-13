<?php

namespace App\Console\Commands;

use App\Models\AiCall;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fortis:usage:backfill-departments')]
#[Description('Departmanı boş ai_calls kayıtlarını kullanıcının şu anki departmanıyla doldurur (bir kerelik; tekrar çalıştırılabilir).')]
class BackfillUsageDepartments extends Command
{
    public function handle(): int
    {
        $updated = 0;
        User::query()->whereNotNull('department_id')->each(function (User $user) use (&$updated) {
            $updated += AiCall::query()->where('user_id', $user->id)->whereNull('department_id')->update(['department_id' => $user->department_id]);
        });

        $this->info("{$updated} kayıt dolduruldu. Kullanıcısı olmayan veya departmansız kullanıcıya ait kayıtlar boş kalır.");

        return self::SUCCESS;
    }
}
