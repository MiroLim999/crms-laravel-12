<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentTemplateBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_a_template_with_its_fields(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post(route('templates.store'), [
            'name' => 'Birth Certificate 2026',
            'doc_type' => DocumentType::Birth->value,
            'description' => 'Current form revision.',
            'fields' => [$this->field('Child Full Name')],
        ])->assertRedirect();

        $template = DocumentTemplate::where('name', 'Birth Certificate 2026')->firstOrFail();

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
                'fields' => [$this->field('Custom Registry Field')],
            ]);

        $response->assertRedirect($createUrl)->assertSessionHasErrors('name');

        $this->get($createUrl)
            ->assertOk()
            ->assertSee('Custom Registry Field');
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
