<?php

namespace App\Models;

use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Freebird departmanı (Commercial, Finance...). Kullanıcı, akış ve AI maliyeti departmana bağlanır.
 */
#[Fillable(['name', 'slug'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    /** Tasarımdaki departmanlar; ilk kurulumda eklenir. */
    public const DEFAULTS = [
        'Commercial',
        'Finance',
        'Revenue Mgmt',
        'Fuel Efficiency',
        'Network Planning',
        'Customer Relations',
        'Flight Ops',
        'Ground Ops',
    ];

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
