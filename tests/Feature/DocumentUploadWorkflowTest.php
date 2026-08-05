<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\User;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                ->assertSee('id="zoomResetBtn"', escape: false)
                ->assertSee('id="resetFieldsBtn"', escape: false)
                ->assertSee('id="deleteSelectedBtn"', escape: false)
                ->assertSee('id="selectAllFields"', escape: false)
                ->assertSee('id="deleteFieldsBtn"', escape: false)
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
                ->assertSee('Validate extracted fields');
        }
    }
}
