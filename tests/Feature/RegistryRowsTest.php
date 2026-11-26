<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\AuditLog;
use App\Models\BirthRecord;
use App\Models\CivilRecord;
use App\Models\DocumentTemplate;
use App\Models\MarriageRecord;
use App\Models\OcrModel;
use App\Models\User;
use App\Support\RegistryFields;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Review & Export (step 4) saves each row of the table into the register's
 * own table (birth_records, death_records, marriage_records).
 */
class RegistryRowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);
        OcrModel::firstOrCreate(['key' => 'test-model'], ['label' => 'Test model']);
    }

    public function test_each_row_is_saved_into_the_register_table_linked_to_the_record(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postJson(route('documents.store'), $this->payload(DocumentType::Birth, [
                $this->birthRow('5', 'Ariel P. Villanujan', ['mother_age' => '27', 'attendant' => 'MIDWIFE']),
                $this->birthRow('6', 'Josefina L. Lim', ['late_registration' => 'true', 'date_of_reg' => 'Jan, 07,1978']),
            ]))
            ->assertCreated();

        $record = CivilRecord::query()->sole();
        $this->assertSame(2, BirthRecord::where('civil_record_id', $record->getKey())->count());

        $ariel = BirthRecord::where('reg_no', '5')->sole();
        $this->assertSame('7', $ariel->book_no);
        $this->assertSame(27, $ariel->mother_age);
        $this->assertSame('MIDWIFE', $ariel->attendant);
        $this->assertFalse($ariel->late_registration);
        // Left blank on the register: blank (null) in the table.
        $this->assertNull($ariel->father_age);
        $this->assertNull($ariel->place_of_birth);

        $josefina = BirthRecord::where('reg_no', '6')->sole();
        $this->assertTrue($josefina->late_registration);
        // Dates are kept as YYYY-MM-DD, however they were written.
        $this->assertSame('1978-01-07', $josefina->date_of_reg);
    }

    public function test_a_row_that_breaks_a_rule_saves_nothing_and_says_which_row(): void
    {
        $user = User::factory()->superAdmin()->create();

        $response = $this->actingAs($user)
            ->postJson(route('documents.store'), $this->payload(DocumentType::Birth, [
                $this->birthRow('', 'No Number'),
                $this->birthRow('7', 'Bad Age', ['mother_age' => '27 yrs', 'attendant' => 'DOCTOR']),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['registry_rows']);

        $messages = implode(' ', $response->json('errors.registry_rows'));
        $this->assertStringContainsString('Row 01: reg_no is required', $messages);
        $this->assertStringContainsString('Row 07: mother_age must be plain digits', $messages);
        $this->assertStringContainsString('Row 07: attendant must be one of', $messages);
        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseCount('birth_records', 0);
    }

    public function test_dates_are_saved_as_year_month_day_and_a_half_date_is_refused(): void
    {
        $user = User::factory()->superAdmin()->create();

        $response = $this->actingAs($user)
            ->postJson(route('documents.store'), $this->payload(DocumentType::Birth, [
                $this->birthRow('4', 'Half Date', ['date_of_reg' => '3-1957']),
            ]))
            ->assertUnprocessable();
        $this->assertStringContainsString('Row 04: date_of_reg is not a whole date (write it as YYYY-MM-DD)', implode(' ', $response->json('errors.registry_rows')));

        $this->actingAs($user)
            ->postJson(route('documents.store'), $this->payload(DocumentType::Birth, [
                $this->birthRow('4', 'Whole Dates', ['date_of_reg' => '3-11-57', 'date_of_birth' => 'Sept. 21, 1950', 'parents_marriage_date' => '1949-6-2']),
            ]))
            ->assertCreated();
        $row = BirthRecord::sole();
        $this->assertSame('1957-03-11', $row->date_of_reg);
        $this->assertSame('1950-09-21', $row->date_of_birth);
        $this->assertSame('1949-06-02', $row->parents_marriage_date);
    }

    public function test_two_rows_with_one_register_number_save_nothing(): void
    {
        $user = User::factory()->superAdmin()->create();
        $second = $this->birthRow('2', 'Second Child');
        $third = $this->birthRow('2', 'Third Child');
        $third['row'] = '03';

        $response = $this->actingAs($user)
            ->postJson(route('documents.store'), $this->payload(DocumentType::Birth, [$second, $third]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['registry_rows']);

        $this->assertStringContainsString(
            "Row 03: reg_no 2 is row 02's register number too.",
            implode(' ', $response->json('errors.registry_rows')),
        );
        // Neither replaced the other: nothing is saved.
        $this->assertDatabaseCount('birth_records', 0);
    }

    public function test_a_book_and_page_already_saved_is_not_saved_again(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postJson(route('documents.store'), $this->payload(DocumentType::Birth, [$this->birthRow('5', 'Ariel Villanujan')]))
            ->assertCreated();
        $recordId = CivilRecord::query()->sole()->getKey();

        // Even "Save again", and even with another register number: the page is saved.
        $this->actingAs($user)
            ->postJson(route('documents.store'), $this->payload(DocumentType::Birth, [$this->birthRow('6', 'Josefina Lim')], ['allow_duplicate' => '1']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page_no' => "Book 7, page 83 is already saved (Record #{$recordId})."]);

        $this->assertDatabaseCount('records', 1);
        $this->assertSame('Ariel Villanujan', BirthRecord::sole()->name);
    }

    public function test_book_and_page_numbers_are_whole_numbers(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postJson(route('documents.store'), [
                ...$this->payload(DocumentType::Birth, null),
                'book_no' => '7A',
                'page_no' => '8.3',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'book_no' => 'whole number',
                'page_no' => 'whole number',
            ]);

        $row = $this->birthRow('5', 'Ariel Villanujan');
        $row['values']['page_no'] = 'p83';
        $response = $this->actingAs($user)
            ->postJson(route('documents.store'), $this->payload(DocumentType::Birth, [$row]))
            ->assertUnprocessable();
        $this->assertStringContainsString('page_no must be plain digits', implode(' ', $response->json('errors.registry_rows')));
    }

    public function test_marriage_rows_need_both_names(): void
    {
        $user = User::factory()->superAdmin()->create();
        $row = static fn (array $values) => ['row' => '01', 'values' => [
            'book_no' => '3', 'page_no' => '12', 'reg_no' => '44', ...$values,
        ]];

        $this->actingAs($user)
            ->postJson(route('documents.store'), $this->payload(DocumentType::Marriage, [$row(['husband_name' => 'Juan Cruz'])]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['registry_rows']);

        $this->actingAs($user)
            ->postJson(route('documents.store'), $this->payload(DocumentType::Marriage, [$row([
                'husband_name' => 'Juan Cruz', 'wife_name' => 'Ana Reyes', 'ceremony_type' => 'CIVIL WEDDING',
            ])]))
            ->assertCreated();

        $this->assertSame('CIVIL WEDDING', MarriageRecord::sole()->ceremony_type);
    }

    public function test_a_save_without_rows_still_works_as_before(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postJson(route('documents.store'), $this->payload(DocumentType::Birth, null))
            ->assertCreated();

        $this->assertDatabaseCount('birth_records', 0);
    }

    public function test_the_column_choices_are_kept_on_the_layout_when_the_record_is_saved(): void
    {
        $user = User::factory()->superAdmin()->create();
        $template = DocumentTemplate::activeFor(DocumentType::Birth);
        $rows = [$this->birthRow('5', 'Ariel P. Villanujan')];

        // A choice that is not one of the register's fields: nothing is saved.
        $this->actingAs($user)
            ->postJson(route('documents.store'), $this->payload(DocumentType::Birth, $rows, [
                'field_maps_json' => json_encode([$template->getKey() => ['Column 3' => 'husband_name']]),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('field_maps');
        $this->assertNull($template->fresh()->field_map);
        $this->assertSame(0, CivilRecord::count());

        // Choices for a layout the record was not read with: refused too.
        $this->actingAs($user)
            ->postJson(route('documents.store'), $this->payload(DocumentType::Birth, $rows, [
                'field_maps_json' => json_encode([DocumentTemplate::activeFor(DocumentType::Death)->getKey() => ['Column 3' => 'name']]),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('field_maps');

        $this->actingAs($user)
            ->postJson(route('documents.store'), $this->payload(DocumentType::Birth, $rows, [
                'field_maps_json' => json_encode([$template->getKey() => ['Column 3' => 'name', 'Column 1' => 'reg_no', 'Column 9' => null]]),
            ]))
            ->assertCreated();

        // Lower-cased names; a column left to be typed by hand (null) is not kept.
        $this->assertEquals(['column 1' => 'reg_no', 'column 3' => 'name'], $template->fresh()->field_map);
        $log = AuditLog::where('action', 'template.field_map_changed')->sole();
        $this->assertSame([], $log->old_values['field_map']);
        $this->assertSame('name', $log->new_values['field_map']['column 3']);

        $this->actingAs($user)
            ->get(route('documents.workspace', ['type' => 'birth']))
            ->assertOk()
            ->assertSee('id="reviewFieldMap"', escape: false)
            ->assertViewHas('registry', fn ($registry) => $registry['type'] === 'birth'
                && array_column($registry['fields'], 'key') === RegistryFields::keys('birth'));
    }

    public function test_the_column_choices_are_kept_on_the_layout_as_soon_as_they_are_made(): void
    {
        $user = User::factory()->superAdmin()->create();
        $template = DocumentTemplate::activeFor(DocumentType::Birth);

        $this->actingAs($user)
            ->putJson(route('documents.field-maps'), [
                'doc_type' => 'birth',
                'field_maps_json' => json_encode([$template->getKey() => ['Age of Father' => 'father_age']]),
            ])
            ->assertOk()
            ->assertJson(['kept' => [$template->getKey()]]);
        $this->assertEquals(['age of father' => 'father_age'], $template->fresh()->field_map);
        $this->assertSame(1, AuditLog::where('action', 'template.field_map_changed')->count());

        // The same choices again: nothing changes, nothing is logged.
        $this->actingAs($user)
            ->putJson(route('documents.field-maps'), [
                'doc_type' => 'birth',
                'field_maps_json' => json_encode([$template->getKey() => ['Age of Father' => 'father_age']]),
            ])
            ->assertOk()
            ->assertJson(['kept' => []]);
        $this->assertSame(1, AuditLog::where('action', 'template.field_map_changed')->count());

        // A layout of another document type is refused.
        $this->actingAs($user)
            ->putJson(route('documents.field-maps'), [
                'doc_type' => 'birth',
                'field_maps_json' => json_encode([DocumentTemplate::activeFor(DocumentType::Death)->getKey() => ['Column 3' => 'name']]),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('field_maps');
    }

    public function test_a_book_downloads_as_excel_with_dates_kept_as_text(): void
    {
        $user = User::factory()->superAdmin()->create();
        BirthRecord::create([
            'book_no' => '7', 'page_no' => '83', 'reg_no' => '5', 'name' => 'Ariel P. Villanujan',
            'date_of_birth' => '1957-02-11', 'date_of_reg' => '1957-03-01',
        ]);

        $response = $this->actingAs($user)
            ->get(route('records.books.download', ['type' => 'birth', 'book_no' => '7', 'format' => 'xlsx']))
            ->assertOk()
            ->assertDownload();
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('content-disposition'));

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        // The sheet is well-formed XML, and a date is a text cell, not a date serial.
        $xml = simplexml_load_string($sheet);
        $this->assertNotFalse($xml);
        $this->assertStringContainsString('<t xml:space="preserve">1957-02-11</t>', $sheet);
        $this->assertStringContainsString('<t xml:space="preserve">1957-03-01</t>', $sheet);
        $this->assertStringContainsString('<t xml:space="preserve">ARIEL P. VILLANUJAN</t>', $sheet);
        $this->assertCount(2, $xml->sheetData->row); // the header and one entry
    }

    public function test_a_book_downloads_with_the_register_headers_in_order(): void
    {
        $user = User::factory()->superAdmin()->create();
        BirthRecord::create([
            'book_no' => '7', 'page_no' => '83', 'reg_no' => '5', 'name' => 'Ariel P. Villanujan',
            'mother_age' => 27, 'late_registration' => true,
        ]);

        $csv = $this->actingAs($user)
            ->get(route('records.books.download', ['type' => 'birth', 'book_no' => '7']))
            ->assertOk()
            ->streamedContent();

        $lines = array_map('str_getcsv', preg_split('/\R/', trim(ltrim($csv, "\xEF\xBB\xBF"))));
        $this->assertSame(RegistryFields::keys('birth'), $lines[0]);
        $row = array_combine($lines[0], $lines[1]);
        $this->assertSame('ARIEL P. VILLANUJAN', $row['name']);
        $this->assertSame('27', $row['mother_age']);
        $this->assertSame('TRUE', $row['late_registration']);
        $this->assertSame('', $row['father_age']);
        $this->assertSame('', $row['place_of_birth']);
    }

    /** A birth row as step 4 sends it. */
    private function birthRow(string $regNo, string $name, array $values = []): array
    {
        return ['row' => str_pad($regNo !== '' ? $regNo : '1', 2, '0', STR_PAD_LEFT), 'values' => [
            'book_no' => '7', 'page_no' => '83', 'reg_no' => $regNo, 'name' => $name,
            'late_registration' => 'false', ...$values,
        ]];
    }

    /** A save from step 4 (`rows` null: from Verify, without the register's rows). */
    private function payload(DocumentType $type, ?array $rows, array $extra = []): array
    {
        $template = DocumentTemplate::activeFor($type);

        return [
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
                'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05,
            ]],
            ...($rows !== null ? ['registry_rows_json' => json_encode($rows)] : []),
            ...$extra,
        ];
    }
}
