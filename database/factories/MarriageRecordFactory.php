<?php

namespace Database\Factories;

use App\Models\MarriageRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarriageRecord>
 */
class MarriageRecordFactory extends Factory
{
    protected $model = MarriageRecord::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'book_no' => fake()->numerify('##'),
            'page_no' => fake()->numerify('###'),
            'reg_no' => fake()->numerify('######'),
            'husband_name' => fake()->name(),
            'wife_name' => fake()->name(),
            'date_of_marriage' => fake()->date('Y-m-d'),
            'husband_age' => fake()->optional()->numberBetween(18, 70),
            'wife_age' => fake()->optional()->numberBetween(18, 70),
            'husb_nationality' => fake()->randomElement(['Filipino', 'American', null]),
            'wife_nationality' => fake()->randomElement(['Filipino', 'American', null]),
            'husb_civil_status' => fake()->randomElement(['Single', 'Widowed', null]),
            'wife_civil_status' => fake()->randomElement(['Single', 'Widowed', null]),
            'husb_mother' => fake()->optional()->name(),
            'wife_mother' => fake()->optional()->name(),
            'husb_father' => fake()->optional()->name(),
            'wife_father' => fake()->optional()->name(),
            'date_of_reg' => fake()->date(),
            'place_of_marriage' => fake()->city(),
            'ceremony_type' => fake()->optional()->randomElement(['Civil', 'Church', null]),
            'late_registration' => fake()->boolean(),
        ];
    }
}
