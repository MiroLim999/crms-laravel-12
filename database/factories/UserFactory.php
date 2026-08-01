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
     * Defaults to Staff: the role that actually does the work, and the one most
     * tests care about.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role_id' => fn () => Role::of(RoleSlug::Staff)->getKey(),
            'must_change_password' => false,
            'is_active' => true,
            'password_changed_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function role(RoleSlug $slug): static
    {
        return $this->state(fn () => ['role_id' => Role::of($slug)->getKey()]);
    }

    public function staff(): static
    {
        return $this->role(RoleSlug::Staff);
    }

    public function admin(): static
    {
        return $this->role(RoleSlug::Admin);
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

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
