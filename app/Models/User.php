<?php

namespace App\Models;

use App\Enums\RoleSlug;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'must_change_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'recovery_codes',
    ];

    /** How many recovery codes are made at a time. */
    public const RECOVERY_CODE_COUNT = 10;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'recovery_codes' => 'array',
            'recovery_codes_generated_at' => 'datetime',
            // Name and email changed once from settings: locked since then (null: still editable).
            'details_locked_at' => 'datetime',
            'must_change_password' => 'boolean',
            'duplicate_file_check' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    // ------------------------------------------------------------ role identity
    //
    // There is one role, the Super Admin. Role checks still funnel through here.

    public function roleSlug(): ?RoleSlug
    {
        return $this->role?->slug;
    }

    public function hasRole(RoleSlug ...$slugs): bool
    {
        $mine = $this->roleSlug();

        return $mine !== null && in_array($mine, $slugs, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(RoleSlug::SuperAdmin);
    }

    // ----------------------------------------------------------- recovery codes
    //
    // Ten one-time 6-digit codes for "Forgot password?". Only a keyed hash of
    // each unused one is stored; a code is crossed off when it is used, and
    // making a new set replaces every old one.

    /**
     * A new set of codes, replacing the old ones. Returned in plain text once:
     * they cannot be shown again.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        $codes = [];
        while (count($codes) < self::RECOVERY_CODE_COUNT) {
            $codes[str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT)] = true;
        }
        $codes = array_map('strval', array_keys($codes));

        $this->forceFill([
            'recovery_codes' => array_map(fn (string $code) => self::recoveryCodeHash($code), $codes),
            'recovery_codes_generated_at' => now(),
        ])->save();

        return $codes;
    }

    /** Cross off `code` if it is one of the unused codes; false when it is not. */
    public function useRecoveryCode(string $code): bool
    {
        $hash = self::hashRecoveryCode($code);

        return $hash !== null && $this->useRecoveryCodeHash($hash);
    }

    /**
     * The stored form of `code` when it is one of the unused codes, without
     * crossing it off (null when it is not). "Forgot password?" checks the
     * code first and crosses it off once the new password is saved.
     */
    public function matchRecoveryCode(string $code): ?string
    {
        $hash = self::hashRecoveryCode($code);

        return $hash !== null && $this->recoveryCodeIndex($hash) !== null ? $hash : null;
    }

    /** Whether the code stored as `hash` (from matchRecoveryCode) is still unused. */
    public function hasRecoveryCodeHash(string $hash): bool
    {
        return $this->recoveryCodeIndex($hash) !== null;
    }

    /** Cross off the code stored as `hash` (from matchRecoveryCode); false when it is no longer unused. */
    public function useRecoveryCodeHash(string $hash): bool
    {
        $index = $this->recoveryCodeIndex($hash);
        if ($index === null) {
            return false;
        }
        $left = $this->recovery_codes ?? [];
        unset($left[$index]);
        $this->forceFill(['recovery_codes' => array_values($left)])->save();

        return true;
    }

    private function recoveryCodeIndex(string $hash): ?int
    {
        foreach ($this->recovery_codes ?? [] as $index => $stored) {
            if (is_string($stored) && hash_equals($stored, $hash)) {
                return $index;
            }
        }

        return null;
    }

    /** Null when `code` is not 6 digits (anything else between them is ignored). */
    private static function hashRecoveryCode(string $code): ?string
    {
        $code = preg_replace('/\D/u', '', $code) ?? '';

        return strlen($code) === 6 ? self::recoveryCodeHash($code) : null;
    }

    /** How many unused codes are left. */
    public function recoveryCodesLeft(): int
    {
        return count($this->recovery_codes ?? []);
    }

    private static function recoveryCodeHash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    // ------------------------------------------------------------------ helpers

    public function initials(): string
    {
        return collect(preg_split('/\s+/', trim($this->name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }
}
