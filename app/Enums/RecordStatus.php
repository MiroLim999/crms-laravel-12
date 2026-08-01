<?php

namespace App\Enums;

enum RecordStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-label-warning',
            self::Submitted => 'bg-label-success',
        };
    }

    /**
     * Submitted records are locked. Values change only through an approved
     * change request.
     */
    public function isLocked(): bool
    {
        return $this === self::Submitted;
    }
}
