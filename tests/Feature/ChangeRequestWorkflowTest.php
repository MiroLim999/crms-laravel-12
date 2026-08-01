<?php

namespace Tests\Feature;

use App\Enums\ChangeRequestStatus;
use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\CivilRecord;
use App\Models\RecordField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The correction workflow, which is the mechanism the whole separation of duties
 * rests on:
 *
 *   - a locked record has no edit route for anyone
 *   - Staff propose, Admin decides
 *   - approving is what applies the values; Admin never edits a record
 *
 * These are the assertions that would catch someone "simplifying" the design.
 */
class ChangeRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_route_can_edit_a_record_directly(): void
    {
        // Nothing may PUT, PATCH, or DELETE a record. Corrections go through a
        // change request, and that is enforced by the absence of a route.
        $writeRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => preg_match('#^records(/|$)#', $route->uri()))
            ->reject(fn ($route) => $route->methods() === ['GET', 'HEAD'])
            ->map(fn ($route) => $route->methods()[0].' '.$route->uri())
            ->values()
            ->all();

        // The only non-GET route under records/ is opening a change request.
        $this->assertSame(['POST records/{record}/change-request'], $writeRoutes);

        $this->assertFalse(Route::has('records.update'));
        $this->assertFalse(Route::has('records.destroy'));
    }

    public function test_admin_cannot_open_a_change_request(): void
    {
        $record = $this->lockedRecord();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('records.change-requests.create', $record))
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('records.change-requests.store', $record), [
                'reason' => 'Admin should not be able to do this at all.',
                'values' => [$record->fields->first()->getKey() => 'Tampered'],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('change_requests', 0);
    }

    public function test_staff_cannot_decide_their_own_request(): void
    {
        $staff = User::factory()->staff()->create();
        $request = $this->openRequest($staff);

        $this->actingAs($staff)
            ->post(route('change-requests.approve', $request))
            ->assertForbidden();

        $this->actingAs($staff)
            ->post(route('change-requests.reject', $request), ['decision_note' => 'Nope.'])
            ->assertForbidden();

        $this->assertSame(ChangeRequestStatus::Pending, $request->fresh()->status);
    }

    public function test_approving_applies_the_proposed_values(): void
    {
        $staff = User::factory()->staff()->create();
        $request = $this->openRequest($staff);
        $field = $request->items->first()->field;

        $this->assertSame('Mana Santos', $field->verified_value);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('change-requests.approve', $request), ['decision_note' => 'Checked the register.'])
            ->assertRedirect();

        $this->assertSame('Maria Santos', $field->fresh()->verified_value);

        $request->refresh();
        $this->assertSame(ChangeRequestStatus::Approved, $request->status);
        $this->assertNotNull($request->reviewed_at);
        $this->assertNotNull($request->reviewed_by);
    }

    public function test_rejecting_leaves_the_record_untouched(): void
    {
        $staff = User::factory()->staff()->create();
        $request = $this->openRequest($staff);
        $field = $request->items->first()->field;

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('change-requests.reject', $request), [
                'decision_note' => 'The original scan supports the current spelling.',
            ])
            ->assertRedirect();

        $this->assertSame('Mana Santos', $field->fresh()->verified_value);
        $this->assertSame(ChangeRequestStatus::Rejected, $request->fresh()->status);
    }

    public function test_rejecting_requires_a_reason(): void
    {
        $request = $this->openRequest(User::factory()->staff()->create());

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('change-requests.reject', $request), ['decision_note' => ''])
            ->assertSessionHasErrors('decision_note');

        $this->assertSame(ChangeRequestStatus::Pending, $request->fresh()->status);
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        $request = $this->openRequest(User::factory()->staff()->create());
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('change-requests.approve', $request));

        $this->actingAs($admin)
            ->post(route('change-requests.approve', $request))
            ->assertSessionHas('error');
    }

    public function test_approval_is_audit_logged_with_before_and_after(): void
    {
        $request = $this->openRequest(User::factory()->staff()->create());

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('change-requests.approve', $request));

        $entry = AuditLog::where('action', 'change_request.approved')->latest('id')->firstOrFail();

        $this->assertSame('Mana Santos', $entry->old_values['Wife Full Name']);
        $this->assertSame('Maria Santos', $entry->new_values['Wife Full Name']);
        $this->assertSame('admin', $entry->actor_role);
    }

    public function test_a_record_can_only_have_one_pending_request(): void
    {
        $staff = User::factory()->staff()->create();
        $request = $this->openRequest($staff);
        $record = $request->record;

        $this->actingAs($staff)
            ->post(route('records.change-requests.store', $record), [
                'reason' => 'A second attempt while one is already pending.',
                'values' => [$record->fields->first()->getKey() => 'Something Else'],
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('change_requests', 1);
    }

    public function test_a_request_with_no_actual_changes_is_rejected(): void
    {
        $staff = User::factory()->staff()->create();
        $record = $this->lockedRecord();
        $field = $record->fields->first();

        $this->actingAs($staff)
            ->post(route('records.change-requests.store', $record), [
                'reason' => 'Submitting the identical value changes nothing.',
                'values' => [$field->getKey() => $field->verified_value],
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('change_requests', 0);
    }

    public function test_staff_only_see_their_own_requests(): void
    {
        $mine = User::factory()->staff()->create();
        $theirs = User::factory()->staff()->create();

        $this->openRequest($mine);
        $other = $this->openRequest($theirs);

        $visible = $this->actingAs($mine)
            ->get(route('change-requests.index'))
            ->assertOk()
            ->viewData('requests');

        $this->assertSame(1, $visible->total());

        // And cannot read someone else's by guessing the URL.
        $this->actingAs($mine)
            ->get(route('change-requests.show', $other))
            ->assertForbidden();
    }

    public function test_admin_sees_every_request(): void
    {
        $this->openRequest(User::factory()->staff()->create());
        $this->openRequest(User::factory()->staff()->create());

        $visible = $this->actingAs(User::factory()->admin()->create())
            ->get(route('change-requests.index'))
            ->assertOk()
            ->viewData('requests');

        $this->assertSame(2, $visible->total());
    }

    public function test_only_the_requester_can_withdraw(): void
    {
        $staff = User::factory()->staff()->create();
        $request = $this->openRequest($staff);

        $this->actingAs(User::factory()->staff()->create())
            ->post(route('change-requests.withdraw', $request))
            ->assertForbidden();

        $this->actingAs($staff)
            ->post(route('change-requests.withdraw', $request))
            ->assertRedirect();

        $this->assertSame(ChangeRequestStatus::Withdrawn, $request->fresh()->status);
    }

    // ------------------------------------------------------------------ helpers

    private function lockedRecord(): CivilRecord
    {
        $staff = User::factory()->staff()->create();

        $record = CivilRecord::factory()->submitted($staff)->create(['created_by' => $staff->getKey()]);

        RecordField::factory()->for($record, 'record')->create([
            'name' => 'Wife Full Name',
            'ocr_text' => 'Mana Santos',
            'verified_value' => 'Mana Santos',
        ]);

        return $record->load('fields');
    }

    private function openRequest(User $staff): ChangeRequest
    {
        $record = $this->lockedRecord();

        $this->actingAs($staff)->post(route('records.change-requests.store', $record), [
            'reason' => 'The surname is misread; the original register shows Maria Santos.',
            'values' => [$record->fields->first()->getKey() => 'Maria Santos'],
        ]);

        return ChangeRequest::latest('id')->firstOrFail()->load('items.field', 'record.fields');
    }
}
