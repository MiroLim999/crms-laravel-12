<?php

namespace Database\Seeders;

use App\Enums\RoleSlug;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds the one account that cannot be created through the UI.
 *
 * Every other account is provisioned by a Super Admin, so this is the bootstrap
 * entry point into the system.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('crms.super_admin.email');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => config('crms.super_admin.name'),
                'password' => config('crms.super_admin.password'),
                'role_id' => Role::of(RoleSlug::SuperAdmin)->getKey(),
                // Not a temporary password - this is the documented bootstrap
                // credential, so it does not force a change on first login.
                'must_change_password' => false,
                'is_active' => true,
                'password_changed_at' => now(),
            ],
        );

        $this->command?->info("Super Admin ready: {$email}");
    }
}
