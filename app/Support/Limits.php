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

    /**
     * A document's scan, in KB (Laravel's file "max"): 200 MB. A large PDF is
     * read one page at a time and usually only that page is sent, but a
     * one-page PDF (or one whose page cannot be cut out) goes whole.
     */
    public const MAX_SCAN_KB = 204800;

    /** A page image drawn from the scan (PNG), in KB: a large page drawn sharp. */
    public const MAX_PAGE_IMAGE_KB = 102400;
}
