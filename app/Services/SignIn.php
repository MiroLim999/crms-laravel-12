<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The last part of signing in, shared by both ways it can end: the password
 * page (an account whose authenticator is not set up yet) and the
 * authenticator code page.
 */
class SignIn
{
    /** Session key of a password that was right, waiting for the authenticator code. */
    public const PENDING = 'login.two_factor';

    /** How long the code page waits after the password before starting over. */
    public const PENDING_MINUTES = 5;

    public function __construct(private readonly AuditLogger $audit) {}

    public function complete(Request $request, User $user, bool $remember, string $description = 'Signed in.'): RedirectResponse
    {
        $request->session()->forget(self::PENDING);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->log('auth.login', $user, description: $description);

        if ($user->must_change_password) {
            return redirect()
                ->route('password.change')
                ->with('warning', 'Choose your own password before you start.');
        }

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Remember a right password while the code is asked for. Nobody is signed
     * in yet: until the code is right, the session only holds who it is for.
     */
    public static function await(Request $request, User $user, bool $remember): void
    {
        $request->session()->regenerate();
        $request->session()->put(self::PENDING, [
            'user' => $user->getKey(),
            'remember' => $remember,
            'until' => now()->addMinutes(self::PENDING_MINUTES)->getTimestamp(),
        ]);
    }

    /** @return array{user: User, remember: bool}|null */
    public static function pending(Request $request): ?array
    {
        $state = $request->session()->get(self::PENDING);
        if (! is_array($state) || ($state['until'] ?? 0) < now()->getTimestamp()) {
            $request->session()->forget(self::PENDING);

            return null;
        }

        $user = User::find($state['user'] ?? null);
        if ($user === null || ! $user->hasTwoFactor()) {
            $request->session()->forget(self::PENDING);

            return null;
        }

        return ['user' => $user, 'remember' => (bool) ($state['remember'] ?? false)];
    }
}
