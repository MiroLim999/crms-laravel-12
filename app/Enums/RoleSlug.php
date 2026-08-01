<?php

namespace App\Enums;

/**
 * The three fixed CRMS roles.
 *
 * These slugs match the `roles.slug` column and are the single source of truth for
 * role identity in code. Never compare against raw strings elsewhere.
 */
enum RoleSlug: string
{
    case Staff = 'staff';
    case Admin = 'admin';
    case SuperAdmin = 'super_admin';

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Staff',
            self::Admin => 'Admin',
            self::SuperAdmin => 'Super Admin',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Staff => 'Digitizes documents, verifies extracted records, and requests corrections.',
            self::Admin => 'Oversees people and approvals. Cannot edit record values.',
            self::SuperAdmin => 'Full access, including document templates and OCR model management.',
        };
    }

    /**
     * Roles that may perform data entry (upload, verify, submit records).
     *
     * Admin is deliberately excluded: data entry belongs to Staff and corrections
     * go through the change-request flow. See .kiro/steering/product.md.
     */
    public function canEnterData(): bool
    {
        return in_array($this, [self::Staff, self::SuperAdmin], true);
    }

    /**
     * Roles with oversight duties (approvals, analytics, user management, audit, reports).
     */
    public function hasOversight(): bool
    {
        return in_array($this, [self::Admin, self::SuperAdmin], true);
    }
}
