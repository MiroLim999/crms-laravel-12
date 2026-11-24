<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\AuditLog;
use App\Models\CivilRecord;
use App\Models\RecordField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Several records' typed tables at once (records.export): Download selected and
 * Download all matching in the archive, and a batch's Download all. Each table
 * is the one that record's own CSV download gives.
 */
class RecordsExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_records_picked_come_back_oldest_first_with_their_tables(): void
    {
        $user = User::factory()->create();
        $first = $this->record($user, 'Ana Cruz');
        $second = $this->record($user, 'Ben Reyes');
        $this->record($user, 'Not picked');

        $response = $this->actingAs($user)
            ->getJson(route('records.export', ['ids' => [$second->getKey(), $first->getKey()]]))
            ->assertOk();

        $records = $response->json('records');
        $this->assertSame([$first->getKey(), $second->getKey()], array_column($records, 'id'));
        $this->assertSame(['Record ID', 'Document type', 'Row', 'Name'], $records[0]['header']);
        $this->assertSame([$first->getKey(), 'BOOK OF BIRTH', 1, 'ANA CRUZ'], $records[0]['rows'][0]);
        $this->assertSame('BEN REYES', $records[1]['rows'][0][3]);
        $this->assertSame('no-cache, no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_all_takes_every_record_the_filters_match(): void
    {
        $user = User::factory()->create();
        $birth = $this->record($user, 'Ana Cruz');
        $this->record($user, 'Ben Reyes', DocumentType::Death);

        $records = $this->actingAs($user)
            ->getJson(route('records.export', ['all' => 1, 'type' => 'birth']))
            ->assertOk()
            ->json('records');

        $this->assertSame([$birth->getKey()], array_column($records, 'id'));
    }

    public function test_the_download_is_one_audit_entry_naming_the_records(): void
    {
        $user = User::factory()->create();
        $first = $this->record($user, 'Ana Cruz');
        $second = $this->record($user, 'Ben Reyes');

        $this->actingAs($user)->getJson(route('records.export', ['ids' => [$first->getKey(), $second->getKey()]]))->assertOk();

        $entry = AuditLog::where('action', 'record.exported')->sole();
        $this->assertSame($user->getKey(), $entry->user_id);
        $this->assertNull($entry->auditable_id);
        $this->assertEquals(['records' => 2, 'rows' => 2, 'ids' => [$first->getKey(), $second->getKey()]], $entry->new_values);
    }

    public function test_nothing_picked_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('records.export'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');
    }

    public function test_a_guest_cannot_download(): void
    {
        $record = $this->record(User::factory()->create(), 'Ana Cruz');

        $this->getJson(route('records.export', ['ids' => [$record->getKey()]]))->assertUnauthorized();
    }

    public function test_the_archive_offers_the_downloads(): void
    {
        $user = User::factory()->create();
        $record = $this->record($user, 'Ana Cruz');

        $this->actingAs($user)->get(route('records.index'))
            ->assertOk()
            ->assertSee('id="recordsDownloadSelected"', false)
            ->assertSee('Download all 1')
            ->assertSee('data-record-id="'.$record->getKey().'"', false);

        // With a search on, the button says it follows the search.
        $this->actingAs($user)->get(route('records.index', ['q' => 'Ana']))
            ->assertOk()
            ->assertSee('Download these 1')
            ->assertDontSee('Download all 1');
    }

    public function test_the_archive_pages_with_the_crms_pager(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 16) as $n) {
            $this->record($user, 'Person '.$n);
        }

        $this->actingAs($user)->get(route('records.index'))
            ->assertOk()
            ->assertSee('class="crms-pager"', false)
            ->assertSee('Showing <strong>1–15</strong>', false)
            ->assertSee('aria-label="Next page"', false)
            ->assertDontSee('Next &raquo;', false);
    }

    private function record(User $user, string $name, DocumentType $type = DocumentType::Birth): CivilRecord
    {
        $record = CivilRecord::factory()->ofType($type)->submitted($user)->create(['created_by' => $user->getKey()]);
        RecordField::factory()->for($record, 'record')->create([
            'page_no' => 1,
            'name' => 'Name',
            'ocr_text' => $name,
            'verified_value' => $name,
            'person_group' => 1,
            'person_field_order' => 0,
            'line_column' => 'Name',
            'line_row' => 1,
            'sort_order' => 0,
        ]);

        return $record;
    }
}
