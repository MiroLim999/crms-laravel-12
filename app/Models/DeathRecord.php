<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A digitised death register entry.
 *
 * Typed columns rather than key/value rows, so a death certificate's fields
 * are queryable directly. Runs alongside the EAV `records` table, which still
 * carries the scanned certificate and its OCR'd field crops.
 */
class DeathRecord extends Model
{
    use HasFactory;

    protected $table = 'death_records';

    protected $fillable = [
        'civil_record_id', 'book_no', 'page_no', 'reg_no',
        'name', 'date_of_death', 'sex', 'date_of_reg',
        'age_years', 'age_months', 'age_days', 'age_hours', 'age_mins',
        'civil_status', 'nationality', 'place_of_death', 'cause_of_death',
        'corpse_disposal', 'attendant', 'residence', 'date_of_birth',
        'late_registration',
    ];

    protected function casts(): array
    {
        return [
            'age_years' => 'integer',
            'age_months' => 'integer',
            'age_days' => 'integer',
            'age_hours' => 'integer',
            'age_mins' => 'integer',
            'late_registration' => 'boolean',
        ];
    }

    public function scopeLate(Builder $query): void
    {
        $query->where('late_registration', true);
    }
}
