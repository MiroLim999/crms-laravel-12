<?php

namespace Database\Seeders;

use App\Enums\RoleSlug;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local-only demo accounts, one per non-seeded role.
 *
 * Deliberately NOT registered in DatabaseSeeder - a fresh install should contain
 * only the bootstrap Super Admin. Run it explicitly while user management is still
 * being built:
 *
 *   php artisan db:seed --class=DemoUsersSeeder
 *
 * Delete this seeder before any real deployment.
 */
class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoUsersSeeder must never run in production.');

            return;
        }

        $superAdmin = User::where('email', config('crms.super_admin.email'))->first();

        $accounts = [
            [RoleSlug::Staff, 'Demo Staff', 'staff@crms.test'],
            [RoleSlug::Admin, 'Demo Admin', 'admin@crms.test'],
        ];

        foreach ($accounts as [$slug, $name, $email]) {
            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => 'password123',
                    'role_id' => Role::of($slug)->getKey(),
                    'must_change_password' => false,
                    'is_active' => true,
                    'password_changed_at' => now(),
                    'created_by' => $superAdmin?->getKey(),
                ],
            );

            $this->command?->info("Demo {$slug->label()}: {$email} / password123");
        }
    }
}
