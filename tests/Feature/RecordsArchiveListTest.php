<?php

namespace Tests\Feature;

use App\Models\CivilRecord;
use App\Models\RecordField;
use App\Models\RecordPage;
use App\Models\ScanBatch;
use App\Models\ScanBatchPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What the Saved Records list says of each record: a register page by its
 * file and pages (with how many people and its first column's first and last
 * value), a single certificate by its name; searched by file name too, and
 * filtered by the batch that saved it.
 */
class RecordsArchiveListTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_register_page_is_named_by_its_file_and_pages_with_its_people(): void
    {
        $user = User::factory()->create();
        $record = $this->register($user, 'births-1978.pdf', ['1978-0001', '1978-0002', '1978-0003']);

        $this->actingAs($user)->get(route('records.index'))
            ->assertOk()
            ->assertSee('births-1978.pdf · pages 1–2')
            ->assertSee('Record #'.$record->getKey())
            ->assertSee('3 people')
            ->assertSee('1978-0001 to 1978-0003')
            // The name opens it: no View button, and no lock on every row.
            ->assertDontSee('>View</a>', false)
            ->assertDontSee('bx-lock-alt', false);
    }

    public function test_a_single_certificate_keeps_its_name_with_its_file_under_it(): void
    {
        $user = User::factory()->create();
        $record = $this->record($user, ['source_name' => 'death-juan.pdf']);
        $this->field($record, 'Name', 'Juan Dela Cruz', person: null, row: null);

        $this->actingAs($user)->get(route('records.index'))
            ->assertOk()
            ->assertSeeInOrder(['Juan Dela Cruz', 'Record #'.$record->getKey(), 'death-juan.pdf']);
    }

    public function test_a_file_name_finds_its_records(): void
    {
        $user = User::factory()->create();
        $wanted = $this->register($user, 'register - Copy (2).pdf', ['A-1', 'A-2']);
        $this->register($user, 'another-book.pdf', ['B-1', 'B-2']);

        $this->actingAs($user)->get(route('records.index', ['q' => 'copy (2)']))
            ->assertOk()
            ->assertSee(route('records.show', $wanted), false)
            ->assertSee('1 matching record')
            ->assertDontSee('another-book.pdf');
    }

    public function test_the_batch_filter_shows_the_records_that_batch_saved(): void
    {
        $user = User::factory()->create();
        $inBatch = $this->register($user, 'first.pdf', ['1', '2']);
        $other = $this->register($user, 'second.pdf', ['3', '4']);
        $batch = ScanBatch::create([
            'user_id' => $user->getKey(),
            'doc_type' => 'birth',
            'batch_key' => 'batch_'.Str::random(8),
            'status' => ScanBatch::STATUS_DONE,
            'version' => 1,
        ]);
        foreach ([1, 2] as $pageNo) {
            ScanBatchPage::create([
                'scan_batch_id' => $batch->getKey(),
                'client_key' => (string) Str::uuid(),
                'position' => $pageNo - 1,
                'source_name' => 'first.pdf',
                'page_no' => $pageNo,
                'page_total' => 2,
                'record_key' => 'g1#1',
                'status' => 'imported',
                'record_id' => $inBatch->getKey(),
            ]);
        }

        $this->actingAs($user)->get(route('records.index', ['batch' => $batch->getKey()]))
            ->assertOk()
            // Offered in the Batch list, counting records (not pages).
            ->assertSee('· 1 record</option>', false)
            ->assertSee(route('records.show', $inBatch), false)
            ->assertDontSee(route('records.show', $other), false);
    }

    public function test_each_record_says_whether_a_batch_or_a_single_file_saved_it(): void
    {
        $user = User::factory()->create();
        // Saved before any batch was kept: it cannot be told.
        $old = $this->register($user, 'old.pdf', ['1', '2']);
        $old->forceFill(['submitted_at' => now()->subDays(3)])->save();
        $batch = $this->batch($user, now()->subDay());
        $fromBatch = $this->register($user, 'batch.pdf', ['3', '4']);
        $this->linkToBatch($batch, $fromBatch);
        $single = $this->register($user, 'single.pdf', ['5', '6']);

        $page = $this->actingAs($user)->get(route('records.index'))->assertOk();
        $page->assertSee('href="'.route('records.index', ['batch' => $batch->getKey()]).'"', false)
            ->assertSee('Single file')
            ->assertSee('Saved before batches were kept', false);

        // Single files only: the one saved alone since batches are kept.
        $this->actingAs($user)->get(route('records.index', ['batch' => 'single']))
            ->assertOk()
            ->assertSee(route('records.show', $single), false)
            ->assertDontSee(route('records.show', $fromBatch), false)
            ->assertDontSee(route('records.show', $old), false);
    }

    public function test_the_results_page_is_not_mistaken_for_a_filter(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 16) as $number) {
            $this->register($user, "book-{$number}.pdf", ['1', '2']);
        }

        // Page 2 of everything: "16 records", not "16 matching records", and no Reset.
        $this->actingAs($user)->get(route('records.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('16 records')
            ->assertDontSee('matching')
            ->assertDontSee('Clear all filters');
    }

    private function batch(User $user, $createdAt): ScanBatch
    {
        $batch = ScanBatch::create([
            'user_id' => $user->getKey(),
            'doc_type' => 'birth',
            'batch_key' => 'batch_'.Str::random(8),
            'status' => ScanBatch::STATUS_DONE,
            'version' => 1,
        ]);
        $batch->forceFill(['created_at' => $createdAt])->save();

        return $batch;
    }

    private function linkToBatch(ScanBatch $batch, CivilRecord $record): void
    {
        ScanBatchPage::create([
            'scan_batch_id' => $batch->getKey(),
            'client_key' => (string) Str::uuid(),
            'position' => 0,
            'source_name' => (string) $record->source_name,
            'page_no' => 1,
            'page_total' => 2,
            'record_key' => 'g'.$record->getKey().'#1',
            'status' => 'imported',
            'record_id' => $record->getKey(),
        ]);
    }

    /** A register page: one record of a 2-page file, a person per row, the first column's values given. */
    private function register(User $user, string $file, array $firstColumn): CivilRecord
    {
        $record = $this->record($user, ['source_name' => $file, 'source_page' => 1, 'source_page_count' => 2]);
        RecordPage::create(['record_id' => $record->getKey(), 'page_no' => 2, 'source_page' => 2]);
        foreach ($firstColumn as $index => $value) {
            $this->field($record, 'Registry No. · row '.($index + 1), $value, person: $index + 1, row: $index + 1, order: $index * 2);
            $this->field($record, 'Name · row '.($index + 1), 'Person '.($index + 1), person: $index + 1, row: $index + 1, column: 'Name', order: $index * 2 + 1);
            // Page 2 has a column of the same name: it is not part of page 1's range.
            $this->field($record, 'Registry No. · page 2 · row '.($index + 1), 'Filipino', person: $index + 1, row: $index + 1, order: 100 + $index, page: 2);
        }

        return $record;
    }

    private function record(User $user, array $source): CivilRecord
    {
        $record = CivilRecord::factory()->submitted($user)->create(['created_by' => $user->getKey()]);
        $record->forceFill($source)->save();

        return $record;
    }

    private function field(CivilRecord $record, string $name, string $value, ?int $person, ?int $row, string $column = 'Registry No.', int $order = 0, int $page = 1): void
    {
        RecordField::factory()->for($record, 'record')->create([
            'page_no' => $page,
            'name' => $name,
            'ocr_text' => $value,
            'verified_value' => $value,
            'person_group' => $person,
            'person_field_order' => $person === null ? null : ($column === 'Name' ? 1 : 0),
            'line_column' => $row === null ? null : $column,
            'line_row' => $row,
            'sort_order' => $order,
        ]);
    }
}
