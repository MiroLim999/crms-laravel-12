<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DocumentTemplate;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Set up CRMS: the one-time page that makes the Super Admin with an email and
 * password of their own, in place of a seeded account with a known password.
 */
class FirstRunSetupTest extends TestCase
{
    use RefreshDatabase;

    private const FORM = [
        'name' => ' Rosa Reyes ',
        'email' => 'Rosa@Example.test',
        'password' => 'MyOwnPassword9',
        'password_confirmation' => 'MyOwnPassword9',
    ];

    public function test_every_page_leads_to_setup_while_there_is_no_account(): void
    {
        $this->get(route('login'))->assertRedirect(route('setup'));
        $this->get('/dashboard')->assertRedirect(route('setup'));
        $this->get(route('password.recover'))->assertRedirect(route('setup'));

        $this->get(route('setup'))
            ->assertOk()
            ->assertSee('Set up CRMS')
            ->assertSee('Create Account');
    }

    public function test_json_requests_are_refused_until_setup(): void
    {
        $this->getJson('/dashboard')->assertStatus(503);
    }

    public function test_setup_makes_the_super_admin_and_signs_in(): void
    {
        $this->post(route('setup.store'), self::FORM)
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHas('success');

        $user = User::sole();
        $this->assertSame('Rosa Reyes', $user->name);
        $this->assertSame('rosa@example.test', $user->email);
        $this->assertTrue($user->isSuperAdmin());
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check('MyOwnPassword9', $user->password));
        $this->assertAuthenticatedAs($user);

        $this->assertSame($user->getKey(), AuditLog::where('action', 'user.created')->sole()->user_id);
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_setup_works_without_the_role_seeded(): void
    {
        // `php artisan migrate` without --seed.
        Role::query()->delete();

        $this->post(route('setup.store'), self::FORM)->assertRedirect(route('settings.edit'));

        $this->assertTrue(User::sole()->isSuperAdmin());
    }

    public function test_the_starter_layouts_are_credited_to_the_new_account(): void
    {
        $this->seed();
        $this->assertTrue(DocumentTemplate::whereNull('created_by')->exists());

        $this->post(route('setup.store'), self::FORM);

        $this->assertFalse(DocumentTemplate::whereNull('created_by')->exists());
    }

    public function test_the_password_rules_apply(): void
    {
        $this->post(route('setup.store'), ['password' => 'short', 'password_confirmation' => 'other'] + self::FORM)
            ->assertSessionHasErrors('password');

        $this->post(route('setup.store'), ['email' => 'not-an-email'] + self::FORM)
            ->assertSessionHasErrors('email');

        $this->assertSame(0, User::count());
    }

    public function test_setup_is_closed_once_the_account_exists(): void
    {
        User::factory()->create(['email' => 'first@example.test']);

        $this->get(route('setup'))->assertRedirect(route('login'));
        $this->post(route('setup.store'), self::FORM)->assertRedirect(route('login'));

        $this->assertSame(1, User::count());
        $this->get(route('login'))->assertOk();
    }

    public function test_another_computer_cannot_make_the_account(): void
    {
        $lan = ['REMOTE_ADDR' => '192.168.1.50', 'SERVER_ADDR' => '192.168.1.10'];

        $this->withServerVariables($lan)
            ->get(route('setup'))
            ->assertOk()
            ->assertSee('only be made on the CRMS computer itself')
            ->assertDontSee('Create Account');

        $this->withServerVariables($lan)
            ->post(route('setup.store'), self::FORM)
            ->assertForbidden();

        $this->assertSame(0, User::count());
    }

    public function test_the_server_pc_may_use_its_own_lan_address(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.10', 'SERVER_ADDR' => '192.168.1.10'])
            ->post(route('setup.store'), self::FORM)
            ->assertRedirect(route('settings.edit'));

        $this->assertSame(1, User::count());
    }

    public function test_the_local_only_check_can_be_turned_off_for_docker(): void
    {
        config(['crms.setup.local_only' => false]);

        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1', 'SERVER_ADDR' => '172.18.0.5'])
            ->post(route('setup.store'), self::FORM)
            ->assertRedirect(route('settings.edit'));

        $this->assertSame(1, User::count());
    }
}
