<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\User;
use App\Services\Auth\Audit;
use App\Services\Auth\Ownership;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

#[Signature('fortis:user:create {email} {--name= : Ad soyad} {--role=user : user | builder | admin} {--department= : Departman adı veya kısa adı} {--title= : Görev unvanı} {--password= : Verilmezse geçici şifre üretilir}')]
#[Description('Fortis kullanıcısı oluşturur. Geçici şifre ilk girişte değiştirilir.')]
class CreateUser extends Command
{
    public function handle(): int
    {
        $data = [
            'email' => mb_strtolower(trim((string) $this->argument('email'))),
            'role' => (string) $this->option('role'),
            'password' => $this->option('password'),
        ];

        $validator = Validator::make($data, [
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(User::ROLES)],
            'password' => ['nullable', 'string', 'min:'.config('fortis.password_min_length')],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $department = null;
        if ($this->option('department') !== null) {
            $department = Department::query()->where('slug', Str::slug((string) $this->option('department')))->orWhere('name', $this->option('department'))->first();

            if ($department === null) {
                $this->error('Departman bulunamadı. Var olanlar: '.Department::query()->orderBy('name')->pluck('name')->implode(', '));

                return self::FAILURE;
            }
        }

        $password = $data['password'] ?? Str::password(16, symbols: false);

        $user = User::create([
            'name' => $this->option('name') ?: Str::of($data['email'])->before('@')->replace(['.', '_'], ' ')->title()->value(),
            'email' => $data['email'],
            'password' => $password,
            'role' => $data['role'],
            'department_id' => $department?->id,
            'title' => $this->option('title'),
            'avatar_color' => Arr::random(User::AVATAR_COLORS),
            'must_change_password' => true,
        ]);

        Audit::record('user.create', $user, meta: ['email' => $user->email, 'role' => $user->role, 'via' => 'console']);

        if ($user->isAdmin()) {
            Ownership::backfill();
        }

        $this->info("Kullanıcı oluşturuldu: {$user->email} ({$user->role})");

        if ($data['password'] === null) {
            $this->warn("Geçici şifre: {$password}  (ilk girişte değiştirilmesi istenecek)");
        }

        return self::SUCCESS;
    }
}
