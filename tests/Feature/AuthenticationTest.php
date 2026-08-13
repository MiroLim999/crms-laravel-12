<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_renders(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Sign In', false);
    }

    /**
     * There is no public self-registration. Nothing should answer on these paths.
     */
    public function test_no_registration_routes_exist(): void
    {
        $this->assertFalse(\Route::has('register'));

        foreach (['register', 'signup', 'sign-up'] as $path) {
            $this->get("/{$path}")->assertNotFound();
        }
    }

    public function test_users_can_sign_in(): void
    {
        $user = User::factory()->staff()->create(['password' => 'password']);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_authenticated_layout_has_responsive_navigation_controls(): void
    {
        $user = User::factory()->staff()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('layout-menu-fixed-offcanvas', escape: false)
            ->assertSee('class="btn btn-sm btn-icon btn-outline-secondary rounded-pill layout-menu-toggle global-sidebar-toggle', escape: false)
            ->assertSee('data-menu-toggle-control', escape: false)
            ->assertSee('aria-controls="layout-menu"', escape: false)
            ->assertDontSee('sidebar-menu-toggle', escape: false);
    }

    public function test_sign_in_is_audit_logged(): void
    {
        $user = User::factory()->staff()->create(['password' => 'password']);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->getKey(),
            'action' => 'auth.login',
        ]);
    }

    public function test_users_cannot_sign_in_with_a_bad_password(): void
    {
        $user = User::factory()->staff()->create(['password' => 'password']);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_deactivated_accounts_cannot_sign_in(): void
    {
        $user = User::factory()->staff()->inactive()->create(['password' => 'password']);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_account_deactivated_mid_session_is_signed_out(): void
    {
        $user = User::factory()->staff()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_users_can_sign_out(): void
    {
        $user = User::factory()->staff()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_sign_in_is_rate_limited(): void
    {
        $user = User::factory()->staff()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            'Too many sign-in attempts',
            session('errors')->first('email'),
        );
    }

    // ------------------------------------------------- forced password change

    public function test_a_temporary_password_locks_the_user_to_the_change_screen(): void
    {
        $user = User::factory()->staff()->withTemporaryPassword()->create();

        foreach (['dashboard', 'records.index', 'documents.create', 'settings.edit'] as $route) {
            $this->actingAs($user)
                ->get(route($route))
                ->assertRedirect(route('password.change'));
        }

        $this->actingAs($user)->get(route('password.change'))->assertOk();
    }

    public function test_changing_the_temporary_password_releases_the_lock(): void
    {
        $user = User::factory()->staff()->withTemporaryPassword()->create([
            'password' => 'temp-password',
        ]);

        $this->actingAs($user)->post(route('password.change.store'), [
            'current_password' => 'temp-password',
            'password' => 'NewPassw0rd',
            'password_confirmation' => 'NewPassw0rd',
        ])->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check('NewPassw0rd', $user->password));

        $this->actingAs($user)->get(route('records.index'))->assertOk();
    }

    public function test_the_temporary_password_cannot_be_reused(): void
    {
        $user = User::factory()->staff()->withTemporaryPassword()->create([
            'password' => 'TempPass123',
        ]);

        $this->actingAs($user)->post(route('password.change.store'), [
            'current_password' => 'TempPass123',
            'password' => 'TempPass123',
            'password_confirmation' => 'TempPass123',
        ])->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_logout_is_reachable_while_a_password_change_is_pending(): void
    {
        $user = User::factory()->staff()->withTemporaryPassword()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));
    }

    // ------------------------------------------------------- account settings

    public function test_any_role_can_change_their_own_password(): void
    {
        $user = User::factory()->admin()->create(['password' => 'password']);

        $this->actingAs($user)->put(route('settings.password.update'), [
            'current_password' => 'password',
            'password' => 'AnotherPass1',
            'password_confirmation' => 'AnotherPass1',
        ])->assertSessionHas('success');

        $this->assertTrue(Hash::check('AnotherPass1', $user->fresh()->password));
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = User::factory()->admin()->create(['password' => 'password']);

        $this->actingAs($user)->put(route('settings.password.update'), [
            'current_password' => 'not-my-password',
            'password' => 'AnotherPass1',
            'password_confirmation' => 'AnotherPass1',
        ])->assertSessionHasErrors('current_password');
    }

    public function test_audit_entries_never_store_raw_passwords(): void
    {
        $user = User::factory()->staff()->create(['password' => 'password']);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        foreach (AuditLog::all() as $entry) {
            $blob = json_encode([$entry->old_values, $entry->new_values]);
            $this->assertStringNotContainsString('password"', (string) $blob);
        }
    }
}
