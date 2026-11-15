<?php

namespace App\Enums;

/**
 * A record's status. Submitted is the only case: no code path creates a
 * draft, so one was never kept here (see docs/CODE_REVIEW_TODO.md #18).
 */
enum RecordStatus: string
{
    case Submitted = 'submitted';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Submitted => 'bg-label-success',
        };
    }

    /**
     * Submitted records are locked: their values change only in Review &
     * Export, which writes each old and new value to the audit log.
     */
    public function isLocked(): bool
    {
        return $this === self::Submitted;
    }
}
