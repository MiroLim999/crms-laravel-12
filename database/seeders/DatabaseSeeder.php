<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Order matters: the Super Admin needs its role to exist first.
        $this->call([
            RoleSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
