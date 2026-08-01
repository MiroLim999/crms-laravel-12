<?php

namespace App\Services;

use App\Enums\RoleSlug;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Account provisioning.
 *
 * There is no self-registration, so this is the only way an account comes into
 * existence besides the seeded bootstrap Super Admin. Every operation writes an
 * audit entry.
 *
 * Temporary passwords are returned to the caller exactly once, for display to the
 * administrator who created the account. They are never persisted in plain text
 * and never written to the audit log.
 */
class UserProvisioner
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Create an account with a generated temporary password.
     *
     * @return array{user: User, temporary_password: string}
     */
    public function create(string $name, string $email, RoleSlug $role, User $actor): array
    {
        $temporary = $this->generateTemporaryPassword();

        $user = DB::transaction(function () use ($name, $email, $role, $actor, $temporary) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $temporary,
                'role_id' => Role::of($role)->getKey(),
                'must_change_password' => true,
                'is_active' => true,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->log(
                'user.created',
                $user,
                new: ['name' => $name, 'email' => $email, 'role' => $role->value],
                description: "Provisioned {$role->label()} account for {$email}.",
                actor: $actor,
            );

            return $user;
        });

        return ['user' => $user, 'temporary_password' => $temporary];
    }

    /**
     * Update the editable fields of an account. Passwords are not touched here.
     */
    public function update(User $user, string $name, string $email, RoleSlug $role, User $actor): User
    {
        $user->fill([
            'name' => $name,
            'email' => $email,
            'role_id' => Role::of($role)->getKey(),
        ]);

        if (! $user->isDirty()) {
            return $user;
        }

        $this->audit->saveAndLog('user.updated', $user, "Updated account {$user->email}.");

        return $user;
    }

    /**
     * Issue a fresh temporary password, forcing another change on next sign-in.
     *
     * @return string The new temporary password, for one-time display.
     */
    public function resetPassword(User $user, User $actor): string
    {
        $temporary = $this->generateTemporaryPassword();

        DB::transaction(function () use ($user, $temporary, $actor) {
            $user->forceFill([
                'password' => $temporary,
                'must_change_password' => true,
                'password_changed_at' => null,
            ])->save();

            $this->audit->log(
                'user.password_reset',
                $user,
                description: "Issued a new temporary password for {$user->email}.",
                actor: $actor,
            );
        });

        return $temporary;
    }

    /**
     * Deactivate rather than delete, so the audit trail keeps referring to a
     * real account.
     */
    public function setActive(User $user, bool $active, User $actor): User
    {
        if ($user->is_active === $active) {
            return $user;
        }

        $user->is_active = $active;

        $this->audit->saveAndLog(
            $active ? 'user.activated' : 'user.deactivated',
            $user,
            ($active ? 'Reactivated' : 'Deactivated')." account {$user->email}.",
        );

        return $user;
    }

    /**
     * Readable but unguessable. Avoids ambiguous glyphs so it survives being
     * read aloud or copied off a screen.
     */
    public function generateTemporaryPassword(): string
    {
        $length = max(8, (int) config('crms.temporary_password_length', 12));

        // Ambiguous glyphs (0/O, 1/l/I) omitted so the password survives being
        // read off a screen or dictated over the phone.
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $alphabet = $upper.$lower.$digits;

        // Seed with one of each class so the result already satisfies the rules
        // the holder must meet when they choose their own password.
        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
        ];

        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        // Fisher-Yates with random_int. str_shuffle is not cryptographically secure
        // and this is a credential.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
