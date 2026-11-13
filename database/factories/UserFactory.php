<?php

namespace Database\Factories;

use App\Enums\RoleSlug;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * The one CRMS account is the Super Admin, so every made-up user is one too.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role_id' => fn () => Role::of(RoleSlug::SuperAdmin)->getKey(),
            'must_change_password' => false,
            'password_changed_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function role(RoleSlug $slug): static
    {
        return $this->state(fn () => ['role_id' => Role::of($slug)->getKey()]);
    }

    public function superAdmin(): static
    {
        return $this->role(RoleSlug::SuperAdmin);
    }

    /**
     * Holds a temporary password and must change it before doing anything else.
     */
    public function withTemporaryPassword(): static
    {
        return $this->state(fn () => [
            'must_change_password' => true,
            'password_changed_at' => null,
        ]);
    }
}
