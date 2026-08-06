<?php

namespace App\Models;

use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A document category available to Template Builder and Staff scanning.
 *
 * The key is permanent so renaming a custom category never disconnects its
 * templates or historical records.
 */
class DocumentTypeDefinition extends Model
{
    protected $table = 'document_types';

    protected $fillable = ['key', 'name', 'short_name', 'icon', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function templates(): HasMany
    {
        return $this->hasMany(DocumentTemplate::class, 'document_type_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(CivilRecord::class, 'document_type_id');
    }

    public function label(): string
    {
        return $this->name;
    }

    public function shortLabel(): string
    {
        return $this->short_name ?: $this->name;
    }

    public function icon(): string
    {
        return $this->icon ?: 'bx-file-blank';
    }

    /** @return list<array{name: string, x: float, y: float, width: float, height: float}> */
    public function defaultFields(): array
    {
        return (DocumentType::tryFrom($this->key) ?? DocumentType::Custom)->defaultFields();
    }

    public function legacyType(): DocumentType
    {
        return DocumentType::tryFrom($this->key) ?? DocumentType::Custom;
    }

    public function getValueAttribute(): string
    {
        return $this->key;
    }

    /** @return Collection<int, self> */
    public static function ordered(): Collection
    {
        return static::query()
            ->orderByDesc('is_system')
            ->orderBy('id')
            ->get();
    }
}
