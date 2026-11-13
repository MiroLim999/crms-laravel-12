<?php

namespace Database\Seeders;

use App\Enums\RoleSlug;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Makes the one CRMS account, the Super Admin.
 *
 * Only when it is missing: running `php artisan db:seed` again never resets an
 * existing account's name or password. The first password comes from
 * config/crms.php and must be replaced at the first sign-in. A forgotten
 * password is reset with `php artisan crms:reset-password`.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('crms.super_admin.email');
        $role = Role::of(RoleSlug::SuperAdmin);

        if (User::where('role_id', $role->getKey())->exists()) {
            $this->command?->info('Super Admin already exists: left as it is.');

            return;
        }

        User::create([
            'name' => config('crms.super_admin.name'),
            'email' => $email,
            'password' => config('crms.super_admin.password'),
            'role_id' => $role->getKey(),
            'must_change_password' => true,
        ]);

        $this->command?->info("Super Admin ready: {$email} (choose your own password at the first sign-in)");
    }
}
