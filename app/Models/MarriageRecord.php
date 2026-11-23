<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A digitised marriage register entry.
 *
 * Typed columns rather than key/value rows, so a marriage certificate's fields
 * are queryable directly. Runs alongside the EAV `records` table, which still
 * carries the scanned certificate and its OCR'd field crops.
 */
class MarriageRecord extends Model
{
    use HasFactory;

    protected $table = 'marriage_records';

    protected $fillable = [
        'book_no', 'page_no', 'reg_no',
        'husband_name', 'wife_name', 'date_of_marriage',
        'husband_age', 'wife_age',
        'husb_nationality', 'wife_nationality',
        'husb_civil_status', 'wife_civil_status',
        'husb_mother', 'wife_mother', 'husb_father', 'wife_father',
        'date_of_reg', 'place_of_marriage', 'ceremony_type',
        'late_registration',
    ];

    protected function casts(): array
    {
        return [
            'date_of_reg' => 'date',
            'husband_age' => 'integer',
            'wife_age' => 'integer',
            'late_registration' => 'boolean',
        ];
    }

    public function scopeLate(Builder $query): void
    {
        $query->where('late_registration', true);
    }
}
