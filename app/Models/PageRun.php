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
}
