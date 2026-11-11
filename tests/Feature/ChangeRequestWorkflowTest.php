<?php

namespace Tests\Feature;

use App\Enums\ChangeRequestStatus;
use App\Exceptions\ChangeRequestException;
use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\CivilRecord;
use App\Models\RecordField;
use App\Models\User;
use App\Services\ChangeRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Correct record: the only way a saved record's values change.
 *
 *   - a locked record has no plain edit route
 *   - saving a correction changes the values at once
 *   - the old and new values stay in the Change history and the Audit Log
 */
class ChangeRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_route_can_edit_a_record_directly(): void
    {
        $writeRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => preg_match('#^records(/|$)#', $route->uri()))
            ->reject(fn ($route) => $route->methods() === ['GET', 'HEAD'])
            ->map(fn ($route) => $route->methods()[0].' '.$route->uri())
            ->values()
            ->all();

        // The only non-GET route under records/ is saving a correction.
        $this->assertSame(['POST records/{record}/change-request'], $writeRoutes);

        $this->assertFalse(Route::has('records.update'));
        $this->assertFalse(Route::has('records.destroy'));
        $this->assertFalse(Route::has('change-requests.approve'));
        $this->assertFalse(Route::has('change-requests.reject'));
        $this->assertFalse(Route::has('change-requests.withdraw'));
    }

    public function test_saving_a_correction_changes_the_record_at_once(): void
    {
        $user = User::factory()->create();
        $record = $this->lockedRecord();
        $field = $record->fields->first();

        $this->actingAs($user)
            ->post(route('records.change-requests.store', $record), [
                'reason' => 'The original register shows Maria Santos.',
                'values' => [$field->getKey() => 'Maria Santos'],
            ])
            ->assertRedirect(route('records.show', $record))
            ->assertSessionHas('success', 'Correction saved.');

        $this->assertSame('Maria Santos', $field->fresh()->verified_value);

        $correction = ChangeRequest::latest('id')->firstOrFail();
        $this->assertSame(ChangeRequestStatus::Approved, $correction->status);
        $this->assertSame($user->getKey(), $correction->requested_by);
        $this->assertSame($user->getKey(), $correction->reviewed_by);
        $this->assertNotNull($correction->reviewed_at);
        $this->assertSame('Mana Santos', $correction->items->first()->current_value);
        $this->assertSame('Maria Santos', $correction->items->first()->proposed_value);
    }

    public function test_the_audit_log_keeps_the_old_and_new_values_and_the_reason(): void
    {
        $user = User::factory()->create();
        $record = $this->lockedRecord();

        $this->actingAs($user)->post(route('records.change-requests.store', $record), [
            'reason' => 'Misread surname.',
            'values' => [$record->fields->first()->getKey() => 'Maria Santos'],
        ]);

        $entry = AuditLog::where('action', 'record.corrected')->firstOrFail();
        $this->assertSame(CivilRecord::class, $entry->auditable_type);
        $this->assertSame($record->getKey(), $entry->auditable_id);
        $this->assertSame(['Wife Full Name' => 'Mana Santos'], $entry->old_values);
        // MySQL keeps JSON keys in its own order, so compare without order.
        $this->assertEquals(['Wife Full Name' => 'Maria Santos', 'reason' => 'Misread surname.'], $entry->new_values);
        $this->assertSame($user->getKey(), $entry->user_id);
    }

    public function test_the_reason_is_optional(): void
    {
        $record = $this->lockedRecord();

        $this->actingAs(User::factory()->create())
            ->post(route('records.change-requests.store', $record), [
                'values' => [$record->fields->first()->getKey() => 'Maria Santos'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('records.show', $record));

        $this->assertNull(ChangeRequest::latest('id')->firstOrFail()->reason);
        $this->assertSame(
            ['Wife Full Name' => 'Maria Santos'],
            AuditLog::where('action', 'record.corrected')->firstOrFail()->new_values,
        );
    }

    public function test_a_correction_with_no_actual_changes_is_refused(): void
    {
        $record = $this->lockedRecord();

        $this->actingAs(User::factory()->create())
            ->from(route('records.change-requests.create', $record))
            ->post(route('records.change-requests.store', $record), [
                'values' => [$record->fields->first()->getKey() => 'Mana Santos'],
            ])
            ->assertRedirect(route('records.change-requests.create', $record))
            ->assertSessionHas('error', 'None of those values differ from what is on record.');

        $this->assertDatabaseCount('change_requests', 0);
    }

    public function test_a_required_field_cannot_be_cleared(): void
    {
        $record = $this->lockedRecord();

        $this->actingAs(User::factory()->create())
            ->post(route('records.change-requests.store', $record), [
                'values' => [$record->fields->first()->getKey() => '   '],
            ])
            ->assertSessionHas('error', 'Wife Full Name is required and cannot be blank.');

        $this->assertSame('Mana Santos', $record->fields->first()->fresh()->verified_value);
        $this->assertDatabaseCount('change_requests', 0);
    }

    public function test_an_optional_field_can_be_cleared(): void
    {
        $record = $this->lockedRecord();
        $optional = RecordField::factory()->for($record, 'record')->create([
            'name' => 'Remarks',
            'verified_value' => 'Late registration',
            'is_required' => false,
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('records.change-requests.store', $record), [
                'values' => [$optional->getKey() => ''],
            ])
            ->assertSessionHas('success');

        $this->assertNull($optional->fresh()->verified_value);
    }

    public function test_a_record_can_be_corrected_again_and_each_correction_is_kept(): void
    {
        $user = User::factory()->create();
        $record = $this->lockedRecord();
        $field = $record->fields->first();

        foreach (['Maria Santos', 'Maria P. Santos'] as $value) {
            $this->actingAs($user)->post(route('records.change-requests.store', $record), [
                'values' => [$field->getKey() => $value],
            ])->assertSessionHas('success');
        }

        $this->assertSame('Maria P. Santos', $field->fresh()->verified_value);
        $this->assertSame(2, $record->changeRequests()->count());
        $this->assertSame(
            ['Mana Santos', 'Maria Santos'],
            $record->changeRequests()->orderBy('id')->with('items')->get()
                ->map(fn (ChangeRequest $correction) => $correction->items->first()->current_value)->all(),
        );
    }

    public function test_a_field_of_another_record_is_ignored(): void
    {
        $record = $this->lockedRecord();
        $other = $this->lockedRecord();

        $this->expectException(ChangeRequestException::class);

        app(ChangeRequestService::class)->correct(
            $record,
            [$other->fields->first()->getKey() => 'Someone Else'],
            null,
            User::factory()->create(),
        );
    }

    public function test_the_record_page_offers_correct_record_and_download_csv(): void
    {
        $record = $this->lockedRecord();

        $this->actingAs(User::factory()->create())
            ->get(route('records.show', $record))
            ->assertOk()
            ->assertSee('Correct record')
            ->assertSee(route('records.csv', $record), false)
            ->assertDontSee('Request a change')
            ->assertDontSee('Change request pending');
    }

    public function test_the_corrections_list_and_detail_are_read_only(): void
    {
        $user = User::factory()->create();
        $record = $this->lockedRecord();

        $this->actingAs($user)->post(route('records.change-requests.store', $record), [
            'reason' => 'Misread surname.',
            'values' => [$record->fields->first()->getKey() => 'Maria Santos'],
        ]);
        $correction = ChangeRequest::latest('id')->firstOrFail();

        $this->actingAs($user)
            ->get(route('change-requests.index'))
            ->assertOk()
            ->assertSee('Corrections')
            ->assertSee('Correction #'.$correction->getKey())
            ->assertDontSee('Waiting for review');

        $this->actingAs($user)
            ->get(route('change-requests.show', $correction))
            ->assertOk()
            ->assertSee('Corrected by')
            ->assertSee('Mana Santos')
            ->assertSee('Maria Santos')
            ->assertSee('Misread surname.')
            ->assertDontSee('Approve and apply')
            ->assertDontSee('Reject request')
            ->assertDontSee('Withdraw request');
    }

    private function lockedRecord(): CivilRecord
    {
        $user = User::factory()->create();

        $record = CivilRecord::factory()->submitted($user)->create(['created_by' => $user->getKey()]);

        RecordField::factory()->for($record, 'record')->create([
            'name' => 'Wife Full Name',
            'ocr_text' => 'Mana Santos',
            'verified_value' => 'Mana Santos',
            'is_required' => true,
        ]);

        return $record->load('fields');
    }
}
