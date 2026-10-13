<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DepartmentSeeder extends Seeder
{
    /**
     * Tasarımdaki departmanlar. Var olanlara dokunmaz.
     */
    public function run(): void
    {
        foreach (Department::DEFAULTS as $name) {
            Department::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
        }
    }
}
