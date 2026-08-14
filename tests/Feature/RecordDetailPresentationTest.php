<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\CivilRecord;
use App\Models\RecordField;
use App\Models\User;
use App\Services\RecordFieldGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordDetailPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_sees_grouped_verified_values_with_an_interactive_scan(): void
    {
        $staff = User::factory()->staff()->create();
        $record = CivilRecord::factory()
            ->ofType(DocumentType::Birth)
            ->submitted($staff)
            ->create([
                'registry_number' => null,
                'scan_path' => 'scans/birth-register.png',
                'scan_mime' => 'image/png',
            ]);

        $this->createPerson($record, 1, 0.20, '1', 'Juan Miguel D. Abad');
        $this->createPerson($record, 2, 0.35, '2', 'Maria Beatriz R. Solis');

        $response = $this->actingAs($staff)->get(route('records.show', $record));

        $response
            ->assertOk()
            ->assertSee('Birth · Entries 1–2')
            ->assertSee('Verified data')
            ->assertSee('Person 01')
            ->assertSee('Juan Miguel D. Abad')
            ->assertSee('Person 02')
            ->assertSee('Compare original')
            ->assertDontSee('Show OCR comparison')
            ->assertDontSee('Model read')
            ->assertSeeInOrder([
                'record-values-card',
                'data-original-toggle',
                'record-group-list',
            ], escape: false)
            ->assertSee('Original scan')
            ->assertSee('Provenance')
            ->assertSee('class="record-split-workspace has-scan"', escape: false)
            ->assertSee('data-record-splitter', escape: false)
            ->assertSee('role="separator"', escape: false)
            ->assertSee('aria-controls="recordScanPane recordDataPane"', escape: false)
            ->assertSee('data-group-id="person-1"', escape: false)
            ->assertSee('data-scan-marker="'.$record->fields()->firstOrFail()->getKey().'"', escape: false);
    }

    public function test_legacy_coordinate_rows_are_inferred_as_people(): void
    {
        $record = CivilRecord::factory()->create();

        foreach ([0.20, 0.35, 0.50] as $personIndex => $y) {
            $this->createPerson(
                $record,
                null,
                $y,
                (string) ($personIndex + 1),
                'Legacy Person '.($personIndex + 1),
            );
        }

        $groups = app(RecordFieldGrouper::class)->groups($record->fields()->get());

        $this->assertCount(3, collect($groups)->where('kind', 'person'));
        $this->assertSame('Legacy Person 1', $groups[0]['identity']);
    }

    private function createPerson(
        CivilRecord $record,
        ?int $personGroup,
        float $y,
        string $entry,
        string $name,
    ): void {
        foreach ([
            ['Entry', $entry, 0.05, 0.08],
            ['Name', $name, 0.18, 0.45],
            ['Sex', 'F', 0.68, 0.08],
        ] as $order => [$label, $value, $x, $width]) {
            RecordField::factory()->for($record, 'record')->create([
                'name' => $label,
                'ocr_text' => $value,
                'verified_value' => $value,
                'person_group' => $personGroup,
                'person_field_order' => $personGroup === null ? null : $order,
                'x' => $x,
                'y' => $y,
                'width' => $width,
                'height' => 0.05,
                'sort_order' => (($personGroup ?? (int) $entry) - 1) * 3 + $order,
            ]);
        }
    }
}
