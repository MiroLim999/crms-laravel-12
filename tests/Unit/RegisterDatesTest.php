<?php

namespace Tests\Unit;

use App\Support\RegistryFields;
use Tests\TestCase;

/** Register dates as YYYY-MM-DD: read, repaired from the page, and in the right century. */
class RegisterDatesTest extends TestCase
{
    public function test_a_page_of_dates_is_written_as_year_month_day(): void
    {
        $rows = [
            ['date_of_birth' => '2-11-57', 'date_of_reg' => '3-1957', 'parents_marriage_date' => '7-15-25'],
            ['date_of_birth' => '22-22-57', 'date_of_reg' => '3-11-57', 'parents_marriage_date' => '10-13-46'],
            ['date_of_birth' => '2-24-57', 'date_of_reg' => '3-11-57', 'parents_marriage_date' => '63-34'],
            ['date_of_birth' => '1957-02-18', 'date_of_reg' => '1957-03-14', 'parents_marriage_date' => '2025-07-15'],
        ];

        $changes = RegistryFields::normalizedDates($rows);

        $this->assertSame('1957-02-11', $changes[0]['date_of_birth']);
        // No registration year on its own row: the page's (1957), so "25" is 1925.
        $this->assertSame('1925-07-15', $changes[0]['parents_marriage_date']);
        // One stray digit, repaired from the page's usual month (February 1957).
        $this->assertSame('1957-02-22', $changes[1]['date_of_birth']);
        $this->assertSame('1957-03-11', $changes[1]['date_of_reg']);
        // Saved before with the year in the wrong century.
        $this->assertSame('1925-07-15', $changes[3]['parents_marriage_date']);
        // Not worked out: left as it is.
        $this->assertArrayNotHasKey('date_of_reg', $changes[0]);
        $this->assertArrayNotHasKey('parents_marriage_date', $changes[2]);
        $this->assertArrayNotHasKey(3, array_filter([3 => $changes[3]['date_of_birth'] ?? null]));
    }

    public function test_the_verified_data_shows_a_persons_dates_as_year_month_day(): void
    {
        $fields = collect([
            new class { public $id = 1; public $name = 'date of birth · row 1'; public $line_column = 'date of birth'; public $verified_value = '2-11-57'; public function getKey() { return $this->id; } },
            new class { public $id = 2; public $name = 'date of registration · row 1'; public $line_column = 'date of registration'; public $verified_value = '3-1-57'; public function getKey() { return $this->id; } },
            new class { public $id = 3; public $name = 'date of mirriage of parents · row 1'; public $line_column = 'date of mirriage of parents'; public $verified_value = '7-15-25'; public function getKey() { return $this->id; } },
            new class { public $id = 4; public $name = 'date of birth · row 2'; public $line_column = 'date of birth'; public $verified_value = '33-37'; public function getKey() { return $this->id; } },
            new class { public $id = 5; public $name = 'place of birth · row 1'; public $line_column = 'place of birth'; public $verified_value = '2-11-57'; public function getKey() { return $this->id; } },
        ]);

        $this->assertSame(
            [1 => '1957-02-11', 2 => '1957-03-01', 3 => '1925-07-15'],
            RegistryFields::shownDates($fields),
        );
    }

    public function test_a_download_writes_dates_as_year_month_day(): void
    {
        $this->assertSame('1957-02-11', RegistryFields::exportCell('date_of_birth', '2-11-57'));
        $this->assertSame('33-37', RegistryFields::exportCell('date_of_birth', '33-37'));
        $this->assertSame('MAASIN, LEYTE', RegistryFields::exportCell('place_of_birth', 'Maasin, Leyte'));
    }
}
