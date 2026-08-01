<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\RecordStatus;
use App\Models\ChangeRequest;
use App\Models\CivilRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The analytics dashboard is oversight, not data entry: Staff never see it, and
 * nothing on it can change a record.
 */
class AnalyticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_are_denied(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->get(route('analytics.index'))
            ->assertForbidden();
    }

    public function test_admin_and_super_admin_may_view_it(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $user) {
            $this->actingAs($user)->get(route('analytics.index'))->assertOk();
        }
    }

    public function test_it_counts_records_change_requests_and_confidence(): void
    {
        $staff = User::factory()->staff()->create();

        $submitted = $this->record($staff, DocumentType::Birth, RecordStatus::Submitted);
        $this->record($staff, DocumentType::Death, RecordStatus::Draft);

        $submitted->fields()->create(['name' => 'Child Full Name', 'ocr_confidence' => 90.0]);
        $submitted->fields()->create(['name' => 'Date of Birth', 'ocr_confidence' => 70.0]);

        ChangeRequest::create([
            'record_id' => $submitted->getKey(),
            'reason' => 'Misread surname.',
            'requested_by' => $staff->getKey(),
        ]);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('analytics.index'))
            ->assertOk();

        $stats = $response->viewData('stats');

        $this->assertSame(2, $stats['total_records']);
        $this->assertSame(1, $stats['submitted_this_month']);
        $this->assertSame(1, $stats['pending_change_requests']);
        $this->assertSame(80.0, $stats['average_confidence']);
    }

    /**
     * The correction rate compares what the model read against what the person
     * confirmed. It is a signal, not an accuracy figure - but it still has to be
     * arithmetically right.
     */
    public function test_the_correction_rate_counts_fields_a_person_changed(): void
    {
        $staff = User::factory()->staff()->create();
        $record = $this->record($staff, DocumentType::Marriage, RecordStatus::Submitted);

        // Whitespace only: the model read it correctly.
        $record->fields()->create([
            'name' => 'Husband Full Name',
            'ocr_text' => ' Juan Dela Cruz ',
            'verified_value' => 'Juan Dela Cruz',
            'ocr_confidence' => 95.0,
        ]);

        $record->fields()->create([
            'name' => 'Wife Full Name',
            'ocr_text' => 'Mana Santos',
            'verified_value' => 'Maria Santos',
            'ocr_confidence' => 60.0,
        ]);

        // No OCR output at all, so there is nothing to compare.
        $record->fields()->create(['name' => 'Place of Marriage', 'verified_value' => 'Cebu City']);

        $quality = $this->actingAs(User::factory()->admin()->create())
            ->get(route('analytics.index'))
            ->assertOk()
            ->viewData('ocrQuality');

        $this->assertSame(2, $quality['comparable_fields']);
        $this->assertSame(1, $quality['corrected_fields']);
        $this->assertSame(50.0, $quality['correction_rate']);
        $this->assertSame(1, $quality['below_threshold']);
    }

    public function test_throughput_is_attributed_to_the_submitter(): void
    {
        $busy = User::factory()->staff()->create(['name' => 'Busy Staffer']);
        $quiet = User::factory()->staff()->create(['name' => 'Quiet Staffer']);

        $this->record($busy, DocumentType::Birth, RecordStatus::Submitted);
        $this->record($busy, DocumentType::Death, RecordStatus::Submitted);
        $this->record($quiet, DocumentType::Marriage, RecordStatus::Submitted);
        $this->record($quiet, DocumentType::Birth, RecordStatus::Draft);

        $throughput = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('analytics.index'))
            ->assertOk()
            ->viewData('throughput');

        $this->assertSame(['Busy Staffer', 'Quiet Staffer'], $throughput->pluck('name')->all());
        $this->assertSame([2, 1], $throughput->pluck('total')->all());
    }

    private function record(User $staff, DocumentType $type, RecordStatus $status): CivilRecord
    {
        return CivilRecord::create([
            'doc_type' => $type->value,
            'status' => $status->value,
            'created_by' => $staff->getKey(),
            'submitted_by' => $status === RecordStatus::Submitted ? $staff->getKey() : null,
            'submitted_at' => $status === RecordStatus::Submitted ? now() : null,
        ]);
    }
}
