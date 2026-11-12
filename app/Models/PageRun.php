<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one Detect or Scan of a page ended, kept after the page itself is gone.
 * Written by ProcessDocumentPage, read by the dashboard.
 */
class PageRun extends Model
{
    public const UPDATED_AT = null;

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id', 'document_page_id', 'mode', 'outcome',
        'duration_ms', 'read_ms', 'lines', 'ocr_model_key', 'error',
    ];

    protected function casts(): array
    {
        return [
            'duration_ms' => 'integer',
            'read_ms' => 'integer',
            'lines' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * How long the steps of a scan usually take on this machine, in seconds,
     * from the last `$sample` completed runs of each kind: `find` (outlining a
     * page's lines), `read` (TrOCR reading them) and `detect` (a Smoke test
     * scan). The progress window paces its ring and its time left by these,
     * so a faster or slower computer needs no setting.
     *
     * The value most of the runs stayed within (4 of the last 5), not the
     * middle one: a step that ends sooner than promised jumps the ring ahead,
     * while one that runs over leaves it waiting. Few runs, so the pace follows
     * a change (another model, another computer) after a handful of scans. A
     * machine with no runs yet gets the defaults.
     *
     * @return array{find: float, read: float, detect: float}
     */
    public static function typicalSeconds(int $sample = 5): array
    {
        $recent = fn (array $modes, bool $read) => static::query()
            ->where('outcome', self::COMPLETED)
            ->whereIn('mode', $modes)
            ->whereNotNull($read ? 'read_ms' : 'duration_ms')
            ->latest('id')
            ->limit($sample)
            ->get(['duration_ms', 'read_ms']);

        $usual = function (iterable $milliseconds, float $default): float {
            $values = collect($milliseconds)->filter(fn ($ms) => $ms !== null && $ms > 0)->sort()->values();
            if ($values->isEmpty()) {
                return $default;
            }

            return round(max(1.0, $values[(int) ceil(0.7 * $values->count()) - 1] / 1000), 1);
        };

        // Mode names as ProcessDocumentPage records them.
        return [
            'find' => $usual($recent(['full'], true)->map(fn (self $run) => $run->duration_ms - $run->read_ms), 20.0),
            'read' => $usual($recent(['full', 'read'], true)->pluck('read_ms'), 5.0),
            'detect' => $usual($recent(['detect'], false)->pluck('duration_ms'), 20.0),
        ];
    }
}
