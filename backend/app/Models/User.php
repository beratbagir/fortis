<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Fortis kullanıcısı. Kayıt ekranı yok; kullanıcıyı admin ekler. Rol: user < builder < admin.
 */
#[Fillable(['name', 'email', 'password', 'department_id', 'role', 'title', 'avatar_color', 'is_active', 'must_change_password', 'theme', 'locale', 'last_login_at', 'entra_object_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_USER = 'user';

    public const ROLE_BUILDER = 'builder';

    public const ROLE_ADMIN = 'admin';

    public const ROLES = [self::ROLE_USER, self::ROLE_BUILDER, self::ROLE_ADMIN];

    public const THEMES = ['system', 'light', 'dark'];

    /** Arayüz ve cevap dili. Varsayılan İngilizce. */
    public const LOCALES = ['en', 'tr'];

    /** Avatar renkleri: arayüzde tema token'larına eşlenir (sabit renk kodu tutulmaz). */
    public const AVATAR_COLORS = ['crimson', 'blue', 'green', 'amber', 'violet', 'slate'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /** Akış (agent) oluşturabilir, düzenleyebilir, yayınlayabilir. */
    public function canBuild(): bool
    {
        return in_array($this->role, [self::ROLE_BUILDER, self::ROLE_ADMIN], true);
    }
}
