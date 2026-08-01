<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\UserProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Account provisioning and oversight.
 *
 * Admin manages Staff accounts; Super Admin manages everyone. Accounts are
 * deactivated rather than deleted so the audit trail keeps pointing at a real row.
 */
class UserController extends Controller
{
    public function __construct(private readonly UserProvisioner $provisioner) {}

    public function index(Request $request): View
    {
        $users = User::query()
            ->with(['role', 'creator'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q').'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->when($request->filled('role'), fn ($q) => $q->whereHas(
                'role',
                fn ($r) => $r->where('slug', $request->string('role')),
            ))
            ->when($request->filled('status'), fn ($q) => $q->where(
                'is_active',
                $request->string('status')->value() === 'active',
            ))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'roles' => Role::orderBy('id')->get(),
        ]);
    }

    public function create(): View
    {
        return view('users.create', ['assignableRoles' => $this->assignableRoles()]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        ['user' => $user, 'temporary_password' => $temporary] = $this->provisioner->create(
            $request->validated('name'),
            $request->validated('email'),
            $request->role(),
            $request->user(),
        );

        /*
         * The temporary password is flashed once so the administrator can pass it
         * on. It is not stored anywhere in plain text and is not in the audit log.
         */
        return redirect()
            ->route('users.index')
            ->with('provisioned', [
                'name' => $user->name,
                'email' => $user->email,
                'password' => $temporary,
            ]);
    }

    public function edit(User $user): View
    {
        $this->authorize('users.update', $user);

        return view('users.edit', [
            'user' => $user->load('role'),
            'assignableRoles' => $this->assignableRoles($user),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->provisioner->update(
            $user,
            $request->validated('name'),
            $request->validated('email'),
            $request->role(),
            $request->user(),
        );

        return redirect()
            ->route('users.index')
            ->with('success', "Updated {$user->email}.");
    }

    /**
     * Issue a fresh temporary password. The holder is forced to change it on their
     * next sign-in.
     */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->authorize('users.update', $user);

        $temporary = $this->provisioner->resetPassword($user, $request->user());

        return redirect()
            ->route('users.index')
            ->with('provisioned', [
                'name' => $user->name,
                'email' => $user->email,
                'password' => $temporary,
                'reset' => true,
            ]);
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        $this->authorize('users.deactivate', $user);

        $this->provisioner->setActive($user, false, $request->user());

        return back()->with('success', "Deactivated {$user->email}.");
    }

    public function activate(Request $request, User $user): RedirectResponse
    {
        $this->authorize('users.update', $user);

        $this->provisioner->setActive($user, true, $request->user());

        return back()->with('success', "Reactivated {$user->email}.");
    }

    /**
     * Roles the signed-in user may hand out, keeping the form honest rather than
     * relying on validation to reject a bad choice.
     *
     * @return Collection<int, Role>
     */
    private function assignableRoles(?User $target = null): Collection
    {
        return Role::orderBy('id')->get()->filter(function (Role $role) use ($target) {
            // Always keep the target's current role selectable, so editing an
            // account never silently changes it.
            if ($target && $role->slug === $target->roleSlug()) {
                return true;
            }

            return request()->user()->can('users.manage-role', $role->slug);
        })->values();
    }
}
