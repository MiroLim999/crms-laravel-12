<?php

namespace App\Console\Commands;

use App\Support\FirstRunSetup;
use Illuminate\Console\Command;

/**
 * Shows the one-time token the Set up CRMS page asks for when it cannot tell
 * that it was opened on the CRMS computer itself (Docker):
 *
 *   docker compose exec web php artisan crms:setup-token
 *
 * Running a command here is the proof of being on the CRMS computer.
 */
class SetupToken extends Command
{
    protected $signature = 'crms:setup-token';

    protected $description = 'Show the one-time token for the Set up CRMS page';

    public function handle(): int
    {
        if (! FirstRunSetup::needed()) {
            FirstRunSetup::forgetToken();
            $this->info('CRMS is already set up: there is no setup token. Sign in instead.');

            return self::SUCCESS;
        }

        if (! FirstRunSetup::needsToken()) {
            $this->info('The Set up CRMS page needs no token here: open it on this computer.');

            return self::SUCCESS;
        }

        $this->line('Setup token: <options=bold>'.FirstRunSetup::formatted(FirstRunSetup::token()).'</>');
        $this->line('Type it on the Set up CRMS page. It stops working once the account is made.');

        return self::SUCCESS;
    }
}
