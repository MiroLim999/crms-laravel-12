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
        'is_active',
        'created_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    // ------------------------------------------------------------ role identity
    //
    // All role checks funnel through here. Do not compare role slugs inline
    // elsewhere in the app.

    public function roleSlug(): ?RoleSlug
    {
        return $this->role?->slug;
    }

    public function hasRole(RoleSlug ...$slugs): bool
    {
        $mine = $this->roleSlug();

        return $mine !== null && in_array($mine, $slugs, true);
    }

    public function isStaff(): bool
    {
        return $this->hasRole(RoleSlug::Staff);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(RoleSlug::Admin);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(RoleSlug::SuperAdmin);
    }

    /**
     * May perform data entry: upload/process documents, verify and submit records,
     * and raise change requests. Staff and Super Admin only - never Admin.
     */
    public function canEnterData(): bool
    {
        return $this->roleSlug()?->canEnterData() ?? false;
    }

    /**
     * May perform oversight: approvals, analytics, user management, audit log, reports.
     */
    public function hasOversight(): bool
    {
        return $this->roleSlug()?->hasOversight() ?? false;
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
