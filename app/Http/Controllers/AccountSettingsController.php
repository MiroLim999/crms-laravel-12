<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Account settings: the account's own name, email and password, its
 * recovery codes, and how its uploads are checked.
 */
class AccountSettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(Request $request): View
    {
        return view('settings.edit', ['user' => $request->user()]);
    }

    /**
     * Name and email can be changed once: the first save that changes them
     * locks both (`php artisan crms:unlock-details` opens them again).
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->details_locked_at !== null) {
            return back()->with('error', 'Your name and email were already changed once and can no longer be edited here.');
        }

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
        // That was the one change allowed: both are locked from now on.
        $user->forceFill(['details_locked_at' => now()])->save();

        return back()->with('success', 'Your details have been saved. Your name and email are now locked.');
    }

    /** Whether uploaded files are checked for copies of what was saved or queued before. */
    public function updateUploads(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill(['duplicate_file_check' => $request->boolean('duplicate_file_check')]);

        if (! $user->isDirty('duplicate_file_check')) {
            return back()->with('info', 'Nothing to save: it was already '.($user->duplicate_file_check ? 'on' : 'off').'.');
        }

        $on = $user->duplicate_file_check;
        $this->audit->saveAndLog(
            'user.updated',
            $user,
            'Duplicate file check turned '.($on ? 'on' : 'off').' from account settings.',
        );

        return back()->with('success', 'Duplicate file check is now '.($on ? 'on' : 'off').'.');
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

    /**
     * Ten new one-time recovery codes for "Forgot password?", replacing the old
     * ones. They are shown once, on the page this redirects to.
     */
    public function generateRecoveryCodes(Request $request): RedirectResponse
    {
        $request->validate([
            'recovery_password' => ['required', 'current_password'],
        ], [
            'recovery_password.current_password' => 'That is not your current password.',
        ]);

        $codes = $request->user()->generateRecoveryCodes();

        $this->audit->log(
            'user.recovery_codes_generated',
            $request->user(),
            description: 'New recovery codes made from account settings (the old ones no longer work).',
        );

        return back()
            ->with('recovery_codes', $codes)
            ->with('success', 'Your new recovery codes are ready. Write them down now: they are shown only once.');
    }
}
