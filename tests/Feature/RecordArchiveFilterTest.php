<?php

namespace Tests\Feature;

use App\Models\CivilRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The archive's filters come from the query string, so an edited URL can carry
 * anything. A bad filter has to give a message on the page, never an error page.
 */
class RecordArchiveFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_invalid_date_gives_a_message_instead_of_an_error_page(): void
    {
        $staff = User::factory()->staff()->create();
        $this->submittedAt('2026-09-15 12:00:00', $staff);

        $response = $this->actingAs($staff)
            ->get(route('records.index', ['from' => 'abc']))
            ->assertOk()
            ->assertSee('The from date field must be a valid date.')
            ->assertViewHas('errors', fn ($errors) => $errors->has('from'));

        // The bad filter is left out, so the list still shows every record.
        $this->assertSame(1, $response->viewData('records')->total());
    }

    public function test_a_valid_date_range_still_filters_the_records(): void
    {
        $staff = User::factory()->staff()->create();
        // Midday UTC, so each day is the same in UTC and in Philippine time.
        $inside = $this->submittedAt('2026-09-15 12:00:00', $staff);
        $this->submittedAt('2026-08-31 12:00:00', $staff);
        $this->submittedAt('2026-10-01 12:00:00', $staff);

        $records = $this->actingAs($staff)
            ->get(route('records.index', ['from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk()
            ->assertViewHas('errors', fn ($errors) => ! $errors->any())
            ->viewData('records');

        $this->assertSame([$inside->getKey()], $records->pluck('id')->all());
    }

    public function test_dates_are_philippine_days(): void
    {
        $staff = User::factory()->staff()->create();
        // 07:30 on 2 October in the Philippines, but still 1 October in UTC.
        $record = $this->submittedAt('2026-10-01 23:30:00', $staff);

        $this->actingAs($staff)
            ->get(route('records.index', ['from' => '2026-10-02']))
            ->assertOk()
            ->assertSee('2 Oct 2026')
            ->assertViewHas('records', fn ($records) => $records->pluck('id')->all() === [$record->getKey()]);

        $this->actingAs($staff)
            ->get(route('records.index', ['to' => '2026-10-01']))
            ->assertOk()
            ->assertViewHas('records', fn ($records) => $records->isEmpty());
    }

    private function submittedAt(string $utc, User $staff): CivilRecord
    {
        return CivilRecord::factory()->submitted($staff)->create([
            'created_by' => $staff->getKey(),
            'submitted_at' => $utc,
        ]);
    }
}
