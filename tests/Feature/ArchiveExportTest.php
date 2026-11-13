<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\AuditLog;
use App\Models\CivilRecord;
use App\Models\RecordDownload;
use App\Models\RecordField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The records archive's download: the records the page is showing, as one CSV
 * or Excel file of their checked values, with each record marked downloaded and
 * the ones not yet downloaded listed first.
 */
class ArchiveExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_csv_holds_every_matching_records_values(): void
    {
        $ana = $this->record(DocumentType::Birth, ['Name' => 'Ana Cruz', 'Sex' => 'F']);
        $ben = $this->record(DocumentType::Birth, ['Name' => 'Ben Reyes', 'Sex' => 'M']);

        [$header, $rows] = $this->csv($this->download());

        $this->assertSame(['Record ID', 'Document type', 'Row', 'Name', 'Sex'], $header);
        $this->assertEqualsCanonicalizing([
            [(string) $ana->getKey(), 'Birth Certificate', '1', 'Ana Cruz', 'F'],
            [(string) $ben->getKey(), 'Birth Certificate', '1', 'Ben Reyes', 'M'],
        ], $rows);
    }

    public function test_the_download_follows_the_archives_search_and_filters(): void
    {
        $this->record(DocumentType::Birth, ['Name' => 'Ana Cruz']);
        $this->record(DocumentType::Birth, ['Name' => 'Ben Reyes']);
        $this->record(DocumentType::Death, ['Name' => 'Ana Lopez']);

        [, $rows] = $this->csv($this->download(['q' => 'Ana', 'type' => DocumentType::Birth->value]));

        $this->assertCount(1, $rows);
        $this->assertContains('Ana Cruz', $rows[0]);
    }

    public function test_records_with_different_columns_share_one_table(): void
    {
        $this->record(DocumentType::Birth, ['Name' => 'Ana Cruz', 'Sex' => 'F']);
        $this->record(DocumentType::Death, ['Name' => 'Pedro Cruz', 'Cause' => 'Illness']);

        [$header, $rows] = $this->csv($this->download());

        $this->assertSame(['Record ID', 'Document type', 'Row', 'Name', 'Sex', 'Cause'], $header);
        $byName = collect($rows)->keyBy(fn ($row) => $row[3]);
        $this->assertSame(['Ana Cruz', 'F', ''], array_slice($byName['Ana Cruz'], 3));
        $this->assertSame(['Pedro Cruz', '', 'Illness'], array_slice($byName['Pedro Cruz'], 3));
    }

    public function test_csv_neutralises_formulas_but_excel_keeps_the_text_as_text(): void
    {
        $this->record(DocumentType::Birth, ['Name' => '=1+1']);

        [, $rows] = $this->csv($this->download());
        $this->assertContains("'=1+1", $rows[0]);

        $xlsx = $this->download(['format' => 'xlsx']);
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('content-type'));
        $this->assertStringContainsString('.xlsx', $xlsx->headers->get('content-disposition'));

        $path = tempnam(sys_get_temp_dir(), 'crms').'.xlsx';
        file_put_contents($path, $xlsx->streamedContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        $this->assertStringContainsString('<t>=1+1</t>', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet);
    }

    public function test_downloaded_records_are_marked_and_not_downloaded_ones_are_listed_first(): void
    {
        $old = $this->record(DocumentType::Birth, ['Name' => 'Old Downloaded'], submittedAt: now()->subDay());
        $new = $this->record(DocumentType::Birth, ['Name' => 'New Pending'], submittedAt: now()->subDays(5));
        RecordDownload::create(['record_id' => $old->getKey(), 'format' => 'csv', 'downloaded_at' => now()]);
        $user = User::factory()->create();

        $page = $this->actingAs($user)->get(route('records.index'))->assertOk();

        // The older record has not been downloaded, so it comes before the newer one that has.
        $page->assertSeeInOrder(['New Pending', 'Not downloaded', 'Old Downloaded', 'Downloaded']);
        $page->assertViewHas('pendingCount', 1);

        $this->assertSame(
            [$new->getKey(), $old->getKey()],
            $page->viewData('records')->pluck('id')->all(),
        );
        $this->assertSame(
            [false, true],
            $page->viewData('records')->map(fn ($record) => (bool) $record->is_downloaded)->all(),
        );
    }

    public function test_the_page_shows_progress_and_groups_the_records(): void
    {
        $done = $this->record(DocumentType::Birth, ['Name' => 'Done One']);
        $this->record(DocumentType::Birth, ['Name' => 'Waiting One']);
        $this->record(DocumentType::Birth, ['Name' => 'Waiting Two']);
        RecordDownload::create(['record_id' => $done->getKey(), 'format' => 'csv', 'downloaded_at' => now()]);

        $html = $this->actingAs(User::factory()->create())->get(route('records.index'))->assertOk()->getContent();

        // Progress: 1 of 3 downloaded, 2 waiting.
        $this->assertStringContainsString('1 of 3 downloaded', $html);
        $this->assertStringContainsString('records waiting to be downloaded', $html);
        $this->assertStringContainsString('Show only not downloaded', $html);

        // Group dividers, the waiting group first.
        $this->assertLessThan(strpos($html, 'Already downloaded'), strpos($html, 'Not downloaded yet'));
        $this->assertSame(1, substr_count($html, 'archive-group-row" aria-hidden'. '="true"') - 1);

        // The page's styles reach the document head.
        $this->assertLessThan(strpos($html, '</head>'), strpos($html, '.archive-status {'));
    }

    public function test_a_download_marks_its_records_as_downloaded(): void
    {
        $ana = $this->record(DocumentType::Birth, ['Name' => 'Ana Cruz']);
        $user = User::factory()->create();

        $this->assertSame(0, RecordDownload::count());
        $this->actingAs($user)->get(route('records.export', ['format' => 'xlsx']))->streamedContent();

        $mark = RecordDownload::firstOrFail();
        $this->assertSame($ana->getKey(), $mark->record_id);
        $this->assertSame($user->getKey(), $mark->user_id);
        $this->assertSame('xlsx', $mark->format);
    }

    public function test_the_not_yet_downloaded_scope_skips_records_already_taken(): void
    {
        $taken = $this->record(DocumentType::Birth, ['Name' => 'Already Taken']);
        $this->record(DocumentType::Birth, ['Name' => 'Still Waiting']);
        RecordDownload::create(['record_id' => $taken->getKey(), 'format' => 'csv', 'downloaded_at' => now()]);

        [, $rows] = $this->csv($this->download(['scope' => 'new']));

        $this->assertCount(1, $rows);
        $this->assertContains('Still Waiting', $rows[0]);

        // Taken now, so a second run of the same scope has nothing left.
        $this->actingAs(User::factory()->create())
            ->from(route('records.index'))
            ->get(route('records.export', ['scope' => 'new']))
            ->assertRedirect(route('records.index'))
            ->assertSessionHas('error');
    }

    public function test_all_scope_includes_records_downloaded_before(): void
    {
        $taken = $this->record(DocumentType::Birth, ['Name' => 'Already Taken']);
        $this->record(DocumentType::Birth, ['Name' => 'Still Waiting']);
        RecordDownload::create(['record_id' => $taken->getKey(), 'format' => 'csv', 'downloaded_at' => now()]);

        [, $rows] = $this->csv($this->download(['scope' => 'all']));

        $this->assertCount(2, $rows);
    }

    public function test_the_archive_can_be_filtered_by_download_status(): void
    {
        $taken = $this->record(DocumentType::Birth, ['Name' => 'Already Taken']);
        $waiting = $this->record(DocumentType::Birth, ['Name' => 'Still Waiting']);
        RecordDownload::create(['record_id' => $taken->getKey(), 'format' => 'csv', 'downloaded_at' => now()]);
        $user = User::factory()->create();

        $pending = $this->actingAs($user)->get(route('records.index', ['download' => 'pending']))->viewData('records');
        $done = $this->actingAs($user)->get(route('records.index', ['download' => 'done']))->viewData('records');

        $this->assertSame([$waiting->getKey()], $pending->pluck('id')->all());
        $this->assertSame([$taken->getKey()], $done->pluck('id')->all());
    }

    public function test_a_single_record_csv_also_counts_as_downloaded(): void
    {
        $record = $this->record(DocumentType::Birth, ['Name' => 'Ana Cruz']);

        $this->actingAs(User::factory()->create())->get(route('records.csv', $record))->assertOk()->streamedContent();

        $this->assertSame(1, RecordDownload::where('record_id', $record->getKey())->count());
    }

    public function test_the_download_is_in_the_audit_log(): void
    {
        $this->record(DocumentType::Birth, ['Name' => 'Ana Cruz']);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('records.export', ['q' => 'Ana', 'format' => 'xlsx', 'scope' => 'new']))->assertOk();

        $entry = AuditLog::where('action', 'records.exported')->firstOrFail();
        $this->assertSame($user->getKey(), $entry->user_id);
        $this->assertSame(['q' => 'Ana', 'scope' => 'new', 'format' => 'xlsx', 'records' => 1], $entry->new_values);
    }

    public function test_nothing_matching_sends_the_user_back_with_a_message(): void
    {
        $this->record(DocumentType::Birth, ['Name' => 'Ana Cruz']);

        $this->actingAs(User::factory()->create())
            ->from(route('records.index'))
            ->get(route('records.export', ['q' => 'Nobody']))
            ->assertRedirect(route('records.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, AuditLog::where('action', 'records.exported')->count());
        $this->assertSame(0, RecordDownload::count());
    }

    public function test_an_unknown_format_is_rejected_and_a_guest_is_sent_to_sign_in(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('records.export', ['format' => 'docx']))
            ->assertSessionHasErrors('format');

        auth()->logout();
        $this->get(route('records.export'))->assertRedirect(route('login'));
    }

    public function test_the_download_panel_has_its_own_filters_started_from_the_page(): void
    {
        $this->record(DocumentType::Birth, ['Name' => 'Ana Cruz']);

        $html = $this->actingAs(User::factory()->create())
            ->get(route('records.index', ['q' => 'Ana', 'type' => DocumentType::Birth->value, 'from' => '2026-01-01']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('action="'.route('records.export').'"', $html);
        foreach (['name="scope"', 'name="type"', 'name="from"', 'name="to"', 'name="format"'] as $field) {
            $this->assertStringContainsString($field, $html);
        }
        // The panel starts from the page's own search and filters.
        $this->assertStringContainsString('<input type="hidden" name="q" value="Ana">', $html);
        $this->assertStringContainsString('name="from" value="2026-01-01"', $html);
    }

    public function test_the_already_downloaded_scope_takes_only_those(): void
    {
        $taken = $this->record(DocumentType::Birth, ['Name' => 'Already Taken']);
        $this->record(DocumentType::Birth, ['Name' => 'Still Waiting']);
        RecordDownload::create(['record_id' => $taken->getKey(), 'format' => 'csv', 'downloaded_at' => now()]);

        [, $rows] = $this->csv($this->download(['scope' => 'done']));

        $this->assertCount(1, $rows);
        $this->assertContains('Already Taken', $rows[0]);
    }

    public function test_the_panels_own_date_range_and_type_narrow_the_download(): void
    {
        $this->record(DocumentType::Birth, ['Name' => 'Old Birth'], submittedAt: '2026-01-10');
        $this->record(DocumentType::Birth, ['Name' => 'New Birth'], submittedAt: '2026-06-10');
        $this->record(DocumentType::Death, ['Name' => 'New Death'], submittedAt: '2026-06-10');

        [, $rows] = $this->csv($this->download([
            'scope' => 'all', 'type' => DocumentType::Birth->value, 'from' => '2026-05-01', 'to' => '2026-07-01',
        ]));

        $this->assertCount(1, $rows);
        $this->assertContains('New Birth', $rows[0]);
    }

    /**
     * @param  array<string, string>  $values  column => value, for one person
     */
    private function record(DocumentType $type, array $values, $submittedAt = null): CivilRecord
    {
        $user = User::factory()->create();
        $record = CivilRecord::factory()->ofType($type)->submitted($user)->create([
            'created_by' => $user->getKey(),
            'submitted_at' => $submittedAt ?? now(),
        ]);

        $order = 0;
        foreach ($values as $column => $value) {
            RecordField::factory()->for($record, 'record')->create([
                'page_no' => 1,
                'name' => $column,
                'ocr_text' => $value,
                'verified_value' => $value,
                'person_group' => 1,
                'person_field_order' => $order,
                'line_column' => $column,
                'line_row' => 1,
                'sort_order' => $order++,
            ]);
        }

        return $record;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function download(array $query = []): TestResponse
    {
        return $this->actingAs(User::factory()->create())
            ->get(route('records.export', $query))
            ->assertOk();
    }

    /**
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function csv(TestResponse $response): array
    {
        $body = preg_replace('/^\x{FEFF}/u', '', $response->streamedContent());
        $lines = array_values(array_filter(preg_split('/\r?\n/', $body), fn ($line) => $line !== ''));
        $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), $lines);

        return [array_shift($rows), $rows];
    }
}
