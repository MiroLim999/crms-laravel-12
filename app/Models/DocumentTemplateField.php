<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field box on a template. Coordinates are fractions of page size (0-1).
 */
class DocumentTemplateField extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_template_id', 'name', 'x', 'y', 'width', 'height',
        'sort_order', 'is_required',
    ];

    protected function casts(): array
    {
        return [
            'x' => 'float',
            'y' => 'float',
            'width' => 'float',
            'height' => 'float',
            'is_required' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    /**
     * Shape the field-marking UI consumes, matching the prototype's box format.
     *
     * @return array<string, mixed>
     */
    public function toBox(): array
    {
        return [
            'name' => $this->name,
            'x' => $this->x,
            'y' => $this->y,
            'w' => $this->width,
            'h' => $this->height,
            'required' => $this->is_required,
        ];
    }
}
