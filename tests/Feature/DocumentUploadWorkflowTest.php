<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\AuditLog;
use App\Models\CivilRecord;
use App\Models\DocumentPage;
use App\Models\DocumentTemplate;
use App\Models\DocumentTypeDefinition;
use App\Models\OcrModel;
use App\Models\PageLine;
use App\Models\User;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentUploadWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /** The seeded Birth layout's required fields after "Child Full Name", in order. */
    private const OTHER_BIRTH_FIELDS = ['Date of Birth', 'Sex', 'Place of Birth', 'Father Full Name', 'Mother Full Name'];

    public function test_staff_and_super_admin_receive_the_interactive_marker_workspace(): void
    {
        $this->seed(DocumentTemplateSeeder::class);

        foreach ([User::factory()->staff()->create(), User::factory()->superAdmin()->create()] as $user) {
            $this->actingAs($user)
                ->get(route('documents.workspace', ['type' => DocumentType::Birth->value]))
                ->assertOk()
                ->assertSee('id="documentFlowSteps"', escape: false)
                ->assertSee('id="documentDropzone"', escape: false)
                ->assertSee('class="field-marker-comparison-grid"', escape: false)
                ->assertSee('class="field-marker-document-pane"', escape: false)
                ->assertSee('class="document-side-panel field-marker-controls-pane"', escape: false)
                ->assertSee('id="docViewport"', escape: false)
                ->assertSee('id="staffFieldSelectionMarquee"', escape: false)
                ->assertSee('class="btn btn-sm btn-icon btn-outline-secondary rounded-pill layout-menu-toggle global-sidebar-toggle"', escape: false)
                ->assertSee('aria-controls="layout-menu"', escape: false)
                ->assertSee('id="zoomResetBtn"', escape: false)
                ->assertSee('id="resetFieldsBtn"', escape: false)
                ->assertSee('id="resetFieldsModal"', escape: false)
                ->assertSee('id="confirmResetFieldsBtn"', escape: false)
                ->assertSee('id="deleteSelectedBtn"', escape: false)
                ->assertSee('id="selectAllFields"', escape: false)
                ->assertSee('id="deleteFieldsBtn"', escape: false)
                ->assertSee('id="paperMismatchWarning"', escape: false)
                ->assertSee('id="paperMismatchMessage"', escape: false)
                ->assertSeeText('Short / Letter (8.5 × 11 in) · Portrait')
                ->assertSee('"orientation":"portrait"', escape: false)
                ->assertSee('id="ocrActionStatus"', escape: false)
                ->assertSee('maxlength="500"', escape: false)
                ->assertSee('maxFields: 450', escape: false)
                ->assertSee('id="ocrProgressRing"', escape: false)
                ->assertSee('id="ocrProgressValue"', escape: false)
                ->assertSee('<kbd>Shift</kbd> + click', escape: false)
                ->assertSee('<kbd>Ctrl</kbd> + drag', escape: false)
                ->assertSee('Move document')
                ->assertSee('Select fields in a rectangle')
                ->assertSee('<kbd>Shift</kbd> + drag', escape: false)
                ->assertSee('Resize selected fields')
                ->assertSee('<kbd>Ctrl</kbd> + <kbd>C</kbd>', escape: false)
                ->assertSee('<kbd>Ctrl</kbd> + <kbd>V</kbd>', escape: false)
                ->assertSee('<kbd>Del</kbd> or <kbd>Backspace</kbd>', escape: false)
                ->assertSee('<kbd>Ctrl</kbd> + <kbd>Z</kbd>', escape: false)
                ->assertSee('Scan with OCR')
                ->assertDontSee('Compare and verify')
                ->assertSee('Original document')
                ->assertSee('Digital text output')
                ->assertSee('Registry records')
                ->assertSee('Select a person to compare the complete row.')
                ->assertSee('Orange shows the complete person row')
                ->assertSee('green identifies the exact field')
                ->assertSee('id="validationPageCanvas"', escape: false)
                ->assertSee('id="validationFieldOverlay"', escape: false)
                ->assertSee('id="verifiedProgress"', escape: false)
                ->assertSee('id="validationSubmitError"', escape: false)
                ->assertSee('Verify all fields for')
                ->assertDontSee('Only checked fields are submitted.');
        }
    }

    public function test_staff_workspace_uses_the_published_custom_page_dimensions(): void
    {
        $this->seed(DocumentTemplateSeeder::class);
        $template = DocumentTemplate::activeFor(DocumentType::Birth);
        $template->update([
            'paper_size' => 'custom',
            'orientation' => 'landscape',
            'custom_width_mm' => 240.5,
            'custom_height_mm' => 355.6,
        ]);

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('documents.workspace', ['type' => DocumentType::Birth->value]))
            ->assertOk()
            ->assertSeeText('Custom size')
            ->assertSeeText('240.5 × 355.6 mm expected')
            ->assertSee('"aspectRatio":1.478', escape: false);
    }

    public function test_staff_workspace_emits_custom_grouping_and_person_box_metadata(): void
    {
        $this->seed(DocumentTemplateSeeder::class);
        $template = DocumentTemplate::activeFor(DocumentType::Birth);
        $template->update(['grouping_mode' => 'custom']);
        $template->fields()->delete();

        foreach ([
            ['Person 1 Name', 0.10, 1, 0],
            ['Person 1 Birth Date', 0.30, 1, 1],
            ['Person 2 Name', 0.50, 2, 0],
            ['Registry Book Number', 0.70, null, null],
        ] as $index => [$name, $y, $personGroup, $personFieldOrder]) {
            $template->fields()->create([
                'name' => $name,
                'x' => 0.1,
                'y' => $y,
                'width' => 0.3,
                'height' => 0.05,
                'sort_order' => $index,
                'is_required' => true,
                'person_group' => $personGroup,
                'person_field_order' => $personFieldOrder,
            ]);
        }

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('documents.workspace', ['type' => DocumentType::Birth->value]))
            ->assertOk()
            ->assertSee('groupingMode: "custom"', escape: false)
            ->assertSee('"name":"Person 1 Name"', escape: false)
            ->assertSee('"personGroup":1', escape: false)
            ->assertSee('"personFieldOrder":0', escape: false)
            ->assertSee('"personGroup":2', escape: false)
            ->assertSee('"name":"Registry Book Number"', escape: false)
            ->assertSee('"personGroup":null', escape: false)
            ->assertSee('"personFieldOrder":null', escape: false);
    }

    public function test_submission_rejects_more_than_four_hundred_fifty_fields(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);
        $this->registerTestModel();

        $template = DocumentTemplate::activeFor(DocumentType::Birth);
        $fields = array_map(
            fn (int $number) => $this->verifiedField("Registry field {$number}", 'Value'),
            range(1, 451),
        );

        $this->actingAs(User::factory()->staff()->create())
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                ...$this->submissionPayload($template, $fields),
                'allow_missing' => '1',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fields' => 'must not have more than 450 items']);

        $this->assertDatabaseCount('records', 0);
    }

    public function test_only_explicitly_verified_fields_are_saved(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);
        $this->registerTestModel();

        $user = User::factory()->staff()->create();
        $template = DocumentTemplate::activeFor(DocumentType::Birth);

        $response = $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                ...$this->submissionPayload($template, [
                    $this->verifiedField('Child Full Name', 'Maria Santos'),
                ]),
                'allow_missing' => '1',
            ]);

        $response
            ->assertCreated()
            ->assertJsonStructure(['message', 'redirect']);

        $this->assertDatabaseCount('records', 1);
        $this->assertDatabaseCount('record_fields', 1);
        $this->assertDatabaseHas('record_fields', [
            'name' => 'Child Full Name',
            'verified_value' => 'Maria Santos',
            'is_required' => true,
        ]);

        $record = CivilRecord::firstOrFail();
        Storage::disk('local')->assertExists($record->scan_path);
    }

    public function test_submission_snapshots_person_grouping_for_the_archive(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);
        $this->registerTestModel();

        $template = DocumentTemplate::activeFor(DocumentType::Birth);
        $field = $this->verifiedField('Child Full Name', 'Maria Santos');
        $field['person_group'] = 2;
        $field['person_field_order'] = 3;

        $this->actingAs(User::factory()->staff()->create())
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                ...$this->submissionPayload($template, [$field]),
                'allow_missing' => '1',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('record_fields', [
            'name' => 'Child Full Name',
            'person_group' => 2,
            'person_field_order' => 3,
        ]);
    }

    public function test_submission_rejects_a_person_group_past_the_field_limit(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);
        $this->registerTestModel();

        $template = DocumentTemplate::activeFor(DocumentType::Birth);
        $field = $this->verifiedField('Child Full Name', 'Maria Santos');
        $field['person_group'] = 451;
        $field['person_field_order'] = 0;

        $this->actingAs(User::factory()->staff()->create())
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                ...$this->submissionPayload($template, [$field]),
                'allow_missing' => '1',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fields.0.person_group');

        $this->assertDatabaseCount('records', 0);
    }

    public function test_verified_fields_can_be_submitted_as_one_json_input(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);
        $this->registerTestModel();

        $template = DocumentTemplate::activeFor(DocumentType::Birth);
        $payload = $this->submissionPayload($template, [
            $this->verifiedField('Child Full Name', 'Maria Santos'),
        ]);
        $payload['fields_json'] = json_encode($payload['fields'], JSON_THROW_ON_ERROR);
        $payload['allow_missing'] = '1';
        unset($payload['fields']);

        $this->actingAs(User::factory()->staff()->create())
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), $payload)
            ->assertCreated();

        $this->assertDatabaseHas('record_fields', [
            'name' => 'Child Full Name',
            'verified_value' => 'Maria Santos',
        ]);
    }

    public function test_unchecked_and_blank_verified_fields_are_rejected_without_saving(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);
        $this->registerTestModel();

        $user = User::factory()->staff()->create();
        $template = DocumentTemplate::activeFor(DocumentType::Birth);
        $unchecked = $this->verifiedField('Child Full Name', 'Maria Santos');
        $unchecked['verified'] = '0';

        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), $this->submissionPayload($template, [$unchecked]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fields.0.verified');

        $blank = $this->verifiedField('Child Full Name', '');
        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), $this->submissionPayload($template, [$blank]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fields.0.verified_value');

        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseCount('record_fields', 0);
    }

    public function test_a_template_from_another_document_type_is_rejected(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);
        $this->registerTestModel();

        $user = User::factory()->staff()->create();
        $deathTemplate = DocumentTemplate::activeFor(DocumentType::Death);

        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                ...$this->submissionPayload($deathTemplate, [
                    $this->verifiedField('Child Full Name', 'Maria Santos'),
                ]),
                'doc_type' => DocumentType::Birth->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_template_id');

        $this->assertDatabaseCount('records', 0);
    }

    public function test_an_unregistered_ocr_model_is_rejected(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);

        $user = User::factory()->staff()->create();
        $template = DocumentTemplate::activeFor(DocumentType::Birth);

        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                ...$this->submissionPayload($template, [
                    $this->verifiedField('Child Full Name', 'Maria Santos'),
                ]),
                'ocr_model_key' => 'not-a-registered-model',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ocr_model_key');

        $this->assertDatabaseCount('records', 0);
    }

    public function test_a_tiff_scan_is_refused_with_a_clear_message(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);
        $this->registerTestModel();

        $template = DocumentTemplate::activeFor(DocumentType::Birth);

        $this->actingAs(User::factory()->staff()->create())
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                ...$this->submissionPayload($template, [
                    $this->verifiedField('Child Full Name', 'Maria Santos'),
                ]),
                'scan' => UploadedFile::fake()->create('scan.tiff', 10, 'image/tiff'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'scan' => 'The scan must be a PDF, PNG, JPG, WEBP or BMP file.',
            ]);

        $this->assertDatabaseCount('records', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('scans'));
    }

    public function test_missing_required_fields_need_confirmation_before_saving(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);
        $this->registerTestModel();

        $template = DocumentTemplate::activeFor(DocumentType::Birth);

        $this->actingAs(User::factory()->staff()->create())
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), $this->submissionPayload($template, [
                $this->verifiedField('Child Full Name', 'Maria Santos'),
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.missing_required', self::OTHER_BIRTH_FIELDS);

        $this->assertDatabaseCount('records', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('scans'));
    }

    public function test_confirmed_missing_fields_are_saved_and_listed_in_the_audit_log(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);
        $this->registerTestModel();

        $template = DocumentTemplate::activeFor(DocumentType::Birth);

        $this->actingAs(User::factory()->staff()->create())
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                ...$this->submissionPayload($template, [
                    $this->verifiedField('Child Full Name', 'Maria Santos'),
                ]),
                'allow_missing' => '1',
            ])
            ->assertCreated();

        $this->assertDatabaseCount('records', 1);
        $audit = AuditLog::where('action', 'record.submitted')->sole();
        $this->assertSame(self::OTHER_BIRTH_FIELDS, $audit->new_values['missing_required_fields']);
    }

    public function test_a_complete_submission_is_not_asked_to_confirm(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);
        $this->registerTestModel();

        $template = DocumentTemplate::activeFor(DocumentType::Birth);
        $fields = array_map(
            fn (string $name) => $this->verifiedField($name, 'Recorded value'),
            ['Child Full Name', ...self::OTHER_BIRTH_FIELDS],
        );
        // Names are compared ignoring case.
        $fields[2]['name'] = 'SEX';

        $this->actingAs(User::factory()->staff()->create())
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), $this->submissionPayload($template, $fields))
            ->assertCreated();

        $audit = AuditLog::where('action', 'record.submitted')->sole();
        $this->assertArrayNotHasKey('missing_required_fields', $audit->new_values);
    }

    public function test_a_required_field_read_as_several_lines_is_not_missing(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);
        $this->registerTestModel();

        $staff = User::factory()->staff()->create();
        $template = DocumentTemplate::activeFor(DocumentType::Birth);
        $page = $this->readPage($template, $staff);
        // Detect split the child's name into two written lines, which Verify
        // lists as "Child Full Name · line 1" and "· line 2".
        $lineFields = [];
        foreach ([1, 2] as $row) {
            $line = $this->pageLine($page, [
                'source' => PageLine::SOURCE_FIELD,
                'column_name' => 'Child Full Name',
                'row' => $row,
            ]);
            $lineFields[] = [
                ...$this->verifiedField("Child Full Name · line {$row}", 'Maria Santos'),
                'line_id' => $line->getKey(),
            ];
        }
        $otherFields = array_map(
            fn (string $name) => $this->verifiedField($name, 'Recorded value'),
            self::OTHER_BIRTH_FIELDS,
        );

        $this->actingAs($staff)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                ...$this->submissionPayload($template, [...$lineFields, ...$otherFields]),
                'document_page_id' => $page->getKey(),
            ])
            ->assertCreated();
    }

    public function test_register_rows_left_incomplete_or_unticked_need_confirmation(): void
    {
        Storage::fake('local');
        [$template, $page, $staff] = $this->registerPage();

        // Row 1 without its Date, row 4 complete, rows 2, 3 and 5 untouched.
        $this->actingAs($staff)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                ...$this->submissionPayload($template, [
                    $this->cellField($page, 1, 'Name'),
                    $this->cellField($page, 4, 'Name'),
                    $this->cellField($page, 4, 'Date'),
                ]),
                'document_page_id' => $page->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.missing_required', [
                'Person 01: Date',
                'Nothing ticked in Person 02 – Person 03, Person 05 (these rows are not saved)',
            ]);

        $this->assertDatabaseCount('records', 0);
    }

    public function test_confirmed_register_rows_are_listed_in_the_audit_log(): void
    {
        Storage::fake('local');
        [$template, $page, $staff] = $this->registerPage();

        // Rows 1, 3 and 4 complete, row 2 without its Date, row 5 untouched.
        $fields = [];
        foreach ([1, 3, 4] as $row) {
            $fields[] = $this->cellField($page, $row, 'Name');
            $fields[] = $this->cellField($page, $row, 'Date');
        }
        $fields[] = $this->cellField($page, 2, 'Name');

        $this->actingAs($staff)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                ...$this->submissionPayload($template, $fields),
                'document_page_id' => $page->getKey(),
                'allow_missing' => '1',
            ])
            ->assertCreated();

        $audit = AuditLog::where('action', 'record.submitted')->sole();
        $this->assertSame([
            'Person 02: Date',
            'Nothing ticked in Person 05 (this row is not saved)',
        ], $audit->new_values['missing_required_fields']);
    }

    public function test_a_complete_register_page_is_not_asked_to_confirm(): void
    {
        Storage::fake('local');
        [$template, $page, $staff] = $this->registerPage();

        $fields = [];
        foreach (range(1, 5) as $row) {
            $fields[] = $this->cellField($page, $row, 'Name');
            $fields[] = $this->cellField($page, $row, 'Date');
        }

        $this->actingAs($staff)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                ...$this->submissionPayload($template, $fields),
                'document_page_id' => $page->getKey(),
            ])
            ->assertCreated();
    }

    public function test_custom_document_type_can_complete_the_staff_submission_flow(): void
    {
        Storage::fake('local');
        $this->registerTestModel();
        $staff = User::factory()->staff()->create();
        $type = DocumentTypeDefinition::create([
            'key' => 'custom-local-registry',
            'name' => 'Local Registry Form',
            'short_name' => 'Local Registry Form',
            'icon' => 'bx-file-blank',
            'is_system' => false,
        ]);
        $template = DocumentTemplate::create([
            'name' => 'Local registry layout',
            'doc_type' => DocumentType::Custom->value,
            'document_type_id' => $type->getKey(),
            'paper_size' => 'a4',
            'orientation' => 'portrait',
            'is_active' => true,
            'created_by' => User::factory()->superAdmin()->create()->getKey(),
        ]);
        $template->fields()->create([
            'name' => 'Resident Full Name',
            'x' => 0.1,
            'y' => 0.1,
            'width' => 0.5,
            'height' => 0.08,
            'sort_order' => 0,
            'is_required' => true,
        ]);

        $this->actingAs($staff)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                ...$this->submissionPayload($template, [
                    $this->verifiedField('Resident Full Name', 'Ana Reyes'),
                ]),
                'doc_type' => $type->key,
            ])
            ->assertCreated();

        $record = CivilRecord::with('documentTypeDefinition')->firstOrFail();
        $this->assertSame(DocumentType::Custom, $record->doc_type);
        $this->assertSame($type->getKey(), $record->document_type_id);
        $this->assertSame('Local Registry Form', $record->typeLabel());

        $type->update(['name' => 'Renamed Local Form', 'short_name' => 'Renamed Local Form']);
        $this->assertSame('Renamed Local Form', $record->refresh()->typeLabel());
    }

    /**
     * A register layout with two required columns, Name and Date, and a read
     * page with a Name and a Date cell in each of its five rows.
     *
     * @return array{0: DocumentTemplate, 1: DocumentPage, 2: User}
     */
    private function registerPage(): array
    {
        $this->seed(DocumentTemplateSeeder::class);
        $this->registerTestModel();
        $staff = User::factory()->staff()->create();

        $template = DocumentTemplate::activeFor(DocumentType::Birth);
        $template->fields()->delete();
        $template->update([
            'columns' => [
                ['name' => 'Name', 'box' => [0.05, 0.1, 0.45, 0.8]],
                ['name' => 'Date', 'box' => [0.5, 0.1, 0.3, 0.8]],
            ],
            'ruled_ys' => [0.1, 0.26, 0.42, 0.58, 0.74, 0.9],
        ]);

        $page = $this->readPage($template, $staff);
        foreach (range(1, 5) as $row) {
            foreach (['Name', 'Date'] as $columnIndex => $column) {
                $this->pageLine($page, ['column_index' => $columnIndex, 'column_name' => $column, 'row' => $row]);
            }
        }

        return [$template->fresh(['fields', 'documentTypeDefinition']), $page->fresh('lines'), $staff];
    }

    /** A page Staff scanned and the worker has read, ready to verify. */
    private function readPage(DocumentTemplate $template, User $staff): DocumentPage
    {
        return DocumentPage::create([
            'document_template_id' => $template->getKey(),
            'created_by' => $staff->getKey(),
            'status' => DocumentPage::STATUS_READY,
            'image_path' => '',
            'width' => 800,
            'height' => 600,
            'geometry' => ['columns' => [], 'ruled_ys' => [], 'fields' => []],
            'ocr_model_key' => 'test-model',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function pageLine(DocumentPage $page, array $attributes): PageLine
    {
        $position = $page->lines()->count();
        $line = $page->lines()->create([
            'position' => $position,
            'source' => 'kraken',
            'polygon' => [[10, 10], [50, 10], [50, 30], [10, 30]],
            'bbox' => [10, 10, 40, 20],
            'crop_path' => sprintf('%s/crops/%03d.png', $page->directory(), $position + 1),
            'flags' => [],
            ...$attributes,
        ]);
        Storage::disk('local')->put($line->crop_path, 'crop');

        return $line;
    }

    /**
     * A verified register cell, named as Verify names it.
     *
     * @return array<string, mixed>
     */
    private function cellField(DocumentPage $page, int $row, string $column): array
    {
        $line = $page->lines->first(fn (PageLine $line) => $line->row === $row && $line->column_name === $column);

        return [
            ...$this->verifiedField("{$column} · row {$row}", 'Recorded value'),
            'line_id' => $line->getKey(),
        ];
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function submissionPayload(DocumentTemplate $template, array $fields): array
    {
        return [
            'doc_type' => $template->doc_type->value,
            'document_template_id' => $template->getKey(),
            'registry_number' => '2026-001',
            'ocr_model_key' => 'test-model',
            'scan' => UploadedFile::fake()->image('certificate.png', 600, 800),
            'fields' => $fields,
        ];
    }

    /** @return array<string, mixed> */
    private function verifiedField(string $name, string $value): array
    {
        return [
            'verified' => '1',
            'name' => $name,
            'ocr_text' => $value,
            'ocr_confidence' => 92.5,
            'verified_value' => $value,
            'x' => 0.1,
            'y' => 0.1,
            'width' => 0.3,
            'height' => 0.05,
        ];
    }

    private function registerTestModel(): void
    {
        OcrModel::create([
            'key' => 'test-model',
            'label' => 'Test model',
        ]);
    }
}
