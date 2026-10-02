<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Philippine time for people, UTC for storage.
 *
 * Timestamps are stored in UTC (config/app.php), and existing rows and the
 * dashboard's sums depend on that. What people read or type is in the
 * reporting timezone instead, so it goes through here: format() for display,
 * and dayStart() and dayEnd() for date filters.
 */
class LocalTime
{
    /**
     * A stored timestamp as Philippine time. Null gives an empty string.
     */
    public static function format(?CarbonInterface $date, string $format): string
    {
        return $date === null ? '' : $date->copy()->setTimezone(self::timezone())->format($format);
    }

    /**
     * The UTC moment the given Philippine day begins, for a ">=" filter.
     */
    public static function dayStart(string $date): Carbon
    {
        return Carbon::parse($date, self::timezone())->startOfDay()->utc();
    }

    /**
     * The UTC moment the given Philippine day ends, for a "<=" filter.
     */
    public static function dayEnd(string $date): Carbon
    {
        return Carbon::parse($date, self::timezone())->endOfDay()->utc();
    }

    private static function timezone(): string
    {
        return (string) config('crms.reporting_timezone', 'Asia/Manila');
    }
}
