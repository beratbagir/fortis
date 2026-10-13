<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * İlk kurulum: departmanlar, ilk admin (FORTIS_ADMIN_EMAIL) ve agent şablonları.
     */
    public function run(): void
    {
        $this->call([DepartmentSeeder::class, AdminSeeder::class, AgentTemplateSeeder::class]);
    }
}
