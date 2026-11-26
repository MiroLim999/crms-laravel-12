<?php

namespace App\Support;

/**
 * Shared by every CSV CRMS writes (a record's Download CSV, and the saved records' download).
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

    /**
     * A cell as a CSV writes it: a date (1957-02-11) becomes ="1957-02-11", so
     * Excel keeps it as text instead of showing 2/11/1957. Only an exact
     * YYYY-MM-DD gets it, so nothing typed can become a formula.
     */
    public static function forCsv(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? '="'.$value.'"' : $value;
    }
}
