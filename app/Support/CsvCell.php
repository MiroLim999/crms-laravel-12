<?php

namespace App\Support;

/**
 * Shared by every CSV CRMS writes (Reports → Export, a record's Download CSV).
 */
final class CsvCell
{
    /**
     * Keep Excel from running a cell as a formula ("CSV injection").
     *
     * Most text here was typed by someone, so a value such as =HYPERLINK(...)
     * would otherwise run when the file is opened. A leading apostrophe makes
     * Excel show it as plain text. Numbers are not strings, so they pass through.
     */
    public static function safe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
