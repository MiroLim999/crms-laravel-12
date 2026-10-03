<?php

namespace App\Support;

/**
 * Limits shared by the Template Builder, line detection and record submission,
 * so the same value is never allowed in one place and refused in another.
 */
final class Limits
{
    /**
     * Fields on one page: in a layout, in the markers sent for line detection,
     * and in one submitted record.
     *
     * It also bounds person numbers. A page cannot hold more people than
     * fields, and a layout's people are renumbered from 1 when it is saved, so
     * a person group runs from 1 to MAX_FIELDS and a field's place within its
     * person from 0 to MAX_FIELDS - 1.
     */
    public const MAX_FIELDS = 450;
}
