<?php

namespace Tests\Feature;

use App\Models\CivilRecord;
use App\Models\RecordField;
use App\Models\RecordPage;
use App\Models\User;
use App\Services\RecordFieldGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A saved record's page: each person is named by their entry number and the
 * first value that reads like a name (on a register with no name column set,
 * not the widest value, which on page 2 is often the attendant), and the
 * record says which pages of its file it is.
 */
class RecordPersonNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_register_person_is_named_by_their_entry_and_name_not_the_widest_value(): void
    {
        $user = User::factory()->create();
        $record = CivilRecord::factory()->submitted($user)->create(['created_by' => $user->getKey()]);
        $record->forceFill(['source_name' => 'register.pdf', 'source_page' => 1, 'source_page_count' => 2])->save();
        RecordPage::create(['record_id' => $record->getKey(), 'page_no' => 2, 'source_page' => 2]);

        // Page 1: entry number, the child's name, a place; page 2: the attendant (the widest value).
        foreach ([
            [1, 0, 'Column 1', '1978-0001', 0.08],
            [1, 1, 'Column 2', 'Ramonte T. Abad', 0.15],
            [1, 2, 'Column 5', 'Poblacion, Maasin City', 0.2],
            [2, 3, 'Column 9 · page 2', 'Midwife Elena R. Lunas', 0.3],
        ] as [$page, $order, $name, $value, $width]) {
            RecordField::factory()->for($record, 'record')->create([
                'page_no' => $page, 'name' => $name, 'verified_value' => $value, 'ocr_text' => $value,
                'width' => $width, 'person_group' => 1, 'person_field_order' => $order, 'sort_order' => $order,
            ]);
        }

        $groups = app(RecordFieldGrouper::class)->groups($record->fields()->get(), $record->template);
        $person = collect($groups)->firstWhere('kind', 'person');
        $this->assertSame('Ramonte T. Abad', $person['identity']);
        $this->assertSame('1978-0001', $person['entry']);

        $this->actingAs($user)->get(route('records.show', $record))
            ->assertOk()
            ->assertSee('1978-0001 · Ramonte T. Abad')
            ->assertSee('Pages 1–2 of 2 · register.pdf')
            // Every saved value is verified: not repeated as "4/4 verified" on each person.
            ->assertDontSee('/4 verified')
            // One person: nothing to find among, so no find box.
            ->assertDontSee('Find a person or value', false);
    }
}
