<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\AuditLog;
use App\Models\CivilRecord;
use App\Models\RecordField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Review & Export for a saved record: its people as a table, edited in place.
 * Corrections are gone; every old and new value goes to the audit log.
 */
class RecordReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_record_page_offers_review_and_export_instead_of_a_correction(): void
    {
        $record = $this->record();

        $this->actingAs(User::factory()->create())
            ->get(route('records.show', $record))
            ->assertOk()
            ->assertSee('Edit in Review &amp; Export', false)
            ->assertSee(route('records.review', $record))
            ->assertDontSee('Correct record');
    }

    public function test_the_page_draws_one_row_per_person_from_both_pages(): void
    {
        $record = $this->record();
        $name = $this->field($record, 1, 1, 'Name · row 1', 'Ana Cruz');
        $father = $this->field($record, 2, 1, 'Father · row 1', 'Pedro Cruz');
        $this->field($record, 1, 2, 'Name · row 2', 'Ben Reyes');

        $review = $this->actingAs(User::factory()->create())
            ->get(route('records.review', $record))
            ->assertOk()
            ->assertSee('Records table')
            ->assertSee('Save changes')
            ->viewData('review');

        $this->assertSame(['person-1', 'person-2'], array_column($review['people'], 'id'));
        $this->assertSame(
            [['side' => 0, 'column' => 'Name', 'ref' => $name->getKey()], ['side' => 0, 'column' => 'Father', 'ref' => $father->getKey()]],
            // Page 2 is only a side when the record has a page 2; this one keeps both on the first.
            $review['people'][0]['fields'],
        );
        $this->assertSame('Ana Cruz', $review['values'][$name->getKey()]);
    }

    public function test_saving_changes_only_what_changed_and_logs_old_and_new(): void
    {
        $record = $this->record();
        $name = $this->field($record, 1, 1, 'Name · row 1', 'Mana Santos');
        $sex = $this->field($record, 1, 1, 'Sex · row 1', 'F');
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson(route('records.review.update', $record), [
            'changes' => [
                ['id' => $name->getKey(), 'value' => ' Maria Santos '],
                // Not changed: not saved, not logged.
                ['id' => $sex->getKey(), 'value' => 'F'],
            ],
        ])->assertOk()->assertJson(['saved' => 1, 'redirect' => route('records.show', $record)]);

        $this->assertSame('Maria Santos', $name->fresh()->verified_value);
        $this->assertSame('Mana Santos', $name->fresh()->ocr_text);

        $audit = AuditLog::where('action', 'record.edited')->sole();
        $this->assertSame($record->getKey(), (int) $audit->auditable_id);
        $this->assertSame(['name' => 'Name · row 1', 'value' => 'Mana Santos'], $audit->old_values['fields'][$name->getKey()]);
        $this->assertSame(['name' => 'Name · row 1', 'value' => 'Maria Santos'], $audit->new_values['fields'][$name->getKey()]);
        $this->assertArrayNotHasKey($sex->getKey(), $audit->new_values['fields']);

        // The record page shows the edit and its history.
        $this->actingAs($user)->get(route('records.show', $record))
            ->assertOk()
            ->assertSee('Edit history')
            ->assertSee('Edited ');
    }

    public function test_nothing_changed_logs_nothing(): void
    {
        $record = $this->record();
        $name = $this->field($record, 1, 1, 'Name · row 1', 'Ana');

        $this->actingAs(User::factory()->create())
            ->patchJson(route('records.review.update', $record), ['changes' => [['id' => $name->getKey(), 'value' => 'Ana']]])
            ->assertOk()
            ->assertJson(['saved' => 0]);

        $this->assertSame(0, AuditLog::where('action', 'record.edited')->count());
    }

    public function test_a_cell_where_nothing_was_saved_becomes_a_field_of_its_person(): void
    {
        $record = $this->record();
        $this->field($record, 1, 3, 'Name · row 3', 'Ana', order: 0);

        $this->actingAs(User::factory()->create())->patchJson(route('records.review.update', $record), [
            'additions' => [['page_no' => 1, 'person_group' => 3, 'column' => 'Sex', 'value' => 'F']],
        ])->assertOk()->assertJson(['saved' => 1]);

        $added = $record->fields()->where('name', 'Sex · row 3')->sole();
        $this->assertSame('F', $added->verified_value);
        $this->assertSame(3, $added->person_group);
        $this->assertSame(1, $added->person_field_order);
        $this->assertSame(1, $added->page_no);
        $this->assertNull($added->ocr_text);
        $this->assertNull($added->x);
        $this->assertSame('Sex', $added->columnName());
    }

    public function test_a_field_of_another_record_or_a_missing_page_is_refused(): void
    {
        $record = $this->record();
        $other = $this->field($this->record(), 1, 1, 'Name · row 1', 'Other');

        $this->actingAs(User::factory()->create())->patchJson(route('records.review.update', $record), [
            'changes' => [['id' => $other->getKey(), 'value' => 'Changed']],
            'additions' => [['page_no' => 2, 'person_group' => 1, 'column' => 'Sex', 'value' => 'F']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['changes.0.id', 'additions.0.page_no']);

        $this->assertSame('Other', $other->fresh()->verified_value);
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $record = $this->record();

        $this->get(route('records.review', $record))->assertRedirect(route('login'));
        $this->patchJson(route('records.review.update', $record), [])->assertUnauthorized();
    }

    private function record(): CivilRecord
    {
        $user = User::factory()->create();

        return CivilRecord::factory()->ofType(DocumentType::Birth)->submitted($user)->create(['created_by' => $user->getKey()]);
    }

    private function field(CivilRecord $record, int $page, ?int $person, string $name, string $value, int $order = 0): RecordField
    {
        static $sort = 0;

        return RecordField::factory()->for($record, 'record')->create([
            'page_no' => $page,
            'name' => $name,
            'ocr_text' => $value,
            'verified_value' => $value,
            'person_group' => $person,
            'person_field_order' => $person === null ? null : $order,
            'sort_order' => $sort++,
        ]);
    }
}
