<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\RecordStatus;
use App\Models\AuditLog;
use App\Models\CivilRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
