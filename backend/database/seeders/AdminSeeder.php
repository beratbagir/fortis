<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Auth\Ownership;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    /**
     * FORTIS_ADMIN_EMAIL ile ilk admin. Geçici şifre konsola bir kez yazılır; ilk girişte değiştirilmesi istenir.
     * Kullanıcı sistemi gelmeden önce oluşan kayıtlar bu admin'e atanır.
     */
    public function run(): void
    {
        $email = mb_strtolower(trim((string) config('fortis.admin_email')));

        if ($email === '') {
            $this->command?->warn('FORTIS_ADMIN_EMAIL boş; admin oluşturulmadı. `php artisan fortis:user:create <e-posta> --role=admin` ile ekleyin.');

            return;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->command?->info("Admin zaten var: {$email}");
            Ownership::backfill();

            return;
        }

        $password = Str::password(16, symbols: false);

        User::create([
            'name' => Str::of($email)->before('@')->replace(['.', '_'], ' ')->title()->value(),
            'email' => $email,
            'password' => $password,
            'role' => User::ROLE_ADMIN,
            'avatar_color' => 'crimson',
            'must_change_password' => true,
        ]);

        Ownership::backfill();

        $this->command?->warn("Admin oluşturuldu: {$email}");
        $this->command?->warn("Geçici şifre: {$password}  (ilk girişte değiştirilmesi istenecek)");
    }
}
