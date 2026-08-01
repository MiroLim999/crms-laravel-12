<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Forced password change for accounts holding a temporary password.
 *
 * Reachable while EnsurePasswordIsChanged is blocking everything else, which is
 * why it is a separate flow from the voluntary change in account settings.
 */
class PasswordChangeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(Request $request): RedirectResponse|View
    {
        // Nothing to force - send them on their way.
        if (! $request->user()->must_change_password) {
            return redirect()->route('dashboard');
        }

        return view('auth.change-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'current_password.current_password' => 'That is not your current password.',
        ]);

        $user = $request->user();

        // Reusing the temporary password would defeat the point of forcing a change.
        if (Hash::check($validated['password'], $user->password)) {
            return back()->withErrors([
                'password' => 'Choose a password different from your temporary one.',
            ]);
        }

        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        $this->audit->log(
            'user.password_changed',
            $user,
            description: 'Temporary password replaced by the account holder.',
        );

        return redirect()
            ->route('dashboard')
            ->with('success', 'Your password has been updated.');
    }
}
