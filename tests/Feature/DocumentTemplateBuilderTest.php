<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\PageOrientation;
use App\Enums\PaperSize;
use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->get(route('templates.create', ['type' => DocumentType::Birth->value]))
            ->assertOk()
            ->assertSee('id="docViewport"', escape: false)
            ->assertSee('id="fieldOverlay"', escape: false)
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

    public function test_published_template_cannot_be_deleted(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $template = $this->template($superAdmin, DocumentType::Birth, 'Published layout', active: true);

        $this->actingAs($superAdmin)
            ->delete(route('templates.destroy', $template))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('document_templates', ['id' => $template->getKey()]);
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
