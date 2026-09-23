<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One outlined line on a page: its polygon, the masked crop TrOCR read, and
 * what TrOCR read from it. Coordinates are page pixels.
 *
 * @property list<string> $flags
 * @property list<list<float>> $polygon
 */
class PageLine extends Model
{
    public const FLAG_NO_ROW = 'no_row';

    public const FLAG_SHARED_CELL = 'shared_cell';

    public const SOURCE_TEMPLATE = 'template';

    /** A written line Detect found inside a rectangle field. */
    public const SOURCE_FIELD = 'field';

    protected $fillable = [
        'document_page_id', 'position', 'source', 'column_index', 'column_name', 'row',
        'person_group', 'person_field_order',
        'polygon', 'baseline', 'bbox', 'crop_path', 'crop_version', 'flags',
        'ocr_text', 'ocr_confidence', 'ocr_error', 'adjusted_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'column_index' => 'integer',
            'row' => 'integer',
            'person_group' => 'integer',
            'person_field_order' => 'integer',
            'polygon' => 'array',
            'baseline' => 'array',
            'bbox' => 'array',
            'flags' => 'array',
            'crop_version' => 'integer',
            'ocr_confidence' => 'float',
            'adjusted_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(DocumentPage::class, 'document_page_id');
    }

    /**
     * Whether the line belongs to a rectangle field (the whole rectangle, or
     * one written line Detect found inside it) rather than to a ledger cell.
     * Such a line keeps its field when a reviewer redraws it.
     */
    public function belongsToField(): bool
    {
        return in_array($this->source, [self::SOURCE_TEMPLATE, self::SOURCE_FIELD], true);
    }

    /**
     * The shape the Verify screen consumes.
     *
     * @return array<string, mixed>
     */
    public function toClient(): array
    {
        return [
            'id' => $this->getKey(),
            'position' => $this->position,
            'source' => $this->source,
            'column' => $this->column_name,
            'columnIndex' => $this->column_index,
            'row' => $this->row,
            'personGroup' => $this->person_group,
            'personFieldOrder' => $this->person_field_order,
            'polygon' => $this->polygon,
            'bbox' => $this->bbox,
            'flags' => $this->flags ?? [],
            'text' => (string) ($this->ocr_text ?? ''),
            'confidence' => $this->ocr_confidence ?? 0.0,
            'error' => $this->ocr_error,
            'adjusted' => $this->adjusted_at !== null,
            'cropUrl' => route('documents.pages.lines.crop', [
                'page' => $this->document_page_id,
                'line' => $this->getKey(),
                'v' => $this->crop_version,
            ]),
        ];
    }
}
