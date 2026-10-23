<?php

namespace Tests\Feature;

use App\Enums\ChangeRequestStatus;
use App\Enums\DocumentType;
use App\Models\CivilRecord;
use App\Models\RecordField;
use App\Models\User;
use App\Services\ChangeRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChangeRequestPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_page_groups_editable_values_by_person_and_tracks_changed_values(): void
    {
        $staff = User::factory()->staff()->create();
        $record = $this->groupedRecord($staff);

        $this->actingAs($staff)
            ->get(route('records.change-requests.create', $record))
            ->assertOk()
            ->assertSee('Request a Change')
            ->assertSee('Birth · Entries 1–2')
            ->assertSee('Person 01')
            ->assertSee('Person 02')
            ->assertSee('data-change-request-form', escape: false)
            ->assertSee('data-change-count', escape: false)
            ->assertSee('data-change-input', escape: false)
            ->assertSee('No changes selected');
    }

    public function test_review_page_reuses_grouped_archive_and_original_scan_comparison(): void
    {
        $staff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();
        $record = $this->groupedRecord($staff);
        $field = $record->fields->firstWhere('name', 'Name');
        $changeRequest = app(ChangeRequestService::class)->open(
            $record,
            [$field->getKey() => 'Corrected Person One'],
            'The original register clearly shows the corrected full name.',
            $staff,
        );

        $this->actingAs($admin)
            ->get(route('change-requests.show', $changeRequest))
            ->assertOk()
            ->assertSee('Proposed changes')
            ->assertSee('Person 01')
            ->assertSee('Corrected Person One')
            ->assertSee('On record when requested')
            ->assertSee('Compare original')
            ->assertSee('change-request-review-workspace has-scan', escape: false)
            ->assertSee('data-record-splitter', escape: false)
            ->assertSee('data-record-field="'.$field->getKey().'"', escape: false)
            ->assertSee('data-scan-marker="'.$field->getKey().'"', escape: false)
            ->assertSee('Approve and apply');
    }

    public function test_request_queue_search_and_status_totals_respect_the_visible_scope(): void
    {
        $staff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();
        $firstRecord = $this->groupedRecord($staff, 'REQ-ONE');
        $secondRecord = $this->groupedRecord($staff, 'REQ-TWO');
        $firstField = $firstRecord->fields->firstWhere('name', 'Name');
        $secondField = $secondRecord->fields->firstWhere('name', 'Name');
        $approved = app(ChangeRequestService::class)->open(
            $firstRecord,
            [$firstField->getKey() => 'Approved correction'],
            'Unique approved correction for the first register entry.',
            $staff,
        );
        app(ChangeRequestService::class)->approve($approved, $admin, 'Verified.');
        app(ChangeRequestService::class)->open(
            $secondRecord,
            [$secondField->getKey() => 'Pending correction'],
            'Unique pending correction for the second register entry.',
            $staff,
        );

        $response = $this->actingAs($admin)
            ->get(route('change-requests.index', ['q' => 'Unique pending correction']))
            ->assertOk()
            ->assertSee('Request queue')
            ->assertSee('REQ-TWO')
            ->assertDontSee('REQ-ONE');

        $this->assertSame(1, $response->viewData('requests')->total());
        $this->assertSame(1, $response->viewData('statusCounts')->get(ChangeRequestStatus::Pending->value));
        $this->assertSame(1, $response->viewData('statusCounts')->get(ChangeRequestStatus::Approved->value));
    }

    public function test_staff_is_not_redirected_to_another_requesters_pending_request(): void
    {
        $requester = User::factory()->staff()->create();
        $otherStaff = User::factory()->staff()->create();
        $record = $this->groupedRecord($requester);
        $field = $record->fields->firstWhere('name', 'Name');
        app(ChangeRequestService::class)->open(
            $record,
            [$field->getKey() => 'Pending correction'],
            'This correction is already waiting for an administrator review.',
            $requester,
        );

        $this->actingAs($otherStaff)
            ->get(route('records.change-requests.create', $record))
            ->assertRedirect(route('records.show', $record))
            ->assertSessionHas('error');
    }

    private function groupedRecord(User $staff, ?string $registryNumber = 'BIRTH-2026-001'): CivilRecord
    {
        $record = CivilRecord::factory()
            ->ofType(DocumentType::Birth)
            ->submitted($staff)
            ->create([
                'created_by' => $staff->getKey(),
                'registry_number' => $registryNumber,
                'scan_path' => 'scans/change-request-source.png',
                'scan_mime' => 'image/png',
            ]);

        foreach ([
            [1, 0, 'Entry', '1', 0.05, 0.08],
            [1, 1, 'Name', 'Person One', 0.18, 0.45],
            [1, 2, 'Sex', 'F', 0.68, 0.08],
            [2, 0, 'Entry', '2', 0.05, 0.08],
            [2, 1, 'Name', 'Person Two', 0.18, 0.45],
            [2, 2, 'Sex', 'M', 0.68, 0.08],
        ] as $sortOrder => [$person, $personOrder, $name, $value, $x, $width]) {
            RecordField::factory()->for($record, 'record')->create([
                'name' => $name,
                'ocr_text' => $value,
                'verified_value' => $value,
                'person_group' => $person,
                'person_field_order' => $personOrder,
                'x' => $x,
                'y' => $person === 1 ? 0.20 : 0.35,
                'width' => $width,
                'height' => 0.05,
                'sort_order' => $sortOrder,
                'is_required' => true,
            ]);
        }

        return $record->load('fields', 'documentTypeDefinition');
    }
}
