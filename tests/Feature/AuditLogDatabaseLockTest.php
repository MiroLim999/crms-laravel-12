<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The audit log is append-only in MySQL itself: a raw query, which skips the
 * model's guard, is refused too.
 */
class AuditLogDatabaseLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The triggers exist on MySQL only.');
        }

        $this->actingAs(User::factory()->superAdmin()->create());
        app(AuditLogger::class)->log('record.submitted', description: 'Original entry.');
    }

    public function test_a_raw_update_is_refused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');

        DB::table('audit_logs')->update(['description' => 'Rewritten.']);
    }

    public function test_a_raw_delete_is_refused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');

        DB::table('audit_logs')->delete();
    }

    public function test_new_entries_can_still_be_added(): void
    {
        app(AuditLogger::class)->log('record.viewed', description: 'Second entry.');

        $this->assertSame(2, DB::table('audit_logs')->count());
        $this->assertSame('Original entry.', DB::table('audit_logs')->orderBy('id')->value('description'));
    }

    public function test_deleting_a_user_keeps_their_entries(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);
        app(AuditLogger::class)->log('auth.login', $user, description: 'Signed in.');

        $user->delete();

        $this->assertSame(2, DB::table('audit_logs')->count());
        $this->assertNull(DB::table('audit_logs')->where('action', 'auth.login')->value('user_id'));
    }
}
