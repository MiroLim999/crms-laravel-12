<?php

namespace App\Console\Commands;

use App\Enums\RoleSlug;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * The account's name and email can be changed once from Account Settings.
 * A mistake in that one change? Run this on the CRMS computer: they can be
 * changed once more.
 */
class UnlockDetails extends Command
{
    protected $signature = 'crms:unlock-details';

    protected $description = 'Let the CRMS account change its name and email once more';

    public function handle(AuditLogger $audit): int
    {
        $user = User::where('role_id', Role::of(RoleSlug::SuperAdmin)->getKey())->orderBy('id')->first();

        if ($user === null) {
            $this->error('There is no account yet. Run: php artisan db:seed');

            return self::FAILURE;
        }

        if ($user->details_locked_at === null) {
            $this->info('The name and email are not locked: they can already be changed once in Account Settings.');

            return self::SUCCESS;
        }

        $user->forceFill(['details_locked_at' => null])->save();

        $audit->log(
            'user.details_unlocked',
            $user,
            description: 'Name and email unlocked from the command line (crms:unlock-details).',
            actor: $user,
        );

        $this->info('Done. The name and email can be changed once more in Account Settings.');

        return self::SUCCESS;
    }
}
