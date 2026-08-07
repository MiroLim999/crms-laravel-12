<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\CivilRecord;
use App\Models\DocumentTemplate;
use App\Models\DocumentTypeDefinition;
use App\Models\OcrModel;
use App\Models\User;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentUploadWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_and_super_admin_receive_the_interactive_marker_workspace(): void
    {
        $this->seed(DocumentTemplateSeeder::class);

        foreach ([User::factory()->staff()->create(), User::factory()->superAdmin()->create()] as $user) {
            $this->actingAs($user)
                ->get(route('documents.workspace', ['type' => DocumentType::Birth->value]))
                ->assertOk()
                ->assertSee('id="documentFlowSteps"', escape: false)
                ->assertSee('id="documentDropzone"', escape: false)
                ->assertSee('id="docViewport"', escape: false)
                ->assertSee('class="layout-menu-toggle global-sidebar-toggle"', escape: false)
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
                ->assertSee('id="ocrProgressRing"', escape: false)
                ->assertSee('id="ocrProgressValue"', escape: false)
                ->assertSee('<kbd>Shift</kbd> + click', escape: false)
                ->assertSee('Resize selected fields')
                ->assertSee('<kbd>Ctrl</kbd> + <kbd>C</kbd>', escape: false)
                ->assertSee('<kbd>Ctrl</kbd> + <kbd>V</kbd>', escape: false)
                ->assertSee('<kbd>Del</kbd> or <kbd>Backspace</kbd>', escape: false)
                ->assertSee('<kbd>Ctrl</kbd> + <kbd>Z</kbd>', escape: false)
                ->assertSee('Scan with OCR')
                ->assertSee('Compare and verify')
                ->assertSee('Original document')
                ->assertSee('Digital text output')
                ->assertSee('id="validationPageCanvas"', escape: false)
                ->assertSee('id="validationFieldOverlay"', escape: false)
                ->assertSee('id="verifiedProgress"', escape: false)
                ->assertSee('id="validationSubmitError"', escape: false)
                ->assertSee('Only checked fields are submitted.');
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

    public function test_only_explicitly_verified_fields_are_saved(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);
        $this->registerTestModel();

        $user = User::factory()->staff()->create();
        $template = DocumentTemplate::activeFor(DocumentType::Birth);

        $response = $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), $this->submissionPayload($template, [
                $this->verifiedField('Child Full Name', 'Maria Santos'),
            ]));

        $response
            ->assertCreated()
            ->assertJsonStructure(['message', 'redirect']);

        $this->assertDatabaseCount('records', 1);
        $this->assertDatabaseCount('record_fields', 1);
        $this->assertDatabaseHas('record_fields', [
            'name' => 'Child Full Name',
            'verified_value' => 'Maria Santos',
        ]);

        $record = CivilRecord::firstOrFail();
        Storage::disk('local')->assertExists($record->scan_path);
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
