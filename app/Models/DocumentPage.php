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

    protected $fillable = [
        'document_template_id', 'created_by', 'status', 'error',
        'image_path', 'width', 'height', 'deskew_degrees', 'geometry',
        'ocr_model_key', 'ocr_model_label', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'geometry' => 'array',
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
