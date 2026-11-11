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
 * A two-page spread saved as ONE record: page 1 stays on the record, page 2 is
 * a record page, and each field says which page it was read from.
 */
class TwoPageRecordTest extends TestCase
{
    use RefreshDatabase;

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
            'sample_document' => UploadedFile::fake()->image('register.png', 1200, 900),
            'publish' => 1,
        ])->assertSessionHasNoErrors();

        $this->template = DocumentTemplate::firstOrFail();
        $this->assertTrue($this->template->is_active);
        $this->staff = User::factory()->staff()->create();
    }

    /**
     * A page the worker has read, with one line, and the scan the workspace queued for it.
     *
     * @return array{0: DocumentPage, 1: PageLine, 2: string}
     */
    private function readPage(string $scanName): array
    {
        $page = DocumentPage::create([
            'document_template_id' => $this->template->getKey(),
            'created_by' => $this->staff->getKey(),
            'status' => DocumentPage::STATUS_READY,
            'image_path' => "documents/pages/{$scanName}.png",
            'width' => 800,
            'height' => 600,
            'geometry' => ['columns' => [], 'ruled_ys' => [], 'fields' => []],
            'ocr_model_key' => 'test-model',
        ]);
        Storage::disk('local')->put($page->image_path, 'png');
        $line = $page->lines()->create([
            'position' => 0,
            'column_name' => '',
            'column_index' => 0,
            'row' => 1,
            'source' => 'kraken',
            'polygon' => [[10, 10], [50, 10], [50, 30], [10, 30]],
            'bbox' => [10, 10, 40, 20],
            'crop_path' => $page->directory().'/crops/001.png',
            'flags' => [],
        ]);
        Storage::disk('local')->put($line->crop_path, 'crop');
        $scan = "documents/workspace/batch_x/{$scanName}.pdf";
        Storage::disk('local')->put($scan, 'pdf');

        return [$page, $line, $scan];
    }

    /** @return array<string, mixed> */
    private function field(string $name, string $value, PageLine $line): array
    {
        return [
            'verified' => '1', 'name' => $name, 'ocr_text' => $value, 'ocr_confidence' => 90,
            'verified_value' => $value, 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05,
            'line_id' => $line->getKey(),
        ];
    }

    public function test_a_two_page_spread_is_saved_as_one_record(): void
    {
        [$first, $firstLine, $firstScan] = $this->readPage('page-1');
        [$second, $secondLine, $secondScan] = $this->readPage('page-2');

        $response = $this->actingAs($this->staff)->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                'doc_type' => DocumentType::Death->value,
                'document_template_id' => $this->template->getKey(),
                'ocr_model_key' => 'test-model',
                'workspace_scan_path' => $firstScan,
                'allow_missing' => true,
                'document_page_id' => $first->getKey(),
                'source_name' => 'book.pdf',
                'source_page' => 1,
                'source_page_count' => 2,
                'fields' => [$this->field('Deceased', 'Juan Cruz', $firstLine)],
                'extra_pages' => [[
                    'document_page_id' => $second->getKey(),
                    'workspace_scan_path' => $secondScan,
                    'source_page' => 2,
                    'fields' => [$this->field('Deceased · page 2', 'Maria Santos', $secondLine)],
                ]],
            ])
            ->assertCreated();

        $this->assertSame(1, CivilRecord::count());
        $record = CivilRecord::with(['fields', 'extraPages'])->firstOrFail();

        // One record, both pages' fields, each tagged with its page.
        $this->assertSame(['Deceased' => 1, 'Deceased · page 2' => 2], $record->fields->pluck('page_no', 'name')->all());
        $this->assertCount(1, $record->extraPages);
        $this->assertSame(2, $record->extraPages->first()->page_no);
        $this->assertSame(2, $record->extraPages->first()->source_page);
        Storage::disk('local')->assertExists($record->scan_path);
        Storage::disk('local')->assertExists($record->extraPages->first()->scan_path);

        // The working pages and queued scans are cleaned up.
        $this->assertSame(0, DocumentPage::count());
        Storage::disk('local')->assertMissing($firstScan);
        Storage::disk('local')->assertMissing($secondScan);

        // Each page's scan is served for the record page.
        $this->actingAs($this->staff)->get(route('records.extra-page-scan', [$record, 2]))->assertOk();
        $this->actingAs($this->staff)->get(route('records.show', $record))
            ->assertOk()->assertSee('data-scan-page-tab="2"', escape: false);

        $response->assertJsonPath('redirect', route('records.show', $record));
    }

    public function test_a_single_page_record_has_no_extra_pages(): void
    {
        [$first, $firstLine, $firstScan] = $this->readPage('only');

        $this->actingAs($this->staff)->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                'doc_type' => DocumentType::Death->value,
                'document_template_id' => $this->template->getKey(),
                'ocr_model_key' => 'test-model',
                'workspace_scan_path' => $firstScan,
                'allow_missing' => true,
                'document_page_id' => $first->getKey(),
                'fields' => [$this->field('Deceased', 'Juan Cruz', $firstLine)],
            ])
            ->assertCreated();

        $record = CivilRecord::with(['fields', 'extraPages'])->firstOrFail();
        $this->assertCount(0, $record->extraPages);
        $this->assertSame([1], $record->fields->pluck('page_no')->unique()->values()->all());
    }

    public function test_an_extra_page_of_another_user_is_refused(): void
    {
        [$first, $firstLine, $firstScan] = $this->readPage('p1');
        [$second, $secondLine, $secondScan] = $this->readPage('p2');
        $second->update(['created_by' => User::factory()->staff()->create()->getKey()]);

        $this->actingAs($this->staff)->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                'doc_type' => DocumentType::Death->value,
                'document_template_id' => $this->template->getKey(),
                'ocr_model_key' => 'test-model',
                'workspace_scan_path' => $firstScan,
                'allow_missing' => true,
                'document_page_id' => $first->getKey(),
                'fields' => [$this->field('Deceased', 'Juan Cruz', $firstLine)],
                'extra_pages' => [[
                    'document_page_id' => $second->getKey(),
                    'workspace_scan_path' => $secondScan,
                    'fields' => [$this->field('Deceased · page 2', 'Maria', $secondLine)],
                ]],
            ])
            ->assertStatus(422);

        $this->assertSame(0, CivilRecord::count());
    }

    /** A second published-or-draft layout of the type, to be page 2. */
    private function pageTwoLayout(string $fieldName): DocumentTemplate
    {
        $this->actingAs(User::factory()->superAdmin()->create())->post(route('templates.store'), [
            'name' => 'Death register - right page',
            'doc_type' => DocumentType::Death->value,
            'page_ratio' => 11 / 8.5,
            'grouping_mode' => 'custom',
            'fields_json' => json_encode([
                ['name' => $fieldName, 'x' => 0.2, 'y' => 0.2, 'width' => 0.3, 'height' => 0.05, 'role' => 'name'],
            ], JSON_THROW_ON_ERROR),
            'columns_json' => '[]',
            'ruled_ys_json' => '[]',
        ])->assertSessionHasNoErrors();

        return DocumentTemplate::where('name', 'Death register - right page')->firstOrFail();
    }

    public function test_a_template_can_name_another_layout_as_its_page_two(): void
    {
        $right = $this->pageTwoLayout('Informant');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->put(route('templates.update', $this->template), [
            'name' => $this->template->name,
            'doc_type' => DocumentType::Death->value,
            'page_ratio' => 11 / 8.5,
            'grouping_mode' => 'custom',
            'page_two_template_id' => $right->getKey(),
            'fields_json' => json_encode([
                ['name' => 'Deceased', 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05, 'role' => 'name'],
            ], JSON_THROW_ON_ERROR),
            'columns_json' => '[]',
            'ruled_ys_json' => '[]',
        ])->assertSessionHasNoErrors();

        // The published layout is in use, so the link is saved as a new version,
        // with its own copy of the chosen layout as page 2.
        $withPageTwo = DocumentTemplate::whereNotNull('page_two_template_id')->firstOrFail();
        $pageTwo = $withPageTwo->pageTwoTemplate;
        $this->assertNotSame($right->getKey(), $pageTwo->getKey());
        $this->assertTrue($pageTwo->is_page_two);
        $this->assertSame(['Informant'], $pageTwo->fields()->pluck('name')->all());
        $this->assertSame($pageTwo->getKey(), $withPageTwo->layoutForPage(2)->getKey());
        $this->assertSame($withPageTwo->getKey(), $withPageTwo->layoutForPage(1)->getKey());
        $this->assertSame($withPageTwo->getKey(), $withPageTwo->layoutForPage(3)->getKey());
        // The chosen layout stays a layout of its own.
        $this->assertFalse($right->refresh()->is_page_two);

        // A layout is not its own page 2.
        $this->actingAs($admin)->put(route('templates.update', $withPageTwo), [
            'name' => $withPageTwo->name,
            'doc_type' => DocumentType::Death->value,
            'page_ratio' => 11 / 8.5,
            'grouping_mode' => 'custom',
            'page_two_template_id' => $withPageTwo->getKey(),
            'fields_json' => '[]',
            'columns_json' => '[]',
            'ruled_ys_json' => '[]',
        ])->assertSessionHasErrors('page_two_template_id');
    }

    public function test_page_two_is_read_with_the_page_two_layout(): void
    {
        $right = $this->pageTwoLayout('Informant');
        $this->template->update(['page_two_template_id' => $right->getKey()]);

        [$first, $firstLine, $firstScan] = $this->readPage('lp1');
        [$second, $secondLine, $secondScan] = $this->readPage('lp2');
        // Page 2 was aligned and scanned under the right-page layout.
        $second->update(['document_template_id' => $right->getKey()]);

        $this->actingAs($this->staff)->get(route('documents.workspace', ['type' => DocumentType::Death->value]))
            ->assertOk()->assertSee('"templateId":'.$right->getKey(), escape: false);

        $this->actingAs($this->staff)->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                'doc_type' => DocumentType::Death->value,
                'document_template_id' => $this->template->getKey(),
                'ocr_model_key' => 'test-model',
                'workspace_scan_path' => $firstScan,
                'allow_missing' => true,
                'document_page_id' => $first->getKey(),
                'fields' => [$this->field('Deceased', 'Juan Cruz', $firstLine)],
                'extra_pages' => [[
                    'document_page_id' => $second->getKey(),
                    'workspace_scan_path' => $secondScan,
                    'fields' => [$this->field('Informant', 'Ana Cruz', $secondLine)],
                ]],
            ])
            ->assertCreated();

        $this->assertSame(['Deceased' => 1, 'Informant' => 2], CivilRecord::firstOrFail()->fields->pluck('page_no', 'name')->all());

        // A page 2 read with the wrong layout is refused.
        [$wrong, $wrongLine, $wrongScan] = $this->readPage('lp3');
        [$other, $otherLine, $otherScan] = $this->readPage('lp4');
        $this->actingAs($this->staff)->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                'doc_type' => DocumentType::Death->value,
                'document_template_id' => $this->template->getKey(),
                'ocr_model_key' => 'test-model',
                'workspace_scan_path' => $wrongScan,
                'allow_missing' => true,
                'document_page_id' => $wrong->getKey(),
                'fields' => [$this->field('Deceased', 'Pedro', $wrongLine)],
                'extra_pages' => [[
                    'document_page_id' => $other->getKey(),
                    'workspace_scan_path' => $otherScan,
                    'fields' => [$this->field('Informant', 'Ana', $otherLine)],
                ]],
            ])
            ->assertStatus(422);
    }

    /** @return array<string, mixed> */
    private function builderPayload(string $name, array $pageTwo = null): array
    {
        return [
            'name' => $name,
            'doc_type' => DocumentType::Death->value,
            'page_ratio' => 11 / 8.5,
            'grouping_mode' => 'custom',
            'fields_json' => json_encode([
                ['name' => 'Deceased', 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05, 'role' => 'name'],
            ], JSON_THROW_ON_ERROR),
            'columns_json' => '[]',
            'ruled_ys_json' => '[]',
            ...($pageTwo !== null ? ['page_two_json' => json_encode($pageTwo, JSON_THROW_ON_ERROR)] : []),
        ];
    }

    public function test_the_builder_saves_both_pages_of_a_two_page_layout(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $pageTwo = [
            'grouping_mode' => 'custom',
            'fields' => [['name' => 'Informant', 'x' => 0.2, 'y' => 0.2, 'width' => 0.3, 'height' => 0.05, 'role' => 'name']],
            'columns' => [],
            'ruled_ys' => [],
        ];

        $this->actingAs($admin)->post(route('templates.store'), $this->builderPayload('Spread layout', $pageTwo))
            ->assertSessionHasNoErrors();

        $main = DocumentTemplate::where('name', 'Spread layout')->firstOrFail();
        $right = $main->pageTwoTemplate;
        $this->assertNotNull($right);
        $this->assertSame(['Informant'], $right->fields()->pluck('name')->all());
        $this->assertFalse($right->is_active);
        $this->assertSame(['Deceased'], $main->fields()->pluck('name')->all());

        // The builder opens with both pages.
        $this->actingAs($admin)->get(route('templates.edit', $main))
            ->assertOk()->assertSee('Informant')->assertSee('id="twoPageToggle"', escape: false);

        // Saving again with a changed page 2 changes the same page 2 layout (nothing read with it yet).
        $pageTwo['fields'][0]['name'] = 'Informant name';
        $this->actingAs($admin)->put(route('templates.update', $main), $this->builderPayload('Spread layout', $pageTwo))
            ->assertSessionHasNoErrors();
        $this->assertSame($right->getKey(), $main->fresh()->page_two_template_id);
        $this->assertSame(['Informant name'], $right->fresh()->fields()->pluck('name')->all());

        // Turning the second page off (an empty page 2) unlinks it.
        $this->actingAs($admin)->put(route('templates.update', $main), $this->builderPayload('Spread layout', [
            'grouping_mode' => 'auto', 'fields' => [], 'columns' => [], 'ruled_ys' => [],
        ]))->assertSessionHasNoErrors();
        $this->assertNull($main->fresh()->page_two_template_id);
    }

    public function test_a_bad_page_two_is_reported_and_nothing_is_saved(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('templates.store'), $this->builderPayload('Broken spread', [
                'grouping_mode' => 'auto',
                'fields' => [['name' => 'Informant', 'x' => 0.9, 'y' => 0.2, 'width' => 0.3, 'height' => 0.05]],
                'columns' => [],
                'ruled_ys' => [],
            ]))
            ->assertSessionHasErrors('page_two_json');

        $this->assertNull(DocumentTemplate::where('name', 'Broken spread')->first());
    }
}
