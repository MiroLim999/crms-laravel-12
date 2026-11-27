<?php

namespace App\Support;

use App\Models\BirthRecord;
use App\Models\CivilRecord;
use App\Models\DeathRecord;
use App\Models\MarriageRecord;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The register's own columns for each certificate type (birth_records,
 * death_records, marriage_records), in the order the downloads use them.
 *
 * Review & Export (step 4) shows a document as these columns, saves each of
 * its rows as one row of the type's table, and downloads them with these
 * headers. Each field is one of:
 *
 *   text      as written
 *   integer   plain digits ("27")
 *   boolean   true / false
 *   choice    one of `options`
 *
 * `required`: every row needs a value. `manual`: never read from the scan,
 * the encoder decides it (no layout column is offered for it).
 * `default`: the value a row starts with.
 */
final class RegistryFields
{
    public const ATTENDANT = ['PHYSICIAN', 'MIDWIFE', 'NURSE', 'HILOT', 'OTHERS', 'NOT APPLICABLE'];

    public const CORPSE_DISPOSAL = ['BURIAL', 'CREMATION', 'OTHERS'];

    public const CEREMONY_TYPE = ['ROMAN CATHOLIC WEDDING', 'CIVIL WEDDING', 'OTHER RELIGIOUS WEDDING'];

    public const BOOLEAN = ['true', 'false'];

    /** The fields that hold a date (kind "date"). */
    public const DATE_FIELDS = ['date_of_birth', 'date_of_death', 'date_of_marriage', 'date_of_reg', 'parents_marriage_date'];

    /** The certificate types that have a register table. */
    public static function types(): array
    {
        return ['birth', 'death', 'marriage'];
    }

    /** @return class-string<Model>|null */
    public static function model(string $type): ?string
    {
        return match ($type) {
            'birth' => BirthRecord::class,
            'death' => DeathRecord::class,
            'marriage' => MarriageRecord::class,
            default => null,
        };
    }

    /**
     * The type's fields, in download order, or [] for a type with no register table.
     *
     * @return list<array{key: string, kind: string, required: bool, manual: bool, options?: list<string>, default?: string}>
     */
    public static function for(string $type): array
    {
        $keys = match ($type) {
            'birth' => [
                'name', 'date_of_birth', 'sex', 'book_no', 'page_no', 'reg_no', 'date_of_reg', 'place_of_birth',
                'name_of_mother', 'nationality_mother', 'name_of_father', 'nationality_father',
                'parents_marriage_date', 'parents_marriage_place', 'attendant', 'type_of_birth',
                'mother_age', 'father_age', 'late_registration',
            ],
            'death' => [
                'name', 'date_of_death', 'sex', 'book_no', 'page_no', 'reg_no', 'date_of_reg',
                'age_years', 'age_months', 'age_days', 'age_hours', 'age_mins',
                'civil_status', 'nationality', 'place_of_death', 'cause_of_death', 'corpse_disposal',
                'attendant', 'residence', 'date_of_birth', 'late_registration',
            ],
            'marriage' => [
                'husband_name', 'wife_name', 'date_of_marriage', 'book_no', 'page_no', 'reg_no',
                'husband_age', 'wife_age', 'husb_nationality', 'wife_nationality',
                'husb_civil_status', 'wife_civil_status', 'husb_mother', 'wife_mother',
                'husb_father', 'wife_father', 'date_of_reg', 'place_of_marriage', 'ceremony_type',
                'late_registration',
            ],
            default => [],
        };

        return array_map(fn (string $key) => self::field($key), $keys);
    }

    /** One field's settings (see the class comment). */
    private static function field(string $key): array
    {
        $field = ['key' => $key, 'kind' => 'text', 'required' => false, 'manual' => false];

        if (in_array($key, ['reg_no', 'book_no', 'page_no', 'name', 'husband_name', 'wife_name'], true)) {
            $field['required'] = true;
        }
        // Whole numbers: the book and page (entered in step 1), and the ages.
        if (in_array($key, ['book_no', 'page_no', 'mother_age', 'father_age', 'husband_age', 'wife_age'], true)) {
            $field['kind'] = 'integer';
        }
        // The death register has a column for each (Age: Years, Months, …), read like any other.
        if (in_array($key, ['age_years', 'age_months', 'age_days', 'age_hours', 'age_mins'], true)) {
            $field['kind'] = 'integer';
        }
        // Dates: saved and downloaded as YYYY-MM-DD (isoDate).
        if (in_array($key, self::DATE_FIELDS, true)) {
            $field['kind'] = 'date';
        }
        if ($key === 'late_registration') {
            $field = [...$field, 'kind' => 'boolean', 'manual' => true, 'options' => self::BOOLEAN, 'default' => 'false'];
        }
        $options = match ($key) {
            'attendant' => self::ATTENDANT,
            'corpse_disposal' => self::CORPSE_DISPOSAL,
            'ceremony_type' => self::CEREMONY_TYPE,
            default => null,
        };
        if ($options !== null) {
            $field = [...$field, 'kind' => 'choice', 'manual' => true, 'options' => $options];
        }

        return $field;
    }

    /** The type's field keys, in download order. */
    public static function keys(string $type): array
    {
        return array_column(self::for($type), 'key');
    }

    /**
     * Problems with one row as sent from step 4: [field => message], empty when it is fine.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    public static function problems(string $type, array $row): array
    {
        $problems = [];
        foreach (self::for($type) as $field) {
            $value = trim((string) ($row[$field['key']] ?? ''));
            if ($value === '') {
                if ($field['required']) {
                    $problems[$field['key']] = 'is required';
                }
                continue;
            }
            if ($field['kind'] === 'integer' && ! preg_match('/^\d{1,9}$/', $value)) {
                $problems[$field['key']] = 'must be plain digits';
            } elseif ($field['kind'] === 'date' && self::isoDate($value) === null) {
                $problems[$field['key']] = 'is not a whole date (write it as YYYY-MM-DD)';
            } elseif (isset($field['options']) && ! in_array($value, $field['options'], true)) {
                $problems[$field['key']] = 'must be one of: ' . implode(', ', $field['options']);
            } elseif (mb_strlen($value) > self::maxLength($type, $field['key'])) {
                $problems[$field['key']] = 'is too long';
            }
        }

        return $problems;
    }

    /**
     * A register table's rows as sent from Review & Export ([{row: "06",
     * values: {field: value}}]), checked field by field and turned into what
     * the table stores. Anything wrong stops it with "Row 06: …" messages
     * (ValidationException on `registry_rows`). Used by step 4 and by a saved
     * record's Review & Export, so both follow one set of rules.
     *
     * @return list<array<string, mixed>>
     */
    public static function checkedRows(string $type, mixed $rows): array
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > Limits::MAX_FIELDS) {
            throw ValidationException::withMessages(['registry_rows' => 'The table is not a list of rows.']);
        }

        $messages = [];
        $clean = [];
        foreach ($rows as $index => $row) {
            $label = is_array($row) && is_scalar($row['row'] ?? null) ? (string) $row['row'] : (string) ($index + 1);
            $values = is_array($row) && is_array($row['values'] ?? null) ? $row['values'] : [];
            $values = array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $values);
            foreach (self::problems($type, $values) as $field => $problem) {
                $messages[] = "Row {$label}: {$field} {$problem}.";
            }
            // A register number may repeat on a page: the books have repeats.
            $clean[] = self::attributes($type, $values);
        }
        if ($messages !== []) {
            throw ValidationException::withMessages(['registry_rows' => $messages]);
        }

        return $clean;
    }

    /**
     * A layout's "Columns → fields" choices as kept: { "column name" (lower
     * case): field or "-" (fills none) }. A choice left empty is dropped;
     * anything that is not one of the type's fields stops it.
     *
     * @return array<string, string>
     */
    public static function cleanFieldMap(string $type, mixed $map): array
    {
        $fields = self::keys($type);
        if ($fields === [] || ! is_array($map) || count($map) > Limits::MAX_FIELDS) {
            throw ValidationException::withMessages(['field_maps' => 'The column choices could not be read.']);
        }

        $clean = [];
        foreach ($map as $column => $field) {
            if ($field === null || $field === '') {
                continue;
            }
            if (! is_string($field) || ! in_array($field, [...$fields, '-'], true)) {
                throw ValidationException::withMessages(['field_maps' => "\"{$column}\" is set to a field this register does not have."]);
            }
            $name = mb_strtolower(trim((string) $column));
            if ($name !== '') {
                $clean[mb_substr($name, 0, 500)] = $field;
            }
        }
        ksort($clean);

        return $clean;
    }

    /**
     * A saved record's rows in its type's register table, oldest first (the
     * order step 4 saved them in); empty for a type with none.
     *
     * @return Collection<int, Model>
     */
    public static function rowsOf(CivilRecord $record, string $type): Collection
    {
        $model = self::model($type);

        return $model === null
            ? new Collection
            : $model::where('civil_record_id', $record->getKey())->orderBy('id')->get();
    }

    /**
     * A stored row's values as Review & Export shows them: text for every
     * field (true / false, digits, YYYY-MM-DD), '' for none.
     *
     * @return array<string, string>
     */
    public static function shownValues(string $type, Model $row): array
    {
        $values = [];
        foreach (self::keys($type) as $key) {
            $value = $row->getAttribute($key);
            $values[$key] = match (true) {
                $value === null => '',
                is_bool($value) => $value ? 'true' : 'false',
                default => (string) $value,
            };
        }

        return $values;
    }

    /**
     * One row as the table stores it: integers as numbers, booleans as true /
     * false, anything left blank as null (late_registration: false).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function attributes(string $type, array $row): array
    {
        $attributes = [];
        foreach (self::for($type) as $field) {
            $value = trim((string) ($row[$field['key']] ?? ''));
            $attributes[$field['key']] = match (true) {
                $field['kind'] === 'boolean' => $value === 'true',
                $value === '' => null,
                $field['kind'] === 'date' => self::isoDate($value) ?? $value,
                // The book and page are text columns: their digits are kept as typed.
                $field['kind'] === 'integer' && in_array($field['key'], ['book_no', 'page_no'], true) => $value,
                $field['kind'] === 'integer' => (int) $value,
                default => $value,
            };
        }

        return $attributes;
    }

    /** A value as the downloads write it: true / false, plain digits, blank for none. */
    public static function cell(mixed $value): string
    {
        // Downloads are all capitals, as the registers are kept; a yes / no
        // (late_registration) is 1 or 0, as the database keeps it.
        return mb_strtoupper(match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        });
    }

    /**
     * A download's file name, readable and safe on any computer: the parts that
     * are not empty, joined by " - " ("Book of Birth - Book 12 Page 23 - 2026-11-25").
     */
    public static function fileName(string ...$parts): string
    {
        $parts = array_map(
            fn (string $part) => trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('~[\\/:*?"<>|[:cntrl:]]+~u', ' ', $part))),
            $parts,
        );
        $name = implode(' - ', array_filter($parts, fn (string $part) => $part !== ''));

        return mb_substr($name, 0, 150) ?: 'Records';
    }

    /**
     * A date as YYYY-MM-DD, from the ways registers write it, or null when it is
     * not a whole, real date. As toIsoDate in resources/js/value-types.js (keep
     * them alike): "5-14-79" and "05/14/1979" month first; "14-5-79" day first
     * when the first number cannot be a month; "1979-05-14"; a month's name in any
     * place ("May 14, 1979"). A two-digit year is this century's up to this year,
     * else the last one's ("57" is 1957).
     */
    public static function isoDate(string $value, ?int $latestYear = null): ?string
    {
        $text = mb_strtolower(trim($value));
        if ($text === '') {
            return null;
        }
        // A two-digit year is at most this one: the entry's registration year, else today's.
        $thisYear = $latestYear ?? (int) now()->format('Y');
        $fullYear = function (string $token) use ($thisYear): ?int {
            $digits = ltrim($token, "'");
            if (preg_match('/^\d{4}$/', $digits)) {
                return (int) $digits;
            }
            if (! preg_match('/^\d{2}$/', $digits)) {
                return null;
            }
            $century = intdiv($thisYear, 100) * 100;

            return $century + (int) $digits <= $thisYear ? $century + (int) $digits : $century - 100 + (int) $digits;
        };
        $build = fn (?int $year, int $month, int $day): ?string => $year !== null && checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : null;

        if (preg_match("~^('?\d{1,4})\s*[/.\-\s]\s*(\d{1,2})\s*[/.\-\s]\s*('?\d{1,4})$~", $text, $m)) {
            if (preg_match('/^\d{4}$/', $m[1])) {
                return $build((int) $m[1], (int) $m[2], (int) $m[3]);
            }
            [$a, $b] = [(int) ltrim($m[1], "'"), (int) $m[2]];

            return $a > 12 ? $build($fullYear($m[3]), $b, $a) : $build($fullYear($m[3]), $a, $b);
        }

        $months = ['jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2, 'mar' => 3, 'march' => 3, 'apr' => 4,
            'april' => 4, 'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7, 'aug' => 8, 'august' => 8,
            'sep' => 9, 'sept' => 9, 'september' => 9, 'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11,
            'dec' => 12, 'december' => 12];
        $tokens = array_values(array_filter(preg_split('~[\s.,/\-]+~', (string) preg_replace('/(\d)(st|nd|rd|th)\b/', '$1', $text))));
        $monthAt = null;
        foreach ($tokens as $index => $token) {
            if (isset($months[$token])) {
                $monthAt = $index;
                break;
            }
        }
        if ($monthAt === null) {
            return null;
        }
        $month = $months[$tokens[$monthAt]];
        $rest = array_values(array_filter($tokens, fn ($token, $index) => $index !== $monthAt, ARRAY_FILTER_USE_BOTH));
        if (count($rest) !== 2 || ! preg_match("/^'?\d{1,4}$/", $rest[0]) || ! preg_match("/^'?\d{1,4}$/", $rest[1])) {
            return null;
        }
        [$dayToken, $yearToken] = preg_match('/^\d{4}$/', $rest[0]) ? [$rest[1], $rest[0]] : [$rest[0], $rest[1]];

        return $build($fullYear($yearToken), $month, (int) $dayToken);
    }

    /**
     * A date misread by one stray digit ("22-22-57" for 2-22-57), repaired from
     * its page: each way of taking one digit out is tried, and the one whole
     * date in the page's usual month (`$usual`: "1957-02") is it; null when none,
     * or more than one, is. As repairedDate in resources/js/value-types.js.
     */
    public static function repairedDate(string $value, ?string $usual, ?int $latestYear = null): ?string
    {
        $text = trim($value);
        if ($usual === null || ! preg_match("/^[\d'][\d'\s\/.\-]*\d$/", $text) || self::isoDate($text, $latestYear) !== null) {
            return null;
        }
        $found = [];
        for ($at = 0; $at < strlen($text); $at++) {
            if (! ctype_digit($text[$at])) {
                continue;
            }
            $iso = self::isoDate(substr($text, 0, $at).substr($text, $at + 1), $latestYear);
            if ($iso !== null && str_starts_with($iso, $usual.'-')) {
                $found[$iso] = true;
            }
        }

        return count($found) === 1 ? array_key_first($found) : null;
    }

    /**
     * One page's rows (of one register type) with every date as YYYY-MM-DD where
     * it can be worked out: as written, else repaired from the page's usual month.
     * Two-digit years are read against each row's own registration year. A date
     * that cannot be worked out is left as it is. Returns [row index => [field => new]].
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array<string, string>>
     */
    public static function normalizedDates(array $rows): array
    {
        $latest = array_map(function (array $row): ?int {
            $reg = self::isoDate((string) ($row['date_of_reg'] ?? ''));

            return $reg === null ? null : (int) substr($reg, 0, 4);
        }, $rows);
        // A row whose registration date cannot be read: the year the page was registered.
        $years = array_count_values(array_filter($latest));
        arsort($years);
        $pageYear = $years === [] ? null : array_key_first($years);
        $latest = array_map(fn (?int $year) => $year ?? $pageYear, $latest);

        $changes = [];
        foreach (self::DATE_FIELDS as $field) {
            $read = [];
            foreach ($rows as $index => $row) {
                if (array_key_exists($field, $row)) {
                    $read[$index] = self::isoDate((string) ($row[$field] ?? ''), $latest[$index]);
                }
            }
            $months = array_count_values(array_map(fn (string $iso) => substr($iso, 0, 7), array_filter($read)));
            arsort($months);
            $usual = (array_sum($months) >= 2 && reset($months) * 2 >= array_sum($months)) ? array_key_first($months) : null;

            foreach ($read as $index => $iso) {
                $value = trim((string) ($rows[$index][$field] ?? ''));
                $iso ??= self::repairedDate($value, $usual, $latest[$index]);
                // Saved with a two-digit year read in the wrong century (2025 on a
                // 1957 entry): nothing in an entry is later than its registration.
                if ($iso !== null && $field !== 'date_of_reg' && $latest[$index] !== null && (int) substr($iso, 0, 4) > $latest[$index]) {
                    $iso = sprintf('%04d', (int) substr($iso, 0, 4) - 100).substr($iso, 4);
                }
                if ($iso !== null && $iso !== $value) {
                    $changes[$index][$field] = $iso;
                }
            }
        }

        return $changes;
    }

    /**
     * One person's date fields as YYYY-MM-DD, for showing what was saved: the
     * transcription itself ("2-11-57") is left as typed. A field is a date when
     * its column is named "date ..."; its value must read as a whole date, else
     * it is not listed. Two-digit years are read against the person's
     * registration year, as normalizedDates() does.
     *
     * @param  iterable<object>  $fields  RecordField rows (id, name, line_column, verified_value)
     * @return array<int, string> field id => YYYY-MM-DD
     */
    public static function shownDates(iterable $fields): array
    {
        $dated = [];
        $registered = null;
        foreach ($fields as $field) {
            $column = mb_strtolower(trim((string) ($field->line_column ?: preg_replace('/ · row \d+/u', '', (string) $field->name))));
            $value = trim((string) $field->verified_value);
            if ($value === '' || ! preg_match('/\bdate\b/u', $column)) {
                continue;
            }
            $isRegistration = str_contains($column, 'regist');
            $dated[] = [$field->getKey(), $value, $isRegistration];
            if ($isRegistration && $registered === null && ($iso = self::isoDate($value)) !== null) {
                $registered = (int) substr($iso, 0, 4);
            }
        }

        $shown = [];
        foreach ($dated as [$id, $value, $isRegistration]) {
            $iso = self::isoDate($value, $registered);
            if ($iso === null) {
                continue;
            }
            // Nothing in an entry is later than its registration (a "25" is 1925, not 2025).
            if (! $isRegistration && $registered !== null && (int) substr($iso, 0, 4) > $registered) {
                $iso = sprintf('%04d', (int) substr($iso, 0, 4) - 100).substr($iso, 4);
            }
            $shown[$id] = $iso;
        }

        return $shown;
    }

    /** A value as a download writes it: a date field as YYYY-MM-DD where it can be, all capitals. */
    public static function exportCell(string $key, mixed $value): string
    {
        if (in_array($key, self::DATE_FIELDS, true) && is_string($value)) {
            $value = self::isoDate($value) ?? $value;
        }

        return self::cell($value);
    }

    /** The longest value a field's column holds. */
    private static function maxLength(string $type, string $key): int
    {
        return match ($key) {
            'book_no', 'page_no' => 32,
            'reg_no', 'date_of_birth', 'date_of_death', 'date_of_marriage', 'date_of_reg',
            'parents_marriage_date', 'type_of_birth', 'civil_status', 'husb_civil_status',
            'wife_civil_status', 'ceremony_type' => 64,
            'sex' => 16,
            default => 255,
        };
    }

    /** What step 4 needs for a type: its fields, or null for a type with no register table. */
    public static function forWorkspace(string $type): ?array
    {
        $fields = self::for($type);

        return $fields === [] ? null : [
            'type' => $type,
            'fields' => $fields,
            'pairs' => self::pairs($type),
            'pageOrder' => self::pageOrder($type),
        ];
    }

    /**
     * The fields in the order their columns run across the printed register page,
     * left to right (the Template Builder lists the columns still to add in it).
     * Not the download order (for()). A pair is listed by its first field. Fields
     * not named here follow in the download order.
     *
     * @return list<string>
     */
    public static function pageOrder(string $type): array
    {
        return match ($type) {
            'marriage' => [
                'date_of_reg', 'reg_no', 'husband_name', 'husband_age', 'husb_nationality',
                'husb_civil_status', 'husb_father', 'husb_mother', 'place_of_marriage', 'date_of_marriage',
            ],
            default => [],
        };
    }

    /**
     * Fields written on two lines of one register entry: [first line, second line].
     * In the marriage book the husband is on the first line and the wife under
     * him, so a column read for husband_name gives wife_name from its second line
     * (step 4 joins each two lines into one row).
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function pairs(string $type): array
    {
        return match ($type) {
            'marriage' => [
                ['husband_name', 'wife_name'],
                ['husband_age', 'wife_age'],
                ['husb_nationality', 'wife_nationality'],
                ['husb_civil_status', 'wife_civil_status'],
                ['husb_mother', 'wife_mother'],
                ['husb_father', 'wife_father'],
            ],
            default => [],
        };
    }
}
