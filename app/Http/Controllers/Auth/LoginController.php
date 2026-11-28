<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuditLogger;
use App\Services\SignIn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Sign-in and sign-out.
 *
 * There is no registration counterpart by design: the one account is made
 * once on the Set up CRMS page (SetupController).
 */
class LoginController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly SignIn $signIn) {}

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->authenticate();
        $remember = $request->boolean('remember');

        // The password was right; with an authenticator set up, its code comes next.
        if ($user->hasTwoFactor()) {
            SignIn::await($request, $user, $remember);

            return redirect()->route('two-factor.challenge');
        }

        // Not set up yet (an account from before two-factor sign-in, or one whose
        // authenticator was reset): signed in, and EnsureTwoFactorIsEnabled sends
        // it to set one up before anything else.
        return $this->signIn->complete($request, $user, $remember);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->audit->log('auth.logout', $request->user(), description: 'Signed out.');

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
