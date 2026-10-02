<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\RecordStatus;
use App\Models\AuditLog;
use App\Models\CivilRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Reporting is Admin oversight and read-only. The export itself is a state change
 * worth recording: registry data leaving the system has to be attributable.
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_are_denied_the_report_page_and_the_export(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('reports.export'))->assertForbidden();
    }

    public function test_the_page_summarises_matching_records(): void
    {
        $staff = User::factory()->staff()->create();
        $this->record($staff, DocumentType::Birth, RecordStatus::Submitted);
        $this->record($staff, DocumentType::Death, RecordStatus::Draft);

        $summary = $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports.index', ['doc_type' => DocumentType::Birth->value]))
            ->assertOk()
            ->viewData('summary');

        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['submitted']);
        $this->assertSame(0, $summary['drafts']);
    }

    public function test_the_export_streams_csv_of_the_filtered_records(): void
    {
        $staff = User::factory()->staff()->create(['name' => 'Nina Staffer']);

        $birth = $this->record($staff, DocumentType::Birth, RecordStatus::Submitted);
        $birth->fields()->create([
            'name' => 'Child Full Name',
            'ocr_text' => 'Ana Reyes',
            'verified_value' => 'Ana Reyes',
            'ocr_confidence' => 88.0,
        ]);

        $death = $this->record($staff, DocumentType::Death, RecordStatus::Submitted);
        $death->fields()->create(['name' => 'Full Name', 'verified_value' => 'Pedro Cruz']);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports.export', ['doc_type' => DocumentType::Birth->value]))
            ->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment;', $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $csv))));

        // Header plus exactly the one matching record.
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('Registry number', $lines[0]);
        $this->assertStringContainsString('Ana Reyes', $lines[1]);
        $this->assertStringContainsString('Nina Staffer', $lines[1]);
        $this->assertStringNotContainsString('Pedro Cruz', $csv);
    }

    public function test_the_export_keeps_excel_from_running_values_as_formulas(): void
    {
        $staff = User::factory()->staff()->create();
        $record = $this->record($staff, DocumentType::Birth, RecordStatus::Submitted);
        $record->update(['registry_number' => '=HYPERLINK("x","y")']);
        $record->fields()->create([
            'name' => 'Child Full Name',
            'ocr_text' => '=1+1',
            'verified_value' => '=1+1',
            'ocr_confidence' => 87.5,
        ]);

        $csv = $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports.export'))
            ->assertOk()
            ->streamedContent();
        $row = $this->csvRows($csv)[0];

        $this->assertSame('\'=HYPERLINK("x","y")', $row['Registry number']);
        $this->assertSame("'=1+1", $row['Primary value']);

        // Number columns are written as they were.
        $this->assertSame((string) $record->getKey(), $row['Record ID']);
        $this->assertSame('1', $row['Fields']);
        $this->assertSame('87.5', $row['Average confidence']);
    }

    public function test_report_days_and_export_times_are_philippine_time(): void
    {
        // 07:30 on 2 October in the Philippines, but still 1 October in UTC.
        $this->travelTo(Carbon::parse('2026-10-01 23:30:00', 'UTC'));
        $record = $this->record(User::factory()->staff()->create(), DocumentType::Birth, RecordStatus::Submitted);
        $this->travelBack();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('reports.index', ['from' => '2026-10-02']))
            ->assertOk()
            ->assertSee('2 Oct 2026')
            ->assertViewHas('records', fn ($records) => $records->pluck('id')->all() === [$record->getKey()]);

        $csv = $this->actingAs($admin)
            ->get(route('reports.export', ['from' => '2026-10-02']))
            ->assertOk()
            ->streamedContent();
        $row = $this->csvRows($csv)[0];

        $this->assertSame('2026-10-02 07:30:00', $row['Created at']);
        $this->assertSame('2026-10-02 07:30:00', $row['Submitted at']);
    }

    public function test_the_export_is_audit_logged_with_the_filters_used(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $this->record($staff, DocumentType::Birth, RecordStatus::Submitted);

        $this->actingAs($admin)
            ->get(route('reports.export', [
                'doc_type' => DocumentType::Birth->value,
                'from' => '2026-01-01',
            ]))
            ->assertOk();

        $entry = AuditLog::where('action', 'report.generated')->latest('id')->first();

        $this->assertNotNull($entry, 'Exporting a report must write an audit entry.');
        $this->assertSame($admin->getKey(), $entry->user_id);
        $this->assertSame('admin', $entry->actor_role);
        $this->assertSame(DocumentType::Birth->value, $entry->new_values['doc_type']);
        $this->assertSame('2026-01-01', $entry->new_values['from']);

        // Unused filters are not recorded as nulls; the entry states what was asked for.
        $this->assertArrayNotHasKey('to', $entry->new_values);
    }

    public function test_an_unusable_date_range_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports.index', ['from' => '2026-06-01', 'to' => '2026-01-01']))
            ->assertSessionHasErrors('to');
    }

    /**
     * The data rows of an exported CSV, each keyed by the header row.
     *
     * @return list<array<string, string>>
     */
    private function csvRows(string $csv): array
    {
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $csv))));
        $header = str_getcsv(array_shift($lines));

        return array_map(fn (string $line) => array_combine($header, str_getcsv($line)), $lines);
    }

    private function record(User $staff, DocumentType $type, RecordStatus $status): CivilRecord
    {
        return CivilRecord::create([
            'doc_type' => $type->value,
            'status' => $status->value,
            'registry_number' => strtoupper($type->value).'-'.fake()->unique()->numberBetween(1000, 9999),
            'created_by' => $staff->getKey(),
            'submitted_by' => $status === RecordStatus::Submitted ? $staff->getKey() : null,
            'submitted_at' => $status === RecordStatus::Submitted ? now() : null,
        ]);
    }
}
