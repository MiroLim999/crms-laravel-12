<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * The audit trail is append-only. These tests guard that contract, because a
 * rewritable log is not evidence of anything.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_entries_cannot_be_updated(): void
    {
        $entry = AuditLog::create(['action' => 'test.action']);

        $this->expectException(LogicException::class);
        $entry->update(['action' => 'tampered']);
    }

    public function test_entries_cannot_be_deleted(): void
    {
        $entry = AuditLog::create(['action' => 'test.action']);

        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_it_records_the_actor_and_their_role(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);

        app(AuditLogger::class)->log('user.created', $user, description: 'Provisioned an account.');

        $entry = AuditLog::latest('id')->first();

        $this->assertSame($user->getKey(), $entry->user_id);
        $this->assertSame($user->name, $entry->actor_name);
        $this->assertSame('admin', $entry->actor_role);
        $this->assertSame(User::class, $entry->auditable_type);
    }

    public function test_it_redacts_secrets(): void
    {
        $user = User::factory()->staff()->create();
        $this->actingAs($user);

        app(AuditLogger::class)->log('user.updated', $user, old: [
            'password' => 'the-old-secret',
            'name' => 'Before',
        ], new: [
            'password' => 'the-new-secret',
            'temporary_password' => 'generated-temp',
            'name' => 'After',
        ]);

        $entry = AuditLog::latest('id')->first();

        $this->assertSame('[redacted]', $entry->old_values['password']);
        $this->assertSame('[redacted]', $entry->new_values['password']);
        $this->assertSame('[redacted]', $entry->new_values['temporary_password']);

        // Non-secret values survive, otherwise the log would be useless.
        $this->assertSame('Before', $entry->old_values['name']);
        $this->assertSame('After', $entry->new_values['name']);
    }

    public function test_save_and_log_records_only_what_moved(): void
    {
        $user = User::factory()->staff()->create(['name' => 'Original Name']);
        $this->actingAs($user);

        // Still dirty: Eloquent re-syncs originals on save, so the diff has to be
        // taken before persisting.
        $user->name = 'Updated Name';
        app(AuditLogger::class)->saveAndLog('user.updated', $user);

        $entry = AuditLog::latest('id')->first();

        $this->assertSame(['name' => 'Original Name'], $entry->old_values);
        $this->assertSame(['name' => 'Updated Name'], $entry->new_values);
        $this->assertSame('Updated Name', $user->fresh()->name);
    }

    public function test_save_and_log_ignores_untouched_attributes(): void
    {
        $user = User::factory()->staff()->create(['name' => 'Same Name']);
        $this->actingAs($user);

        $user->is_active = false;
        app(AuditLogger::class)->saveAndLog('user.deactivated', $user);

        $entry = AuditLog::latest('id')->first();

        $this->assertArrayNotHasKey('name', $entry->new_values);
        $this->assertArrayHasKey('is_active', $entry->new_values);
    }

    public function test_the_actor_survives_deletion_of_their_account(): void
    {
        $user = User::factory()->staff()->create(['name' => 'Departed Staffer']);
        $this->actingAs($user);

        app(AuditLogger::class)->log('record.submitted', description: 'Submitted a record.');
        $user->delete();

        $entry = AuditLog::latest('id')->first();

        $this->assertNull($entry->user_id);
        $this->assertSame('Departed Staffer', $entry->actor_name);
        $this->assertSame('staff', $entry->actor_role);
    }
}
