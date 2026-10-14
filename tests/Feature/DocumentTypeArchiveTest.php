<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\DocumentTypeDefinition;
use App\Models\User;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentTypeArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_archived_type_is_hidden_from_staff_scanning_but_kept(): void
    {
        $this->seed(DocumentTemplateSeeder::class);
        $type = DocumentTypeDefinition::where('key', DocumentType::Birth->value)->firstOrFail();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('templates.document-types.archive', $type))
            ->assertRedirect(route('templates.index'));

        $this->assertTrue($type->fresh()->isArchived());
        $this->assertDatabaseHas('audit_logs', ['action' => 'document-type.archived']);

        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->get(route('documents.create'))
            ->assertOk()
            ->assertDontSee(route('documents.workspace', ['type' => $type->key]), escape: false);

        $this->actingAs($staff)
            ->get(route('documents.workspace', ['type' => $type->key]))
            ->assertRedirect(route('documents.create'));
    }

    public function test_restoring_a_type_makes_it_scannable_again(): void
    {
        $this->seed(DocumentTemplateSeeder::class);
        $type = DocumentTypeDefinition::where('key', DocumentType::Birth->value)->firstOrFail();
        $type->forceFill(['archived_at' => now()])->save();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('templates.document-types.restore', $type))
            ->assertRedirect();

        $this->assertFalse($type->fresh()->isArchived());

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('documents.workspace', ['type' => $type->key]))
            ->assertOk();
    }

    public function test_staff_cannot_archive_a_type(): void
    {
        $this->seed(DocumentTemplateSeeder::class);
        $type = DocumentTypeDefinition::where('key', DocumentType::Birth->value)->firstOrFail();

        $this->actingAs(User::factory()->staff()->create())
            ->post(route('templates.document-types.archive', $type))
            ->assertForbidden();

        $this->assertFalse($type->fresh()->isArchived());
    }

    public function test_template_builder_lists_archived_types_with_a_restore_action(): void
    {
        $this->seed(DocumentTemplateSeeder::class);
        $type = DocumentTypeDefinition::where('key', DocumentType::Birth->value)->firstOrFail();
        $type->forceFill(['archived_at' => now()])->save();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('templates.index'))
            ->assertOk()
            ->assertSee('Archived')
            ->assertSee('Restore for Staff');
    }
}
