<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The OCR workspace's saved settings - one row, always.
 *
 * `current()` memoises for the life of the request because the review threshold is
 * read per field while rendering a record, and that must not become one query per
 * field.
 */
class OcrSetting extends Model
{
    protected $fillable = [
        'allow_staff_model_choice',
        'confidence_review_threshold',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'allow_staff_model_choice' => 'boolean',
            'confidence_review_threshold' => 'float',
        ];
    }

    /** Per-request cache. Never a longer life than that: it must not survive a save. */
    private static ?self $cached = null;

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The settings row, created on first read so callers never deal with null.
     */
    public static function current(): self
    {
        return self::$cached ??= static::query()->first() ?? static::create([]);
    }

    /**
     * Drop the memoised row. Called after a save so the next read is fresh.
     */
    public static function forgetCached(): void
    {
        self::$cached = null;
    }

    /**
     * Confidence below which a field is flagged for review.
     *
     * Falls back to config when unset, so the documented `CRMS_CONFIDENCE_THRESHOLD`
     * still works and an install that never opens the settings form is unaffected.
     * A missing table (mid-migration, or a unit test with no database) falls back
     * too rather than taking a page down.
     */
    public static function threshold(): float
    {
        $fallback = (float) config('crms.confidence_review_threshold', 80.0);

        try {
            return (float) (static::current()->confidence_review_threshold ?? $fallback);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * May Staff choose a model other than the promoted one?
     */
    public static function staffMayChooseModel(): bool
    {
        try {
            return static::current()->allow_staff_model_choice;
        } catch (\Throwable) {
            return false;
        }
    }
}
