<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Http\Controllers\ScanBatchController;
use App\Models\CivilRecord;
use App\Models\DocumentPage;
use App\Models\DocumentTemplate;
use App\Models\OcrModel;
use App\Models\ScanBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Batch File Upload kept on the server, so it can be resumed after a reload or
 * a crash: its queue and drafts are its owner's alone, a change from an older
 * copy (another tab) is refused, its pages outlive the usual pruning while it
 * is open, and a record saved from it links back to it.
 */
class ScanBatchTest extends TestCase
{
    use RefreshDatabase;

    private DocumentTemplate $template;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        OcrModel::create(['key' => 'test-model', 'label' => 'Test model']);

        $this->actingAs(User::factory()->superAdmin()->create())->post(route('templates.store'), [
            'name' => 'Death register',
            'doc_type' => DocumentType::Death->value,
            'page_ratio' => 11 / 8.5,
            'grouping_mode' => 'custom',
            'fields_json' => json_encode([
                ['name' => 'Deceased', 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05, 'role' => 'name'],
            ], JSON_THROW_ON_ERROR),
            'columns_json' => '[]',
            'ruled_ys_json' => '[]',
            'sample_document' => UploadedFile::fake()->image('register.png', 1200, 900),
            'publish' => 1,
        ])->assertSessionHasNoErrors();

        $this->template = DocumentTemplate::firstOrFail();
        $this->owner = User::factory()->superAdmin()->create();
    }

    private function createBatch(string $key = 'batch_1_abc', string $tab = 'tab-a'): ScanBatch
    {
        $this->actingAs($this->owner)
            ->postJson(route('documents.batches.store'), [
                'doc_type' => DocumentType::Death->value,
                'document_template_id' => $this->template->getKey(),
                'batch_key' => $key,
                'tab' => $tab,
            ])
            ->assertCreated()
            ->assertJsonPath('version', 0)
            ->assertJsonPath('status', ScanBatch::STATUS_OPEN);

        return ScanBatch::where('batch_key', $key)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function page(string $key, int $pageNo, array $extra = []): array
    {
        return [
            'key' => $key,
            'position' => $pageNo - 1,
            'source_name' => 'BOOK-1995.pdf',
            'source_hash' => str_repeat('a', 64),
            'page_no' => $pageNo,
            'page_total' => 4,
            'record_key' => 'g1#'.($pageNo % 2 === 1 ? $pageNo : $pageNo - 1),
            'status' => 'pending',
            ...$extra,
        ];
    }

    private function documentPage(User $user, string $age = '1 minute'): DocumentPage
    {
        $page = DocumentPage::create([
            'document_template_id' => $this->template->getKey(),
            'created_by' => $user->getKey(),
            'status' => DocumentPage::STATUS_READY,
            'image_path' => 'x.png',
            'width' => 10,
            'height' => 10,
            'geometry' => ['columns' => [], 'ruled_ys' => [], 'fields' => []],
        ]);
        $page->forceFill(['updated_at' => now()->sub($age)])->saveQuietly();

        return $page;
    }

    public function test_pages_and_drafts_are_kept_and_come_back_to_resume(): void
    {
        $batch = $this->createBatch();
        $own = $this->documentPage($this->owner);
        $someoneElses = $this->documentPage(User::factory()->superAdmin()->create());

        $this->actingAs($this->owner)
            ->putJson(route('documents.batches.pages', $batch), [
                'version' => 0,
                'tab' => 'tab-a',
                'pages' => [
                    $this->page('p1', 1, [
                        'status' => 'ready',
                        'document_page_id' => $own->getKey(),
                        'workspace_scan_path' => 'documents/workspace/batch_1_abc/1-BOOK.pdf',
                        'alignment' => ['boxes' => [['name' => 'Deceased', 'x' => 0.1]], 'rows' => [0.2, 0.3]],
                        'flags' => ['alignCheck' => true],
                    ]),
                    // Another user's page is never linked to this batch.
                    $this->page('p2', 2, ['status' => 'saved', 'document_page_id' => $someoneElses->getKey()]),
                    $this->page('p3', 3),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('version', 1);

        $this->actingAs($this->owner)
            ->putJson(route('documents.batches.draft', $batch), [
                'version' => 1,
                'tab' => 'tab-a',
                'record_key' => 'g1#1',
                'draft' => ['verify' => [['line' => 5, 'value' => 'Juan Cruz', 'verified' => true]]],
            ])
            ->assertOk()
            ->assertJsonPath('version', 2);

        // Page 3 removed from the batch.
        $this->actingAs($this->owner)
            ->putJson(route('documents.batches.pages', $batch), [
                'version' => 2, 'tab' => 'tab-a', 'pages' => [], 'removed' => ['p3'],
            ])
            ->assertOk()
            ->assertJsonPath('version', 3);

        $this->actingAs($this->owner)
            ->getJson(route('documents.batches.show', $batch))
            ->assertOk()
            ->assertJsonPath('version', 3)
            ->assertJsonCount(2, 'pages')
            ->assertJsonPath('pages.0.key', 'p1')
            ->assertJsonPath('pages.0.status', 'ready')
            ->assertJsonPath('pages.0.documentPageId', $own->getKey())
            ->assertJsonPath('pages.0.statusUrl', route('documents.pages.show', $own))
            ->assertJsonPath('pages.0.alignment.rows', [0.2, 0.3])
            ->assertJsonPath('pages.0.flags.alignCheck', true)
            ->assertJsonPath('pages.1.documentPageId', null)
            ->assertJsonPath('drafts.0.recordKey', 'g1#1')
            ->assertJsonPath('drafts.0.draft.verify.0.value', 'Juan Cruz');

        // The workspace offers it to resume: 1 record, none saved.
        $this->actingAs($this->owner)
            ->get(route('documents.workspace', ['type' => DocumentType::Death->value]))
            ->assertOk()
            ->assertViewHas('openBatches', fn (array $open) => count($open) === 1
                && $open[0]['id'] === $batch->getKey() && $open[0]['records'] === 1 && $open[0]['saved'] === 0);
    }

    public function test_a_batch_is_its_owners_alone(): void
    {
        $batch = $this->createBatch();
        $other = User::factory()->superAdmin()->create();
        $change = ['version' => 0, 'tab' => 'tab-b'];

        $this->actingAs($other)->getJson(route('documents.batches.show', $batch))->assertNotFound();
        $this->actingAs($other)->postJson(route('documents.batches.claim', $batch), ['tab' => 'tab-b'])->assertNotFound();
        $this->actingAs($other)->postJson(route('documents.batches.ping', $batch), $change)->assertNotFound();
        $this->actingAs($other)
            ->putJson(route('documents.batches.pages', $batch), [...$change, 'pages' => [$this->page('p1', 1)]])
            ->assertNotFound();
        $this->actingAs($other)
            ->putJson(route('documents.batches.draft', $batch), [...$change, 'record_key' => 'g1#1', 'draft' => ['x' => 1]])
            ->assertNotFound();
        $this->actingAs($other)->postJson(route('documents.batches.done', $batch), $change)->assertNotFound();
        $this->actingAs($other)->postJson(route('documents.batches.abandon', $batch))->assertNotFound();

        $this->assertSame(0, $batch->fresh()->version);
        $this->assertSame(0, $batch->pages()->count());
        $this->assertSame(ScanBatch::STATUS_OPEN, $batch->fresh()->status);
        // And it is not offered to anyone else.
        $this->assertSame([], ScanBatchController::openBatchesFor($other->getKey()));
    }

    public function test_a_change_from_an_older_copy_is_refused(): void
    {
        $batch = $this->createBatch();
        $pages = fn (int $version, string $tab) => $this->actingAs($this->owner)
            ->putJson(route('documents.batches.pages', $batch), [
                'version' => $version, 'tab' => $tab, 'pages' => [$this->page('p1', 1)],
            ]);

        $pages(0, 'tab-a')->assertOk()->assertJsonPath('version', 1);
        // The same tab's copy from before that change.
        $pages(0, 'tab-a')->assertStatus(409)->assertJsonPath('reason', 'stale')->assertJsonPath('version', 1);

        // A second tab takes the batch over; the first tab's next change is refused.
        $this->actingAs($this->owner)
            ->postJson(route('documents.batches.claim', $batch), ['tab' => 'tab-b'])
            ->assertOk()
            ->assertJsonPath('version', 2)
            ->assertJsonPath('tab', 'tab-b');
        $pages(2, 'tab-a')->assertStatus(409)->assertJsonPath('reason', 'taken');
        $this->actingAs($this->owner)
            ->postJson(route('documents.batches.ping', $batch), ['version' => 2, 'tab' => 'tab-a'])
            ->assertStatus(409);
        $pages(2, 'tab-b')->assertOk()->assertJsonPath('version', 3);
    }

    public function test_a_finished_or_discarded_batch_takes_no_more_changes(): void
    {
        $done = $this->createBatch('batch_done');
        $this->actingAs($this->owner)
            ->postJson(route('documents.batches.done', $done), ['version' => 0, 'tab' => 'tab-a'])
            ->assertOk()
            ->assertJsonPath('status', ScanBatch::STATUS_DONE);
        $this->actingAs($this->owner)
            ->putJson(route('documents.batches.pages', $done), ['version' => 1, 'tab' => 'tab-a', 'pages' => []])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'closed');

        $discarded = $this->createBatch('batch_gone');
        $this->actingAs($this->owner)
            ->postJson(route('documents.batches.abandon', $discarded))
            ->assertOk()
            ->assertJsonPath('status', ScanBatch::STATUS_ABANDONED);
        $this->actingAs($this->owner)
            ->postJson(route('documents.batches.claim', $discarded), ['tab' => 'tab-b'])
            ->assertStatus(409);

        $this->assertSame([], ScanBatchController::openBatchesFor($this->owner->getKey()));
    }

    public function test_pruning_keeps_an_open_batchs_pages_and_removes_an_expired_one_with_its_files(): void
    {
        $disk = Storage::disk('local');
        $withScan = function (DocumentPage $page, string $batchKey) use ($disk) {
            $scan = "documents/workspace/{$batchKey}/{$page->getKey()}-BOOK.pdf";
            $disk->put($scan, 'pdf');
            $disk->put($page->directory().'/workspace_scan.json', json_encode(['path' => $scan]));
            $page->forceFill(['updated_at' => now()->subDays(2)])->saveQuietly();

            return $scan;
        };
        $link = function (ScanBatch $batch, DocumentPage $page) {
            $batch->pages()->create([
                'client_key' => "p{$page->getKey()}", 'position' => 0, 'source_name' => 'BOOK.pdf',
                'page_no' => 1, 'page_total' => 1, 'record_key' => 'r1', 'status' => 'ready',
                'document_page_id' => $page->getKey(),
            ]);
        };

        // Open and worked on yesterday: its two-day-old page stays.
        $open = $this->createBatch('batch_open');
        $kept = $this->documentPage($this->owner, '2 days');
        $keptScan = $withScan($kept, 'batch_open');
        $link($open, $kept);

        // Open but untouched for longer than its limit: it goes, with its page and files.
        $expired = $this->createBatch('batch_old');
        $old = $this->documentPage($this->owner, '9 days');
        $oldScan = $withScan($old, 'batch_old');
        $link($expired, $old);
        $expired->forceFill(['updated_at' => now()->subDays(ScanBatch::keepDays() + 1)])->saveQuietly();

        // Discarded today: it goes too.
        $discarded = $this->createBatch('batch_discarded');
        $dropped = $this->documentPage($this->owner, '1 minute');
        $droppedScan = $withScan($dropped, 'batch_discarded');
        $link($discarded, $dropped);
        $discarded->forceFill(['status' => ScanBatch::STATUS_ABANDONED])->save();

        // A page in no batch is pruned as before.
        $loose = $this->documentPage($this->owner, '2 days');

        $open->forceFill(['updated_at' => now()->subDay()])->saveQuietly();

        $this->artisan('documents:prune-pages')->assertSuccessful();

        $this->assertModelExists($open);
        $this->assertModelExists($kept);
        $disk->assertExists($keptScan);

        $this->assertModelMissing($expired);
        $this->assertModelMissing($old);
        $disk->assertMissing($oldScan);
        $disk->assertMissing('documents/workspace/batch_old');

        $this->assertModelMissing($discarded);
        $this->assertModelMissing($dropped);
        $disk->assertMissing($droppedScan);

        $this->assertModelMissing($loose);
    }

    public function test_a_record_saved_from_a_batch_links_back_to_its_pages(): void
    {
        $batch = $this->createBatch();
        $page = $this->documentPage($this->owner);
        $page->forceFill(['ocr_model_key' => 'test-model', 'image_path' => 'documents/pages/p1.png'])->save();
        Storage::disk('local')->put($page->image_path, 'png');
        $line = $page->lines()->create([
            'position' => 0, 'column_name' => '', 'column_index' => 0, 'row' => 1, 'source' => 'kraken',
            'polygon' => [[10, 10], [50, 10], [50, 30], [10, 30]], 'bbox' => [10, 10, 40, 20],
            'crop_path' => $page->directory().'/crops/001.png', 'flags' => [],
        ]);
        Storage::disk('local')->put($line->crop_path, 'crop');
        $scan = 'documents/workspace/batch_1_abc/1-BOOK.pdf';
        Storage::disk('local')->put($scan, 'pdf');

        $this->actingAs($this->owner)
            ->putJson(route('documents.batches.pages', $batch), [
                'version' => 0, 'tab' => 'tab-a',
                'pages' => [
                    $this->page('p1', 1, ['status' => 'ready', 'document_page_id' => $page->getKey()]),
                    $this->page('p3', 3),
                ],
            ])
            ->assertOk();

        $this->actingAs($this->owner)->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                'doc_type' => DocumentType::Death->value,
                'document_template_id' => $this->template->getKey(),
                'ocr_model_key' => 'test-model',
                'workspace_scan_path' => $scan,
                'allow_missing' => true,
                'document_page_id' => $page->getKey(),
                'fields' => [[
                    'verified' => '1', 'name' => 'Deceased', 'ocr_text' => 'Juan Cruz', 'ocr_confidence' => 90,
                    'verified_value' => 'Juan Cruz', 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05,
                    'line_id' => $line->getKey(),
                ]],
                'scan_batch_id' => $batch->getKey(),
                'scan_batch_pages' => ['p1'],
            ])
            ->assertCreated();

        $record = CivilRecord::firstOrFail();
        $linked = $batch->pages()->where('client_key', 'p1')->firstOrFail();
        $this->assertSame($record->getKey(), $linked->record_id);
        $this->assertSame('imported', $linked->status);
        $this->assertNull($batch->pages()->where('client_key', 'p3')->firstOrFail()->record_id);

        // A resumed batch lists it as saved, with its link.
        $this->actingAs($this->owner)
            ->getJson(route('documents.batches.show', $batch))
            ->assertJsonPath('pages.0.recordId', $record->getKey())
            ->assertJsonPath('pages.0.recordUrl', route('records.show', $record));

        // A page saved as a record is not removed from the batch.
        $this->actingAs($this->owner)
            ->putJson(route('documents.batches.pages', $batch), [
                'version' => 1, 'tab' => 'tab-a', 'pages' => [], 'removed' => ['p1'],
            ])
            ->assertOk();
        $this->assertSame(1, $batch->pages()->where('client_key', 'p1')->count());
    }
}
