<?php

namespace App\Models;

use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'doc_type', 'description', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return [
            'doc_type' => DocumentType::class,
            'is_active' => 'boolean',
        ];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(DocumentTemplateField::class)->orderBy('sort_order');
    }

    public function records(): HasMany
    {
        return $this->hasMany(CivilRecord::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The template Staff get when starting a new document of this type.
     */
    public static function activeFor(DocumentType $type): ?self
    {
        return static::with('fields')
            ->where('doc_type', $type->value)
            ->where('is_active', true)
            ->first();
    }
}
