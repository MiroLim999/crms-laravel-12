<?php

namespace App\Http\Controllers\Auth;

use App\Enums\RoleSlug;
use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\FirstRunSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Set up CRMS: the one-time page where the Super Admin chooses their own name,
 * email and password. There is no default account with a known password.
 *
 * Open only while no account exists, and by default only on the CRMS computer
 * itself (FirstRunSetup::allowedFrom).
 */
class SetupController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(Request $request): RedirectResponse|View
    {
        if (! FirstRunSetup::needed()) {
            return redirect()->route('login');
        }

        return view('auth.setup', ['allowed' => FirstRunSetup::allowedFrom($request)]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! FirstRunSetup::needed()) {
            return redirect()->route('login')->with('info', 'CRMS is already set up. Sign in.');
        }

        abort_unless(FirstRunSetup::allowedFrom($request), 403, 'Set up CRMS on the CRMS computer itself.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $user = DB::transaction(function () use ($validated): ?User {
            $role = Role::firstOrCreate(
                ['slug' => RoleSlug::SuperAdmin->value],
                ['name' => RoleSlug::SuperAdmin->label(), 'description' => RoleSlug::SuperAdmin->description()],
            );
            // Two setup forms sent at once: the second waits here, then finds the account.
            Role::whereKey($role->getKey())->lockForUpdate()->first();

            // A locking read: it sees what is committed now, not the snapshot the
            // transaction's first read (firstOrCreate) took before the wait.
            if (User::query()->lockForUpdate()->exists()) {
                return null;
            }

            $user = User::create([
                'name' => trim($validated['name']),
                'email' => mb_strtolower(trim($validated['email'])),
                'password' => $validated['password'],
                'role_id' => $role->getKey(),
                'must_change_password' => false,
            ]);

            // The starter layouts were seeded before any account existed.
            DocumentTemplate::whereNull('created_by')->update(['created_by' => $user->getKey()]);

            return $user;
        });

        if ($user === null) {
            return redirect()->route('login')->with('info', 'CRMS is already set up. Sign in.');
        }

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['password_changed_at' => now(), 'last_login_at' => now()])->save();

        $this->audit->log('user.created', $user, description: 'Account made at Set up CRMS.');

        return redirect()
            ->route('settings.edit')
            ->with('success', 'CRMS is set up. Next, make your recovery codes below: they are the way back in if you forget your password.');
    }
}
