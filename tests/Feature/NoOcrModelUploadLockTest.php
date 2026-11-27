<?php

namespace Tests\Feature;

use App\Models\OcrModel;
use App\Models\User;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * With no active OCR model, the scan workspace locks the upload: the drop area,
 * its file inputs and the upload mode choice.
 */
class NoOcrModelUploadLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_is_locked_when_no_model_is_active(): void
    {
        $this->seed(DocumentTemplateSeeder::class);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('documents.workspace', ['type' => 'birth']))
            ->assertOk()
            ->assertSee('Upload is locked: no OCR model is active.')
            ->assertSee('data-no-ocr-model aria-disabled="true"', escape: false)
            ->assertSee('id="dropzoneTitle">Activate an OCR model to upload<', escape: false)
            ->assertSee(route('ocr.index'), escape: false);
    }

    public function test_upload_is_open_when_a_model_is_active(): void
    {
        $this->seed(DocumentTemplateSeeder::class);
        OcrModel::create(['key' => 'trocr-base', 'label' => 'TrOCR base', 'is_active' => true]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('documents.workspace', ['type' => 'birth']))
            ->assertOk()
            ->assertDontSee('Upload is locked')
            ->assertDontSee('data-no-ocr-model aria-disabled="true"', escape: false)
            ->assertSee('id="dropzoneTitle">Drop the scan here<', escape: false);
    }
}
