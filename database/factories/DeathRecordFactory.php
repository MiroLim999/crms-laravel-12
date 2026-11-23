<?php

namespace Database\Factories;

use App\Models\DeathRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeathRecord>
 */
class DeathRecordFactory extends Factory
{
    protected $model = DeathRecord::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'book_no' => fake()->numerify('##'),
            'page_no' => fake()->numerify('###'),
            'reg_no' => fake()->numerify('######'),
            'name' => fake()->name(),
            'date_of_death' => fake()->date('Y-m-d'),
            'sex' => fake()->randomElement(['M', 'F']),
            'date_of_reg' => fake()->optional()->date(),
            'age_years' => fake()->optional()->numberBetween(0, 110),
            'age_months' => fake()->optional()->numberBetween(0, 11),
            'age_days' => fake()->optional()->numberBetween(0, 30),
            'age_hours' => fake()->optional()->numberBetween(0, 23),
            'age_mins' => fake()->optional()->numberBetween(0, 59),
            'civil_status' => fake()->randomElement(['Single', 'Married', 'Widowed', null]),
            'nationality' => fake()->randomElement(['Filipino', 'American', null]),
            'place_of_death' => fake()->city(),
            'cause_of_death' => fake()->optional()->sentence(),
            'corpse_disposal' => fake()->optional()->randomElement(['Burial', 'Cremation', null]),
            'attendant' => fake()->optional()->name(),
            'residence' => fake()->optional()->address(),
            'date_of_birth' => fake()->optional()->date(),
            'late_registration' => fake()->boolean(),
        ];
    }
}
