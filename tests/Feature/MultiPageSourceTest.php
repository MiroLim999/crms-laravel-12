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

    private User $staff;

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
        $this->staff = User::factory()->staff()->create();
    }

    /** @param  array<string, mixed>  $source */
    private function submitPage(array $source, string $name = 'Juan Cruz'): TestResponse
    {
        return $this->actingAs($this->staff)
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

        $this->actingAs($this->staff)->get(route('records.show', $record))
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

        // Staff chose "Save again": saved, and the audit log says so.
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

    public function test_staff_are_told_which_pages_of_a_file_are_saved(): void
    {
        $this->submitPage($this->page(1))->assertCreated();
        $this->submitPage($this->page(3))->assertCreated();
        $this->submitPage([...$this->page(3), 'allow_duplicate' => '1'], 'Pedro Reyes')->assertCreated();
        $this->submitPage($this->page(2, hash: self::OTHER_BOOK))->assertCreated();
        $newest = CivilRecord::where('source_page', 3)->latest('id')->first();

        $pages = $this->actingAs($this->staff)
            ->getJson(route('documents.sources.pages', ['hash' => self::BOOK]))
            ->assertOk()
            ->json('pages');

        // One entry per page, the newest record for it; the other book is left out.
        $this->assertSame([1, 3], array_column($pages, 'page'));
        $this->assertSame($newest->getKey(), $pages[1]['recordId']);
        $this->assertSame('Pedro Reyes', $pages[1]['title']);

        $this->actingAs($this->staff)
            ->getJson(route('documents.sources.pages', ['hash' => str_repeat('b', 64)]))
            ->assertOk()
            ->assertExactJson(['pages' => [], 'skipped' => []]);

        // Not a fingerprint at all.
        $this->actingAs($this->staff)->getJson('/documents/sources/not-a-hash/pages')->assertNotFound();

        // Admin does not process documents.
        $this->actingAs(User::factory()->admin()->create())
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
        $this->actingAs($this->staff)->get(route('records.show', $record))
            ->assertOk()
            ->assertSee('Open scanned PDF')
            ->assertDontSee('data-scan-image', escape: false);

        $record->update(['page_image_path' => 'records/'.$record->getKey().'/page.png']);
        $this->actingAs($this->staff)->get(route('records.show', $record))
            ->assertOk()
            ->assertSee('data-scan-image', escape: false)
            ->assertSee(route('records.page-image', $record), escape: false)
            ->assertDontSee('Open scanned PDF');
    }

    public function test_a_page_can_be_skipped_and_read_after_all(): void
    {
        $skip = fn (int $page) => $this->actingAs($this->staff)
            ->postJson(route('documents.sources.skip', ['hash' => self::BOOK, 'page' => $page]));

        $skip(1)->assertOk()->assertJsonPath('skipped', [1]);
        // Twice is still once.
        $skip(1)->assertOk()->assertJsonPath('skipped', [1]);
        $skip(5)->assertOk()->assertJsonPath('skipped', [1, 5]);

        $this->actingAs($this->staff)
            ->getJson(route('documents.sources.pages', ['hash' => self::BOOK]))
            ->assertJsonPath('skipped', [1, 5])
            ->assertJsonPath('pages', []);

        $this->actingAs($this->staff)
            ->deleteJson(route('documents.sources.unskip', ['hash' => self::BOOK, 'page' => 5]))
            ->assertOk()->assertJsonPath('skipped', [1]);

        // Saving a skipped page after all: saved, no longer skipped.
        $this->submitPage($this->page(1))->assertCreated();
        $this->actingAs($this->staff)
            ->getJson(route('documents.sources.pages', ['hash' => self::BOOK]))
            ->assertJsonPath('skipped', [])
            ->assertJsonPath('pages.0.page', 1);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('documents.sources.skip', ['hash' => self::BOOK, 'page' => 2]))
            ->assertForbidden();
    }

    public function test_the_same_register_volume_and_page_asks_first_even_from_another_file(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())->post(route('templates.store'), [
            'name' => 'Death register with page numbers',
            'doc_type' => DocumentType::Death->value,
            'page_ratio' => 11 / 8.5,
            'grouping_mode' => 'custom',
            'fields_json' => json_encode([
                ['name' => 'Deceased', 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05, 'role' => 'name'],
                ['name' => 'Volume No.', 'x' => 0.5, 'y' => 0.02, 'width' => 0.1, 'height' => 0.04, 'role' => 'volume'],
                ['name' => 'Page No.', 'x' => 0.7, 'y' => 0.02, 'width' => 0.1, 'height' => 0.04, 'role' => 'page_no'],
                ['name' => 'Year', 'x' => 0.85, 'y' => 0.02, 'width' => 0.1, 'height' => 0.04, 'role' => 'year'],
            ], JSON_THROW_ON_ERROR),
            'columns_json' => '[]',
            'ruled_ys_json' => '[]',
            'publish' => 1,
        ])->assertSessionHasNoErrors();
        $template = DocumentTemplate::where('name', 'Death register with page numbers')->firstOrFail();

        $field = fn (string $name, string $value) => [
            'verified' => '1', 'name' => $name, 'ocr_text' => $value, 'ocr_confidence' => 90,
            'verified_value' => $value, 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05,
        ];
        $submit = fn (string $volume, string $page, string $year, string $hash, array $extra = []) => $this->actingAs($this->staff)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                'doc_type' => DocumentType::Death->value,
                'document_template_id' => $template->getKey(),
                'ocr_model_key' => 'test-model',
                'scan' => UploadedFile::fake()->create('page.pdf', 40, 'application/pdf'),
                'fields' => [$field('Deceased', 'Juan Cruz'), $field('Volume No.', $volume), $field('Page No.', $page), $field('Year', $year)],
                ...$this->page(1, 1, $hash),
                ...$extra,
            ]);

        $submit('7', '83', '1978', self::BOOK)->assertCreated();
        $first = CivilRecord::sole();

        // Another file (the book scanned again), written a little differently.
        $submit('Vol. 07', '083', '1978', self::OTHER_BOOK)
            ->assertStatus(409)
            ->assertJsonPath('duplicate_page', true)
            ->assertJsonPath('message', 'Volume Vol. 07, page 083 (1978) is already saved as record #'.$first->getKey().'.');

        // Another year, or another page, is another register page.
        $submit('7', '83', '1979', self::OTHER_BOOK)->assertCreated();
        $submit('7', '84', '1978', str_repeat('c', 64))->assertCreated();

        // "Save again".
        $submit('7', '83', '1978', str_repeat('d', 64), ['allow_duplicate' => '1'])->assertCreated();
        $this->assertDatabaseCount('records', 4);
    }

    public function test_a_page_role_belongs_to_one_field_of_the_page(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())->post(route('templates.store'), [
            'name' => 'Two volumes',
            'doc_type' => DocumentType::Death->value,
            'page_ratio' => 11 / 8.5,
            'grouping_mode' => 'custom',
            'fields_json' => json_encode([
                ['name' => 'Volume', 'x' => 0.1, 'y' => 0.02, 'width' => 0.1, 'height' => 0.04, 'role' => 'volume', 'person_group' => 1, 'person_field_order' => 0],
                ['name' => 'Volume again', 'x' => 0.3, 'y' => 0.02, 'width' => 0.1, 'height' => 0.04, 'role' => 'volume', 'person_group' => 2, 'person_field_order' => 0],
            ], JSON_THROW_ON_ERROR),
            'columns_json' => '[]',
            'ruled_ys_json' => '[]',
        ])->assertSessionHasErrors(['fields.1.role' => 'Only one field on the page can hold the volume number.']);
    }

    public function test_the_archive_lists_the_pages_of_one_file(): void
    {
        $this->submitPage($this->page(2))->assertCreated();
        $this->submitPage($this->page(1))->assertCreated();
        $this->submitPage($this->page(1, 3, self::OTHER_BOOK))->assertCreated();

        $page = $this->actingAs($this->staff)->get(route('records.index', ['source' => self::BOOK]))
            ->assertOk()
            ->assertSee('From file')
            ->assertSee('BIRTHS-1995.pdf (2 of 12 pages)')
            ->assertSee('2 matching records');
        // In page order.
        $html = $page->getContent();
        $this->assertLessThan(strpos($html, 'Page 2 of 12'), strpos($html, 'Page 1 of 12'));

        $record = CivilRecord::where('source_hash', self::BOOK)->first();
        $this->actingAs($this->staff)->get(route('records.show', $record))
            ->assertSee(route('records.index', ['source' => self::BOOK]), escape: false);

        // Not a fingerprint: shown as a problem, and every record listed.
        $this->actingAs($this->staff)->get(route('records.index', ['source' => 'nope']))
            ->assertOk()->assertSee('3 records');
    }
}
