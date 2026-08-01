<?php

namespace App\Http\Requests;

use App\Enums\RoleSlug;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('users.update', $this->target());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->target()->getKey()),
            ],
            'role' => ['required', Rule::enum(RoleSlug::class)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('role')) {
                    return;
                }

                $role = RoleSlug::from($this->input('role'));
                $target = $this->target();

                // Granting a role is a separate decision from editing the account.
                if ($role !== $target->roleSlug()
                    && $this->user()->cannot('users.manage-role', $role)) {
                    $validator->errors()->add(
                        'role',
                        "You are not permitted to assign the {$role->label()} role.",
                    );
                }

                // Removing the last active Super Admin would lock everyone out of
                // template and OCR management with no way back in.
                if ($target->isSuperAdmin() && $role !== RoleSlug::SuperAdmin
                    && $this->isLastActiveSuperAdmin($target)) {
                    $validator->errors()->add(
                        'role',
                        'This is the only active Super Admin. Promote another one first.',
                    );
                }
            },
        ];
    }

    public function target(): User
    {
        return $this->route('user');
    }

    public function role(): RoleSlug
    {
        return RoleSlug::from($this->validated('role'));
    }

    private function isLastActiveSuperAdmin(User $user): bool
    {
        return User::query()
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->whereHas('role', fn ($q) => $q->where('slug', RoleSlug::SuperAdmin->value))
            ->doesntExist();
    }
}
