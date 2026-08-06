<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Enums\RecordStatus;
use App\Models\CivilRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CivilRecord>
 */
class CivilRecordFactory extends Factory
{
    protected $model = CivilRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(array_filter(
            DocumentType::cases(),
            fn (DocumentType $type) => $type !== DocumentType::Custom,
        ));

        return [
            'doc_type' => $type->value,
            'registry_number' => strtoupper($type->value).'-'.fake()->unique()->numberBetween(1000, 9999),
            'status' => RecordStatus::Draft->value,
            'ocr_model_key' => 'base',
            'created_by' => fn () => User::factory()->staff(),
        ];
    }

    /**
     * Submitted, and therefore locked. Values change only via a change request.
     */
    public function submitted(?User $by = null): static
    {
        return $this->state(function (array $attributes) use ($by) {
            $submitter = $by?->getKey() ?? $attributes['created_by'];

            return [
                'status' => RecordStatus::Submitted->value,
                'submitted_by' => $submitter,
                'submitted_at' => now(),
            ];
        });
    }

    public function ofType(DocumentType $type): static
    {
        return $this->state(fn () => ['doc_type' => $type->value]);
    }
}
