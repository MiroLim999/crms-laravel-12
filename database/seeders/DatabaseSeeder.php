<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // No account is seeded: the Super Admin makes it on the Set up CRMS page
        // at the first visit, with their own email and password.
        $this->call([
            RoleSeeder::class,
            DocumentTemplateSeeder::class,
        ]);
    }
}
