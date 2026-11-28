<?php

namespace App\Http\Requests\Auth;

use App\Services\AuditLogger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** Wrong sign-ins for one email from one address before a pause. */
    private const MAX_ATTEMPTS = 5;

    /**
     * Wrong sign-ins from one address, whatever email is typed, before it is
     * paused for an hour. Each one is written to the audit log, which can never
     * be trimmed, so a script trying a new email every time must not be able to
     * add rows without end. Far above what a person mistyping ever reaches.
     */
    private const MAX_ATTEMPTS_PER_IP = 30;

    private const IP_DECAY_SECONDS = 3600;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Attempt authentication, rate limited per email + IP, and per IP alone.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->ipThrottleKey(), self::IP_DECAY_SECONDS);

            // The typed password is never logged, only the email it was tried with.
            $email = Str::limit((string) $this->string('email'), 255, '');
            $audit = app(AuditLogger::class);
            $audit->log('auth.login_failed', description: "Wrong password or email ({$email}).");

            if (RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
                $audit->log('auth.locked_out', description: "Too many failed sign-ins for {$email}; sign-in paused.");
            }
            // Logged once, on the attempt that reaches the limit; the paused
            // attempts after it are refused before they reach the log.
            if (RateLimiter::attempts($this->ipThrottleKey()) === self::MAX_ATTEMPTS_PER_IP) {
                $audit->log('auth.locked_out', description: 'Too many failed sign-ins from '.$this->ip().' with different emails; sign-in from there paused for an hour.');
            }

            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(): void
    {
        foreach ([$this->throttleKey() => self::MAX_ATTEMPTS, $this->ipThrottleKey() => self::MAX_ATTEMPTS_PER_IP] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $seconds = RateLimiter::availableIn($key);

                throw ValidationException::withMessages([
                    'email' => "Too many sign-in attempts. Try again in {$seconds} seconds.",
                ]);
            }
        }
    }

    private function ipThrottleKey(): string
    {
        return 'login-ip|'.$this->ip();
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
