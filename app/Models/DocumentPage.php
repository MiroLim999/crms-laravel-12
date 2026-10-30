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
    public const NOTE_CODES = ['rows_uneven', 'lines_below_grid', 'lines_above_grid', 'grid_moved', 'rows_do_not_fit', 'rows_empty'];

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

    /**
     * The rows (1-based) each ledger column Staff shortened in Align reaches,
     * by lower-cased name; a column reaching every row is left out. A row
     * counts when its middle lies inside the marker, give or take a quarter
     * row, as line_markers.py reads it (_rows_in_column), and the workspace
     * asks for it (rowsInColumn).
     *
     * @return array<string, list<int>>
     */
    public function shortenedColumnRows(): array
    {
        $ruled = array_map('floatval', $this->geometry['ruled_ys'] ?? []);
        $count = count($ruled) - 1;
        if ($count < 1) {
            return [];
        }
        $gaps = array_map(fn (int $r) => $ruled[$r + 1] - $ruled[$r], range(0, $count - 1));
        sort($gaps);
        $median = $count % 2 === 1 ? $gaps[intdiv($count, 2)] : ($gaps[$count / 2 - 1] + $gaps[$count / 2]) / 2;
        $reach = 0.25 * $median;

        $shortened = [];
        foreach ($this->geometry['columns'] ?? [] as $column) {
            [, $top, , $height] = array_map('floatval', $column['box'] ?? [0, 0, 0, 0]);
            $rows = array_values(array_filter(range(1, $count), function (int $row) use ($ruled, $top, $height, $reach) {
                $middle = ($ruled[$row - 1] + $ruled[$row]) / 2;

                return $middle >= $top - $reach && $middle <= $top + $height + $reach;
            }));
            if (count($rows) < $count) {
                $shortened[mb_strtolower(trim((string) $column['name']))] = $rows;
            }
        }

        return $shortened;
    }
}
