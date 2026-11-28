<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Setting up the authenticator app: scan the QR code, then type the code the
 * app shows to prove it works. Required (EnsureTwoFactorIsEnabled), so an
 * account without one sees only this page until it is done.
 *
 * The new key waits in the session (encrypted) until a code from it is
 * right; only then is it saved, so a half-done setup never locks anyone out.
 */
class TwoFactorSetupController extends Controller
{
    private const SESSION_KEY = 'two_factor_setup';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TwoFactor $twoFactor,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->hasTwoFactor()) {
            return redirect()->route('dashboard');
        }

        $secret = $this->pendingSecret($request);

        return view('auth.two-factor-setup', [
            'qrCode' => $this->twoFactor->qrCodeSvg($user, $secret),
            'secret' => $this->twoFactor->formatSecret($secret),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->hasTwoFactor()) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ], [
            'code.required' => 'Enter the 6-digit code your app shows.',
        ]);

        $secret = $this->pendingSecret($request);
        $step = $this->twoFactor->verify($secret, $validated['code']);
        if ($step === null) {
            throw ValidationException::withMessages([
                'code' => 'That code is not right. Enter the code the app shows now, and check that this computer\'s clock is right.',
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
        ])->save();
        $request->session()->forget(self::SESSION_KEY);

        $this->audit->log(
            'user.two_factor_enabled',
            $user,
            description: 'Authenticator app set up; signing in now needs its code.',
        );

        return redirect()
            ->route('settings.edit')
            ->with('success', 'The authenticator app is set up: signing in now asks for its code. Make recovery codes below if you have none: they are the way in if the phone is lost.');
    }

    /** The key being set up, made once per setup and kept encrypted in the session. */
    private function pendingSecret(Request $request): string
    {
        $stored = $request->session()->get(self::SESSION_KEY);
        if (is_string($stored)) {
            try {
                return Crypt::decryptString($stored);
            } catch (\Throwable) {
                // An old or damaged value: make a new key below.
            }
        }

        $secret = $this->twoFactor->newSecret();
        $request->session()->put(self::SESSION_KEY, Crypt::encryptString($secret));

        return $secret;
    }
}
