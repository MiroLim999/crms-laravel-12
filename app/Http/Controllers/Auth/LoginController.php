<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Sign-in and sign-out.
 *
 * There is no registration counterpart by design: all accounts are provisioned by
 * a Super Admin (Staff and Admin) or seeded (the bootstrap Super Admin).
 */
class LoginController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        $user = $request->user();

        // Deactivated accounts must not gain a session, even with valid credentials.
        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors(['email' => 'This account has been deactivated.']);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->log('auth.login', $user, description: 'Signed in.');

        if ($user->must_change_password) {
            return redirect()
                ->route('password.change')
                ->with('warning', 'Your password is temporary. Please choose a new one.');
        }

        return redirect()->intended(route('dashboard'));
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
