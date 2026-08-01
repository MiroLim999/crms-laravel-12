<?php

namespace App\Http\Requests;

use App\Enums\RoleSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('users.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(RoleSlug::class)],
        ];
    }

    /**
     * The role being granted is itself an authorization decision: Admin may only
     * provision Staff, while Super Admin may provision any role.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('role')) {
                    return;
                }

                $role = RoleSlug::from($this->input('role'));

                if ($this->user()->cannot('users.manage-role', $role)) {
                    $validator->errors()->add(
                        'role',
                        "You are not permitted to create {$role->label()} accounts.",
                    );
                }
            },
        ];
    }

    public function role(): RoleSlug
    {
        return RoleSlug::from($this->validated('role'));
    }
}
