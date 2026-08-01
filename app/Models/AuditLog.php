<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only audit entry. Never update or delete these.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'actor_name',
        'actor_role',
        'action',
        'auditable_type',
        'auditable_id',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Guard the append-only contract at the model level, so an accidental
     * ->update() or ->delete() fails loudly instead of quietly rewriting history.
     */
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \LogicException('Audit log entries are append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new \LogicException('Audit log entries are append-only and cannot be deleted.');
        });
    }
}
