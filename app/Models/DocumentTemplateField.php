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

    /** What a field can hold for its person: their name, or the entry number. */
    public const ROLES = ['name', 'entry'];

    /** How Staff check a value in Verify. */
    public const VALUE_TYPES = ['text', 'date', 'number', 'choice'];

    protected $fillable = [
        'document_template_id', 'name', 'x', 'y', 'width', 'height', 'angle',
        'sort_order', 'is_required', 'person_group', 'person_field_order',
        'role', 'value_type', 'options', 'hint',
    ];

    protected function casts(): array
    {
        return [
            'x' => 'float',
            'y' => 'float',
            'width' => 'float',
            'height' => 'float',
            'angle' => 'float',
            'is_required' => 'boolean',
            'person_group' => 'integer',
            'person_field_order' => 'integer',
            'options' => 'array',
        ];
    }

    /**
     * What the field holds and how Staff check it, in the shape the builder
     * and the workspace use (ledger columns carry the same keys).
     *
     * @return array{role: string|null, required: bool, type: string, options: list<string>|null, hint: string|null}
     */
    public function settings(): array
    {
        return [
            'role' => $this->role,
            'required' => (bool) $this->is_required,
            'type' => $this->value_type ?: 'text',
            'options' => $this->options ?: null,
            'hint' => $this->hint,
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
            'angle' => (float) ($this->angle ?? 0),
            'personGroup' => $this->person_group,
            'personFieldOrder' => $this->person_field_order,
            ...$this->settings(),
        ];
    }
}
