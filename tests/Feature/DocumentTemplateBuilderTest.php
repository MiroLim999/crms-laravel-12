<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\PageOrientation;
use App\Enums\PaperSize;
use App\Models\CivilRecord;
use App\Models\DocumentTemplate;
use App\Models\DocumentTypeDefinition;
use App\Models\User;
use App\Services\TemplateSampleStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentTemplateBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_sees_the_rebuilt_template_builder_workspace(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('templates.index'))
            ->assertOk()
            ->assertSeeText('Template Builder')
            ->assertDontSeeText('Document Templates')
            ->assertSeeText('Published layouts are the Staff defaults.');

        $this->get(route('templates.index'))
            ->assertSee('class="template-library-layouts"', escape: false)
            ->assertSee('data-template-layout-toggle', escape: false)
            ->assertSeeText('Show layouts')
            ->assertSeeText('New document type');

        $this->get(route('templates.create', ['type' => DocumentType::Birth->value]))
            ->assertOk()
            ->assertSee('id="docViewport"', escape: false)
            ->assertSee('id="fieldOverlay"', escape: false)
            ->assertSee('id="fieldSelectionMarquee"', escape: false)
            ->assertSee('id="selectAllFields"', escape: false)
            ->assertSee('id="publishIntent"', escape: false)
            ->assertSee('id="paper_size"', escape: false)
            ->assertSee('value="custom"', escape: false)
            ->assertSee('id="custom_width_mm"', escape: false)
            ->assertSee('id="custom_height_mm"', escape: false)
            ->assertSee('id="orientation_portrait"', escape: false)
            ->assertSee('id="orientation_landscape"', escape: false)
            ->assertSee('id="paperPreviewStatus"', escape: false)
            ->assertSee('id="samplePageSize"', escape: false)
            ->assertSee('id="samplePhysicalSize"', escape: false)
            ->assertSee('id="useSampleSizeBtn"', escape: false)
            ->assertSeeText('Save & publish for Staff');
    }

    public function test_super_admin_can_create_a_template_with_its_fields(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post(route('templates.store'), [
            'name' => 'Birth Certificate 2026',
            'doc_type' => DocumentType::Birth->value,
            ...$this->paperSpec(PaperSize::LongBond, PageOrientation::Landscape),
            'description' => 'Current form revision.',
            'fields' => [$this->field('Child Full Name')],
        ])->assertRedirect();

        $template = DocumentTemplate::where('name', 'Birth Certificate 2026')->firstOrFail();

        $this->assertFalse($template->is_active);
        $this->assertSame(PaperSize::LongBond, $template->paper_size);
        $this->assertSame(PageOrientation::Landscape, $template->orientation);
        $this->assertDatabaseHas('document_template_fields', [
            'document_template_id' => $template->getKey(),
            'name' => 'Child Full Name',
        ]);
    }

    public function test_layout_sample_is_privately_stored_previewed_and_deleted(): void
    {
        Storage::fake('local');
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post(route('templates.store'), [
            'name' => 'Birth Registry 2026',
            'doc_type' => DocumentType::Birth->value,
            ...$this->paperSpec(),
            'sample_document' => UploadedFile::fake()->image('Municipal Birth Form.jpg', 900, 1200),
            'fields' => [$this->field('Child Full Name')],
        ])->assertRedirect();

        $template = DocumentTemplate::where('name', 'Birth Registry 2026')->firstOrFail();
        $samplePath = $template->sample_path;

        $this->assertNotNull($samplePath);
        $this->assertStringContainsString('template-samples/001-birth-certificate/', $samplePath);
        $this->assertStringContainsString(
            sprintf('/%05d-birth-registry-2026/', $template->getKey()),
            $samplePath,
        );
        $this->assertStringEndsWith(
            '/birth-certificate--birth-registry-2026--sample.jpg',
            $samplePath,
        );
        $this->assertSame('Municipal Birth Form.jpg', $template->sample_original_name);
        $this->assertSame('image/jpeg', $template->sample_mime);
        Storage::disk('local')->assertExists($samplePath);

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('templates.sample', $template))
            ->assertForbidden();

        $this->actingAs($superAdmin)->get(route('templates.edit', $template))
            ->assertOk()
            ->assertSee('enctype="multipart/form-data"', escape: false)
            ->assertSeeText('Municipal Birth Form.jpg')
            ->assertSee(route('templates.sample', $template), escape: false);

        $this->get(route('templates.sample', $template))
            ->assertOk()
            ->assertJsonPath('name', 'Municipal Birth Form.jpg')
            ->assertJsonPath('mime', 'image/jpeg')
            ->assertJsonStructure(['data'])
            ->assertHeaderMissing('content-disposition');

        $this->delete(route('templates.sample.destroy', $template))
            ->assertRedirect()
            ->assertSessionHas('success');

        Storage::disk('local')->assertMissing($samplePath);
        $this->assertNull($template->refresh()->sample_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'template.sample-deleted']);
    }

    public function test_sample_survives_create_publish_and_reopening_the_layout(): void
    {
        Storage::fake('local');
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post(route('templates.store'), [
            'name' => 'Published Sample Layout',
            'doc_type' => DocumentType::Birth->value,
            ...$this->paperSpec(),
            'publish' => '1',
            'sample_document' => UploadedFile::fake()->createWithContent(
                'archival-birth-scan.pdf',
                '%PDF-1.4 sample document',
            ),
            'fields' => [$this->field('Child Full Name')],
        ])->assertRedirect();

        $template = DocumentTemplate::where('name', 'Published Sample Layout')->firstOrFail();

        $this->assertTrue($template->is_active);
        $this->assertNotNull($template->sample_path);
        Storage::disk('local')->assertExists($template->sample_path);

        $this->get(route('templates.edit', $template))
            ->assertOk()
            ->assertSeeText('archival-birth-scan.pdf')
            ->assertSee(route('templates.sample', $template), escape: false);

        $this->get(route('templates.sample', $template))
            ->assertOk()
            ->assertJsonPath('name', 'archival-birth-scan.pdf')
            ->assertJsonPath('mime', 'application/pdf')
            ->assertJsonStructure(['data'])
            ->assertHeaderMissing('content-disposition');
    }

    public function test_sample_path_follows_layout_and_custom_document_type_names(): void
    {
        Storage::fake('local');
        $superAdmin = User::factory()->superAdmin()->create();
        $type = DocumentTypeDefinition::create([
            'key' => 'custom-residency-sample-test',
            'name' => 'Residency Certificate',
            'short_name' => 'Residency Certificate',
            'icon' => 'bx-file',
            'is_system' => false,
        ]);

        $this->actingAs($superAdmin)->post(route('templates.store'), [
            'name' => 'Old Residency Layout',
            'doc_type' => $type->key,
            ...$this->paperSpec(),
            'sample_document' => UploadedFile::fake()->image('residency.png', 900, 1200),
            'fields' => [$this->field('Resident Full Name')],
        ])->assertRedirect();

        $template = DocumentTemplate::where('name', 'Old Residency Layout')->firstOrFail();
        $initialPath = $template->sample_path;

        $this->actingAs($superAdmin)->put(route('templates.update', $template), [
            'name' => 'Current Residency Layout',
            'document_type_id' => $type->getKey(),
            'doc_type' => DocumentType::Custom->value,
            ...$this->paperSpec(),
            'fields' => [$this->field('Resident Full Name')],
        ])->assertRedirect();

        $layoutPath = $template->refresh()->sample_path;
        Storage::disk('local')->assertMissing($initialPath);
        Storage::disk('local')->assertExists($layoutPath);
        $this->assertStringContainsString('current-residency-layout', $layoutPath);

        $this->actingAs($superAdmin)->put(route('templates.document-types.update', $type), [
            'document_type_name' => 'Barangay Residency Record',
        ])->assertRedirect();

        $renamedTypePath = $template->refresh()->sample_path;
        Storage::disk('local')->assertMissing($layoutPath);
        Storage::disk('local')->assertExists($renamedTypePath);
        $this->assertStringContainsString('barangay-residency-record', $renamedTypePath);
        $this->assertStringContainsString('current-residency-layout', $renamedTypePath);
    }

    public function test_unsupported_template_sample_is_rejected(): void
    {
        Storage::fake('local');
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post(route('templates.store'), [
            'name' => 'Unsafe sample',
            'doc_type' => DocumentType::Birth->value,
            ...$this->paperSpec(),
            'sample_document' => UploadedFile::fake()->create(
                'payload.exe',
                10,
                'application/x-msdownload',
            ),
            'fields' => [$this->field('Child Full Name')],
        ])->assertSessionHasErrors('sample_document');

        $this->assertDatabaseMissing('document_templates', ['name' => 'Unsafe sample']);
        $this->assertSame([], Storage::disk('local')->allFiles(TemplateSampleStorage::ROOT));
    }

    public function test_validation_redirect_restores_the_working_field_layout(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $createUrl = route('templates.create', ['type' => DocumentType::Birth->value]);

        $response = $this->actingAs($superAdmin)
            ->from($createUrl)
            ->post(route('templates.store'), [
                'name' => '',
                'doc_type' => DocumentType::Birth->value,
                ...$this->paperSpec(),
                'fields' => [$this->field('Custom Registry Field')],
            ]);

        $response->assertRedirect($createUrl)->assertSessionHasErrors('name');

        $this->get($createUrl)
            ->assertOk()
            ->assertSee('Custom Registry Field');
    }

    public function test_create_can_save_and_publish_in_one_request(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $previous = $this->template($superAdmin, DocumentType::Birth, 'Previous layout', active: true);

        $this->actingAs($superAdmin)->post(route('templates.store'), [
            'name' => 'Current layout',
            'doc_type' => DocumentType::Birth->value,
            ...$this->paperSpec(),
            'publish' => '1',
            'fields' => [$this->field('Registry Name')],
        ])->assertRedirect();

        $current = DocumentTemplate::where('name', 'Current layout')->firstOrFail();

        $this->assertTrue($current->is_active);
        $this->assertFalse($previous->refresh()->is_active);
        $this->assertSame(
            1,
            DocumentTemplate::where('doc_type', DocumentType::Birth->value)->where('is_active', true)->count(),
        );
    }

    public function test_edit_can_publish_a_draft_for_staff(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $previous = $this->template($superAdmin, DocumentType::Death, 'Old death layout', active: true);
        $draft = $this->template($superAdmin, DocumentType::Death, 'Draft death layout');

        $this->actingAs($superAdmin)->put(route('templates.update', $draft), [
            'name' => 'Published death layout',
            'doc_type' => DocumentType::Death->value,
            ...$this->paperSpec(PaperSize::A4, PageOrientation::Landscape),
            'publish' => '1',
            'fields' => [$this->field('Deceased Full Name')],
        ])->assertRedirect();

        $this->assertTrue($draft->refresh()->is_active);
        $this->assertSame('Published death layout', $draft->name);
        $this->assertSame(PaperSize::A4, $draft->paper_size);
        $this->assertSame(PageOrientation::Landscape, $draft->orientation);
        $this->assertFalse($previous->refresh()->is_active);
    }

    public function test_legacy_publish_action_keeps_one_staff_layout_per_type(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $previous = $this->template($superAdmin, DocumentType::Marriage, 'Previous', active: true);
        $next = $this->template($superAdmin, DocumentType::Marriage, 'Next');

        $this->actingAs($superAdmin)
            ->post(route('templates.activate', $next))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($previous->refresh()->is_active);
        $this->assertTrue($next->refresh()->is_active);
    }

    public function test_field_names_must_be_unique_ignoring_case(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post(route('templates.store'), [
            'name' => 'Duplicate fields',
            'doc_type' => DocumentType::Birth->value,
            ...$this->paperSpec(),
            'fields' => [
                $this->field('Child Full Name'),
                $this->field('child full name'),
            ],
        ])->assertSessionHasErrors('fields.1.name');

        $this->assertDatabaseMissing('document_templates', ['name' => 'Duplicate fields']);
    }

    public function test_field_markers_must_stay_inside_the_document(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $field = $this->field('Outside field');
        $field['x'] = 0.8;
        $field['width'] = 0.3;

        $this->actingAs($superAdmin)->post(route('templates.store'), [
            'name' => 'Invalid bounds',
            'doc_type' => DocumentType::Birth->value,
            ...$this->paperSpec(),
            'fields' => [$field],
        ])->assertSessionHasErrors('fields.0.width');
    }

    public function test_layouts_are_limited_to_one_hundred_fields(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $fields = collect(range(1, 101))
            ->map(fn (int $number) => $this->field("Field {$number}"))
            ->all();

        $this->actingAs($superAdmin)->post(route('templates.store'), [
            'name' => 'Too many fields',
            'doc_type' => DocumentType::Birth->value,
            ...$this->paperSpec(),
            'fields' => $fields,
        ])->assertSessionHasErrors('fields');
    }

    public function test_certificate_type_cannot_be_changed_after_creation(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $template = $this->template($superAdmin, DocumentType::Birth, 'Birth layout');

        $this->actingAs($superAdmin)->put(route('templates.update', $template), [
            'name' => 'Tampered layout',
            'doc_type' => DocumentType::Death->value,
            ...$this->paperSpec(),
            'fields' => [$this->field('Full Name')],
        ])->assertSessionHasErrors('doc_type');

        $this->assertSame(DocumentType::Birth, $template->refresh()->doc_type);
        $this->assertSame('Birth layout', $template->name);
    }

    public function test_super_admin_can_delete_a_published_template(): void
    {
        Storage::fake('local');
        $superAdmin = User::factory()->superAdmin()->create();
        $template = $this->template($superAdmin, DocumentType::Birth, 'Published layout', active: true);
        $samplePath = 'template-samples/001-birth-certificate/published-layout/sample.jpg';
        Storage::disk('local')->put($samplePath, 'sample');
        $template->forceFill([
            'sample_path' => $samplePath,
            'sample_original_name' => 'sample.jpg',
            'sample_mime' => 'image/jpeg',
            'sample_size' => 6,
        ])->save();

        $this->actingAs($superAdmin)
            ->delete(route('templates.destroy', $template))
            ->assertRedirect(route('templates.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('document_templates', ['id' => $template->getKey()]);
        Storage::disk('local')->assertMissing($samplePath);
        $this->assertDatabaseHas('audit_logs', ['action' => 'template.deleted']);
    }

    public function test_deleting_a_used_template_keeps_its_existing_records(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $staff = User::factory()->staff()->create();
        $template = $this->template($superAdmin, DocumentType::Birth, 'Used layout');
        $record = CivilRecord::factory()->create([
            'doc_type' => DocumentType::Birth->value,
            'document_type_id' => $template->document_type_id,
            'document_template_id' => $template->getKey(),
            'created_by' => $staff->getKey(),
        ]);

        $this->actingAs($superAdmin)
            ->delete(route('templates.destroy', $template))
            ->assertRedirect(route('templates.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('document_templates', ['id' => $template->getKey()]);
        $this->assertDatabaseHas('records', ['id' => $record->getKey()]);
        $this->assertNull($record->refresh()->document_template_id);
    }

    public function test_unknown_paper_settings_are_rejected(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post(route('templates.store'), [
            'name' => 'Invalid paper',
            'doc_type' => DocumentType::Birth->value,
            'paper_size' => 'tabloid',
            'orientation' => 'upside_down',
            'fields' => [$this->field('Full Name')],
        ])->assertSessionHasErrors(['paper_size', 'orientation']);
    }

    public function test_super_admin_can_save_a_custom_page_size(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post(route('templates.store'), [
            'name' => 'Custom registry sheet',
            'doc_type' => DocumentType::Birth->value,
            'paper_size' => PaperSize::Custom->value,
            'orientation' => PageOrientation::Landscape->value,
            'custom_width_mm' => 240.5,
            'custom_height_mm' => 355.6,
            'fields' => [$this->field('Full Name')],
        ])->assertRedirect();

        $template = DocumentTemplate::where('name', 'Custom registry sheet')->firstOrFail();

        $this->assertSame(PaperSize::Custom, $template->paper_size);
        $this->assertSame(240.5, $template->custom_width_mm);
        $this->assertSame(355.6, $template->custom_height_mm);
        $this->assertSame('240.5 × 355.6 mm', $template->paperDimensionsLabel());
        $this->assertEqualsWithDelta(355.6 / 240.5, $template->paperAspectRatio(), 0.00001);
    }

    public function test_custom_page_size_requires_valid_dimensions(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post(route('templates.store'), [
            'name' => 'Invalid custom sheet',
            'doc_type' => DocumentType::Birth->value,
            'paper_size' => PaperSize::Custom->value,
            'orientation' => PageOrientation::Portrait->value,
            'custom_width_mm' => 20,
            'fields' => [$this->field('Full Name')],
        ])->assertSessionHasErrors(['custom_width_mm', 'custom_height_mm']);
    }

    public function test_super_admin_can_create_and_rename_a_custom_document_type(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post(route('templates.document-types.store'), [
            'document_type_name' => 'Residency Certificate',
        ]);

        $type = DocumentTypeDefinition::where('name', 'Residency Certificate')->firstOrFail();
        $this->assertFalse($type->is_system);
        $this->assertStringStartsWith('custom-residency-certificate-', $type->key);
        $response->assertRedirect(route('templates.create', ['type' => $type->key]));

        $this->actingAs($superAdmin)->put(route('templates.document-types.update', $type), [
            'document_type_name' => 'Barangay Residency Record',
        ])->assertRedirect(route('templates.index', ['open' => $type->key]));

        $this->assertSame('Barangay Residency Record', $type->refresh()->name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document-type.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document-type.renamed']);
    }

    public function test_builtin_document_types_cannot_be_renamed(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $birth = DocumentTypeDefinition::where('key', DocumentType::Birth->value)->firstOrFail();

        $this->actingAs($superAdmin)->put(route('templates.document-types.update', $birth), [
            'document_type_name' => 'Changed birth name',
        ])->assertForbidden();

        $this->assertSame('Birth Certificate', $birth->refresh()->name);
    }

    public function test_super_admin_can_delete_an_unused_custom_document_type_and_its_layouts(): void
    {
        Storage::fake('local');
        $superAdmin = User::factory()->superAdmin()->create();
        $type = DocumentTypeDefinition::create([
            'key' => 'custom-residency-delete-test',
            'name' => 'Temporary Residency Record',
            'short_name' => 'Temporary Residency Record',
            'icon' => 'bx-file',
            'is_system' => false,
        ]);
        $samplePath = 'template-samples/004-temporary-residency-record/00001-temporary-layout/sample.jpg';
        Storage::disk('local')->put($samplePath, 'sample');
        $template = DocumentTemplate::create([
            'name' => 'Temporary layout',
            'doc_type' => DocumentType::Custom->value,
            'document_type_id' => $type->getKey(),
            ...$this->paperSpec(),
            'is_active' => false,
            'created_by' => $superAdmin->getKey(),
            'sample_path' => $samplePath,
            'sample_original_name' => 'sample.jpg',
            'sample_mime' => 'image/jpeg',
            'sample_size' => 6,
        ]);
        $field = $template->fields()->create([
            ...$this->field('Resident name'),
            'sort_order' => 0,
            'is_required' => true,
        ]);

        $this->actingAs($superAdmin)
            ->delete(route('templates.document-types.destroy', $type))
            ->assertRedirect(route('templates.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('document_types', ['id' => $type->getKey()]);
        $this->assertDatabaseMissing('document_templates', ['id' => $template->getKey()]);
        $this->assertDatabaseMissing('document_template_fields', ['id' => $field->getKey()]);
        Storage::disk('local')->assertMissing($samplePath);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document-type.deleted']);
    }

    public function test_custom_document_type_with_records_cannot_be_deleted(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $staff = User::factory()->staff()->create();
        $type = DocumentTypeDefinition::create([
            'key' => 'custom-residency-record-test',
            'name' => 'Residency Record With History',
            'short_name' => 'Residency Record With History',
            'icon' => 'bx-file',
            'is_system' => false,
        ]);
        CivilRecord::factory()->create([
            'doc_type' => DocumentType::Custom->value,
            'document_type_id' => $type->getKey(),
            'created_by' => $staff->getKey(),
        ]);

        $this->actingAs($superAdmin)
            ->delete(route('templates.document-types.destroy', $type))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('document_types', ['id' => $type->getKey()]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'document-type.deleted']);
    }

    public function test_builtin_document_types_cannot_be_deleted(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $birth = DocumentTypeDefinition::where('key', DocumentType::Birth->value)->firstOrFail();

        $this->actingAs($superAdmin)
            ->delete(route('templates.document-types.destroy', $birth))
            ->assertForbidden();

        $this->assertDatabaseHas('document_types', ['id' => $birth->getKey()]);
    }

    public function test_only_super_admin_can_manage_custom_document_types(): void
    {
        foreach ([User::factory()->staff()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->post(route('templates.document-types.store'), [
                'document_type_name' => 'Unauthorized custom type',
            ])->assertForbidden();
        }

        $this->assertDatabaseMissing('document_types', ['name' => 'Unauthorized custom type']);
    }

    public function test_custom_document_type_layout_can_be_published_for_staff(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $type = DocumentTypeDefinition::create([
            'key' => 'custom-residency-test',
            'name' => 'Residency Certificate',
            'short_name' => 'Residency Certificate',
            'icon' => 'bx-file-blank',
            'is_system' => false,
        ]);

        $this->actingAs($superAdmin)->post(route('templates.store'), [
            'name' => 'Residency layout',
            'document_type_id' => $type->getKey(),
            'doc_type' => $type->key,
            ...$this->paperSpec(),
            'publish' => '1',
            'fields' => [$this->field('Resident Full Name')],
        ])->assertRedirect();

        $template = DocumentTemplate::where('name', 'Residency layout')->firstOrFail();
        $this->assertSame(DocumentType::Custom, $template->doc_type);
        $this->assertSame($type->getKey(), $template->document_type_id);
        $this->assertTrue($template->is_active);

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('documents.create'))
            ->assertOk()
            ->assertSeeText('Residency Certificate')
            ->assertSee(route('documents.workspace', ['type' => $type->key]), escape: false);

        $this->get(route('documents.workspace', ['type' => $type->key]))
            ->assertOk()
            ->assertSeeText('Scan: Residency Certificate')
            ->assertSee('name="doc_type" value="'.$type->key.'"', escape: false);
    }

    private function template(
        User $creator,
        DocumentType $type,
        string $name,
        bool $active = false,
    ): DocumentTemplate {
        $template = DocumentTemplate::create([
            'name' => $name,
            'doc_type' => $type->value,
            ...$this->paperSpec(),
            'is_active' => $active,
            'created_by' => $creator->getKey(),
        ]);

        $template->fields()->create([
            ...$this->field('Full Name'),
            'sort_order' => 0,
            'is_required' => true,
        ]);

        return $template;
    }

    /**
     * @return array{paper_size: string, orientation: string}
     */
    private function paperSpec(
        PaperSize $paperSize = PaperSize::Letter,
        PageOrientation $orientation = PageOrientation::Portrait,
    ): array {
        return [
            'paper_size' => $paperSize->value,
            'orientation' => $orientation->value,
        ];
    }

    /**
     * @return array{name: string, x: float, y: float, width: float, height: float}
     */
    private function field(string $name): array
    {
        return [
            'name' => $name,
            'x' => 0.1,
            'y' => 0.2,
            'width' => 0.3,
            'height' => 0.1,
        ];
    }
}
