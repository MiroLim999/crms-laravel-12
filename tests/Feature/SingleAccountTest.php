<?php

namespace Tests\Feature;

use App\Console\Commands\ResetPassword;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The gaps closed when CRMS went to one account (docs/SUPER-ADMIN-ONLY-PLAN.md):
 * G1 edit your own name and email, G2 choose your own password at the first
 * sign-in, G3 reset a forgotten password from the command line.
 */
class SingleAccountTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ G1

    public function test_settings_show_your_details_without_a_role(): void
    {
        $user = User::factory()->create(['name' => 'Rosa Reyes', 'email' => 'rosa@example.test']);

        $this->actingAs($user)
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Your details')
            ->assertSee('value="Rosa Reyes"', false)
            ->assertSee('value="rosa@example.test"', false)
            ->assertDontSee('managed by an administrator')
            ->assertDontSee('>Role<', false);
    }

    public function test_you_can_change_your_name_and_email(): void
    {
        $user = User::factory()->create(['name' => 'Super Admin', 'email' => 'superadmin@admin.com']);

        $this->actingAs($user)
            ->from(route('settings.edit'))
            ->put(route('settings.profile.update'), ['name' => '  Rosa Reyes ', 'email' => 'Rosa@Example.test'])
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('Rosa Reyes', $user->name);
        $this->assertSame('rosa@example.test', $user->email);

        $entry = AuditLog::where('action', 'user.updated')->firstOrFail();
        $this->assertEquals(['name' => 'Super Admin', 'email' => 'superadmin@admin.com'], $entry->old_values);
        $this->assertEquals(['name' => 'Rosa Reyes', 'email' => 'rosa@example.test'], $entry->new_values);

        // The new name shows top right.
        $this->actingAs($user)->get(route('dashboard'))->assertSee('Rosa Reyes');
    }

    public function test_the_email_must_be_valid_and_unused(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('settings.profile.update'), ['name' => '', 'email' => 'taken@example.test'])
            ->assertSessionHasErrors(['name', 'email']);

        $this->actingAs($user)
            ->put(route('settings.profile.update'), ['name' => 'Rosa', 'email' => 'not-an-email'])
            ->assertSessionHasErrors('email');
    }

    public function test_saving_unchanged_details_writes_no_audit_entry(): void
    {
        $user = User::factory()->create(['name' => 'Rosa', 'email' => 'rosa@example.test']);

        $this->actingAs($user)
            ->put(route('settings.profile.update'), ['name' => 'Rosa', 'email' => 'rosa@example.test'])
            ->assertSessionHas('info');

        $this->assertSame(0, AuditLog::where('action', 'user.updated')->count());
    }

    // ------------------------------------------------------------------ G2

    public function test_the_seeder_makes_the_account_with_a_first_password_to_replace(): void
    {
        $this->seed(SuperAdminSeeder::class);

        $user = User::where('email', config('crms.super_admin.email'))->firstOrFail();
        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check(config('crms.super_admin.password'), $user->password));
    }

    public function test_running_the_seeder_again_never_resets_the_account(): void
    {
        $this->seed(SuperAdminSeeder::class);
        $user = User::firstOrFail();
        $user->forceFill([
            'name' => 'Rosa Reyes',
            'password' => 'MyOwnPassword9',
            'must_change_password' => false,
        ])->save();

        $this->seed(SuperAdminSeeder::class);

        $user->refresh();
        $this->assertSame(1, User::count());
        $this->assertSame('Rosa Reyes', $user->name);
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('MyOwnPassword9', $user->password));
    }

    public function test_the_first_sign_in_asks_you_to_choose_your_own_password(): void
    {
        $this->seed(SuperAdminSeeder::class);

        $this->post(route('login.store'), [
            'email' => config('crms.super_admin.email'),
            'password' => config('crms.super_admin.password'),
        ])->assertRedirect(route('password.change'));

        $this->get(route('dashboard'))->assertRedirect(route('password.change'));

        $this->get(route('password.change'))
            ->assertOk()
            ->assertSee('Choose your own password before you start.')
            ->assertSee('Current password')
            ->assertDontSee('Temporary password');

        $this->post(route('password.change.store'), [
            'current_password' => config('crms.super_admin.password'),
            'password' => 'MyOwnPassword9',
            'password_confirmation' => 'MyOwnPassword9',
        ])->assertRedirect(route('dashboard'));

        $this->assertFalse(User::firstOrFail()->must_change_password);
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_the_sign_in_page_has_no_administrator_line(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('administrator')
            ->assertDontSee('Need access?');
    }

    // ------------------------------------------------------------------ G3

    public function test_the_reset_command_gives_a_temporary_password_and_asks_for_a_new_one(): void
    {
        $user = User::factory()->create(['email' => 'rosa@example.test', 'password' => 'Forgotten123']);

        $this->artisan('crms:reset-password')
            ->expectsOutputToContain('rosa@example.test')
            ->expectsOutputToContain('Temporary password:')
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertFalse(Hash::check('Forgotten123', $user->password));

        $entry = AuditLog::where('action', 'user.password_reset')->firstOrFail();
        $this->assertSame($user->getKey(), $entry->user_id);
        $this->assertStringContainsString('crms:reset-password', $entry->description);
    }

    public function test_the_printed_password_signs_in_and_must_be_replaced(): void
    {
        User::factory()->create(['email' => 'rosa@example.test']);

        Artisan::call('crms:reset-password');
        $this->assertSame(1, preg_match('/Temporary password:\s+(\S+)/', Artisan::output(), $match));

        $this->post(route('login.store'), ['email' => 'rosa@example.test', 'password' => $match[1]])
            ->assertRedirect(route('password.change'));
    }

    public function test_the_reset_command_says_so_when_there_is_no_account(): void
    {
        $this->artisan('crms:reset-password')
            ->expectsOutputToContain('There is no account yet')
            ->assertFailed();
    }

    public function test_temporary_passwords_meet_the_password_rules(): void
    {
        foreach (range(1, 20) as $ignored) {
            $password = ResetPassword::temporaryPassword();
            $this->assertSame(12, strlen($password));
            $this->assertMatchesRegularExpression('/[A-Za-z]/', $password);
            $this->assertMatchesRegularExpression('/\d/', $password);
            $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $password);
        }
    }
}
