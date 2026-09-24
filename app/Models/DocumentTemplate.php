<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\PageOrientation;
use App\Enums\PaperSize;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property DocumentType $doc_type
 * @property PaperSize $paper_size
 * @property PageOrientation $orientation
 * @property float|null $custom_width_mm
 * @property float|null $custom_height_mm
 * @property string $grouping_mode
 * @property bool $is_active
 * @property int|null $document_type_id
 * @property string|null $sample_path
 * @property string|null $sample_original_name
 * @property string|null $sample_mime
 * @property int|null $sample_size
 * @property list<array{name: string, box: list<float>}>|null $columns
 * @property list<float>|null $ruled_ys
 */
class DocumentTemplate extends Model
{
    use HasFactory;

    protected $attributes = [
        'grouping_mode' => 'auto',
    ];

    protected $fillable = [
        'name', 'doc_type', 'document_type_id', 'paper_size', 'orientation', 'custom_width_mm', 'custom_height_mm', 'description',
        'grouping_mode', 'columns', 'ruled_ys',
        'sample_path', 'sample_original_name', 'sample_mime', 'sample_size',
        'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'doc_type' => DocumentType::class,
            'paper_size' => PaperSize::class,
            'orientation' => PageOrientation::class,
            'custom_width_mm' => 'float',
            'custom_height_mm' => 'float',
            'grouping_mode' => 'string',
            'columns' => 'array',
            'ruled_ys' => 'array',
            'is_active' => 'boolean',
            'sample_size' => 'integer',
        ];
    }

    /**
     * Whether this is a ruled register template: columns plus printed row lines,
     * read line by line instead of one rectangle per field.
     */
    public function isLedger(): bool
    {
        return count($this->columns ?? []) > 0 && count($this->ruled_ys ?? []) >= 2;
    }

    /**
     * Column markers in the shape the field-marking UI consumes.
     *
     * Staff align these like any other marker; the row lines are then placed
     * inside them and snapped to each page's own printed rules.
     *
     * @return list<array<string, mixed>>
     */
    public function columnBoxes(): array
    {
        if (! $this->isLedger()) {
            return [];
        }

        return collect($this->columns)
            ->values()
            ->map(fn (array $column, int $index) => [
                'name' => (string) $column['name'],
                'x' => (float) $column['box'][0],
                'y' => (float) $column['box'][1],
                'w' => (float) $column['box'][2],
                'h' => (float) $column['box'][3],
                'angle' => (float) ($column['angle'] ?? 0),
                'required' => true,
                'kind' => 'column',
                'columnIndex' => $index,
            ])
            ->all();
    }

    /**
     * Every marker Staff align: rectangle fields, then ledger columns.
     *
     * @return list<array<string, mixed>>
     */
    public function markerBoxes(): array
    {
        return [
            ...$this->fields->map->toBox()->values()->all(),
            ...$this->columnBoxes(),
        ];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(DocumentTemplateField::class)->orderBy('sort_order');
    }

    public function documentTypeDefinition(): BelongsTo
    {
        return $this->belongsTo(DocumentTypeDefinition::class, 'document_type_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(CivilRecord::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array{width: float, height: float} */
    public function paperDimensions(): array
    {
        $portrait = $this->paper_size === PaperSize::Custom
            ? [
                'width' => $this->custom_width_mm ?? 210.0,
                'height' => $this->custom_height_mm ?? 297.0,
            ]
            : $this->paper_size->portraitDimensions();

        return $this->orientation === PageOrientation::Portrait
            ? $portrait
            : ['width' => $portrait['height'], 'height' => $portrait['width']];
    }

    public function paperDimensionsLabel(): string
    {
        if ($this->paper_size !== PaperSize::Custom) {
            return $this->paper_size->dimensionsLabel();
        }

        return $this->formatMillimetres($this->custom_width_mm ?? 210.0)
            .' × '.$this->formatMillimetres($this->custom_height_mm ?? 297.0).' mm';
    }

    public function paperAspectRatio(): float
    {
        $dimensions = $this->paperDimensions();

        return $dimensions['width'] / $dimensions['height'];
    }

    private function formatMillimetres(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    public function typeLabel(): string
    {
        return $this->documentTypeDefinition?->label() ?? $this->doc_type->label();
    }

    public function typeShortLabel(): string
    {
        return $this->documentTypeDefinition?->shortLabel() ?? $this->doc_type->shortLabel();
    }

    public function typeIcon(): string
    {
        return $this->documentTypeDefinition?->icon() ?? $this->doc_type->icon();
    }

    /**
     * The template Staff get when starting a new document of this type.
     */
    public static function activeFor(DocumentType|DocumentTypeDefinition|string $type): ?self
    {
        $definition = $type instanceof DocumentTypeDefinition
            ? $type
            : DocumentTypeDefinition::query()
                ->where('key', $type instanceof DocumentType ? $type->value : $type)
                ->first();

        $query = static::with(['fields', 'documentTypeDefinition'])
            ->where('is_active', true)
            ->when(
                $definition,
                fn ($builder) => $builder->where('document_type_id', $definition->getKey()),
                fn ($builder) => $builder->where('doc_type', $type instanceof DocumentType ? $type->value : $type),
            );

        return $query->first();
    }

    protected static function booted(): void
    {
        static::creating(function (self $template) {
            if ($template->document_type_id !== null) {
                return;
            }

            $key = $template->doc_type instanceof DocumentType
                ? $template->doc_type->value
                : (string) $template->doc_type;
            $template->document_type_id = DocumentTypeDefinition::query()
                ->where('key', $key)
                ->value('id');
        });
    }
}
