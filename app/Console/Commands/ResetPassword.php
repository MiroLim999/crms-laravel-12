<?php

namespace App\Console\Commands;

use App\Enums\RoleSlug;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * Forgot the password? Run this on the CRMS computer.
 *
 * CRMS has one account and no email when offline, so nobody else can reset it.
 * This gives the account a new temporary password, prints it once, and makes the
 * account choose its own password at the next sign-in.
 */
class ResetPassword extends Command
{
    protected $signature = 'crms:reset-password';

    protected $description = 'Give the CRMS account a new temporary password (for a forgotten password)';

    public function handle(AuditLogger $audit): int
    {
        $user = User::where('role_id', Role::of(RoleSlug::SuperAdmin)->getKey())->orderBy('id')->first();

        if ($user === null) {
            $this->error('There is no account yet. Open CRMS in the browser to set it up.');

            return self::FAILURE;
        }

        $temporary = self::temporaryPassword();

        $user->forceFill([
            'password' => $temporary,
            'must_change_password' => true,
            'password_changed_at' => null,
        ])->save();

        // Whoever runs this is at the CRMS computer; the phone may be the thing
        // that was lost. The authenticator is set up again after the new password.
        $hadTwoFactor = $user->hasTwoFactor();
        $user->forgetTwoFactor();

        $audit->log(
            'user.password_reset',
            $user,
            description: 'Password reset from the command line (crms:reset-password)'.($hadTwoFactor ? '; the authenticator app must be set up again.' : '.'),
            actor: $user,
        );

        $this->info("Email:              {$user->email}");
        $this->info("Temporary password: {$temporary}");
        $this->line('Sign in with these. You will then be asked to choose your own password,');
        $this->line('then to set up the authenticator app on your phone again.');
        $this->line('This password is shown only once.');

        return self::SUCCESS;
    }

    /**
     * Readable but unguessable: no 0/O or 1/l/I, so it can be copied off the screen.
     */
    public static function temporaryPassword(int $length = 12): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $alphabet = $upper.$lower.$digits;

        // One of each kind, so it already meets the rules for a chosen password.
        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
        ];

        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        // Fisher-Yates with random_int: str_shuffle is not secure enough for a credential.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
