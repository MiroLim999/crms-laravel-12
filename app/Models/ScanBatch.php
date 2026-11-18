<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A batch of scans kept on the server while it is worked on (see the
 * create_scan_batches_tables migration). Only its owner sees it.
 *
 * @property string $status
 * @property int $version
 */
class ScanBatch extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_DONE = 'done';

    public const STATUS_ABANDONED = 'abandoned';

    protected $fillable = [
        'user_id', 'doc_type', 'document_template_id', 'page_two_template_id',
        'batch_key', 'status', 'version', 'tab', 'seen_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(ScanBatchPage::class)->orderBy('position');
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(ScanBatchDraft::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** How long an open batch is kept untouched before it is pruned (config crms.scan_batches.keep_days). */
    public static function keepDays(): int
    {
        return max(1, (int) config('crms.scan_batches.keep_days', 7));
    }
}
