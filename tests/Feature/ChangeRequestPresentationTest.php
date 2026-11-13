<?php

namespace Tests\Feature;

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
        $user = User::factory()->create();
        $record = $this->groupedRecord($user);

        $this->actingAs($user)
            ->get(route('records.change-requests.create', $record))
            ->assertOk()
            ->assertSee('Correct Record')
            ->assertSee('Saving updates the record right away.')
            ->assertSee('Save correction')
            ->assertSee('Reason <span class="text-muted">(optional)</span>', escape: false)
            ->assertSee('Birth · Entries 1–2')
            ->assertSee('Person 01')
            ->assertSee('Person 02')
            ->assertSee('data-change-request-form', escape: false)
            ->assertSee('data-change-count', escape: false)
            ->assertSee('data-change-input', escape: false)
            ->assertSee('No changes selected')
            ->assertDontSee('until an Admin approves');
    }

    public function test_correction_page_reuses_grouped_archive_and_original_scan_comparison(): void
    {
        $user = User::factory()->create();
        $record = $this->groupedRecord($user);
        $field = $record->fields->firstWhere('name', 'Name');
        $correction = app(ChangeRequestService::class)->correct(
            $record,
            [$field->getKey() => 'Corrected Person One'],
            'The original register clearly shows the corrected full name.',
            $user,
        );

        $this->actingAs($user)
            ->get(route('change-requests.show', $correction))
            ->assertOk()
            ->assertSee('Correction #'.$correction->getKey())
            ->assertSee('Person 01')
            ->assertSee('Corrected Person One')
            ->assertSee('Old value')
            ->assertSee('New value')
            ->assertSee('Compare original')
            ->assertSee('change-request-review-workspace has-scan', escape: false)
            ->assertSee('data-record-splitter', escape: false)
            ->assertSee('data-record-field="'.$field->getKey().'"', escape: false)
            ->assertSee('data-scan-marker="'.$field->getKey().'"', escape: false)
            ->assertDontSee('Approve and apply');
    }

    public function test_corrections_search_finds_by_reason(): void
    {
        $user = User::factory()->create();
        $firstRecord = $this->groupedRecord($user);
        $secondRecord = $this->groupedRecord($user);
        app(ChangeRequestService::class)->correct(
            $firstRecord,
            [$firstRecord->fields->firstWhere('name', 'Name')->getKey() => 'First correction'],
            'Unique first correction for the first register entry.',
            $user,
        );
        app(ChangeRequestService::class)->correct(
            $secondRecord,
            [$secondRecord->fields->firstWhere('name', 'Name')->getKey() => 'Second correction'],
            'Unique second correction for the second register entry.',
            $user,
        );

        $response = $this->actingAs($user)
            ->get(route('change-requests.index', ['q' => 'Unique second correction']))
            ->assertOk()
            ->assertSee('All corrections')
            ->assertSee('<small>Record #'.$secondRecord->getKey().'</small>', escape: false)
            ->assertDontSee('<small>Record #'.$firstRecord->getKey().'</small>', escape: false);

        $this->assertSame(1, $response->viewData('requests')->total());
    }

    private function groupedRecord(User $account): CivilRecord
    {
        $record = CivilRecord::factory()
            ->ofType(DocumentType::Birth)
            ->submitted($account)
            ->create([
                'created_by' => $account->getKey(),
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
