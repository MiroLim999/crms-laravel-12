<?php

namespace App\Http\Controllers;

use App\Models\CivilRecord;
use App\Models\DocumentTemplate;
use App\Models\RecordField;
use App\Services\AuditLogger;
use App\Services\RecordFieldGrouper;
use App\Support\Limits;
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

        $old = [];
        $new = [];
        DB::transaction(function () use ($data, $record, $fields, &$old, &$new) {
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

        $count = count($new);
        if ($count > 0) {
            $audit->log(
                'record.edited',
                $record,
                old: ['fields' => $old],
                new: ['fields' => $new],
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
            'fileName' => implode('-', array_filter([
                $record->typeShortLabel(),
                'record',
                (string) $record->getKey(),
            ])),
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
