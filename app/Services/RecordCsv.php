<?php

namespace App\Services;

use App\Models\CivilRecord;
use App\Models\RecordField;
use App\Support\CsvCell;
use App\Support\RegistryFields;
use Illuminate\Support\Collection;

/**
 * One document's checked values as CSV rows, for the client's old system.
 *
 * One row per person. The columns follow the layout's field names, page 1 then
 * page 2. Fields that belong to no person (a page number, say) repeat on every
 * person row. Values are the verified ones, so later corrections are included.
 *
 * Pages of one document join by row: the person on row 5 of page 1 and the
 * entry on row 5 of page 2 are one person, one CSV row. A page's row is the
 * ledger row its lines were read from (record_fields.line_row, which counts
 * from 1 on every page); without it, the order of that page's people.
 */
class RecordCsv
{
    /**
     * @return array{header: list<string>, rows: list<list<string|int|null>>}
     */
    public function table(CivilRecord $record): array
    {
        // A register type (birth, death, marriage): its own columns, in its order.
        $register = $this->registerRows($record);
        if ($register !== null) {
            $keys = RegistryFields::keys($this->typeKey($record));

            return [
                'header' => $keys,
                'rows' => $register->map(fn ($row) => array_map(
                    fn (string $key) => CsvCell::safe(RegistryFields::exportCell($key, $row->getAttribute($key))),
                    $keys,
                ))->values()->all(),
            ];
        }

        $fields = $record->fields
            ->sortBy([['page_no', 'asc'], ['sort_order', 'asc'], ['id', 'asc']])
            ->values();

        /** @var array<string, string> $columns key => header, in first-seen order */
        $columns = [];
        $details = [];
        /** @var array<int, array<string, string|null>> $people row key => [column key => value] */
        $people = [];

        foreach ($fields->whereNull('person_group')->groupBy('page_no') as $page => $pageFields) {
            foreach ($this->cells($pageFields, (int) $page, $columns) as $key => $value) {
                $details[$key] = $value;
            }
        }

        foreach ($fields->whereNotNull('person_group')->groupBy('page_no') as $page => $pageFields) {
            $groups = $pageFields->groupBy('person_group')->sortKeys()->values();

            foreach ($groups as $ordinal => $personFields) {
                $ordered = $personFields
                    ->sortBy(fn (RecordField $field) => [$field->person_field_order ?? PHP_INT_MAX, $field->sort_order])
                    ->values();
                $row = $this->pageRow($ordered, $ordinal + 1);

                foreach ($this->cells($ordered, (int) $page, $columns) as $key => $value) {
                    $people[$row][$key] = $value;
                }
            }
        }

        ksort($people);

        // A document with no people (a certificate read as single fields) is one row.
        if ($people === []) {
            $people = [1 => []];
        }

        $header = ['Record ID', 'Document type', 'Row', ...array_values($columns)];
        $rows = [];

        foreach ($people as $row => $values) {
            $cells = [$record->getKey(), $record->typeLabel(), $row];
            foreach (array_keys($columns) as $key) {
                $cells[] = $values[$key] ?? $details[$key] ?? null;
            }
            // Downloads are all capitals.
            $rows[] = array_map(fn ($cell) => CsvCell::safe(is_string($cell) ? mb_strtoupper($cell) : $cell), $cells);
        }

        return ['header' => array_map(CsvCell::safe(...), $header), 'rows' => $rows];
    }

    public function filename(CivilRecord $record): string
    {
        return $this->baseName($record).'.csv';
    }

    /**
     * A download's name without its extension: the type, the register's book
     * and page when it has them, and the record ("Book of Birth - Book 12 Page 23 - Record 7").
     */
    public function baseName(CivilRecord $record): string
    {
        $first = $this->registerRows($record)?->first();
        $where = $first ? trim("Book {$first->book_no} Page {$first->page_no}") : '';

        return RegistryFields::fileName($record->typeLabel(), $where, "Record {$record->getKey()}");
    }

    /** The register type's key (birth, death, marriage, or another type's). */
    private function typeKey(CivilRecord $record): string
    {
        return (string) ($record->documentTypeDefinition?->key ?? $record->doc_type?->value ?? '');
    }

    /**
     * The record's rows in its type's register table, in the order they were
     * saved (the table's order); null for a type with none, or none saved.
     *
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>|null
     */
    private function registerRows(CivilRecord $record): ?Collection
    {
        $model = RegistryFields::model($this->typeKey($record));
        if ($model === null) {
            return null;
        }
        $rows = $model::where('civil_record_id', $record->getKey())->orderBy('id')->get();

        return $rows->isEmpty() ? null : $rows;
    }

    /**
     * The fields' values keyed by column, registering any new column. Two fields
     * with one name in a person (two lines drawn in one cell) get "(2)", "(3)".
     *
     * @param  Collection<int, RecordField>  $fields
     * @param  array<string, string>  $columns
     * @return array<string, string|null>
     */
    private function cells(Collection $fields, int $page, array &$columns): array
    {
        $cells = [];
        $seen = [];

        foreach ($fields as $field) {
            $label = $this->label($field);
            $seen[$label] = ($seen[$label] ?? 0) + 1;
            $name = $seen[$label] > 1 ? "{$label} ({$seen[$label]})" : $label;
            $key = "{$page}|{$name}";

            if (! isset($columns[$key])) {
                // Page 2 repeats a page 1 heading (both have "Remarks"): say which page.
                $columns[$key] = $page > 1 && in_array($name, $columns, true)
                    ? "{$name} (page {$page})"
                    : $name;
            }

            $cells[$key] = $field->verified_value;
        }

        return $cells;
    }

    /**
     * The column a field belongs to: its ledger column, or its name without the
     * "· row 5" and "· page 2" the scan adds to keep names unique.
     */
    private function label(RecordField $field): string
    {
        return $field->columnName();
    }

    /**
     * The person's row on its page: the ledger row its lines came from, else its
     * place among that page's people.
     *
     * @param  Collection<int, RecordField>  $fields
     */
    private function pageRow(Collection $fields, int $ordinal): int
    {
        $row = $fields->pluck('line_row')->filter(fn ($value) => $value !== null)->min();

        return $row === null ? $ordinal : (int) $row;
    }
}
