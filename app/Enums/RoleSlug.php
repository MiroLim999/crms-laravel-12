<?php

namespace App\Enums;

/**
 * The CRMS role. There is one: the Super Admin, the only account on the PC.
 *
 * The slug matches the `roles.slug` column. Staff and Admin were removed on
 * 2026-11-11 (docs/SUPER-ADMIN-ONLY-PLAN.md); the role table stays so the
 * permission checks keep one place to ask.
 */
enum RoleSlug: string
{
    case SuperAdmin = 'super_admin';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Full access: scanning, records, reports, templates and OCR models.',
        };
    }
}
