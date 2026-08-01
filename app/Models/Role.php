<?php

namespace App\Models;

use App\Enums\RoleSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'name', 'description'];

    protected function casts(): array
    {
        return [
            'slug' => RoleSlug::class,
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Look up a role by its enum case.
     *
     * Deliberately not memoised in a static: a cached instance outlives database
     * transaction rollbacks, which hands out primary keys that no longer exist.
     * Roles are a tiny table and this is not on a hot path.
     */
    public static function of(RoleSlug $slug): self
    {
        return static::where('slug', $slug->value)->firstOrFail();
    }
}
