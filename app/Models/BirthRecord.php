<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A digitised birth register entry.
 *
 * Typed columns rather than key/value rows, so a birth certificate's fields
 * are queryable directly. Runs alongside the EAV `records` table, which still
 * carries the scanned certificate and its OCR'd field crops.
 */
class BirthRecord extends Model
{
    use HasFactory;

    protected $table = 'birth_records';

    protected $fillable = [
        'civil_record_id', 'book_no', 'page_no', 'reg_no',
        'name', 'date_of_birth', 'sex', 'date_of_reg', 'place_of_birth',
        'name_of_mother', 'nationality_mother', 'name_of_father', 'nationality_father',
        'parents_marriage_date', 'parents_marriage_place',
        'attendant', 'type_of_birth', 'mother_age', 'father_age',
        'late_registration',
    ];

    protected function casts(): array
    {
        return [
            'mother_age' => 'integer',
            'father_age' => 'integer',
            'late_registration' => 'boolean',
        ];
    }

    public function scopeLate(Builder $query): void
    {
        $query->where('late_registration', true);
    }
}
