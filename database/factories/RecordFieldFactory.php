<?php

namespace Database\Factories;

use App\Models\CivilRecord;
use App\Models\RecordField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordField>
 */
class RecordFieldFactory extends Factory
{
    protected $model = RecordField::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $value = fake()->name();

        return [
            'record_id' => fn () => CivilRecord::factory(),
            'name' => fake()->randomElement(['Child Full Name', 'Date of Birth', 'Place of Birth']),
            // Verified matches the reading by default: the common case is the model
            // getting it right.
            'ocr_text' => $value,
            'verified_value' => $value,
            'is_required' => true,
            'ocr_confidence' => fake()->randomFloat(1, 80, 99),
            'x' => 0.3,
            'y' => 0.3,
            'width' => 0.4,
            'height' => 0.05,
            'sort_order' => 0,
        ];
    }

    /**
     * The model was unsure. Flagged for review, not necessarily wrong.
     */
    public function lowConfidence(): static
    {
        return $this->state(fn () => ['ocr_confidence' => fake()->randomFloat(1, 10, 60)]);
    }
}
