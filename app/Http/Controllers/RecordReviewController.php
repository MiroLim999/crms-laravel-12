<?php

namespace App\Http\Controllers;

use App\Models\CivilRecord;
use App\Models\DocumentTemplate;
use App\Models\DocumentTypeDefinition;
use App\Models\RecordField;
use App\Services\AuditLogger;
use App\Services\RecordFieldGrouper;
use App\Support\Limits;
use App\Support\RegistryFields;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Review & Export for a saved record: its people as a table (a row per person,
 * a column per field), edited in place and downloaded as CSV or Excel.
 *
 * The scan's fourth step is the same table before the record is saved. Here
 * a save changes the record's values at once and writes every old and new
 * value to the audit log (record.edited), so nothing is lost.
 */
class RecordReviewController extends Controller
{
    public function __construct(private readonly RecordFieldGrouper $fieldGrouper) {}

    public function edit(CivilRecord $record): View
    {
        $record->load(['fields', 'extraPages', 'documentTypeDefinition', 'template.pageTwoTemplate']);

        return view('records.review', [
            'record' => $record,
            'recordHeading' => $this->fieldGrouper->heading($record, $this->fieldGrouper->groups($record->fields, $record->template)),
            'review' => $this->table($record),
        ]);
    }

    /**
     * Save the cells changed in the table: `changes` [{id, value}] for fields
     * the record has, `additions` [{page_no, person_group, column, value}] for
     * cells where nothing was read.
     */
    public function update(Request $request, CivilRecord $record, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'changes' => ['sometimes', 'array', 'max:'.Limits::MAX_FIELDS * 2],
            'changes.*.id' => ['required', 'integer', 'distinct'],
            'changes.*.value' => ['nullable', 'string', 'max:2000'],
            'additions' => ['sometimes', 'array', 'max:'.Limits::MAX_FIELDS * 2],
            'additions.*.page_no' => ['required', 'integer', 'min:1'],
            'additions.*.person_group' => ['required', 'integer', 'min:1', 'max:'.Limits::MAX_FIELDS * 2],
            'additions.*.column' => ['required', 'string', 'max:480'],
            'additions.*.value' => ['required', 'string', 'max:2000'],
            // A record with rows in its register table (birth_records, …): every row as
            // the table now shows it, [{row, values: {field: value}}] (see RegistryFields).
            'registry_rows' => ['sometimes', 'array', 'max:'.Limits::MAX_FIELDS],
        ]);

        $record->load(['fields', 'extraPages', 'template.pageTwoTemplate']);
        $fields = $record->fields->keyBy(fn (RecordField $field) => $field->getKey());
        $pages = [1, ...$record->extraPages->pluck('page_no')->map(fn ($page) => (int) $page)->all()];

        $errors = [];
        foreach ($data['changes'] ?? [] as $index => $change) {
            if (! $fields->has((int) $change['id'])) {
                $errors["changes.{$index}.id"] = 'This value is not part of the record. Reload the page.';
            }
        }
        foreach ($data['additions'] ?? [] as $index => $addition) {
            if (! in_array((int) $addition['page_no'], $pages, true)) {
                $errors["additions.{$index}.page_no"] = 'The record has no such page.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        [$stored, $registerRows, $registerOld, $registerNew] = array_key_exists('registry_rows', $data)
            ? $this->registerChanges($record, $data['registry_rows'])
            : [null, [], [], []];

        $old = [];
        $new = [];
        DB::transaction(function () use ($data, $record, $fields, $stored, $registerRows, $registerOld, &$old, &$new) {
            // The register rows (what the downloads hold), saved again in their order
            // when a value in them changed. Deleted first, then written, so two rows
            // that swap register numbers never meet the one-entry-per-number rule.
            if ($stored !== null && $registerOld !== []) {
                // Asked again here, one save of the book type at a time: two saves of the
                // same book and page at the same moment cannot both pass the check above.
                DocumentTypeDefinition::whereKey($record->document_type_id)->lockForUpdate()->first();
                $this->refuseOtherRecordsPages($record, $this->typeKey($record), $registerRows, locking: true);

                $model = $stored->first()::class;
                $model::whereKey($stored->modelKeys())->delete();
                foreach ($registerRows as $index => $attributes) {
                    (new $model)->forceFill([
                        ...$attributes,
                        'civil_record_id' => $record->getKey(),
                        'created_at' => $stored[$index]->created_at,
                    ])->save();
                }
            }

            foreach ($data['changes'] ?? [] as $change) {
                /** @var RecordField $field */
                $field = $fields->get((int) $change['id']);
                $value = trim((string) ($change['value'] ?? ''));
                if ($value === trim((string) $field->verified_value)) {
                    continue;
                }

                $old[$field->getKey()] = ['name' => $field->name, 'value' => $field->verified_value];
                $field->update(['verified_value' => $value === '' ? null : $value]);
                $new[$field->getKey()] = ['name' => $field->name, 'value' => $field->verified_value];
            }

            $names = $record->fields->map(fn (RecordField $field) => mb_strtolower($field->name))->flip();
            $sortOrder = (int) $record->fields->max('sort_order');
            foreach ($data['additions'] ?? [] as $addition) {
                $page = (int) $addition['page_no'];
                $person = (int) $addition['person_group'];
                $column = trim((string) $addition['column']);
                $name = "{$column} · row {$person}";
                if ($page > 1 && $names->has(mb_strtolower($name))) {
                    $name .= " · page {$page}";
                }
                if ($names->has(mb_strtolower($name))) {
                    continue;
                }

                $template = $page > 1 ? ($record->template?->pageTwoTemplate ?? $record->template) : $record->template;
                $settings = $template?->settingsByName()[mb_strtolower($column)] ?? null;
                $order = $record->fields
                    ->filter(fn (RecordField $field) => $field->person_group === $person)
                    ->max('person_field_order');

                $field = $record->fields()->create([
                    'page_no' => $page,
                    'name' => $name,
                    'ocr_text' => null,
                    'ocr_confidence' => null,
                    'verified_value' => trim((string) $addition['value']),
                    'is_required' => $settings['required'] ?? true,
                    'role' => $settings['role'] ?? null,
                    'person_group' => $person,
                    'person_field_order' => $order === null ? 0 : (int) $order + 1,
                    'sort_order' => ++$sortOrder,
                ]);
                $names->put(mb_strtolower($name), true);
                // The next cell of this person comes after it.
                $record->fields->push($field);
                $old[$field->getKey()] = null;
                $new[$field->getKey()] = ['name' => $field->name, 'value' => $field->verified_value];
            }
        });

        $count = count($new) + array_sum(array_map('count', $registerNew));
        if ($count > 0) {
            $audit->log(
                'record.edited',
                $record,
                old: ['fields' => $old, ...($registerOld === [] ? [] : ['register' => $registerOld])],
                new: ['fields' => $new, ...($registerNew === [] ? [] : ['register' => $registerNew])],
                description: sprintf(
                    'Edited %d %s of record #%d in Review & Export.',
                    $count,
                    $count === 1 ? 'value' : 'values',
                    $record->getKey(),
                ),
                actor: $request->user(),
            );
            session()->flash('success', $count === 1 ? '1 value saved.' : "{$count} values saved.");
        }

        return response()->json([
            'saved' => $count,
            'redirect' => route('records.show', $record),
        ]);
    }

    /**
     * The register rows sent from the page, checked as step 4 checks them,
     * against the rows the record has: [the stored rows, the rows to store,
     * old values, new values] (the last two by row label: [field => value],
     * only what changed).
     *
     * @param  array<mixed>  $rows
     * @return array{0: Collection<int, Model>, 1: list<array<string, mixed>>, 2: array<string, array<string, string>>, 3: array<string, array<string, string>>}
     */
    private function registerChanges(CivilRecord $record, array $rows): array
    {
        $type = $this->typeKey($record);
        $stored = RegistryFields::rowsOf($record, $type);
        if ($stored->isEmpty()) {
            throw ValidationException::withMessages(['registry_rows' => 'This record has no register rows to change. Reload the page.']);
        }

        $clean = RegistryFields::checkedRows($type, $rows);
        if (count($clean) !== $stored->count()) {
            throw ValidationException::withMessages([
                'registry_rows' => 'The table has '.count($clean)." rows but the record has {$stored->count()}. Reload the page and try again.",
            ]);
        }

        // A book and page belongs to one record (as when it was first saved).
        $model = RegistryFields::model($type);
        $this->refuseOtherRecordsPages($record, $type, $clean);

        $old = [];
        $new = [];
        foreach ($clean as $index => $attributes) {
            $before = RegistryFields::shownValues($type, $stored[$index]);
            $after = RegistryFields::shownValues($type, (new $model)->forceFill($attributes));
            $label = is_array($rows[$index] ?? null) && is_scalar($rows[$index]['row'] ?? null)
                ? (string) $rows[$index]['row']
                : (string) ($index + 1);
            foreach ($after as $key => $value) {
                if ($value !== $before[$key]) {
                    $old[$label][$key] = $before[$key];
                    $new[$label][$key] = $value;
                }
            }
        }

        return [$stored, $clean, $old, $new];
    }

    /**
     * A book and page that another record has stops the save, saying which.
     * `locking`: inside the save's transaction, read what is committed now.
     *
     * @param  list<array<string, mixed>>  $rows  checked register rows
     */
    private function refuseOtherRecordsPages(CivilRecord $record, string $type, array $rows, bool $locking = false): void
    {
        $model = RegistryFields::model($type);
        $pages = collect($rows)->map(fn (array $row) => [(string) $row['book_no'], (string) $row['page_no']])
            ->unique(fn (array $page) => implode('|', $page));
        foreach ($pages as [$book, $page]) {
            $other = $model::where('book_no', $book)->where('page_no', $page)
                ->where(fn ($query) => $query->whereNull('civil_record_id')->orWhere('civil_record_id', '!=', $record->getKey()))
                ->when($locking, fn ($query) => $query->lockForUpdate())
                ->first();
            if ($other !== null) {
                $owner = $other->civil_record_id ? " (Record #{$other->civil_record_id})" : '';
                throw ValidationException::withMessages([
                    'registry_rows' => "Book {$book}, page {$page} is already saved{$owner}. Enter another page number.",
                ]);
            }
        }
    }

    private function typeKey(CivilRecord $record): string
    {
        return (string) ($record->documentTypeDefinition?->key ?? $record->doc_type?->value ?? '');
    }

    /**
     * What the page's table is drawn from (see buildReviewModel in
     * resources/js/review-table.js): each page's columns and settings, the
     * people, the details of no person, every field's value, and which
     * fields have a box on the scan to show.
     *
     * @return array<string, mixed>
     */
    private function table(CivilRecord $record): array
    {
        $pageNumbers = [1, ...$record->extraPages->pluck('page_no')->map(fn ($page) => (int) $page)->all()];
        $side = array_flip($pageNumbers);

        $pages = array_map(function (int $number) use ($record) {
            $template = $number > 1 ? ($record->template?->pageTwoTemplate ?? $record->template) : $record->template;

            return [
                'number' => $number,
                'columns' => $this->columns($template),
                'settings' => (object) ($template?->settingsByName() ?? []),
            ];
        }, $pageNumbers);

        $ordered = $record->fields
            ->sortBy([['page_no', 'asc'], ['person_field_order', 'asc'], ['sort_order', 'asc'], ['id', 'asc']])
            ->values();
        $entry = fn (RecordField $field) => [
            'side' => $side[(int) ($field->page_no ?? 1)] ?? 0,
            'column' => $field->columnName(),
            'ref' => $field->getKey(),
        ];

        $people = $ordered
            ->whereNotNull('person_group')
            ->groupBy('person_group')
            ->sortKeys()
            ->map(fn ($fields, $person) => [
                'id' => "person-{$person}",
                'person' => (int) $person,
                // As the record's CSV numbers the row: its register row on page 1, else the person.
                'label' => str_pad((string) ($fields
                    ->filter(fn (RecordField $field) => (int) ($field->page_no ?? 1) === 1)
                    ->pluck('line_row')
                    ->filter(fn ($row) => $row !== null)
                    ->min() ?? $person), 2, '0', STR_PAD_LEFT),
                'fields' => $fields->map($entry)->values()->all(),
            ])
            ->values()
            ->all();

        return [
            'pages' => $pages,
            'people' => $people,
            'details' => $ordered->whereNull('person_group')->map($entry)->values()->all(),
            'values' => $record->fields->mapWithKeys(fn (RecordField $field) => [
                $field->getKey() => (string) ($field->verified_value ?? ''),
            ]),
            'located' => $record->fields
                ->filter(fn (RecordField $field) => $field->x !== null && $field->width !== null)
                ->map(fn (RecordField $field) => $field->getKey())
                ->values(),
            'updateUrl' => route('records.review.update', $record),
            ...$this->registerTable($record),
            // Readable: "Book of Birth - Book 12 Page 23 - Record 7" (the date is added on download).
            'fileName' => app(\App\Services\RecordCsv::class)->baseName($record),
        ];
    }

    /**
     * For a record saved with rows in its register table (birth_records, …):
     * the register's fields, each page's saved "Columns → fields" choices, and
     * the rows as stored. The page then shows the register's columns, as step 4
     * did, with these values: what the downloads hold. [] for any other record.
     *
     * @return array<string, mixed>
     */
    private function registerTable(CivilRecord $record): array
    {
        $type = $this->typeKey($record);
        $rows = RegistryFields::rowsOf($record, $type);
        if ($rows->isEmpty()) {
            return [];
        }

        $pageNumbers = [1, ...$record->extraPages->pluck('page_no')->map(fn ($page) => (int) $page)->all()];

        return [
            'registry' => RegistryFields::forWorkspace($type),
            // By page side, as `pages`: { column name: field } or null (none chosen yet).
            'fieldMaps' => array_map(function (int $number) use ($record) {
                $template = $number > 1 ? ($record->template?->pageTwoTemplate ?? $record->template) : $record->template;
                $map = $template?->field_map;

                return is_array($map) && $map !== [] ? $map : null;
            }, $pageNumbers),
            'registerRows' => $rows->map(fn (Model $row) => RegistryFields::shownValues($type, $row))->values()->all(),
        ];
    }

    /**
     * A layout's ledger columns in order, each with its settings.
     *
     * @return list<array{name: string, settings: array<string, mixed>}>
     */
    private function columns(?DocumentTemplate $template): array
    {
        if ($template === null || ! $template->isLedger()) {
            return [];
        }

        return array_values(array_map(fn (array $column) => [
            'name' => (string) $column['name'],
            'settings' => DocumentTemplate::columnSettings($column),
        ], $template->columns ?? []));
    }
}
