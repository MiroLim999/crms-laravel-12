<?php

namespace App\Providers;

use App\Enums\RoleSlug;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * The capability matrix, in code.
 *
 * This is the single authoritative translation of the table in
 * .kiro/steering/product.md. Every route and view guard must reference one of
 * these abilities rather than testing roles inline.
 *
 * Note what is deliberately absent: there is no ability that lets Admin write
 * record values. Data entry belongs to Staff, and corrections go through the
 * change-request flow. Do not add one.
 */
class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // A deactivated account can do nothing, whatever its role.
        Gate::before(fn (User $user) => $user->is_active ? null : false);

        // -------------------------------------------------- data entry (Staff, Super Admin)
        Gate::define('documents.process', fn (User $u) => $u->canEnterData());
        Gate::define('records.submit', fn (User $u) => $u->canEnterData());
        Gate::define('change-requests.create', fn (User $u) => $u->canEnterData());

        // -------------------------------------------------- archive (everyone signed in)
        Gate::define('records.view', fn (User $u) => $u->roleSlug() !== null);

        // -------------------------------------------------- oversight (Admin, Super Admin)
        Gate::define('change-requests.moderate', fn (User $u) => $u->hasOversight());
        Gate::define('analytics.view', fn (User $u) => $u->hasOversight());
        Gate::define('users.manage', fn (User $u) => $u->hasOversight());
        Gate::define('audit.view', fn (User $u) => $u->hasOversight());
        Gate::define('reports.generate', fn (User $u) => $u->hasOversight());

        // -------------------------------------------------- Super Admin only
        Gate::define('templates.manage', fn (User $u) => $u->isSuperAdmin());
        Gate::define('ocr.manage', fn (User $u) => $u->isSuperAdmin());

        /*
         * Provisioning a specific role.
         *
         * Admin user management covers Staff accounts only. Creating another Admin,
         * or touching a Super Admin, requires a Super Admin.
         */
        Gate::define('users.manage-role', function (User $actor, RoleSlug $target) {
            if ($actor->isSuperAdmin()) {
                return true;
            }

            return $actor->isAdmin() && $target === RoleSlug::Staff;
        });

        /*
         * Editing an existing account. Nobody but a Super Admin may modify a
         * Super Admin, and no one may deactivate themselves out of the system.
         */
        Gate::define('users.update', function (User $actor, User $target) {
            if ($target->isSuperAdmin() && ! $actor->isSuperAdmin()) {
                return false;
            }

            return $actor->hasOversight();
        });
    }
}
