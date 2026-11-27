<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\BirthRecord;
use App\Models\CivilRecord;
use App\Models\DeathRecord;
use App\Models\DocumentTemplate;
use App\Models\MarriageRecord;
use App\Models\OcrModel;
use App\Models\User;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_identify_reference(): void
    {
        User::factory()->create(); // with no account at all, Set up CRMS comes first

        $this->getJson(route('documents.identify-reference', [
            'doc_type' => 'birth',
            'book_no' => '7',
            'page_no' => '83',
        ]))->assertUnauthorized();
    }

    public function test_identify_reference_validates_required_parameters(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->getJson(route('documents.identify-reference', []))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['doc_type', 'book_no', 'page_no']);
    }

    public function test_identify_reference_identifies_birth_record(): void
    {
        $user = User::factory()->superAdmin()->create();

        BirthRecord::create([
            'book_no' => '7',
            'page_no' => '83',
            'reg_no' => '1978-001',
            'name' => 'Ramonito T. Abad',
            'sex' => 'M',
            'date_of_birth' => '1978-01-01',
            'date_of_reg' => '1978-01-05',
            'place_of_birth' => 'Maasin City',
            'name_of_father' => 'Tomas F. Abad',
            'name_of_mother' => 'Lucia M. Abad',
        ]);

        $response = $this->actingAs($user)->getJson(route('documents.identify-reference', [
            'doc_type' => 'birth',
            'book_no' => '7',
            'page_no' => '83',
        ]))->assertOk();

        $response->assertJson([
            'identified' => true,
            'book_no' => '7',
            'page_no' => '83',
            'table' => 'birth_records',
            'count' => 1,
        ]);

        $this->assertSame('Ramonito T. Abad', $response->json('entries.0.label'));
    }

    public function test_identify_reference_identifies_death_record(): void
    {
        $user = User::factory()->superAdmin()->create();

        DeathRecord::create([
            'book_no' => '4',
            'page_no' => '12',
            'reg_no' => '1985-050',
            'name' => 'Juan Dela Cruz',
            'sex' => 'M',
            'date_of_death' => '1985-06-15',
            'place_of_death' => 'Maasin City',
        ]);

        $response = $this->actingAs($user)->getJson(route('documents.identify-reference', [
            'doc_type' => 'death',
            'book_no' => '4',
            'page_no' => '12',
        ]))->assertOk();

        $response->assertJson([
            'identified' => true,
            'book_no' => '4',
            'page_no' => '12',
            'table' => 'death_records',
            'count' => 1,
        ]);

        $this->assertSame('Juan Dela Cruz', $response->json('entries.0.label'));
    }

    public function test_identify_reference_identifies_marriage_record(): void
    {
        $user = User::factory()->superAdmin()->create();

        MarriageRecord::create([
            'book_no' => '2',
            'page_no' => '45',
            'reg_no' => '1990-100',
            'husband_name' => 'Pedro Penduko',
            'wife_name' => 'Maria Clara',
            'date_of_marriage' => '1990-12-25',
            'date_of_reg' => '1990-12-28',
            'place_of_marriage' => 'Maasin City',
        ]);

        $response = $this->actingAs($user)->getJson(route('documents.identify-reference', [
            'doc_type' => 'marriage',
            'book_no' => '2',
            'page_no' => '45',
        ]))->assertOk();

        $response->assertJson([
            'identified' => true,
            'book_no' => '2',
            'page_no' => '45',
            'table' => 'marriage_records',
            'count' => 1,
        ]);

        $this->assertSame('Pedro Penduko & Maria Clara', $response->json('entries.0.label'));
    }

    public function test_identify_reference_reports_new_document_when_not_found(): void
    {
        $user = User::factory()->superAdmin()->create();

        $response = $this->actingAs($user)->getJson(route('documents.identify-reference', [
            'doc_type' => 'birth',
            'book_no' => '999',
            'page_no' => '999',
        ]))->assertOk();

        $response->assertJson([
            'identified' => false,
            'book_no' => '999',
            'page_no' => '999',
            'table' => 'birth_records',
            'count' => 0,
            'entries' => [],
            'saved_record' => null,
        ]);

        $this->assertStringContainsString('New document', $response->json('message'));
    }

    public function test_workspace_view_receives_existing_books_list(): void
    {
        $this->seed(DocumentTemplateSeeder::class);
        $user = User::factory()->superAdmin()->create();

        BirthRecord::create([
            'book_no' => '15',
            'page_no' => '1',
            'reg_no' => '1999-001',
            'name' => 'Test Baby',
            'sex' => 'F',
            'date_of_birth' => '1999-05-10',
            'date_of_reg' => '1999-05-15',
            'place_of_birth' => 'Maasin City',
            'name_of_father' => 'Father',
            'name_of_mother' => 'Mother',
        ]);

        $response = $this->actingAs($user)
            ->get(route('documents.workspace', ['type' => 'birth']))
            ->assertOk();

        $response->assertSee('id="bookNumberInput"', escape: false)
            ->assertSee('id="pageNumberInput"', escape: false)
            ->assertSee('id="existingBooksList"', escape: false)
            ->assertSee('value="15"', escape: false);
    }

    public function test_store_document_requires_book_no_and_page_no_when_provided_empty(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);
        $this->registerTestModel();

        $user = User::factory()->superAdmin()->create();
        $template = DocumentTemplate::activeFor(DocumentType::Birth);

        $payload = [
            'doc_type' => $template->doc_type->value,
            'document_template_id' => $template->getKey(),
            'ocr_model_key' => 'test-model',
            'scan' => UploadedFile::fake()->image('certificate.png', 600, 800),
            'book_no' => '',
            'page_no' => '',
            'allow_missing' => '1',
            'fields' => [[
                'verified' => '1',
                'name' => 'Child Full Name',
                'ocr_text' => 'Maria Santos',
                'ocr_confidence' => 95.0,
                'verified_value' => 'Maria Santos',
                'x' => 0.1,
                'y' => 0.1,
                'width' => 0.3,
                'height' => 0.05,
            ]],
        ];

        $this->actingAs($user)
            ->postJson(route('documents.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['book_no', 'page_no']);
    }

    public function test_store_document_persists_book_no_and_page_no_on_civil_record(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);
        $this->registerTestModel();

        $user = User::factory()->superAdmin()->create();
        $template = DocumentTemplate::activeFor(DocumentType::Birth);

        $birthRecord = BirthRecord::create([
            'book_no' => '7',
            'page_no' => '83',
            'reg_no' => '1978-001',
            'name' => 'Maria Santos',
            'sex' => 'F',
            'date_of_birth' => '1978-01-01',
            'date_of_reg' => '1978-01-05',
            'place_of_birth' => 'Maasin City',
            'name_of_father' => 'Tomas Santos',
            'name_of_mother' => 'Lucia Santos',
        ]);

        $payload = [
            'doc_type' => $template->doc_type->value,
            'document_template_id' => $template->getKey(),
            'ocr_model_key' => 'test-model',
            'scan' => UploadedFile::fake()->image('certificate.png', 600, 800),
            'book_no' => '7',
            'page_no' => '83',
            'allow_missing' => '1',
            'fields' => [[
                'verified' => '1',
                'name' => 'Child Full Name',
                'ocr_text' => 'Maria Santos',
                'ocr_confidence' => 95.0,
                'verified_value' => 'Maria Santos',
                'x' => 0.1,
                'y' => 0.1,
                'width' => 0.3,
                'height' => 0.05,
            ]],
        ];

        $response = $this->actingAs($user)
            ->postJson(route('documents.store'), $payload)
            ->assertCreated();

        $this->assertDatabaseCount('records', 1);

        // Verify identifyReference locates the BirthRecord by book_no and page_no
        $identifyResponse = $this->actingAs($user)->getJson(route('documents.identify-reference', [
            'doc_type' => 'birth',
            'book_no' => '7',
            'page_no' => '83',
        ]))->assertOk();

        $this->assertTrue($identifyResponse->json('identified'));
        $this->assertSame('birth_records', $identifyResponse->json('table'));
        $this->assertSame('Maria Santos', $identifyResponse->json('entries.0.label'));
    }

    private function registerTestModel(): void
    {
        OcrModel::firstOrCreate(
            ['key' => 'test-model'],
            ['label' => 'Test model']
        );
    }
}
