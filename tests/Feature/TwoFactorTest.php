<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two-factor sign-in with an authenticator app: required for the account,
 * asked after the password, offline (TOTP).
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function twoFactor(): TwoFactor
    {
        return app(TwoFactor::class);
    }

    private function password(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    }

    // ------------------------------------------------------------- required

    public function test_an_account_without_an_authenticator_must_set_one_up_first(): void
    {
        $user = User::factory()->withoutTwoFactor()->create();

        // Password alone signs in, then every page leads to the setup.
        $this->password($user)->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertRedirect(route('two-factor.setup'));
        $this->get(route('records.index'))->assertRedirect(route('two-factor.setup'));
        $this->getJson(route('documents.ocr-status'))->assertForbidden();

        $page = $this->get(route('two-factor.setup'))->assertOk()->assertSee('<svg', false)->assertSee('Google Authenticator');

        // The key on the page is the one the code must come from.
        $secret = session()->has('two_factor_setup')
            ? \Illuminate\Support\Facades\Crypt::decryptString(session('two_factor_setup'))
            : null;
        $this->assertNotNull($secret);
        $page->assertSee($this->twoFactor()->formatSecret($secret));

        // A wrong code: not turned on.
        $this->post(route('two-factor.setup.store'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($user->refresh()->hasTwoFactor());

        // The right one: on, and the app opens.
        $this->post(route('two-factor.setup.store'), ['code' => $this->twoFactor()->currentCode($secret)])
            ->assertRedirect(route('settings.edit'));
        $this->assertTrue($user->refresh()->hasTwoFactor());
        $this->assertTrue(AuditLog::where('action', 'user.two_factor_enabled')->exists());
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('two-factor.setup'))->assertRedirect(route('dashboard'));
    }

    public function test_the_secret_is_stored_encrypted(): void
    {
        $user = User::factory()->create();
        $raw = DB::table('users')->where('id', $user->getKey())->value('two_factor_secret');

        $this->assertNotSame($user->two_factor_secret, $raw);
        $this->assertStringNotContainsString($user->two_factor_secret, (string) $raw);
        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
    }

    // ------------------------------------------------------------- sign in

    public function test_the_password_alone_does_not_sign_in(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->password($user)->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('two-factor.challenge'))->assertOk()->assertSee('Authenticator code');
        $this->assertFalse(AuditLog::where('action', 'auth.login')->exists());
    }

    public function test_the_code_page_needs_a_right_password_first(): void
    {
        // An account exists (otherwise every page leads to Set up CRMS).
        User::factory()->create();

        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
        $this->post(route('two-factor.challenge.store'), ['code' => '123456'])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_wrong_code_is_refused_and_logged(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $right = $this->twoFactor()->currentCode($user->two_factor_secret);
        $wrong = $right === '000000' ? '000001' : '000000';

        $this->password($user);
        $this->post(route('two-factor.challenge.store'), ['code' => $wrong])->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertSame(1, AuditLog::where('action', 'auth.two_factor_failed')->count());
    }

    public function test_a_code_works_only_once(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $code = $this->twoFactor()->currentCode($user->two_factor_secret);

        $this->password($user);
        $this->post(route('two-factor.challenge.store'), ['code' => $code])->assertRedirect(route('dashboard'));
        $this->post(route('logout'));

        // The same code again, as if read off the screen: refused.
        $this->password($user);
        $this->post(route('two-factor.challenge.store'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_guessing_is_paused_after_five_wrong_codes(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $right = $this->twoFactor()->currentCode($user->two_factor_secret);

        $this->password($user);
        $wrong = collect(range(0, 20))
            ->map(fn ($n) => str_pad((string) $n, 6, '0', STR_PAD_LEFT))
            ->reject(fn ($code) => $code === $right)
            ->take(5);
        foreach ($wrong as $code) {
            $this->post(route('two-factor.challenge.store'), ['code' => $code])->assertSessionHasErrors('code');
        }

        // Even the right code waits now.
        $this->post(route('two-factor.challenge.store'), ['code' => $right])->assertSessionHasErrors('code');
        $this->assertStringContainsString('Too many wrong codes', session('errors')->first('code'));
        $this->assertGuest();
    }

    public function test_the_code_page_expires(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $this->password($user);

        $this->travel(6)->minutes();
        $this->post(route('two-factor.challenge.store'), ['code' => $this->twoFactor()->currentCode($user->two_factor_secret)])
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_recovery_code_signs_in_without_the_phone_and_is_used_up(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $codes = $user->generateRecoveryCodes();

        $this->password($user);
        $this->post(route('two-factor.challenge.store'), ['recovery_code' => $codes[2]])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('warning');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(9, $user->refresh()->recoveryCodesLeft());
        $this->post(route('logout'));

        // Used up.
        $this->password($user);
        $this->post(route('two-factor.challenge.store'), ['recovery_code' => $codes[2]])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    // ------------------------------------------------------------- new phone

    public function test_a_new_phone_needs_the_password_and_then_a_new_setup(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $oldSecret = $user->two_factor_secret;

        $this->actingAs($user)
            ->from(route('settings.edit'))
            ->post(route('settings.two-factor.reset'), ['two_factor_password' => 'wrong'])
            ->assertSessionHasErrors('two_factor_password');
        $this->assertTrue($user->refresh()->hasTwoFactor());

        $this->actingAs($user)
            ->post(route('settings.two-factor.reset'), ['two_factor_password' => 'password'])
            ->assertRedirect(route('two-factor.setup'));

        $user->refresh();
        $this->assertFalse($user->hasTwoFactor());
        $this->assertTrue(AuditLog::where('action', 'user.two_factor_reset')->exists());
        // Required: nothing else opens until the new phone is set up.
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('two-factor.setup'));
        $this->assertNotSame($oldSecret, $user->two_factor_secret);
    }

    public function test_reset_password_from_the_command_line_also_resets_the_authenticator(): void
    {
        $user = User::factory()->create();

        $this->artisan('crms:reset-password')->assertSuccessful();

        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertFalse($user->hasTwoFactor());
    }

    public function test_account_settings_shows_two_factor_is_on(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Two-factor sign-in')
            ->assertSee('Set up on a new phone');
    }
}
