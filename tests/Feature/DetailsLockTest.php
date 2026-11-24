<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The account's name and email can be changed once from Account Settings;
 * `php artisan crms:unlock-details` allows one more change.
 */
class DetailsLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_change_saves_and_locks_both(): void
    {
        $user = User::factory()->superAdmin()->create(['name' => 'Rosa', 'email' => 'rosa@example.test']);

        $this->actingAs($user)
            ->get(route('settings.edit'))
            ->assertSee('You can change your name and email only once.')
            ->assertSee('Save details');

        $this->actingAs($user)
            ->from(route('settings.edit'))
            ->put(route('settings.profile.update'), ['name' => 'Rosa Reyes', 'email' => 'rosa@example.test'])
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('Rosa Reyes', $user->name);
        $this->assertNotNull($user->details_locked_at);

        $this->actingAs($user)
            ->get(route('settings.edit'))
            ->assertSee('Locked: changed once')
            ->assertDontSee('Save details');
    }

    public function test_a_locked_account_cannot_change_them_again(): void
    {
        $user = User::factory()->superAdmin()->create(['name' => 'Rosa', 'email' => 'rosa@example.test']);
        $user->forceFill(['details_locked_at' => now()])->save();

        $this->actingAs($user)
            ->from(route('settings.edit'))
            ->put(route('settings.profile.update'), ['name' => 'Someone Else', 'email' => 'else@example.test'])
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHas('error');

        $user->refresh();
        $this->assertSame('Rosa', $user->name);
        $this->assertSame('rosa@example.test', $user->email);
    }

    public function test_saving_without_a_change_does_not_lock(): void
    {
        $user = User::factory()->superAdmin()->create(['name' => 'Rosa', 'email' => 'rosa@example.test']);

        $this->actingAs($user)
            ->put(route('settings.profile.update'), ['name' => 'Rosa', 'email' => 'rosa@example.test'])
            ->assertSessionHas('info');

        $this->assertNull($user->refresh()->details_locked_at);
    }

    public function test_the_unlock_command_allows_one_more_change(): void
    {
        $user = User::factory()->superAdmin()->create(['name' => 'Rosa', 'email' => 'rosa@example.test']);
        $user->forceFill(['details_locked_at' => now()])->save();

        $this->artisan('crms:unlock-details')->assertSuccessful();

        $this->assertNull($user->refresh()->details_locked_at);
        $this->assertTrue(AuditLog::where('action', 'user.details_unlocked')->exists());
    }
}
