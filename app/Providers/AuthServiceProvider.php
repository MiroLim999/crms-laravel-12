<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * The abilities every route and view checks.
 *
 * CRMS has one account, the Super Admin, so each ability simply asks "is this the
 * Super Admin?". They stay named so routes and views read the same as before.
 */
class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([
            'documents.process',
            'change-requests.create',
            'records.view',
            'audit.view',
            'reports.generate',
            'templates.manage',
            'ocr.manage',
        ] as $ability) {
            Gate::define($ability, fn (User $u) => $u->isSuperAdmin());
        }
    }
}
