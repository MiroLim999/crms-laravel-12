<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Account settings: the account's own name, email and password.
 */
class AccountSettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(Request $request): View
    {
        return view('settings.edit', ['user' => $request->user()]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->getKey())],
        ]);

        $validated['name'] = trim($validated['name']);
        $validated['email'] = mb_strtolower(trim($validated['email']));

        $user->fill($validated);

        if (! $user->isDirty()) {
            return back()->with('info', 'Nothing to save: your details were already like that.');
        }

        $this->audit->saveAndLog('user.updated', $user, 'Name or email changed from account settings.');

        return back()->with('success', 'Your details have been saved.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'current_password.current_password' => 'That is not your current password.',
        ]);

        $request->user()->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        $this->audit->log(
            'user.password_changed',
            $request->user(),
            description: 'Password changed from account settings.',
        );

        return back()->with('success', 'Your password has been updated.');
    }
}
