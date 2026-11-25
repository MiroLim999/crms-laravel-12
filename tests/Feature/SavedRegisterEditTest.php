<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\AuditLog;
use App\Models\BirthRecord;
use App\Models\CivilRecord;
use App\Models\DocumentTemplate;
use App\Models\OcrModel;
use App\Models\User;
use App\Services\RecordCsv;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A saved record with rows in its register table (birth_records, …): its
 * Review & Export shows those rows, and a change saved there reaches them, so
 * the record's and the book's downloads hold it. Also the book download's
 * page order and formula guard.
 */
class SavedRegisterEditTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);
        OcrModel::firstOrCreate(['key' => 'test-model'], ['label' => 'Test model']);
        $this->user = User::factory()->superAdmin()->create();
    }

    public function test_the_saved_records_review_page_holds_the_register_rows(): void
    {
        $record = $this->savedRecord();

        $review = $this->actingAs($this->user)->get(route('records.review', $record))->assertOk()->viewData('review');

        $this->assertSame('birth', $review['registry']['type']);
        $this->assertCount(2, $review['registerRows']);
        $this->assertSame('Ana Crus', $review['registerRows'][0]['name']);
        $this->assertSame('MIDWIFE', $review['registerRows'][0]['attendant']);
        $this->assertSame('false', $review['registerRows'][0]['late_registration']);
    }

    public function test_a_change_saved_on_a_saved_record_reaches_its_download(): void
    {
        $record = $this->savedRecord();

        $this->actingAs($this->user)
            ->patchJson(route('records.review.update', $record), [
                'registry_rows' => [
                    $this->row('5', 'Ana Cruz', ['attendant' => 'NURSE']),
                    $this->row('6', 'Ben Santos'),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('saved', 2);

        $ana = BirthRecord::where('reg_no', '5')->sole();
        $this->assertSame('Ana Cruz', $ana->name);
        $this->assertSame('NURSE', $ana->attendant);
        $this->assertSame($record->getKey(), $ana->civil_record_id);
        $this->assertSame(2, BirthRecord::where('civil_record_id', $record->getKey())->count());

        // The record's download (and so the book's) has the fix, rows in their order.
        $table = app(RecordCsv::class)->table($record->fresh());
        $name = array_search('name', $table['header'], true);
        $this->assertSame(['ANA CRUZ', 'BEN SANTOS'], array_column($table['rows'], $name));

        $log = AuditLog::where('action', 'record.edited')->latest('id')->firstOrFail();
        $this->assertSame('Ana Crus', $log->old_values['register']['05']['name']);
        $this->assertSame('Ana Cruz', $log->new_values['register']['05']['name']);
    }

    public function test_two_rows_can_swap_register_numbers(): void
    {
        $record = $this->savedRecord();

        $this->actingAs($this->user)
            ->patchJson(route('records.review.update', $record), [
                'registry_rows' => [$this->row('6', 'Ana Crus'), $this->row('5', 'Ben Santos')],
            ])
            ->assertOk();

        $this->assertSame('Ana Crus', BirthRecord::where('reg_no', '6')->sole()->name);
        $this->assertSame('Ben Santos', BirthRecord::where('reg_no', '5')->sole()->name);
    }

    public function test_a_wrong_value_or_another_records_page_saves_nothing(): void
    {
        $record = $this->savedRecord();
        BirthRecord::create(['book_no' => '7', 'page_no' => '84', 'reg_no' => '1', 'name' => 'Other page']);

        $this->actingAs($this->user)
            ->patchJson(route('records.review.update', $record), [
                'registry_rows' => [$this->row('5', 'Ana Cruz', ['mother_age' => 'twenty']), $this->row('6', 'Ben Santos')],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.registry_rows.0', 'Row 05: mother_age must be plain digits.');

        $this->actingAs($this->user)
            ->patchJson(route('records.review.update', $record), [
                'registry_rows' => [$this->row('5', 'Ana Cruz', ['page_no' => '84']), $this->row('6', 'Ben Santos')],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('registry_rows');

        // A row left out would be lost: refused too.
        $this->actingAs($this->user)
            ->patchJson(route('records.review.update', $record), ['registry_rows' => [$this->row('5', 'Ana Cruz')]])
            ->assertStatus(422);

        $this->assertSame('Ana Crus', BirthRecord::where('reg_no', '5')->where('page_no', '83')->sole()->name);
    }

    public function test_a_book_downloads_page_2_before_page_10_with_formulas_kept_as_text(): void
    {
        foreach ([['10', '1', 'Page ten'], ['2', '10', 'Page two, ten'], ['2', '9', '=HYPERLINK("x")']] as [$page, $reg, $name]) {
            BirthRecord::create(['book_no' => '7', 'page_no' => $page, 'reg_no' => $reg, 'name' => $name]);
        }

        $csv = $this->actingAs($this->user)
            ->get(route('records.books.download', ['type' => 'birth', 'book_no' => '7']))
            ->assertOk()
            ->streamedContent();

        $lines = array_slice(preg_split('/\R/', trim($csv)), 1);
        $this->assertStringStartsWith('"\'=HYPERLINK', $lines[0]);
        $this->assertStringStartsWith('"PAGE TWO, TEN"', $lines[1]);
        $this->assertStringStartsWith('"PAGE TEN"', $lines[2]);
    }

    public function test_the_book_filter_lists_a_types_books_in_number_order(): void
    {
        foreach (['10', '9', '2'] as $book) {
            BirthRecord::create(['book_no' => $book, 'page_no' => '1', 'reg_no' => '1', 'name' => 'Someone']);
        }

        // No type picked: no Book filter.
        $this->actingAs($this->user)->get(route('records.index'))->assertOk()
            ->assertViewHas('books', [])
            ->assertDontSee('id="book"', false);

        $this->actingAs($this->user)->get(route('records.index', ['type' => 'birth']))->assertOk()
            ->assertViewHas('books', ['2', '9', '10'])
            ->assertSee('id="book"', false);
    }

    public function test_a_picked_book_lists_its_records_and_downloads_as_that_book(): void
    {
        $record = $this->savedRecord();
        $other = CivilRecord::factory()->create(['doc_type' => DocumentType::Birth->value, 'document_type_id' => $record->document_type_id]);
        BirthRecord::create(['civil_record_id' => $other->getKey(), 'book_no' => '8', 'page_no' => '1', 'reg_no' => '1', 'name' => 'Book eight']);

        $response = $this->actingAs($this->user)
            ->get(route('records.index', ['type' => 'birth', 'book' => '7']))
            ->assertOk();

        $this->assertSame([$record->getKey()], $response->viewData('records')->pluck('id')->all());
        $response->assertSee('Download Book 7')
            ->assertSee(route('records.books.download', ['type' => 'birth', 'book_no' => '7', 'format' => 'xlsx']));

        // Without a book: every birth record, and the usual Download.
        $this->actingAs($this->user)->get(route('records.index', ['type' => 'birth']))->assertOk()
            ->assertSee('Download these 2')
            ->assertDontSee('Download Book');
    }

    /** A birth record saved through step 4 with two register rows (book 7, page 83). */
    private function savedRecord(): CivilRecord
    {
        $template = DocumentTemplate::activeFor(DocumentType::Birth);

        $this->actingAs($this->user)
            ->postJson(route('documents.store'), [
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
                    'ocr_text' => 'Ana Crus',
                    'ocr_confidence' => 95.0,
                    'verified_value' => 'Ana Crus',
                    'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05,
                ]],
                'registry_rows_json' => json_encode([
                    $this->row('5', 'Ana Crus', ['attendant' => 'MIDWIFE']),
                    $this->row('6', 'Ben Santos'),
                ]),
            ])
            ->assertCreated();

        return CivilRecord::query()->sole();
    }

    private function row(string $regNo, string $name, array $values = []): array
    {
        return ['row' => str_pad($regNo, 2, '0', STR_PAD_LEFT), 'values' => [
            'book_no' => '7', 'page_no' => '83', 'reg_no' => $regNo, 'name' => $name,
            'late_registration' => 'false',
            ...$values,
        ]];
    }
}
