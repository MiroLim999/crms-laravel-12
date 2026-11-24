<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\AuditLog;
use App\Models\CivilRecord;
use App\Models\RecordField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A record's Download CSV (docs/SUPER-ADMIN-ONLY-PLAN.md, E1): one file per
 * document, one row per person, both pages side by side, checked values.
 */
class RecordCsvExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_one_page_ledger_gives_one_row_per_person(): void
    {
        $record = $this->record();
        $this->field($record, 1, null, 'Page No.', '12', order: 0);
        foreach ([1 => ['Ana Cruz', 'F'], 2 => ['Ben Reyes', 'M']] as $row => [$name, $sex]) {
            $this->field($record, 1, $row, 'Name', $name, column: 'Name', order: 0);
            $this->field($record, 1, $row, 'Sex', $sex, column: 'Sex', order: 1);
        }

        [$header, $rows] = $this->csv($this->download($record));

        $this->assertSame(['Record ID', 'Document type', 'Row', 'Page No.', 'Name', 'Sex'], $header);
        $this->assertSame([
            [(string) $record->getKey(), 'BOOK OF BIRTH', '1', '12', 'ANA CRUZ', 'F'],
            [(string) $record->getKey(), 'BOOK OF BIRTH', '2', '12', 'BEN REYES', 'M'],
        ], $rows);
    }

    public function test_a_two_page_record_puts_each_persons_two_pages_on_one_row(): void
    {
        $record = $this->record();
        // Page 1: rows 1 and 2, people 1 and 2. Page 2 continues the person
        // numbers (3 and 4) but its own rows start at 1 again.
        foreach ([1 => 'Ana Cruz', 2 => 'Ben Reyes'] as $row => $name) {
            $this->field($record, 1, $row, "Name · row {$row}", $name, column: 'Name', lineRow: $row);
            $this->field($record, 1, $row, "Remarks · row {$row}", "p1 note {$row}", column: 'Remarks', lineRow: $row, order: 1);
        }
        foreach ([1 => 'Pedro Cruz', 2 => 'Jose Reyes'] as $row => $father) {
            $this->field($record, 2, $row + 2, 'Father · row '.($row + 20), $father, column: 'Father', lineRow: $row);
            $this->field($record, 2, $row + 2, 'Remarks · row '.($row + 20), "p2 note {$row}", column: 'Remarks', lineRow: $row, order: 1);
        }

        [$header, $rows] = $this->csv($this->download($record));

        $this->assertSame(['Record ID', 'Document type', 'Row', 'Name', 'Remarks', 'Father', 'Remarks (page 2)'], $header);
        $this->assertCount(2, $rows);
        $this->assertSame(['1', 'ANA CRUZ', 'P1 NOTE 1', 'PEDRO CRUZ', 'P2 NOTE 1'], array_slice($rows[0], 2));
        $this->assertSame(['2', 'BEN REYES', 'P1 NOTE 2', 'JOSE REYES', 'P2 NOTE 2'], array_slice($rows[1], 2));
    }

    public function test_accented_names_survive_and_excel_gets_a_byte_order_mark(): void
    {
        $record = $this->record();
        $this->field($record, 1, 1, 'Name', 'Santo Niño Peñaflor', column: 'Name');

        $response = $this->download($record);
        $body = $response->streamedContent();

        $this->assertStringStartsWith("\u{FEFF}", $body);
        $this->assertStringContainsString('SANTO NIÑO PEÑAFLOR', $body);
        $this->assertStringContainsString('charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Book of Birth - Record '.$record->getKey().'.csv', $response->headers->get('Content-Disposition'));
    }

    public function test_a_value_that_looks_like_a_formula_is_kept_as_text(): void
    {
        $record = $this->record();
        $this->field($record, 1, 1, 'Name', '=HYPERLINK("http://x","click")', column: 'Name');
        $this->field($record, 1, 1, 'Note', '-5', column: 'Note', order: 1);

        [, $rows] = $this->csv($this->download($record));

        $this->assertSame("'=HYPERLINK(\"HTTP://X\",\"CLICK\")", $rows[0][3]);
        $this->assertSame("'-5", $rows[0][4]);
    }

    public function test_edits_after_saving_are_in_the_file(): void
    {
        $user = User::factory()->superAdmin()->create();
        $record = $this->record($user);
        $field = $this->field($record, 1, 1, 'Name', 'Mana Santos', column: 'Name');

        $this->actingAs($user)->patchJson(route('records.review.update', $record), ['changes' => [
            ['id' => $field->getKey(), 'value' => 'Maria Santos'],
        ]])->assertOk();

        [, $rows] = $this->csv($this->download($record->fresh(), $user));

        $this->assertSame('MARIA SANTOS', $rows[0][3]);
    }

    public function test_a_certificate_without_people_is_one_row(): void
    {
        $record = $this->record();
        $this->field($record, 1, null, 'Child Full Name', 'Ana Cruz', order: 0);
        $this->field($record, 1, null, 'Date of Birth', '1 Jan 2001', order: 1);

        [$header, $rows] = $this->csv($this->download($record));

        $this->assertSame(['Record ID', 'Document type', 'Row', 'Child Full Name', 'Date of Birth'], $header);
        $this->assertSame([[(string) $record->getKey(), 'BOOK OF BIRTH', '1', 'ANA CRUZ', '1 JAN 2001']], $rows);
    }

    public function test_the_download_is_in_the_audit_log(): void
    {
        $user = User::factory()->create();
        $record = $this->record($user);
        $this->field($record, 1, 1, 'Name', 'Ana Cruz', column: 'Name');

        $this->download($record, $user);

        $entry = AuditLog::where('action', 'record.exported')->firstOrFail();
        $this->assertSame($user->getKey(), $entry->user_id);
        $this->assertSame(CivilRecord::class, $entry->auditable_type);
        $this->assertSame($record->getKey(), $entry->auditable_id);
        $this->assertSame(['rows' => 1], $entry->new_values);
    }

    public function test_a_guest_cannot_download(): void
    {
        $record = $this->record();

        $this->get(route('records.csv', $record))->assertRedirect(route('login'));
    }

    public function test_a_register_record_downloads_its_register_columns_in_capitals_with_a_readable_name(): void
    {
        $record = $this->record();
        \App\Models\BirthRecord::create([
            'civil_record_id' => $record->getKey(), 'book_no' => '12', 'page_no' => '23', 'reg_no' => '5',
            'name' => 'Ana Cruz', 'sex' => 'Female', 'late_registration' => false,
        ]);

        $response = $this->download($record);
        [$header, $rows] = $this->csv($response);

        $this->assertSame(\App\Support\RegistryFields::keys('birth'), $header);
        $row = array_combine($header, $rows[0]);
        $this->assertSame('ANA CRUZ', $row['name']);
        $this->assertSame('FEMALE', $row['sex']);
        $this->assertSame('FALSE', $row['late_registration']);
        $this->assertStringContainsString(
            'Book of Birth - Book 12 Page 23 - Record '.$record->getKey().'.csv',
            $response->headers->get('Content-Disposition'),
        );
    }

    private function record(?User $user = null): CivilRecord
    {
        $user ??= User::factory()->create();

        return CivilRecord::factory()->ofType(DocumentType::Birth)->submitted($user)->create(['created_by' => $user->getKey()]);
    }

    private function field(
        CivilRecord $record,
        int $page,
        ?int $person,
        string $name,
        string $value,
        ?string $column = null,
        ?int $lineRow = null,
        int $order = 0,
    ): RecordField {
        static $sort = 0;

        return RecordField::factory()->for($record, 'record')->create([
            'page_no' => $page,
            'name' => $name,
            'ocr_text' => $value,
            'verified_value' => $value,
            'person_group' => $person,
            'person_field_order' => $person === null ? null : $order,
            'line_column' => $column,
            'line_row' => $lineRow,
            'sort_order' => $sort++,
        ]);
    }

    private function download(CivilRecord $record, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? User::factory()->create())
            ->get(route('records.csv', $record))
            ->assertOk();
    }

    /**
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function csv(TestResponse $response): array
    {
        $body = preg_replace('/^\x{FEFF}/u', '', $response->streamedContent());
        $lines = array_values(array_filter(preg_split('/\r?\n/', $body), fn ($line) => $line !== ''));
        $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), $lines);

        return [array_shift($rows), $rows];
    }
}
