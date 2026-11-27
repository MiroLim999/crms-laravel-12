<?php

namespace App\Http\Middleware;

use App\Support\FirstRunSetup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While no account exists, every page leads to the one-time Set up CRMS page.
 *
 * Runs app-wide on web requests, like EnsurePasswordIsChanged. Once the
 * account exists this is one cheap query and nothing else.
 */
class EnsureSetupIsDone
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('setup', 'setup.store') && FirstRunSetup::needed()) {
            if ($request->expectsJson()) {
                abort(503, 'CRMS is not set up yet.');
            }

            return redirect()->route('setup');
        }

        return $next($request);
    }
}
