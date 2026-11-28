<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor sign-in is required: an account without an authenticator app set
 * up is held on the setup page until it has one.
 *
 * Runs app-wide after EnsurePasswordIsChanged, so a temporary password is
 * replaced first, then the app is set up. A no-op for guests.
 */
class EnsureTwoFactorIsEnabled
{
    /** Routes reachable while the authenticator is not set up. */
    private const ALLOWED = [
        'two-factor.setup',
        'two-factor.setup.store',
        'password.change',
        'password.change.store',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->hasTwoFactor() && ! $request->routeIs(self::ALLOWED)) {
            if ($request->expectsJson()) {
                abort(403, 'Set up the authenticator app before continuing.');
            }

            return redirect()->route('two-factor.setup');
        }

        return $next($request);
    }
}
