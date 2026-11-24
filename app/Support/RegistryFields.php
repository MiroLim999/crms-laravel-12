<?php

namespace App\Support;

use App\Models\BirthRecord;
use App\Models\DeathRecord;
use App\Models\MarriageRecord;
use Illuminate\Database\Eloquent\Model;

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
            } elseif (isset($field['options']) && ! in_array($value, $field['options'], true)) {
                $problems[$field['key']] = 'must be one of: ' . implode(', ', $field['options']);
            } elseif (mb_strlen($value) > self::maxLength($type, $field['key'])) {
                $problems[$field['key']] = 'is too long';
            }
        }

        return $problems;
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
        // Downloads are all capitals, as the registers are kept (TRUE / FALSE too).
        return mb_strtoupper(match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
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

        return $fields === [] ? null : ['type' => $type, 'fields' => $fields, 'pairs' => self::pairs($type)];
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
