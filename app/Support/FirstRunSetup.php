<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * The one-time Set up CRMS step: needed while no account exists.
 *
 * Replaces a seeded account with a published default password. The Super Admin
 * chooses their own email and password on the first visit instead.
 */
class FirstRunSetup
{
    public static function needed(): bool
    {
        return ! User::query()->exists();
    }

    /**
     * Whether this request may do the setup: by default only from the CRMS
     * computer itself (config crms.setup.local_only).
     */
    public static function allowedFrom(Request $request): bool
    {
        if (! config('crms.setup.local_only')) {
            return true;
        }

        $client = $request->server('REMOTE_ADDR');

        // Loopback, or the server's own LAN address (opened as https://<server IP> on the server PC).
        return in_array($client, ['127.0.0.1', '::1'], true)
            || ($client !== null && $client === $request->server('SERVER_ADDR'));
    }
}
