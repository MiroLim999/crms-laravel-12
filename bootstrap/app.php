<?php

use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureSetupIsDone;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        /*
         * Runs app-wide on every web request rather than per route group, so a
         * newly added feature cannot forget to apply it. A no-op for guests.
         */
        $middleware->web(append: [
            EnsureSetupIsDone::class,
            EnsurePasswordIsChanged::class,
        ]);
        $middleware->append(SecurityHeaders::class);

        // Refuse Host headers other than APP_URL's host (and its subdomains) and
        // config('crms.trusted_hosts'). Laravel skips this in APP_ENV=local and tests.
        $middleware->trustHosts(at: fn () => array_map(
            fn (string $host) => '^'.preg_quote($host).'$',
            config('crms.trusted_hosts'),
        ));
        // Before the sign-in check: with no account yet, "set up" comes before "sign in".
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureSetupIsDone::class);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
