<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An aligned page and the lines found on it.
 *
 * Exists between the Align step and submission. Its files live under
 * pages/{id}/ on the local disk; the record copies the crops it keeps.
 *
 * @property string $status
 * @property array<string, mixed> $geometry
 */
class DocumentPage extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_DETECTING = 'detecting';

    /** Detect finished: outlines and crops are ready, nothing has been read yet. */
    public const STATUS_DETECTED = 'detected';

    public const STATUS_READING = 'reading';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /** Staff cancelled Detect or Scan: the worker discards the page at its next step. */
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'document_template_id', 'created_by', 'status', 'error',
        'image_path', 'width', 'height', 'deskew_degrees', 'geometry', 'notes',
        'ocr_model_key', 'ocr_model_label', 'processed_at',
    ];

    /** The grid notes the page job may report; anything else is dropped. */
    public const NOTE_CODES = ['rows_uneven', 'lines_below_grid', 'lines_above_grid', 'grid_moved', 'rows_do_not_fit'];

    protected function casts(): array
    {
        return [
            'geometry' => 'array',
            'notes' => 'array',
            'width' => 'integer',
            'height' => 'integer',
            'deskew_degrees' => 'float',
            'processed_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PageLine::class)->orderBy('position');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The grid notes as the page job reported them, kept to what the Verify
     * step words: a known code, a count, and at most six row numbers.
     *
     * @param  mixed  $notes
     * @return list<array{code: string, count?: int, rows?: int|list<int>}>
     */
    public static function cleanNotes(mixed $notes): array
    {
        if (! is_array($notes)) {
            return [];
        }

        $clean = [];
        foreach ($notes as $note) {
            if (! is_array($note) || ! in_array($note['code'] ?? null, self::NOTE_CODES, true)) {
                continue;
            }

            $entry = ['code' => $note['code']];
            if (isset($note['count']) && is_numeric($note['count'])) {
                $entry['count'] = max(0, (int) $note['count']);
            }
            if (isset($note['rows'])) {
                $entry['rows'] = is_array($note['rows'])
                    ? array_values(array_map('intval', array_slice(array_filter($note['rows'], 'is_numeric'), 0, 6)))
                    : (int) $note['rows'];
            }
            $clean[] = $entry;
        }

        return array_slice($clean, 0, 10);
    }

    /** Folder on the local disk holding the image, crops, overlay and lines.json. */
    public function directory(): string
    {
        return 'pages/'.$this->getKey();
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_READY, self::STATUS_FAILED], true);
    }

    /** Outlines and crops exist (after Detect, or after a full run). */
    public function hasLines(): bool
    {
        return in_array($this->status, [self::STATUS_DETECTED, self::STATUS_READY], true);
    }
}
