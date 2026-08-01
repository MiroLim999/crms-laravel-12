<?php

namespace Tests\Feature;

use App\Enums\RoleSlug;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_provision_any_role(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        foreach (RoleSlug::cases() as $slug) {
            $this->actingAs($superAdmin)->post(route('users.store'), [
                'name' => "New {$slug->value}",
                'email' => "{$slug->value}@example.test",
                'role' => $slug->value,
            ])->assertRedirect(route('users.index'));

            $this->assertDatabaseHas('users', ['email' => "{$slug->value}@example.test"]);
        }
    }

    public function test_admin_can_only_provision_staff(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'A Staffer',
            'email' => 'staffer@example.test',
            'role' => RoleSlug::Staff->value,
        ])->assertRedirect(route('users.index'));

        foreach ([RoleSlug::Admin, RoleSlug::SuperAdmin] as $forbidden) {
            $this->actingAs($admin)->post(route('users.store'), [
                'name' => 'Should Not Exist',
                'email' => "{$forbidden->value}-by-admin@example.test",
                'role' => $forbidden->value,
            ])->assertSessionHasErrors('role');

            $this->assertDatabaseMissing('users', [
                'email' => "{$forbidden->value}-by-admin@example.test",
            ]);
        }
    }

    public function test_staff_cannot_reach_user_management(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get(route('users.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('users.create'))->assertForbidden();
        $this->actingAs($staff)->post(route('users.store'), [
            'name' => 'Nope',
            'email' => 'nope@example.test',
            'role' => RoleSlug::Staff->value,
        ])->assertForbidden();
    }

    public function test_a_new_account_must_change_its_password(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post(route('users.store'), [
            'name' => 'Fresh Staffer',
            'email' => 'fresh@example.test',
            'role' => RoleSlug::Staff->value,
        ]);

        $created = User::where('email', 'fresh@example.test')->firstOrFail();

        $this->assertTrue($created->must_change_password);
        $this->assertNull($created->password_changed_at);
        $this->assertSame($superAdmin->getKey(), $created->created_by);
    }

    public function test_the_temporary_password_is_shown_once_and_works(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post(route('users.store'), [
            'name' => 'Fresh Staffer',
            'email' => 'fresh@example.test',
            'role' => RoleSlug::Staff->value,
        ]);

        $provisioned = $response->getSession()->get('provisioned');
        $this->assertNotEmpty($provisioned['password']);

        $created = User::where('email', 'fresh@example.test')->firstOrFail();
        $this->assertTrue(Hash::check($provisioned['password'], $created->password));
    }

    public function test_the_temporary_password_is_never_written_to_the_audit_log(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post(route('users.store'), [
            'name' => 'Fresh Staffer',
            'email' => 'fresh@example.test',
            'role' => RoleSlug::Staff->value,
        ]);

        $secret = $response->getSession()->get('provisioned')['password'];

        foreach (AuditLog::all() as $entry) {
            $blob = json_encode([$entry->old_values, $entry->new_values, $entry->description]);
            $this->assertStringNotContainsString($secret, (string) $blob);
        }
    }

    public function test_generated_passwords_meet_the_rules_users_must_meet(): void
    {
        $provisioner = app(UserProvisioner::class);

        for ($i = 0; $i < 25; $i++) {
            $password = $provisioner->generateTemporaryPassword();

            $this->assertGreaterThanOrEqual(8, strlen($password));
            $this->assertMatchesRegularExpression('/[a-zA-Z]/', $password);
            $this->assertMatchesRegularExpression('/\d/', $password);
            // Ambiguous glyphs would defeat the point of a password read aloud.
            $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $password);
        }
    }

    public function test_admin_cannot_edit_another_admin_or_a_super_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($admin)->get(route('users.edit', $otherAdmin))->assertForbidden();
        $this->actingAs($admin)->get(route('users.edit', $superAdmin))->assertForbidden();
        $this->actingAs($admin)->get(route('users.edit', $staff))->assertOk();
    }

    public function test_nobody_can_deactivate_themselves(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->post(route('users.deactivate', $superAdmin))
            ->assertForbidden();

        $this->assertTrue($superAdmin->fresh()->is_active);
    }

    public function test_accounts_are_deactivated_not_deleted(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($superAdmin)
            ->post(route('users.deactivate', $staff))
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $staff->getKey(), 'is_active' => false]);

        // There is no destroy route at all.
        $this->assertFalse(\Route::has('users.destroy'));
    }

    public function test_deactivation_and_reactivation_are_audit_logged(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($superAdmin)->post(route('users.deactivate', $staff));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.deactivated',
            'auditable_id' => $staff->getKey(),
        ]);

        $this->actingAs($superAdmin)->post(route('users.activate', $staff));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.activated',
            'auditable_id' => $staff->getKey(),
        ]);
    }

    public function test_resetting_a_password_forces_another_change(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $staff = User::factory()->staff()->create();

        $response = $this->actingAs($superAdmin)
            ->post(route('users.password.reset', $staff))
            ->assertRedirect(route('users.index'));

        $secret = $response->getSession()->get('provisioned')['password'];

        $staff->refresh();
        $this->assertTrue($staff->must_change_password);
        $this->assertTrue(Hash::check($secret, $staff->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset']);
    }

    public function test_the_last_active_super_admin_cannot_be_demoted(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->put(route('users.update', $superAdmin), [
            'name' => $superAdmin->name,
            'email' => $superAdmin->email,
            'role' => RoleSlug::Staff->value,
        ])->assertSessionHasErrors('role');

        $this->assertTrue($superAdmin->fresh()->isSuperAdmin());
    }

    public function test_a_super_admin_can_be_demoted_when_another_remains(): void
    {
        $first = User::factory()->superAdmin()->create();
        $second = User::factory()->superAdmin()->create();

        $this->actingAs($first)->put(route('users.update', $second), [
            'name' => $second->name,
            'email' => $second->email,
            'role' => RoleSlug::Admin->value,
        ])->assertRedirect(route('users.index'));

        $this->assertTrue($second->fresh()->isAdmin());
    }

    public function test_email_must_be_unique(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $existing = User::factory()->staff()->create();

        $this->actingAs($superAdmin)->post(route('users.store'), [
            'name' => 'Duplicate',
            'email' => $existing->email,
            'role' => RoleSlug::Staff->value,
        ])->assertSessionHasErrors('email');
    }

    public function test_updates_are_audit_logged_with_before_and_after(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $staff = User::factory()->staff()->create(['name' => 'Old Name']);

        $this->actingAs($superAdmin)->put(route('users.update', $staff), [
            'name' => 'New Name',
            'email' => $staff->email,
            'role' => RoleSlug::Staff->value,
        ]);

        $entry = AuditLog::where('action', 'user.updated')->latest('id')->firstOrFail();

        $this->assertSame('Old Name', $entry->old_values['name']);
        $this->assertSame('New Name', $entry->new_values['name']);
    }
}
