<?php

namespace Database\Factories;

use App\Models\BirthRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BirthRecord>
 */
class BirthRecordFactory extends Factory
{
    protected $model = BirthRecord::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'book_no' => fake()->numerify('##'),
            'page_no' => fake()->numerify('###'),
            'reg_no' => fake()->numerify('######'),
            'name' => fake()->name(),
            'date_of_birth' => fake()->date('Y-m-d'),
            'sex' => fake()->randomElement(['M', 'F']),
            'date_of_reg' => fake()->date(),
            'place_of_birth' => fake()->city(),
            'name_of_mother' => fake()->name(),
            'nationality_mother' => fake()->randomElement(['Filipino', 'American', null]),
            'name_of_father' => fake()->name(),
            'nationality_father' => fake()->randomElement(['Filipino', 'American', null]),
            'parents_marriage_date' => fake()->optional()->date(),
            'parents_marriage_place' => fake()->optional()->city(),
            'attendant' => fake()->optional()->name(),
            'type_of_birth' => fake()->randomElement(['Normal', 'Caesarean', null]),
            'mother_age' => fake()->optional()->numberBetween(15, 50),
            'father_age' => fake()->optional()->numberBetween(18, 60),
            'late_registration' => fake()->boolean(),
        ];
    }
}
