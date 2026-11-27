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

    /**
     * Plain-language names for the dotted action codes, as the log page shows them.
     * An action missing here falls back to a headline of its code.
     */
    public const EVENT_LABELS = [
        'auth.login' => 'Signed in',
        'auth.logout' => 'Signed out',
        'auth.login_failed' => 'Failed sign-in',
        'auth.locked_out' => 'Sign-in locked',
        'user.created' => 'Account created',
        'user.updated' => 'Account details changed',
        'user.details_unlocked' => 'Account details unlocked',
        'user.password_changed' => 'Password changed',
        'user.password_reset' => 'Password reset',
        'user.password_recovered' => 'Password recovered',
        'user.recovery_codes_generated' => 'Recovery codes generated',
        'user.recovery_failed' => 'Failed password recovery',
        'record.submitted' => 'Record saved',
        'record.viewed' => 'Record opened',
        'record.edited' => 'Record edited',
        'record.exported' => 'Records exported',
        'registry.dates_normalized' => 'Register dates normalized',
        'scan.discarded' => 'Scan batch discarded',
        'template.created' => 'Template created',
        'template.versioned' => 'Template new version',
        'template.activated' => 'Template activated',
        'template.archived' => 'Template archived',
        'template.restored' => 'Template restored',
        'template.deleted' => 'Template deleted',
        'template.duplicated' => 'Template duplicated',
        'template.field_map_changed' => 'Template field map changed',
        'ocr_model.added' => 'OCR model added',
        'ocr_model.registered' => 'OCR model registered',
        'ocr_model.activated' => 'OCR model activated',
        'training.exported' => 'Training data exported',
    ];

    /** Plain-language names for the kinds of thing an entry can be about. */
    private const TARGET_LABELS = [
        'CivilRecord' => 'Record',
        'User' => 'Account',
        'DocumentTemplate' => 'Template',
        'DocumentTypeDefinition' => 'Document type',
        'ScanBatch' => 'Scan batch',
        'OcrModel' => 'OCR model',
    ];

    public static function labelFor(string $action): string
    {
        return self::EVENT_LABELS[$action]
            ?? str($action)->replace(['.', '_'], ' ')->ucfirst()->toString();
    }

    public function eventLabel(): string
    {
        return self::labelFor($this->action);
    }

    public function targetLabel(): ?string
    {
        if (! $this->auditable_type) {
            return null;
        }

        $base = class_basename($this->auditable_type);

        return self::TARGET_LABELS[$base] ?? str($base)->headline()->toString();
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
