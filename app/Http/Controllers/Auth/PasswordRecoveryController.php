<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * "Forgot password?": a new password set with one of the account's one-time
 * recovery codes (made in Account Settings), in two steps:
 *
 *  1. email + code: checked, not yet used up;
 *  2. the new password: saved, and the code crossed off.
 *
 * Between the two, the session holds the account and the code's stored form
 * (never the code itself) for a few minutes.
 *
 * A 6-digit code is short, so attempts are limited per email (wherever they
 * come from): a few tries an hour, then a wait.
 */
class PasswordRecoveryController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 3600;

    /** How long step 2 stays open after the code is accepted. */
    private const STEP_TWO_MINUTES = 10;

    private const SESSION_KEY = 'password_recovery';

    public function __construct(private readonly AuditLogger $audit) {}

    public function create(Request $request): View|RedirectResponse
    {
        if ($request->boolean('start-over')) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('password.recover');
        }

        $pending = $this->pending($request);

        return view('auth.forgot-password', [
            'verifiedEmail' => $pending ? $pending['user']->email : null,
        ]);
    }

    /** Step 1: the email and the code. */
    public function store(Request $request): RedirectResponse
    {
        // Keep only the digits: a code may be typed or pasted as "123 456",
        // "123-456" or with a copied narrow space between the halves.
        $request->merge(['code' => preg_replace('/\D+/u', '', (string) $request->input('code', ''))]);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ], [
            'email.required' => 'Enter the email you sign in with.',
            'email.email' => 'Enter a valid email address.',
            'code.required' => 'Enter one of your 6-digit recovery codes.',
            'code.regex' => 'A recovery code is 6 digits.',
        ]);

        $key = 'password-recovery|'.Str::transliterate(Str::lower(trim($validated['email'])));
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages([
                'code' => "Too many tries. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.',
            ]);
        }

        $user = User::where('email', mb_strtolower(trim($validated['email'])))->first();
        $hash = $user?->matchRecoveryCode($validated['code']);
        if ($hash === null) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            if ($user !== null) {
                $this->audit->log('user.recovery_failed', $user, description: 'A wrong recovery code was entered on the sign-in page.', actor: $user);
            }
            // The same answer whether the email or the code is wrong.
            throw ValidationException::withMessages([
                'code' => 'That email and recovery code do not match.',
            ])->redirectTo(route('password.recover'));
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, [
            'user' => $user->getKey(),
            'code' => $hash,
            'until' => now()->addMinutes(self::STEP_TWO_MINUTES)->getTimestamp(),
        ]);

        return redirect()->route('password.recover');
    }

    /** Step 2: the new password. */
    public function reset(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);
        if ($pending === null) {
            return redirect()
                ->route('password.recover')
                ->with('warning', 'That took too long. Enter your email and recovery code again.');
        }

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'password.required' => 'Choose a new password.',
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        $user = $pending['user'];
        $request->session()->forget(self::SESSION_KEY);

        // Crossed off now; if it was used meanwhile (another tab), start again.
        if (! $user->useRecoveryCodeHash($pending['code'])) {
            return redirect()
                ->route('password.recover')
                ->with('warning', 'That recovery code was already used. Enter another one.');
        }

        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        $left = $user->recoveryCodesLeft();
        $this->audit->log(
            'user.password_recovered',
            $user,
            description: "Password set with a recovery code on the sign-in page ({$left} code".($left === 1 ? '' : 's').' left).',
            actor: $user,
        );

        $warning = $left <= 2 ? ' Make a new set in Account Settings soon.' : '';

        return redirect()
            ->route('login')
            ->with('success', "Your password has been changed. Sign in with it. {$left} recovery code".($left === 1 ? '' : 's')." left.{$warning}");
    }

    /**
     * The accepted step 1 still waiting for its new password: the account and
     * the code's stored form, or null (none, expired, or the code is gone).
     *
     * @return array{user: User, code: string}|null
     */
    private function pending(Request $request): ?array
    {
        $state = $request->session()->get(self::SESSION_KEY);
        if (! is_array($state) || ($state['until'] ?? 0) < now()->getTimestamp()) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        $user = User::find($state['user'] ?? null);
        if ($user === null || ! $user->hasRecoveryCodeHash((string) ($state['code'] ?? ''))) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return ['user' => $user, 'code' => $state['code']];
    }
}
