<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The one-time Set up CRMS step: needed while no account exists.
 *
 * Replaces a seeded account with a published default password. The Super Admin
 * chooses their own email and password on the first visit instead.
 *
 * Who may do it:
 *  - by default (crms.setup.local_only) only the CRMS computer itself, judged by
 *    the request's address;
 *  - where that cannot be judged (Docker: every request comes from Docker's own
 *    network), whoever types the one-time setup token. Only someone who can run
 *    commands on the CRMS computer can read it (php artisan crms:setup-token),
 *    so a page opened first from elsewhere cannot claim the account.
 */
class FirstRunSetup
{
    /** On the local disk (storage/app/private), never in the web root. */
    private const TOKEN_FILE = 'setup-token';

    /** Crockford base32, as the recovery codes: no I, L, O or U to misread. */
    private const TOKEN_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const TOKEN_LENGTH = 12;

    public static function needed(): bool
    {
        return ! User::query()->exists();
    }

    /**
     * Whether this request may see the setup form: by default only from the
     * CRMS computer itself (config crms.setup.local_only). With that check off,
     * anyone may see it, and the form asks for the setup token instead.
     */
    public static function allowedFrom(Request $request): bool
    {
        if (self::needsToken()) {
            return true;
        }

        $client = $request->server('REMOTE_ADDR');

        // Loopback, or the server's own LAN address (opened as https://<server IP> on the server PC).
        return in_array($client, ['127.0.0.1', '::1'], true)
            || ($client !== null && $client === $request->server('SERVER_ADDR'));
    }

    /** Whether the form asks for the one-time setup token (the address check is off). */
    public static function needsToken(): bool
    {
        return ! config('crms.setup.local_only');
    }

    /** The setup token, made the first time it is asked for and kept until setup is done. */
    public static function token(): string
    {
        $disk = Storage::disk('local');

        $existing = $disk->exists(self::TOKEN_FILE) ? self::normalize((string) $disk->get(self::TOKEN_FILE)) : '';
        if (strlen($existing) === self::TOKEN_LENGTH) {
            return $existing;
        }

        $last = strlen(self::TOKEN_ALPHABET) - 1;
        $token = '';
        for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
            $token .= self::TOKEN_ALPHABET[random_int(0, $last)];
        }
        $disk->put(self::TOKEN_FILE, $token);

        return $token;
    }

    /** The token as people read it: three groups of four. */
    public static function formatted(string $token): string
    {
        return implode('-', str_split($token, 4));
    }

    /** Whether `typed` is the setup token (any case, dashes and spaces ignored). */
    public static function tokenMatches(?string $typed): bool
    {
        $typed = self::normalize((string) $typed);

        return strlen($typed) === self::TOKEN_LENGTH && hash_equals(self::token(), $typed);
    }

    /** Once the account exists the token is no use to anyone: delete it. */
    public static function forgetToken(): void
    {
        Storage::disk('local')->delete(self::TOKEN_FILE);
    }

    private static function normalize(string $token): string
    {
        $token = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $token) ?? '');

        return strtr($token, ['O' => '0', 'I' => '1', 'L' => '1']);
    }
}
