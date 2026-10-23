<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\CivilRecord;
use App\Models\DocumentTemplate;
use App\Models\OcrModel;
use App\Models\RecordField;
use App\Models\User;
use App\Services\RecordFieldGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What a field holds (the person's name, the entry number) and how Staff check
 * its value (text, date, number, one of a list; required or not). Set in the
 * Template Builder, kept with each record, and read instead of a layout the
 * code used to know by heart.
 */
class TemplateFieldSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->superAdmin()->create();
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Death register',
            'doc_type' => DocumentType::Death->value,
            'paper_size' => 'letter',
            'orientation' => 'landscape',
            'grouping_mode' => 'auto',
            'fields_json' => json_encode([[
                'name' => 'Registrar', 'x' => 0.6, 'y' => 0.05, 'width' => 0.2, 'height' => 0.05,
                'role' => null, 'required' => false, 'type' => 'text', 'hint' => 'Signature line',
            ]], JSON_THROW_ON_ERROR),
            'columns_json' => json_encode([
                ['name' => 'No.', 'box' => [0.05, 0.2, 0.05, 0.6], 'role' => 'entry', 'type' => 'number'],
                ['name' => 'Deceased', 'box' => [0.1, 0.2, 0.3, 0.6], 'role' => 'name'],
                ['name' => 'Sex', 'box' => [0.4, 0.2, 0.05, 0.6], 'type' => 'choice', 'options' => ['M', 'F', ' m ']],
                ['name' => 'Date of Death', 'box' => [0.45, 0.2, 0.15, 0.6], 'type' => 'date', 'hint' => 'Month day, year'],
                ['name' => 'Remarks', 'box' => [0.6, 0.2, 0.3, 0.6], 'required' => false],
            ], JSON_THROW_ON_ERROR),
            'ruled_ys_json' => json_encode([0.2, 0.35, 0.5, 0.65, 0.8], JSON_THROW_ON_ERROR),
            ...$overrides,
        ];
    }

    public function test_field_and_column_settings_are_saved_and_reopened(): void
    {
        $this->actingAs($this->admin)->post(route('templates.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $template = DocumentTemplate::firstOrFail();
        $this->assertSame(['role' => 'entry', 'type' => 'number'], array_diff_key($template->columns[0], ['name' => 1, 'box' => 1]));
        $this->assertSame('name', $template->columns[1]['role']);
        // Choices are trimmed and kept once, ignoring case.
        $this->assertSame(['M', 'F'], $template->columns[2]['options']);
        $this->assertSame('Month day, year', $template->columns[3]['hint']);
        $this->assertFalse($template->columns[4]['required']);
        // Defaults are left out of the stored column.
        $this->assertArrayNotHasKey('required', $template->columns[1]);

        $this->assertDatabaseHas('document_template_fields', [
            'name' => 'Registrar', 'is_required' => false, 'value_type' => 'text', 'hint' => 'Signature line', 'role' => null,
        ]);

        $this->actingAs($this->admin)->get(route('templates.edit', $template))
            ->assertOk()
            ->assertSee('"role":"name"', escape: false)
            ->assertSee('"hint":"Signature line"', escape: false);
    }

    public function test_a_choice_needs_its_choices(): void
    {
        $this->actingAs($this->admin)->post(route('templates.store'), $this->payload([
            'columns_json' => json_encode([
                ['name' => 'Sex', 'box' => [0.1, 0.2, 0.1, 0.6], 'type' => 'choice', 'options' => ['  ']],
            ], JSON_THROW_ON_ERROR),
        ]))->assertSessionHasErrors('columns.0.options');
    }

    public function test_a_row_has_one_name_and_one_entry_number(): void
    {
        $this->actingAs($this->admin)->post(route('templates.store'), $this->payload([
            'columns_json' => json_encode([
                ['name' => 'Deceased', 'box' => [0.1, 0.2, 0.3, 0.6], 'role' => 'name'],
                ['name' => 'Informant', 'box' => [0.4, 0.2, 0.3, 0.6], 'role' => 'name'],
            ], JSON_THROW_ON_ERROR),
        ]))->assertSessionHasErrors('columns.1.role');

        // Person rows drawn by hand: one name in each row is fine.
        $field = fn (string $name, int $group, int $order) => [
            'name' => $name, 'x' => 0.1 + 0.3 * $order, 'y' => 0.1 * $group, 'width' => 0.2, 'height' => 0.05,
            'person_group' => $group, 'person_field_order' => $order, 'role' => $order === 0 ? 'name' : null,
        ];
        $this->actingAs($this->admin)->post(route('templates.store'), $this->payload([
            'name' => 'Hand-drawn rows',
            'grouping_mode' => 'custom',
            'columns_json' => '[]',
            'ruled_ys_json' => '[]',
            'fields_json' => json_encode([
                $field('Person 1 name', 1, 0), $field('Person 1 age', 1, 1),
                $field('Person 2 name', 2, 0), $field('Person 2 age', 2, 1),
            ], JSON_THROW_ON_ERROR),
        ]))->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('templates.store'), $this->payload([
            'name' => 'Two names in one row',
            'grouping_mode' => 'custom',
            'columns_json' => '[]',
            'ruled_ys_json' => '[]',
            'fields_json' => json_encode([
                $field('Person 1 name', 1, 0), [...$field('Person 1 nickname', 1, 1), 'role' => 'name'],
            ], JSON_THROW_ON_ERROR),
        ]))->assertSessionHasErrors('fields.1.role');
    }

    public function test_staff_receive_the_settings_with_the_markers(): void
    {
        $this->actingAs($this->admin)->post(route('templates.store'), $this->payload(['publish' => 1]));

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('documents.workspace', ['type' => DocumentType::Death->value]))
            ->assertOk()
            ->assertSee('"role":"name"', escape: false)
            ->assertSee('"type":"choice","options":["M","F"]', escape: false)
            ->assertSee('"hint":"Month day, year"', escape: false)
            ->assertDontSee('birthRegistryColumns');
    }

    public function test_a_submitted_record_keeps_each_field_role_and_whether_it_was_required(): void
    {
        Storage::fake('local');
        OcrModel::create(['key' => 'test-model', 'label' => 'Test model']);
        $this->actingAs($this->admin)->post(route('templates.store'), $this->payload([
            'grouping_mode' => 'custom',
            'columns_json' => '[]',
            'ruled_ys_json' => '[]',
            'fields_json' => json_encode([
                ['name' => 'Deceased', 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05, 'role' => 'name'],
                ['name' => 'Remarks', 'x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.05, 'required' => false],
            ], JSON_THROW_ON_ERROR),
            'publish' => 1,
        ]));
        $template = DocumentTemplate::firstOrFail();

        $field = fn (string $name, string $value) => [
            'verified' => '1', 'name' => $name, 'ocr_text' => $value, 'ocr_confidence' => 90,
            'verified_value' => $value, 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05,
        ];
        $this->actingAs(User::factory()->staff()->create())
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.store'), [
                'doc_type' => DocumentType::Death->value,
                'document_template_id' => $template->getKey(),
                'ocr_model_key' => 'test-model',
                'scan' => UploadedFile::fake()->image('register.png', 800, 600),
                'fields' => [$field('Deceased', 'Juan Cruz'), $field('Remarks', 'None'), $field('Added by staff', 'x')],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('record_fields', ['name' => 'Deceased', 'role' => 'name', 'is_required' => true]);
        $this->assertDatabaseHas('record_fields', ['name' => 'Remarks', 'role' => null, 'is_required' => false]);
        $this->assertDatabaseHas('record_fields', ['name' => 'Added by staff', 'role' => null, 'is_required' => true]);
    }

    public function test_the_person_is_named_by_the_field_holding_the_name(): void
    {
        $record = CivilRecord::factory()->create(['doc_type' => DocumentType::Death->value]);
        $fields = collect([
            ['No.', '19', 'entry', 0.05],
            ['Deceased', 'Juan Cruz', 'name', 0.2],
            ['Remarks', 'Died at home of a long illness', null, 0.4],
        ])->map(fn (array $field, int $order) => RecordField::factory()->create([
            'record_id' => $record->getKey(), 'name' => $field[0], 'verified_value' => $field[1], 'role' => $field[2],
            'width' => $field[3], 'person_group' => 1, 'person_field_order' => $order, 'sort_order' => $order,
        ]));

        $grouper = app(RecordFieldGrouper::class);
        $groups = $grouper->groups($fields);

        // Not the widest value (Remarks): the one the layout says is the name.
        $this->assertSame('Juan Cruz', $groups[0]['identity']);
        $this->assertSame('19', $groups[0]['entry']);
        $this->assertStringContainsString('Entry 19', $grouper->heading($record, $groups));
    }

    public function test_records_from_before_roles_take_them_from_their_ledger_columns(): void
    {
        $template = DocumentTemplate::create([
            'name' => 'Old ledger', 'doc_type' => DocumentType::Death->value,
            'paper_size' => 'letter', 'orientation' => 'landscape',
            'columns' => [
                ['name' => 'No.', 'box' => [0.05, 0.2, 0.05, 0.6], 'role' => 'entry'],
                ['name' => 'Deceased', 'box' => [0.1, 0.2, 0.2, 0.6], 'role' => 'name'],
                ['name' => 'Remarks', 'box' => [0.3, 0.2, 0.6, 0.6]],
            ],
            'ruled_ys' => [0.2, 0.5, 0.8],
        ]);
        $record = CivilRecord::factory()->create(['document_template_id' => $template->getKey()]);
        $fields = collect(['7', 'Maria Santos', 'A very long remark that is the widest value'])
            ->map(fn (string $value, int $order) => RecordField::factory()->create([
                'record_id' => $record->getKey(), 'name' => "d copy {$order}", 'verified_value' => $value,
                'width' => [0.05, 0.1, 0.5][$order], 'person_group' => 1, 'person_field_order' => $order,
                'sort_order' => $order,
            ]));

        $groups = app(RecordFieldGrouper::class)->groups($fields, $template);
        $this->assertSame('Maria Santos', $groups[0]['identity']);
        $this->assertSame('7', $groups[0]['entry']);

        // Without the layout, the widest value is still the best guess.
        $this->assertSame('A very long remark that is the widest value', app(RecordFieldGrouper::class)->groups($fields)[0]['identity']);
    }
}
