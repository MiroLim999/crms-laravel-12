<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordField extends Model
{
    use HasFactory;

    protected $fillable = [
        'record_id', 'name', 'ocr_text', 'ocr_confidence', 'verified_value',
        'crop_path', 'x', 'y', 'width', 'height', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'ocr_confidence' => 'float',
            'x' => 'float',
            'y' => 'float',
            'width' => 'float',
            'height' => 'float',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(CivilRecord::class, 'record_id');
    }

    /**
     * True when the model's confidence in its own reading fell below the review
     * threshold. Not a statement about correctness.
     */
    public function needsReview(): bool
    {
        return $this->ocr_confidence !== null
            && $this->ocr_confidence < OcrSetting::threshold();
    }

    /**
     * Whether the human changed what the model read. Useful as a rough signal of
     * model quality on real documents.
     */
    public function wasCorrected(): bool
    {
        return trim((string) $this->ocr_text) !== trim((string) $this->verified_value);
    }
}
