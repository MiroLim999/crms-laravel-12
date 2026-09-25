<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Jobs\ProcessDocumentPage;
use App\Models\CivilRecord;
use App\Models\DocumentPage;
use App\Models\DocumentTemplate;
use App\Models\OcrModel;
use App\Models\PageLine;
use App\Models\User;
use App\Services\Lines\LineMarkers;
use App\Services\Lines\LineMarkersException;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Align -> line detection job -> Verify -> manual fix -> submit, with the
 * Python detector replaced by a stub that writes crops like line_markers.py
 * and the TrOCR service faked over HTTP.
 */
class LineOutlinePipelineTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $ocrCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);
        OcrModel::create(['key' => 'test-model', 'label' => 'Test model']);

        $this->ocrCalls = [];
        Http::fake([
            '*/ocr' => function (HttpRequest $request) {
                $this->ocrCalls[] = $request->data();

                return Http::response([
                    'results' => array_map(fn (array $field) => [
                        'name' => $field['name'],
                        'text' => 'read '.$field['name'],
                        'confidence' => 88.5,
                    ], $request->data()['fields']),
                    'model' => 'Test model',
                    'modelKey' => 'test-model',
                ]);
            },
        ]);
    }

    public function test_finishing_align_stores_the_page_and_queues_line_detection(): void
    {
        Queue::fake();
        $user = User::factory()->staff()->create();
        $template = $this->ledgerTemplate();

        $response = $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.pages.store'), [
                'document_template_id' => $template->getKey(),
                'page' => UploadedFile::fake()->image('page.png', 800, 600),
                'geometry_json' => json_encode($this->geometry(), JSON_THROW_ON_ERROR),
            ]);

        $response->assertAccepted()->assertJsonPath('status', DocumentPage::STATUS_QUEUED);
        $page = DocumentPage::firstOrFail();
        $this->assertSame([800, 600], [$page->width, $page->height]);
        $this->assertSame(['Name', 'Date'], array_column($page->geometry['columns'], 'name'));
        Storage::disk('local')->assertExists($page->image_path);
        Queue::assertPushed(ProcessDocumentPage::class, fn ($job) => $job->pageId === $page->getKey());
    }

    public function test_tilted_markers_reach_the_line_detector_with_their_angles(): void
    {
        Queue::fake();
        $geometry = $this->geometry();
        // Staff tilted each column and one field, each on its own.
        $geometry['columns'][0]['angle'] = 2.04;
        $geometry['columns'][1]['angle'] = -1.26;
        $geometry['fields'] = [
            ['name' => 'Remarks', 'box' => [0.1, 0.8, 0.3, 0.05], 'angle' => -6],
            ['name' => 'Page', 'box' => [0.6, 0.8, 0.1, 0.05], 'angle' => 0],
        ];

        $this->actingAs(User::factory()->staff()->create())
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.pages.store'), [
                'document_template_id' => $this->ledgerTemplate()->getKey(),
                'page' => UploadedFile::fake()->image('page.png', 800, 600),
                'geometry_json' => json_encode($geometry, JSON_THROW_ON_ERROR),
            ])
            ->assertAccepted();

        $stored = DocumentPage::firstOrFail()->geometry;
        $this->assertEquals([2.0, -1.3], array_column($stored['columns'], 'angle'));
        $this->assertEquals(-6.0, $stored['fields'][0]['angle']);
        $this->assertArrayNotHasKey('angle', $stored['fields'][1]);
    }

    public function test_a_scan_the_detector_straightened_keeps_the_straightened_page(): void
    {
        $inner = $this->stubMarkers();
        $this->app->instance(LineMarkers::class, new class($inner) extends LineMarkers
        {
            public function __construct(private readonly LineMarkers $inner) {}

            public function process(string $pagePath, array $geometry, string $outDirectory): array
            {
                // A tilted grid: line_markers.py straightened the page first.
                return [...$this->inner->process($pagePath, $geometry, $outDirectory),
                    'size' => [830, 640], 'deskew' => 2.0, 'geometry' => [...$geometry, 'ruled_ys' => [0.11, 0.21, 0.31, 0.41]]];
            }
        });
        $page = $this->storedPage();

        ProcessDocumentPage::dispatchSync($page->getKey());

        $page->refresh();
        $this->assertSame(DocumentPage::STATUS_READY, $page->status);
        $this->assertSame([830, 640], [$page->width, $page->height]);
        $this->assertSame(2.0, $page->deskew_degrees);
        $this->assertSame([0.11, 0.21, 0.31, 0.41], $page->geometry['ruled_ys']);
    }

    public function test_detect_is_queued_as_detection_only(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->staff()->create())
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.pages.store'), [
                'document_template_id' => $this->ledgerTemplate()->getKey(),
                'page' => UploadedFile::fake()->image('page.png', 800, 600),
                'geometry_json' => json_encode($this->geometry(), JSON_THROW_ON_ERROR),
                'detect' => '1',
            ])
            ->assertAccepted();

        Queue::assertPushed(ProcessDocumentPage::class, fn ($job) => $job->mode === ProcessDocumentPage::MODE_DETECT);
    }

    public function test_detect_straightens_fits_and_outlines_without_reading(): void
    {
        $page = $this->detectedPage();

        $this->assertSame(DocumentPage::STATUS_DETECTED, $page->status);
        $this->assertSame(1.5, $page->deskew_degrees);
        $this->assertSame([820, 610], [$page->width, $page->height]);
        $this->assertSame([0.12, 0.22, 0.32, 0.42], $page->geometry['ruled_ys']);
        $this->assertSame([], $this->ocrCalls, 'Detect must not read anything.');

        $this->actingAs($page->creator)
            ->getJson(route('documents.pages.show', $page))
            ->assertOk()
            ->assertJsonPath('status', 'detected')
            ->assertJsonPath('deskew', 1.5)
            ->assertJsonPath('geometry.columns.0.box', [0.08, 0.12, 0.44, 0.3])
            ->assertJsonCount(3, 'lines')
            ->assertJsonPath('lines.0.text', '');
    }

    public function test_scan_after_detect_reads_the_detected_crops_without_detecting_again(): void
    {
        $page = $this->detectedPage();
        $crops = $page->lines->pluck('crop_path', 'id');
        $this->app->instance(LineMarkers::class, new class extends LineMarkers
        {
            public function process(string $pagePath, array $geometry, string $outDirectory): array
            {
                throw new \LogicException('Reading after Detect must not outline the page again.');
            }

            public function detect(string $pagePath, array $geometry, string $outDirectory): array
            {
                throw new \LogicException('Reading after Detect must not outline the page again.');
            }
        });

        $this->actingAs($page->creator)
            ->postJson(route('documents.pages.read', $page))
            ->assertAccepted();

        $page->refresh();
        $this->assertSame(DocumentPage::STATUS_READY, $page->status);
        $this->assertSame($crops->all(), $page->lines()->pluck('crop_path', 'id')->all());
        $this->assertCount(3, collect($this->ocrCalls)->flatMap(fn ($call) => $call['fields']));
        $this->assertSame('read line-'.$page->lines->first()->getKey(), $page->lines()->first()->ocr_text);
    }

    public function test_only_a_detected_page_can_be_read_that_way(): void
    {
        $page = $this->processedPage();

        $this->actingAs($page->creator)
            ->postJson(route('documents.pages.read', $page))
            ->assertStatus(409);
    }

    public function test_the_straightened_page_image_is_only_for_its_uploader(): void
    {
        $page = $this->detectedPage();

        $this->actingAs($page->creator)
            ->get(route('documents.pages.image', $page))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->actingAs(User::factory()->staff()->create())
            ->get(route('documents.pages.image', $page))
            ->assertNotFound();
    }

    public function test_the_record_keeps_the_rotation_its_outlines_were_made_in(): void
    {
        $page = $this->detectedPage();
        $this->actingAs($page->creator)->postJson(route('documents.pages.read', $page))->assertAccepted();
        $line = $page->lines()->firstWhere('column_name', 'Name');

        $this->actingAs($page->creator)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                'doc_type' => DocumentType::Birth->value,
                'document_template_id' => $page->document_template_id,
                'document_page_id' => $page->getKey(),
                'ocr_model_key' => 'test-model',
                'scan' => UploadedFile::fake()->image('certificate.png', 800, 600),
                'fields' => [[
                    'verified' => '1', 'name' => 'Name · row 1', 'verified_value' => 'Juan',
                    'x' => 0.05, 'y' => 0.1, 'width' => 0.2, 'height' => 0.05,
                    'line_id' => $line->getKey(),
                ]],
            ])
            ->assertCreated();

        $this->assertSame(1.5, (float) CivilRecord::firstOrFail()->scan_rotation);
    }

    public function test_geometry_with_columns_but_no_ruled_lines_is_rejected(): void
    {
        Queue::fake();
        $geometry = $this->geometry();
        $geometry['ruled_ys'] = [];

        $this->actingAs(User::factory()->staff()->create())
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.pages.store'), [
                'document_template_id' => $this->ledgerTemplate()->getKey(),
                'page' => UploadedFile::fake()->image('page.png', 800, 600),
                'geometry_json' => json_encode($geometry, JSON_THROW_ON_ERROR),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('geometry.ruled_ys');

        $this->assertDatabaseCount('document_pages', 0);
        Queue::assertNothingPushed();
    }

    public function test_the_job_saves_every_outline_and_reads_each_masked_crop(): void
    {
        $page = $this->processedPage();

        $this->assertSame(DocumentPage::STATUS_READY, $page->status);
        $this->assertSame('test-model', $page->ocr_model_key);
        $lines = $page->lines;
        $this->assertCount(3, $lines);

        $flagged = $lines->firstWhere('column_name', 'Date');
        $this->assertSame([PageLine::FLAG_NO_ROW], $flagged->flags);
        $this->assertNull($flagged->row);
        $this->assertSame('read line-'.$flagged->getKey(), $flagged->ocr_text);

        // TrOCR received exactly the stored crop files, and nothing else.
        $sent = collect($this->ocrCalls)->flatMap(fn ($call) => $call['fields']);
        $this->assertCount(3, $sent);
        foreach ($lines as $line) {
            $image = $sent->firstWhere('name', 'line-'.$line->getKey())['image'];
            $this->assertSame(
                'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($line->crop_path)),
                $image,
            );
        }
    }

    public function test_verify_loads_stored_lines_without_recomputing_them(): void
    {
        $page = $this->processedPage();
        $this->ocrCalls = [];
        $this->app->instance(LineMarkers::class, new class extends LineMarkers
        {
            public function process(string $pagePath, array $geometry, string $outDirectory): array
            {
                throw new \LogicException('A page load must not run line detection.');
            }
        });

        $response = $this->actingAs($page->creator)
            ->getJson(route('documents.pages.show', $page))
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonCount(3, 'lines')
            ->assertJsonPath('lines.0.column', 'Name')
            ->assertJsonPath('lines.0.row', 1)
            ->assertJsonPath('lines.2.flags', ['no_row']);

        $this->assertSame([], $this->ocrCalls);

        $this->actingAs($page->creator)
            ->get($response->json('lines.0.cropUrl'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_another_user_cannot_see_an_unsubmitted_page_or_its_crops(): void
    {
        $page = $this->processedPage();
        $line = $page->lines->first();
        $stranger = User::factory()->staff()->create();

        $this->actingAs($stranger)->getJson(route('documents.pages.show', $page))->assertNotFound();
        $this->actingAs($stranger)
            ->get(route('documents.pages.lines.crop', ['page' => $page, 'line' => $line]))
            ->assertNotFound();
        $this->actingAs($stranger)
            ->putJson(route('documents.pages.lines.update', ['page' => $page, 'line' => $line]), [
                'polygon' => [[1, 1], [9, 1], [9, 9]],
            ])
            ->assertNotFound();
    }

    public function test_a_manual_fix_recrops_and_rereads_that_line_only(): void
    {
        $page = $this->processedPage();
        $flagged = $page->lines->firstWhere('column_name', 'Date');
        $this->ocrCalls = [];

        $response = $this->actingAs($page->creator)
            ->putJson(route('documents.pages.lines.update', ['page' => $page, 'line' => $flagged]), [
                'polygon' => [[410, 120], [560, 120], [560, 150], [410, 150]],
            ]);

        $response->assertOk()
            ->assertJsonPath('line.row', 2)
            ->assertJsonPath('line.flags', [])
            ->assertJsonPath('line.adjusted', true)
            ->assertJsonPath('line.text', 'read line-'.$flagged->getKey());

        $flagged->refresh();
        $this->assertEquals([[410, 120], [560, 120], [560, 150], [410, 150]], $flagged->polygon);
        $this->assertSame(2, $flagged->crop_version);
        Storage::disk('local')->assertExists($flagged->crop_path);

        // One OCR request, for that one line.
        $this->assertCount(1, $this->ocrCalls);
        $this->assertSame(['line-'.$flagged->getKey()], array_column($this->ocrCalls[0]['fields'], 'name'));
    }

    public function test_after_detect_an_outline_is_recropped_but_not_read_until_scan(): void
    {
        $page = $this->detectedPage();
        $line = $page->lines->firstWhere('column_name', 'Date');
        $this->ocrCalls = [];

        $this->actingAs($page->creator)
            ->putJson(route('documents.pages.lines.update', ['page' => $page, 'line' => $line]), [
                'polygon' => [[405, 118], [565, 118], [565, 152], [405, 152]],
            ])
            ->assertOk()
            ->assertJsonPath('line.adjusted', true)
            ->assertJsonPath('line.text', '');

        $this->assertSame([], $this->ocrCalls, 'Nothing is read before Scan with OCR.');
        $this->assertSame(DocumentPage::STATUS_DETECTED, $page->fresh()->status);
        $line->refresh();
        $this->assertSame(2, $line->crop_version);
        Storage::disk('local')->assertExists($line->crop_path);

        // Reset: the detector's outline again, no longer counted as adjusted.
        $this->actingAs($page->creator)
            ->putJson(route('documents.pages.lines.update', ['page' => $page, 'line' => $line]), [
                'polygon' => [[400, 105], [560, 105], [560, 130], [400, 130]],
                'reset' => true,
            ])
            ->assertOk()
            ->assertJsonPath('line.adjusted', false);
        $this->assertSame([], $this->ocrCalls);

        // Scan with OCR then reads the crops as they now are.
        $this->actingAs($page->creator)->postJson(route('documents.pages.read', $page))->assertAccepted();
        $this->assertSame(DocumentPage::STATUS_READY, $page->fresh()->status);
        $this->assertContains('line-'.$line->getKey(), array_merge(...array_map(fn ($call) => array_column($call['fields'], 'name'), $this->ocrCalls)));
    }

    public function test_moving_a_line_into_an_occupied_cell_flags_both_as_shared(): void
    {
        $page = $this->processedPage();
        $flagged = $page->lines->firstWhere('column_name', 'Date');
        $nameRowOne = $page->lines->firstWhere('column_name', 'Name');

        $this->app->instance(LineMarkers::class, $this->stubMarkers(cropPlacement: ['column_index' => 0, 'row' => 1]));

        $this->actingAs($page->creator)
            ->putJson(route('documents.pages.lines.update', ['page' => $page, 'line' => $flagged]), [
                'polygon' => [[60, 70], [200, 70], [200, 95], [60, 95]],
            ])
            ->assertOk()
            ->assertJsonPath('line.column', 'Name')
            ->assertJsonPath('flags.'.$nameRowOne->getKey(), ['shared_cell']);

        $this->assertSame(['shared_cell'], $flagged->fresh()->flags);
    }

    public function test_a_redrawn_line_of_a_detected_field_keeps_its_field_and_line_number(): void
    {
        $page = $this->processedPage();
        // Detect split a drawn "Diseases" box into written lines; this is its line 2.
        $fieldLine = $page->lines->firstWhere('source', 'ink');
        $fieldLine->forceFill(['source' => PageLine::SOURCE_FIELD, 'column_index' => null, 'column_name' => 'Diseases', 'row' => 2])->save();
        $nameRowOne = $page->lines->firstWhere('column_name', 'Name');

        // Redrawn over where the ledger's Name row 1 would be.
        $this->app->instance(LineMarkers::class, $this->stubMarkers(cropPlacement: ['column_index' => 0, 'row' => 1]));

        $this->actingAs($page->creator)
            ->putJson(route('documents.pages.lines.update', ['page' => $page, 'line' => $fieldLine]), [
                'polygon' => [[60, 70], [200, 70], [200, 95], [60, 95]],
            ])
            ->assertOk()
            ->assertJsonPath('line.column', 'Diseases')
            ->assertJsonPath('line.row', 2)
            ->assertJsonPath('line.flags', [])
            ->assertJsonPath('flags.'.$nameRowOne->getKey(), []);

        $this->assertNull($fieldLine->fresh()->column_index);
    }

    public function test_submitting_keeps_the_exact_crop_and_outline_then_removes_the_page(): void
    {
        $page = $this->processedPage();
        $line = $page->lines->firstWhere('column_name', 'Name');
        $cropBytes = Storage::disk('local')->get($line->crop_path);

        $this->actingAs($page->creator)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                'doc_type' => DocumentType::Birth->value,
                'document_template_id' => $page->document_template_id,
                'document_page_id' => $page->getKey(),
                'ocr_model_key' => 'test-model',
                'scan' => UploadedFile::fake()->image('certificate.png', 800, 600),
                'fields' => [[
                    'verified' => '1',
                    'name' => 'Name · row 1',
                    'ocr_text' => $line->ocr_text,
                    'ocr_confidence' => 88.5,
                    'verified_value' => 'Juan Dela Cruz',
                    'person_group' => 1,
                    'person_field_order' => 0,
                    'x' => 0.05, 'y' => 0.1, 'width' => 0.2, 'height' => 0.05,
                    'line_id' => $line->getKey(),
                ]],
            ])
            ->assertCreated();

        $field = CivilRecord::firstOrFail()->fields->first();
        $this->assertSame('Name', $field->line_column);
        $this->assertSame(1, $field->line_row);
        $this->assertSame([], $field->line_flags);
        $this->assertEqualsWithDelta(0.05, $field->polygon[0][0], 0.00001);
        $this->assertSame($cropBytes, Storage::disk('local')->get($field->crop_path));

        $this->assertDatabaseCount('document_pages', 0);
        $this->assertDatabaseCount('page_lines', 0);
        Storage::disk('local')->assertMissing($page->image_path);
    }

    public function test_a_line_from_someone_elses_page_cannot_be_submitted(): void
    {
        $page = $this->processedPage();
        $line = $page->lines->first();

        $this->actingAs(User::factory()->staff()->create())
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                'doc_type' => DocumentType::Birth->value,
                'document_template_id' => $page->document_template_id,
                'document_page_id' => $page->getKey(),
                'ocr_model_key' => 'test-model',
                'scan' => UploadedFile::fake()->image('certificate.png', 800, 600),
                'fields' => [[
                    'verified' => '1', 'name' => 'Name', 'verified_value' => 'X',
                    'x' => 0.1, 'y' => 0.1, 'width' => 0.1, 'height' => 0.1,
                    'line_id' => $line->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_page_id');

        $this->assertDatabaseCount('records', 0);
    }

    public function test_a_failed_detection_is_reported_on_the_page(): void
    {
        $this->app->instance(LineMarkers::class, new class extends LineMarkers
        {
            public function process(string $pagePath, array $geometry, string $outDirectory): array
            {
                throw new LineMarkersException('Line detection failed: Kraken is not installed.');
            }
        });

        $page = $this->storedPage();

        try {
            ProcessDocumentPage::dispatchSync($page->getKey());
        } catch (LineMarkersException) {
            // The sync queue rethrows after calling failed().
        }

        $page->refresh();
        $this->assertSame(DocumentPage::STATUS_FAILED, $page->status);
        $this->actingAs($page->creator)
            ->getJson(route('documents.pages.show', $page))
            ->assertJsonPath('error', 'Line detection failed: Kraken is not installed.');
    }

    public function test_workspace_offers_ledger_columns_as_markers_and_legacy_rectangles_unchanged(): void
    {
        $staff = User::factory()->staff()->create();
        $legacy = DocumentTemplate::activeFor(DocumentType::Birth);
        $legacyBoxes = $legacy->fields->map->toBox()->values()->all();

        $this->assertSame($legacyBoxes, $legacy->markerBoxes());
        $this->actingAs($staff)
            ->get(route('documents.workspace', ['type' => DocumentType::Birth->value]))
            ->assertOk()
            ->assertSee('ruledYs: []', escape: false)
            ->assertSee('<span>Submit <span id="submitVerifiedCount">0</span> verified</span>', escape: false)
            ->assertSee('id="lineEditToolbar"', escape: false);

        $this->ledgerTemplate();
        $this->actingAs($staff)
            ->get(route('documents.workspace', ['type' => DocumentType::Birth->value]))
            ->assertOk()
            ->assertSee('"kind":"column"', escape: false)
            ->assertSee('ruledYs: [0.1,0.2,0.3,0.4]', escape: false);
    }

    public function test_the_line_markers_service_passes_the_page_and_reads_the_summary(): void
    {
        Process::fake([
            '*' => Process::result(output: "some library noise\n".json_encode(['ok' => true, 'lines' => 0])),
        ]);
        config(['services.line_markers.python' => 'C:/python/python.exe']);
        $out = Storage::disk('local')->path('pages/99');
        File::ensureDirectoryExists($out);
        File::put($out.'/lines.json', json_encode(['lines' => [], 'size' => [10, 10]]));

        $result = app(LineMarkers::class)->process('page.png', $this->geometry(), $out);

        $this->assertSame([], $result['lines']);
        Process::assertRan(fn ($process) => $process->command[0] === 'C:/python/python.exe'
            && in_array('process', $process->command, true)
            && in_array('--geometry', $process->command, true));
    }

    public function test_the_line_markers_service_surfaces_python_errors(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode(['ok' => false, 'error' => 'ValueError: bad polygon']), exitCode: 1),
        ]);

        $this->expectException(LineMarkersException::class);
        $this->expectExceptionMessage('bad polygon');

        app(LineMarkers::class)->crop('page.png', [[0, 0], [1, 0], [1, 1]], Storage::disk('local')->path('x.png'));
    }

    /**
     * The real PHP-to-Python contract, on the rectangle path that needs no
     * Kraken: an older template's fields come back as four-point polygons with
     * crops on disk.
     */
    public function test_line_markers_python_crops_template_rectangles(): void
    {
        $python = app(LineMarkers::class)->python();
        if ($python === 'python' || ! File::exists($python)) {
            $this->markTestSkipped('No project Python environment (ml/.venv-kraken or .venv).');
        }

        $out = Storage::disk('local')->path('pages/real');
        File::ensureDirectoryExists($out);
        $pagePath = $out.'/page.png';
        File::put($pagePath, UploadedFile::fake()->image('page.png', 400, 300)->getContent());

        $result = app(LineMarkers::class)->process($pagePath, [
            'columns' => [],
            'ruled_ys' => [],
            'fields' => [['name' => 'Registry number', 'box' => [0.1, 0.1, 0.5, 0.2]]],
        ], $out);

        $this->assertEquals([[40, 30], [240, 30], [240, 90], [40, 90]], $result['lines'][0]['polygon']);
        $this->assertSame('template', $result['lines'][0]['source']);
        $this->assertFileExists($out.'/'.$result['lines'][0]['crop']);
        $this->assertFileExists($out.'/overlay.png');
    }

    // ------------------------------------------------------------------ helpers

    private function ledgerTemplate(): DocumentTemplate
    {
        $template = DocumentTemplate::activeFor(DocumentType::Birth);
        $template->update([
            'columns' => [
                ['name' => 'Name', 'box' => [0.05, 0.1, 0.45, 0.3]],
                ['name' => 'Date', 'box' => [0.5, 0.1, 0.3, 0.3]],
            ],
            'ruled_ys' => [0.1, 0.2, 0.3, 0.4],
        ]);

        return $template->fresh(['fields', 'documentTypeDefinition']);
    }

    /** @return array<string, mixed> */
    private function geometry(): array
    {
        return [
            'columns' => [
                ['name' => 'Name', 'box' => [0.05, 0.1, 0.45, 0.3]],
                ['name' => 'Date', 'box' => [0.5, 0.1, 0.3, 0.3]],
            ],
            'ruled_ys' => [0.1, 0.2, 0.3, 0.4],
            'fields' => [],
        ];
    }

    private function storedPage(): DocumentPage
    {
        $template = $this->ledgerTemplate();
        $page = DocumentPage::create([
            'document_template_id' => $template->getKey(),
            'created_by' => User::factory()->staff()->create()->getKey(),
            'status' => DocumentPage::STATUS_QUEUED,
            'image_path' => '',
            'width' => 800,
            'height' => 600,
            'geometry' => $this->geometry(),
            'ocr_model_key' => 'test-model',
        ]);
        $path = $page->directory().'/page.png';
        Storage::disk('local')->put($path, UploadedFile::fake()->image('page.png', 800, 600)->getContent());
        $page->forceFill(['image_path' => $path])->save();

        return $page;
    }

    private function detectedPage(): DocumentPage
    {
        $this->app->instance(LineMarkers::class, $this->stubMarkers());
        $page = $this->storedPage();
        ProcessDocumentPage::dispatchSync($page->getKey(), ProcessDocumentPage::MODE_DETECT);

        return $page->fresh(['lines', 'creator']);
    }

    private function processedPage(): DocumentPage
    {
        $this->app->instance(LineMarkers::class, $this->stubMarkers());
        $page = $this->storedPage();
        ProcessDocumentPage::dispatchSync($page->getKey());

        return $page->fresh(['lines', 'creator']);
    }

    /**
     * Stands in for line_markers.py: writes crop files where the script would
     * and returns lines.json-shaped data.
     *
     * @param  array{column_index: int|null, row: int|null}  $cropPlacement
     */
    private function stubMarkers(array $cropPlacement = ['column_index' => 1, 'row' => 2]): LineMarkers
    {
        return new class($cropPlacement) extends LineMarkers
        {
            public function __construct(private readonly array $placement) {}

            public function process(string $pagePath, array $geometry, string $outDirectory): array
            {
                $lines = [
                    ['source' => 'kraken', 'column_index' => 0, 'column' => 'Name', 'row' => 1, 'flags' => [],
                        'polygon' => [[40, 60], [300, 60], [300, 110], [40, 110]], 'bbox' => [40, 60, 260, 50]],
                    ['source' => 'ink', 'column_index' => 0, 'column' => 'Name', 'row' => 2, 'flags' => [],
                        'polygon' => [[40, 120], [200, 120], [200, 170], [40, 170]], 'bbox' => [40, 120, 160, 50]],
                    ['source' => 'kraken', 'column_index' => 1, 'column' => 'Date', 'row' => null, 'flags' => ['no_row'],
                        'polygon' => [[400, 105], [560, 105], [560, 130], [400, 130]], 'bbox' => [400, 105, 160, 25]],
                ];
                File::ensureDirectoryExists($outDirectory.'/crops');
                foreach ($lines as $index => &$line) {
                    $line['crop'] = sprintf('crops/%03d.png', $index + 1);
                    $line['baseline'] = null;
                    File::put($outDirectory.'/'.$line['crop'], 'crop-'.$index);
                }
                File::put($outDirectory.'/lines.json', json_encode(['lines' => $lines]));

                return ['lines' => $lines, 'size' => [800, 600]];
            }

            public function detect(string $pagePath, array $geometry, string $outDirectory): array
            {
                // Straightened and fitted: the page grew a little when rotated,
                // and the columns moved to where this page has its table.
                $result = $this->process($pagePath, $geometry, $outDirectory);
                $fitted = $geometry;
                $fitted['columns'][0]['box'] = [0.08, 0.12, 0.44, 0.3];
                $fitted['ruled_ys'] = [0.12, 0.22, 0.32, 0.42];

                return array_merge($result, ['size' => [820, 610], 'deskew' => 1.5, 'geometry' => $fitted,
                    'fit' => ['fitted' => true]]);
            }

            public function crop(string $pagePath, array $polygon, string $outPath, ?string $linesPath = null): array
            {
                File::ensureDirectoryExists(dirname($outPath));
                File::put($outPath, 'recrop');

                return ['bbox' => [410, 120, 150, 30], 'column_index' => $this->placement['column_index'],
                    'row' => $this->placement['row'], 'flags' => $this->placement['row'] === null ? ['no_row'] : []];
            }
        };
    }
}
