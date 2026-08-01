<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locks an account with a temporary password to the change-password screen.
 *
 * Accounts provisioned by a Super Admin receive a generated temporary password.
 * Until it is replaced, every route other than the change-password form and
 * logout is blocked. This runs app-wide rather than per-route so a new feature
 * cannot accidentally forget to apply it.
 */
class EnsurePasswordIsChanged
{
    /**
     * Routes reachable while a password change is pending.
     */
    private const ALLOWED = [
        'password.change',
        'password.change.store',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->must_change_password && ! $request->routeIs(self::ALLOWED)) {
            if ($request->expectsJson()) {
                abort(403, 'You must change your temporary password before continuing.');
            }

            return redirect()
                ->route('password.change')
                ->with('warning', 'Please choose a new password before continuing.');
        }

        return $next($request);
    }
}
