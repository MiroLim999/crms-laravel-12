<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CivilRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Events added to the trail beyond state changes: failed and locked sign-ins,
 * and opening a record.
 */
class AuditEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_sign_in_is_logged_without_the_password(): void
    {
        User::factory()->superAdmin()->create(['email' => 'clerk@example.test']);

        $this->post(route('login.store'), ['email' => 'clerk@example.test', 'password' => 'wrong-secret']);

        $entry = AuditLog::query()->where('action', 'auth.login_failed')->sole();
        $this->assertNull($entry->user_id);
        $this->assertStringContainsString('clerk@example.test', $entry->description);
        $this->assertStringNotContainsString('wrong-secret', $entry->description);
    }

    public function test_the_fifth_failed_sign_in_logs_a_lockout(): void
    {
        User::factory()->superAdmin()->create(['email' => 'clerk@example.test']);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.store'), ['email' => 'clerk@example.test', 'password' => 'nope']);
        }

        $this->assertSame(5, AuditLog::query()->where('action', 'auth.login_failed')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.locked_out')->count());
    }

    public function test_opening_a_record_is_logged(): void
    {
        $account = User::factory()->superAdmin()->create();
        $record = CivilRecord::factory()->create();

        $this->actingAs($account)->get(route('records.show', $record))->assertOk();

        $entry = AuditLog::query()->where('action', 'record.viewed')->sole();
        $this->assertSame($record->getKey(), $entry->auditable_id);
        $this->assertSame($account->getKey(), $entry->user_id);
    }

    public function test_the_log_page_shows_plain_event_and_target_names(): void
    {
        $account = User::factory()->superAdmin()->create();
        $this->actingAs($account)->post(route('logout'));

        $this->actingAs($account)
            ->get(route('audit.index'))
            ->assertOk()
            ->assertSee('Signed out')
            ->assertSee('Account')
            ->assertDontSee('<th>IP</th>', false);
    }
}
