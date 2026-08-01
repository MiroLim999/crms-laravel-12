<?php

namespace App\Enums;

enum ChangeRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'bg-label-warning',
            self::Approved => 'bg-label-success',
            self::Rejected => 'bg-label-danger',
            self::Withdrawn => 'bg-label-secondary',
        };
    }

    /**
     * Only a pending request can still be decided or withdrawn. Everything else
     * is history and must stay untouched.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
