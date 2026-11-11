<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\AuditLog;
use App\Models\CivilRecord;
use App\Models\DocumentTemplate;
use App\Models\OcrModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A register book scanned as one PDF: each page is saved as its own record,
 * which keeps the file's name, the page and the file's fingerprint. The
 * workspace asks which pages of a file are saved, and is told before a page
 * is saved twice.
 */
class MultiPageSourceTest extends TestCase
{
    use RefreshDatabase;

    private const BOOK = 'a3f1c2d4e5b60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private const OTHER_BOOK = '0000000000000000000000000000000000000000000000000000000000000001';

    private DocumentTemplate $template;

    private User $account;

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
            'publish' => 1,
        ])->assertSessionHasNoErrors();

        $this->template = DocumentTemplate::firstOrFail();
        $this->account = User::factory()->superAdmin()->create();
    }

    /** @param  array<string, mixed>  $source */
    private function submitPage(array $source, string $name = 'Juan Cruz'): TestResponse
    {
        return $this->actingAs($this->account)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                'doc_type' => DocumentType::Death->value,
                'document_template_id' => $this->template->getKey(),
                'ocr_model_key' => 'test-model',
                // The workspace sends the page alone, cut out of the book.
                'scan' => UploadedFile::fake()->create('BIRTHS-1995 (page '.($source['source_page'] ?? 1).').pdf', 40, 'application/pdf'),
                'fields' => [[
                    'verified' => '1', 'name' => 'Deceased', 'ocr_text' => $name, 'ocr_confidence' => 90,
                    'verified_value' => $name, 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05,
                ]],
                ...$source,
            ]);
    }

    /** @return array<string, mixed> */
    private function page(int $page, int $count = 12, string $hash = self::BOOK): array
    {
        return [
            'source_name' => 'BIRTHS-1995.pdf',
            'source_page' => $page,
            'source_page_count' => $count,
            'source_hash' => $hash,
        ];
    }

    public function test_a_saved_page_keeps_the_file_name_page_and_fingerprint(): void
    {
        $response = $this->submitPage($this->page(3))->assertCreated();

        $record = CivilRecord::sole();
        $this->assertSame('BIRTHS-1995.pdf', $record->source_name);
        $this->assertSame(3, $record->source_page);
        $this->assertSame(12, $record->source_page_count);
        $this->assertSame(self::BOOK, $record->source_hash);
        $response->assertJsonPath('record.page', 3)
            ->assertJsonPath('record.recordId', $record->getKey())
            ->assertJsonPath('record.title', 'Juan Cruz');

        $audit = AuditLog::where('action', 'record.submitted')->sole();
        $this->assertSame(3, $audit->new_values['source_page']);
        $this->assertSame(12, $audit->new_values['source_page_count']);

        $this->actingAs($this->account)->get(route('records.show', $record))
            ->assertOk()
            ->assertSee('Page 3 of 12 · BIRTHS-1995.pdf');
    }

    public function test_the_same_page_of_the_same_file_is_not_saved_twice_without_asking(): void
    {
        $this->submitPage($this->page(3))->assertCreated();
        $first = CivilRecord::sole();

        $this->submitPage($this->page(3), 'Pedro Reyes')
            ->assertStatus(409)
            ->assertJsonPath('duplicate_page', true)
            ->assertJsonPath('message', "Page 3 of this file is already saved as record #{$first->getKey()}.")
            ->assertJsonPath('record.recordId', $first->getKey())
            ->assertJsonPath('record.url', route('records.show', $first));
        $this->assertDatabaseCount('records', 1);

        // Other pages of the book, and the same page of another file, are fine.
        $this->submitPage($this->page(4))->assertCreated();
        $this->submitPage($this->page(3, hash: self::OTHER_BOOK))->assertCreated();
        $this->assertDatabaseCount('records', 3);

        // The user chose "Save again": saved, and the audit log says so.
        $this->submitPage([...$this->page(3), 'allow_duplicate' => '1'], 'Pedro Reyes')->assertCreated();
        $this->assertDatabaseCount('records', 4);
        $audit = AuditLog::where('action', 'record.submitted')->latest('id')->first();
        $this->assertSame($first->getKey(), $audit->new_values['saved_again_after_record']);
    }

    public function test_a_file_of_one_page_saved_twice_is_called_this_file(): void
    {
        $this->submitPage($this->page(1, 1))->assertCreated();
        $saved = CivilRecord::sole();

        $this->submitPage($this->page(1, 1))
            ->assertStatus(409)
            ->assertJsonPath('message', "This file is already saved as record #{$saved->getKey()}.");
    }

    public function test_the_user_is_told_which_pages_of_a_file_are_saved(): void
    {
        $this->submitPage($this->page(1))->assertCreated();
        $this->submitPage($this->page(3))->assertCreated();
        $this->submitPage([...$this->page(3), 'allow_duplicate' => '1'], 'Pedro Reyes')->assertCreated();
        $this->submitPage($this->page(2, hash: self::OTHER_BOOK))->assertCreated();
        $newest = CivilRecord::where('source_page', 3)->latest('id')->first();

        $pages = $this->actingAs($this->account)
            ->getJson(route('documents.sources.pages', ['hash' => self::BOOK]))
            ->assertOk()
            ->json('pages');

        // One entry per page, the newest record for it; the other book is left out.
        $this->assertSame([1, 3], array_column($pages, 'page'));
        $this->assertSame($newest->getKey(), $pages[1]['recordId']);
        $this->assertSame('Pedro Reyes', $pages[1]['title']);

        $this->actingAs($this->account)
            ->getJson(route('documents.sources.pages', ['hash' => str_repeat('b', 64)]))
            ->assertOk()
            ->assertExactJson(['pages' => []]);

        // Not a fingerprint at all.
        $this->actingAs($this->account)->getJson('/documents/sources/not-a-hash/pages')->assertNotFound();

        // Admin does not process documents.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson(route('documents.sources.pages', ['hash' => self::BOOK]))
            ->assertForbidden();
    }

    public function test_the_page_details_are_checked(): void
    {
        $this->submitPage($this->page(13, 12))->assertUnprocessable()->assertJsonValidationErrors('source_page');
        $this->submitPage([...$this->page(2), 'source_hash' => 'NOT-A-HASH'])
            ->assertUnprocessable()->assertJsonValidationErrors('source_hash');
        // A fingerprint says which file; the page must come with it.
        $this->submitPage(['source_hash' => self::BOOK])->assertUnprocessable()->assertJsonValidationErrors('source_page');

        $this->assertDatabaseCount('records', 0);
    }

    public function test_a_scan_sent_without_page_details_keeps_the_uploaded_name(): void
    {
        $this->submitPage([])->assertCreated();

        $record = CivilRecord::sole();
        $this->assertSame('BIRTHS-1995 (page 1).pdf', $record->source_name);
        $this->assertNull($record->source_page);
        $this->assertNull($record->source_hash);
        // Nothing to compare it with: a second one is saved without asking.
        $this->submitPage([])->assertCreated();
    }

    public function test_a_pdf_page_is_shown_as_a_picture_once_its_page_image_is_kept(): void
    {
        $this->submitPage($this->page(2))->assertCreated();
        $record = CivilRecord::sole();

        // Without the page Detect kept, a PDF can only be opened in a new tab.
        $this->actingAs($this->account)->get(route('records.show', $record))
            ->assertOk()
            ->assertSee('Open scanned PDF')
            ->assertDontSee('data-scan-image', escape: false);

        $record->update(['page_image_path' => 'records/'.$record->getKey().'/page.png']);
        $this->actingAs($this->account)->get(route('records.show', $record))
            ->assertOk()
            ->assertSee('data-scan-image', escape: false)
            ->assertSee(route('records.page-image', $record), escape: false)
            ->assertDontSee('Open scanned PDF');
    }
}
