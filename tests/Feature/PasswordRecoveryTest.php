<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "Forgot password?": ten one-time 6-digit recovery codes, made in Account
 * Settings, each of which sets a new password once.
 */
class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    /** Both steps: email + code, then (when the code is accepted) the new password. */
    private function recover(string $email, string $code, string $password = 'newpass123'): \Illuminate\Testing\TestResponse
    {
        $step1 = $this->post(route('password.recover.store'), ['email' => $email, 'code' => $code]);
        if (! session()->has('password_recovery')) {
            return $step1;
        }

        return $this->post(route('password.recover.reset'), [
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    public function test_the_new_password_is_asked_only_after_the_code_is_accepted(): void
    {
        $user = User::factory()->superAdmin()->create(['password' => 'password']);
        $codes = $user->generateRecoveryCodes();

        // Step 1 shows no password fields.
        $this->get(route('password.recover'))
            ->assertOk()
            ->assertSee('Recovery code')
            ->assertDontSee('name="password"', false);

        // The code is accepted but not used up yet.
        $this->post(route('password.recover.store'), ['email' => $user->email, 'code' => $codes[0]])
            ->assertRedirect(route('password.recover'));
        $this->assertSame(10, $user->refresh()->recoveryCodesLeft());
        $this->assertStringNotContainsString($codes[0], json_encode(session('password_recovery')));

        $this->get(route('password.recover'))
            ->assertOk()
            ->assertSee('Code accepted for')
            ->assertSee('name="password"', false)
            ->assertDontSee('name="code"', false);

        // Passwords that do not match: still on step 2, code still unused.
        $this->post(route('password.recover.reset'), ['password' => 'newpass123', 'password_confirmation' => 'other1234'])
            ->assertSessionHasErrors(['password' => 'The two passwords do not match.']);
        $this->assertSame(10, $user->refresh()->recoveryCodesLeft());
        $this->get(route('password.recover'))->assertSee('Code accepted for');

        $this->post(route('password.recover.reset'), ['password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertRedirect(route('login'));
        $this->assertSame(9, $user->refresh()->recoveryCodesLeft());
        $this->assertTrue(Hash::check('newpass123', $user->password));
        $this->get(route('password.recover'))->assertSee('Recovery code');
    }

    public function test_step_two_needs_step_one_and_closes_after_ten_minutes(): void
    {
        $user = User::factory()->superAdmin()->create(['password' => 'password']);
        $codes = $user->generateRecoveryCodes();

        $this->post(route('password.recover.reset'), ['password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertRedirect(route('password.recover'))
            ->assertSessionHas('warning');
        $this->assertTrue(Hash::check('password', $user->refresh()->password));

        $this->post(route('password.recover.store'), ['email' => $user->email, 'code' => $codes[0]]);
        $this->travel(11)->minutes();

        $this->post(route('password.recover.reset'), ['password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertRedirect(route('password.recover'))
            ->assertSessionHas('warning');
        $this->assertTrue(Hash::check('password', $user->refresh()->password));
        $this->assertSame(10, $user->recoveryCodesLeft());
    }

    public function test_use_another_code_goes_back_to_step_one(): void
    {
        $user = User::factory()->superAdmin()->create(['password' => 'password']);
        $codes = $user->generateRecoveryCodes();

        $this->post(route('password.recover.store'), ['email' => $user->email, 'code' => $codes[0]]);
        $this->get(route('password.recover', ['start-over' => 1]))->assertRedirect(route('password.recover'));
        $this->get(route('password.recover'))->assertSee('Recovery code')->assertDontSee('Code accepted for');
        $this->assertSame(10, $user->refresh()->recoveryCodesLeft());
    }

    public function test_the_sign_in_page_links_to_forgot_password(): void
    {
        User::factory()->create();

        $this->get(route('login'))->assertOk()->assertSee(route('password.recover'), false);
        $this->get(route('password.recover'))->assertOk()->assertSee('Recovery code');
    }

    public function test_settings_make_ten_long_codes_shown_once_and_stored_hashed(): void
    {
        $user = User::factory()->superAdmin()->create(['password' => 'password']);

        $response = $this->actingAs($user)
            ->from(route('settings.edit'))
            ->post(route('settings.recovery-codes.generate'), ['recovery_password' => 'password']);

        $response->assertRedirect(route('settings.edit'));
        $codes = session('recovery_codes');
        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));
        foreach ($codes as $code) {
            // 10 characters, Crockford base32: no I, L, O or U to misread.
            $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{10}$/', $code);
        }

        $user->refresh();
        $this->assertSame(10, $user->recoveryCodesLeft());
        $this->assertStringNotContainsString($codes[0], json_encode($user->recovery_codes));
        $this->assertTrue(AuditLog::where('action', 'user.recovery_codes_generated')->exists());
    }

    public function test_making_codes_needs_the_current_password(): void
    {
        $user = User::factory()->superAdmin()->create(['password' => 'password']);

        $this->actingAs($user)
            ->from(route('settings.edit'))
            ->post(route('settings.recovery-codes.generate'), ['recovery_password' => 'wrong'])
            ->assertSessionHasErrors('recovery_password');

        $this->assertSame(0, $user->refresh()->recoveryCodesLeft());
    }

    public function test_a_code_sets_a_new_password_and_works_only_once(): void
    {
        $user = User::factory()->superAdmin()->create(['password' => 'password']);
        $codes = $user->generateRecoveryCodes();

        $this->recover($user->email, $codes[3])
            ->assertRedirect(route('login'))
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertTrue(Hash::check('newpass123', $user->password));
        $this->assertSame(9, $user->recoveryCodesLeft());
        $this->assertTrue(AuditLog::where('action', 'user.password_recovered')->exists());

        // The same code again: refused, and the password stays.
        $this->recover($user->email, $codes[3], 'another123')->assertSessionHasErrors('code');
        $this->assertTrue(Hash::check('newpass123', $user->refresh()->password));

        // Another code still works; written with a space it is the same code.
        $this->recover($user->email, substr($codes[5], 0, 5).' '.substr($codes[5], 5), 'third1234')
            ->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('third1234', $user->refresh()->password));
        $this->assertSame(8, $user->recoveryCodesLeft());

        // Pasted with a narrow no-break space or a dash, or typed in lower case: still the code.
        $this->recover($user->email, ' '.substr($codes[6], 0, 5)."\u{202F}".substr($codes[6], 5).' ', 'fourth123')
            ->assertRedirect(route('login'));
        $this->recover($user->email, strtolower(substr($codes[7], 0, 5).'-'.substr($codes[7], 5)), 'fifth1234')
            ->assertRedirect(route('login'));
        $this->assertSame(6, $user->refresh()->recoveryCodesLeft());

        // Not a code's shape: the format message.
        $this->recover($user->email, '12345')
            ->assertSessionHasErrors(['code' => 'A recovery code is 10 letters and digits, like ABCDE-12345.']);
    }

    public function test_a_wrong_code_or_email_is_refused_the_same_way(): void
    {
        $user = User::factory()->superAdmin()->create(['password' => 'password']);
        $codes = $user->generateRecoveryCodes();
        $wrong = '0000000000';
        while (in_array($wrong, $codes, true)) {
            $wrong = str_pad((string) ((int) $wrong + 1), 10, '0', STR_PAD_LEFT);
        }

        $this->recover($user->email, $wrong)->assertSessionHasErrors(['code' => 'That email and recovery code do not match.']);
        $this->recover('nobody@example.com', $codes[0])->assertSessionHasErrors(['code' => 'That email and recovery code do not match.']);

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
        $this->assertSame(10, $user->recoveryCodesLeft());
    }

    public function test_new_codes_replace_the_old_ones(): void
    {
        $user = User::factory()->superAdmin()->create(['password' => 'password']);
        $old = $user->generateRecoveryCodes();
        $new = $user->generateRecoveryCodes();
        $stale = collect($old)->first(fn ($code) => ! in_array($code, $new, true));

        $this->recover($user->email, $stale)->assertSessionHasErrors('code');
        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_guessing_is_stopped_after_five_wrong_codes(): void
    {
        $user = User::factory()->superAdmin()->create(['password' => 'password']);
        $codes = $user->generateRecoveryCodes();
        $wrong = collect(range(0, 20))
            ->map(fn ($n) => str_pad((string) $n, 6, '0', STR_PAD_LEFT))
            ->reject(fn ($code) => in_array($code, $codes, true))
            ->take(5);

        foreach ($wrong as $code) {
            $this->recover($user->email, $code)->assertSessionHasErrors('code');
        }

        // Even the right code waits now.
        $this->recover($user->email, $codes[0])->assertSessionHasErrors('code');
        $this->assertStringContainsString('Too many tries', session('errors')->first('code'));
        $this->assertTrue(Hash::check('password', $user->refresh()->password));
        $this->assertSame(10, $user->recoveryCodesLeft());
    }

    public function test_look_alike_letters_are_read_as_digits(): void
    {
        $user = User::factory()->superAdmin()->create(['password' => 'password']);
        $codes = $user->generateRecoveryCodes();
        $withZeroOrOne = collect($codes)->first(fn ($code) => strpbrk($code, '01') !== false);
        if ($withZeroOrOne === null) {
            $this->markTestSkipped('No code in this set has a 0 or 1.');
        }

        // Read off paper: O for 0, l for 1.
        $typed = strtr(strtolower($withZeroOrOne), ['0' => 'O', '1' => 'l']);
        $this->recover($user->email, $typed)->assertRedirect(route('login'));
    }

    public function test_old_six_digit_codes_still_work_until_a_new_set_is_made(): void
    {
        $user = User::factory()->superAdmin()->create(['password' => 'password']);
        // A set made before the codes grew: plain keyed hashes of 6 digits.
        $old = ['123456', '654321'];
        $user->forceFill([
            'recovery_codes' => array_map(fn ($code) => hash_hmac('sha256', $code, (string) config('app.key')), $old),
        ])->save();
        $this->assertTrue($user->hasOldRecoveryCodes());

        // Account Settings asks for a new set.
        $this->actingAs($user)->get(route('settings.edit'))->assertOk()->assertSee('Make a new set of codes.');
        auth()->logout();

        $this->recover($user->email, '123 456')->assertRedirect(route('login'));
        $this->assertSame(1, $user->refresh()->recoveryCodesLeft());

        // A new set retires the rest, and the warning goes.
        $user->generateRecoveryCodes();
        $this->assertFalse($user->refresh()->hasOldRecoveryCodes());
        $this->recover($user->email, '654321')->assertSessionHasErrors('code');
        $this->actingAs($user)->get(route('settings.edit'))->assertDontSee('Make a new set of codes.');
    }
}
