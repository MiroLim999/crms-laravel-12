<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SignIn;
use App\Services\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Step 2 of signing in: the 6-digit code from the authenticator app, after a
 * right password (LoginController). A recovery code works instead when the
 * phone is not at hand; it is crossed off as for "Forgot password?".
 *
 * Nobody is signed in until this step passes. A few wrong codes pause it.
 */
class TwoFactorChallengeController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 900;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SignIn $signIn,
        private readonly TwoFactor $twoFactor,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        if (SignIn::pending($request) === null) {
            return $this->startOver();
        }

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $pending = SignIn::pending($request);
        if ($pending === null) {
            return $this->startOver();
        }

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:32', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ], [
            'code.required_without' => 'Enter the 6-digit code from your authenticator app.',
        ]);

        /** @var User $user */
        $user = $pending['user'];
        $key = 'two-factor|'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages([
                'code' => "Too many wrong codes. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.',
            ]);
        }

        // A recovery code: for a phone that is lost, broken or not at hand.
        if (filled($validated['recovery_code'] ?? null)) {
            if ($user->useRecoveryCode((string) $validated['recovery_code'])) {
                RateLimiter::clear($key);
                $left = $user->recoveryCodesLeft();

                return $this->signIn
                    ->complete($request, $user, $pending['remember'], "Signed in with a recovery code instead of the authenticator app ({$left} code".($left === 1 ? '' : 's').' left).')
                    ->with('warning', 'You signed in with a recovery code. If your phone is lost, set up the authenticator again in Account Settings, and make new recovery codes.');
            }

            return $this->refuse($user, $key, 'recovery_code', 'That recovery code is not right, or was already used.');
        }

        $step = $this->twoFactor->verify((string) $user->two_factor_secret, (string) $validated['code'], $user->two_factor_last_step);
        if ($step === null) {
            return $this->refuse($user, $key, 'code', 'That code is not right. Enter the code your app shows now (check that this computer\'s clock is right).');
        }

        RateLimiter::clear($key);
        // Each code works once: a code seen on the screen cannot be used again.
        $user->forceFill(['two_factor_last_step' => $step])->save();

        return $this->signIn->complete($request, $user, $pending['remember']);
    }

    private function refuse(User $user, string $key, string $field, string $message): never
    {
        RateLimiter::hit($key, self::DECAY_SECONDS);
        $this->audit->log(
            'auth.two_factor_failed',
            $user,
            description: $field === 'recovery_code'
                ? 'A wrong recovery code was entered at the authenticator step of signing in.'
                : 'A wrong authenticator code was entered after a right password.',
            actor: $user,
        );

        throw ValidationException::withMessages([$field => $message]);
    }

    private function startOver(): RedirectResponse
    {
        return redirect()
            ->route('login')
            ->with('warning', 'Sign in again: enter your email and password first.');
    }
}
