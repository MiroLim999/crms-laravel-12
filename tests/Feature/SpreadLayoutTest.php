<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\CivilRecord;
use App\Models\DocumentPage;
use App\Models\DocumentTemplate;
use App\Models\OcrModel;
use App\Models\PageLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A register spread: a layout with a second page, whose rows run across both
 * pages. Row N of the first page and row N of the second are one person, saved
 * as one record.
 */
class SpreadLayoutTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE_ONE = [
        ['name' => 'Name', 'box' => [0.05, 0.1, 0.45, 0.8]],
        ['name' => 'Date', 'box' => [0.5, 0.1, 0.3, 0.8]],
    ];

    private const PAGE_TWO = [
        ['name' => 'Place', 'box' => [0.05, 0.1, 0.45, 0.8]],
        ['name' => 'Remarks', 'box' => [0.5, 0.1, 0.3, 0.8]],
    ];

    private const ROWS = [0.1, 0.26, 0.42, 0.58, 0.74, 0.9];

    public function test_the_builder_saves_a_second_page_and_each_page_reads_as_a_layout_of_its_own(): void
    {
        $template = $this->spreadTemplate();

        $this->assertTrue($template->hasSecondPage());
        $this->assertSame(2, $template->pageCount());
        $this->assertTrue($template->isLedger());
        $this->assertSame(['Name', 'Date'], array_column($template->columns, 'name'));

        $second = $template->forPage(2);
        $this->assertTrue($second->isLedger());
        $this->assertSame(['Place', 'Remarks'], array_column($second->columns, 'name'));
        $this->assertSame(['Place', 'Remarks'], array_column($second->markerBoxes(), 'name'));
        // The first page is untouched, and a layout of one page is its own page 2.
        $this->assertSame(['Name', 'Date'], array_column($template->markerBoxes(), 'name'));
        $this->assertSame($template, $template->forPage(1));

        // A field is found by its name whichever page it is on.
        $this->assertEqualsCanonicalizing(['name', 'date', 'place', 'remarks'], array_keys($template->settingsByName()));
    }

    public function test_a_name_used_on_both_pages_is_refused(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('templates.store'), $this->payload('Clashing spread', [
                ['name' => 'Name', 'box' => [0.1, 0.1, 0.4, 0.8]],
            ]))
            ->assertSessionHasErrors('page_two');

        $this->assertDatabaseMissing('document_templates', ['name' => 'Clashing spread']);
    }

    public function test_a_second_page_is_checked_like_the_first(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('templates.store'), $this->payload('Broken page two', self::PAGE_TWO, ruled: [0.5, 0.3]))
            ->assertSessionHasErrors('page_two.ruled_ys');
    }

    public function test_editing_a_layout_in_use_to_add_or_change_page_two_makes_a_new_version(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $template = $this->spreadTemplate();
        $template->update(['is_active' => true]);

        $changed = self::PAGE_TWO;
        $changed[0]['box'] = [0.06, 0.1, 0.44, 0.8];
        $this->actingAs($admin)
            ->put(route('templates.update', $template), [
                ...$this->payload($template->name, $changed),
                'doc_type' => DocumentType::Birth->value,
            ])
            ->assertRedirect();

        $this->assertSame(2, DocumentTemplate::where('name', 'Register spread')->count());
        $this->assertSame(self::PAGE_TWO[0]['box'], $template->fresh()->page_two['columns'][0]['box']);
    }

    public function test_both_pages_are_saved_as_one_record_with_a_person_for_each_row(): void
    {
        Storage::fake('local');
        OcrModel::create(['key' => 'test-model', 'label' => 'Test model']);
        $staff = User::factory()->staff()->create();
        $template = $this->spreadTemplate();
        $one = $this->readPage($template, $staff, self::PAGE_ONE, 'page-one');
        $two = $this->readPage($template, $staff, self::PAGE_TWO, 'page-two');

        $fields = [];
        foreach ([1, 2] as $row) {
            foreach (['Name', 'Date'] as $column) {
                $fields[] = $this->cell($one, $row, $column, 1);
            }
            foreach (['Place', 'Remarks'] as $column) {
                $fields[] = $this->cell($two, $row, $column, 2);
            }
        }

        $response = $this->actingAs($staff)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                'doc_type' => $template->doc_type->value,
                'document_template_id' => $template->getKey(),
                'ocr_model_key' => 'test-model',
                'scan' => UploadedFile::fake()->create('spread.pdf', 50, 'application/pdf'),
                'document_page_id' => $one->getKey(),
                'document_page_id_two' => $two->getKey(),
                'allow_missing' => '1',
                'source_name' => 'spread.pdf',
                'source_page' => 1,
                'source_page_count' => 2,
                'fields' => $fields,
            ])
            ->assertCreated()
            ->assertJsonPath('summary.fields', 8);

        $record = CivilRecord::with('fields')->findOrFail($response->json('record.recordId'));
        $this->assertNotNull($record->page_image_path);
        $this->assertNotNull($record->page_image_path_two);
        Storage::disk('local')->assertExists([$record->page_image_path, $record->page_image_path_two]);

        // Row 1 is one person across both pages, with page 2's cells after page 1's.
        $person = $record->fields->where('person_group', 1)->sortBy('person_field_order');
        $this->assertSame(['Name', 'Date', 'Place', 'Remarks'], $person->map(fn ($f) => explode(' · ', $f->name)[0])->values()->all());
        $this->assertSame([1, 1, 2, 2], $person->pluck('page')->values()->all());
        $this->assertCount(2, $record->fields->pluck('person_group')->unique());

        // The working pages are gone, as for a record of one page.
        $this->assertSame(0, DocumentPage::count());

        // Each page's picture is served on its own.
        $this->actingAs($staff)->get(route('records.page-image', [$record, 1]))->assertOk();
        $this->actingAs($staff)->get(route('records.page-image', [$record, 2]))->assertOk();
    }

    public function test_what_page_two_leaves_out_is_named_for_that_page(): void
    {
        Storage::fake('local');
        OcrModel::create(['key' => 'test-model', 'label' => 'Test model']);
        $staff = User::factory()->staff()->create();
        $template = $this->spreadTemplate();
        $one = $this->readPage($template, $staff, self::PAGE_ONE, 'page-one');
        $two = $this->readPage($template, $staff, self::PAGE_TWO, 'page-two');

        // Row 1 complete on page 1; on page 2 only Place is ticked.
        $fields = [
            $this->cell($one, 1, 'Name', 1),
            $this->cell($one, 1, 'Date', 1),
            $this->cell($two, 1, 'Place', 2),
        ];

        $this->actingAs($staff)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                'doc_type' => $template->doc_type->value,
                'document_template_id' => $template->getKey(),
                'ocr_model_key' => 'test-model',
                'scan' => UploadedFile::fake()->create('spread.pdf', 50, 'application/pdf'),
                'document_page_id' => $one->getKey(),
                'document_page_id_two' => $two->getKey(),
                'fields' => $fields,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.missing_required', [
                'Nothing ticked in Person 02 – Person 05 (these rows are not saved)',
                'Page 2 · Person 01: Remarks',
                'Page 2 · Nothing ticked in Person 02 – Person 05 (these rows are not saved)',
            ]);

        $this->assertDatabaseCount('records', 0);
    }

    public function test_a_layout_of_one_page_reads_page_two_with_its_columns_as_the_same_people(): void
    {
        Storage::fake('local');
        OcrModel::create(['key' => 'test-model', 'label' => 'Test model']);
        $staff = User::factory()->staff()->create();
        $template = $this->spreadTemplate();
        $template->update(['page_two' => null]);
        $template->refresh();
        $this->assertFalse($template->hasSecondPage());
        $one = $this->readPage($template, $staff, self::PAGE_ONE, 'page-one');
        $two = $this->readPage($template, $staff, self::PAGE_ONE, 'page-two');

        // Page 2 has the same columns: the workspace names its fields apart.
        $fields = [
            $this->cell($one, 1, 'Name', 1),
            $this->cell($one, 1, 'Date', 1),
            ['name' => 'Name · row 1 · page 2', ...array_diff_key($this->cell($two, 1, 'Name', 2), ['name' => 1])],
        ];

        $response = $this->actingAs($staff)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                'doc_type' => $template->doc_type->value,
                'document_template_id' => $template->getKey(),
                'ocr_model_key' => 'test-model',
                'scan' => UploadedFile::fake()->create('spread.pdf', 50, 'application/pdf'),
                'document_page_id' => $one->getKey(),
                'document_page_id_two' => $two->getKey(),
                'allow_missing' => '1',
                'fields' => $fields,
            ])
            ->assertCreated();

        $record = CivilRecord::with('fields')->findOrFail($response->json('record.recordId'));
        $this->assertNotNull($record->page_image_path_two);
        $this->assertSame([1, 1, 1], $record->fields->pluck('person_group')->all());
        $this->assertSame([1, 1, 2], $record->fields->pluck('page')->all());
    }

    /** @return array<string, mixed> */
    private function payload(string $name, array $pageTwoColumns, array $ruled = self::ROWS): array
    {
        return [
            'name' => $name,
            'doc_type' => DocumentType::Birth->value,
            'page_ratio' => 8.5 / 11,
            'grouping_mode' => 'auto',
            'fields_json' => '[]',
            'columns_json' => json_encode(self::PAGE_ONE, JSON_THROW_ON_ERROR),
            'ruled_ys_json' => json_encode(self::ROWS, JSON_THROW_ON_ERROR),
            'page_two_json' => json_encode([
                'fields' => [],
                'columns' => $pageTwoColumns,
                'ruled_ys' => $ruled,
            ], JSON_THROW_ON_ERROR),
        ];
    }

    private function spreadTemplate(): DocumentTemplate
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('templates.store'), $this->payload('Register spread', self::PAGE_TWO))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return DocumentTemplate::where('name', 'Register spread')->firstOrFail();
    }

    /** A read page with a cell for every column in each of the five rows. */
    private function readPage(DocumentTemplate $template, User $staff, array $columns, string $folder): DocumentPage
    {
        $page = DocumentPage::create([
            'document_template_id' => $template->getKey(),
            'created_by' => $staff->getKey(),
            'status' => DocumentPage::STATUS_READY,
            'image_path' => "pages/{$folder}.png",
            'width' => 800,
            'height' => 600,
            'geometry' => ['columns' => $columns, 'ruled_ys' => self::ROWS, 'fields' => []],
            'ocr_model_key' => 'test-model',
        ]);
        Storage::disk('local')->put($page->image_path, 'png');

        foreach (range(1, 5) as $row) {
            foreach ($columns as $columnIndex => $column) {
                $position = $page->lines()->count();
                $line = $page->lines()->create([
                    'position' => $position,
                    'source' => 'kraken',
                    'polygon' => [[10, 10], [50, 10], [50, 30], [10, 30]],
                    'bbox' => [10, 10, 40, 20],
                    'crop_path' => sprintf('%s/crops/%03d.png', $page->directory(), $position + 1),
                    'flags' => [],
                    'column_index' => $columnIndex,
                    'column_name' => $column['name'],
                    'row' => $row,
                ]);
                Storage::disk('local')->put($line->crop_path, 'crop');
            }
        }

        return $page->fresh('lines');
    }

    /** @return array<string, mixed> */
    private function cell(DocumentPage $page, int $row, string $column, int $pageNumber): array
    {
        $line = $page->lines->first(fn (PageLine $line) => $line->row === $row && $line->column_name === $column);

        return [
            'verified' => '1',
            'name' => "{$column} · row {$row}",
            'ocr_text' => 'Recorded value',
            'ocr_confidence' => 92.5,
            'verified_value' => 'Recorded value',
            'person_group' => $row,
            'person_field_order' => ($line->column_index ?? 0) + ($pageNumber === 2 ? 100 : 0),
            'x' => 0.1,
            'y' => 0.1,
            'width' => 0.3,
            'height' => 0.05,
            'line_id' => $line->getKey(),
            'page' => $pageNumber,
        ];
    }
}
