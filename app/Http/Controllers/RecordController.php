<?php

namespace App\Http\Controllers;

use App\Enums\RecordStatus;
use App\Models\AuditLog;
use App\Models\CivilRecord;
use App\Models\DocumentTypeDefinition;
use App\Models\RecordField;
use App\Models\RecordPage;
use App\Models\ScanBatch;
use App\Models\ScanBatchPage;
use App\Services\AuditLogger;
use App\Services\RecordCsv;
use App\Services\RecordFieldGrouper;
use App\Support\LocalTime;
use App\Support\SearchWords;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The searchable archive. Every signed-in role may read it.
 *
 * Read-only by design. There is no edit or destroy action here for anyone,
 * including Super Admin: submitted records change only through an approved change
 * request, which is what makes the trail worth anything.
 */
class RecordController extends Controller
{
    /** The most records one download joins (Download selected, or all matching). */
    public const EXPORT_LIMIT = 1000;

    public function __construct(private readonly RecordFieldGrouper $fieldGrouper) {}

    /**
     * The archive's filters: the search words, type, status, the batch the
     * records were saved from, and submitted dates.
     *
     * @return array<string, array<int, string>>
     */
    private function filterRules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'exists:document_types,key'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(RecordStatus::cases(), 'value'))],
            'batch' => ['nullable', 'integer', 'exists:scan_batches,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    /**
     * @param  Builder<CivilRecord>  $query
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $words  from SearchWords::from()
     * @return Builder<CivilRecord>
     */
    private function applyFilters(Builder $query, array $filters, array $words): Builder
    {
        return $query
            ->search($words)
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->whereHas(
                'documentTypeDefinition',
                fn ($typeQuery) => $typeQuery->where('key', $type),
            ))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            // The records one batch saved (its pages know their record).
            ->when($filters['batch'] ?? null, fn ($q, $batch) => $q->whereIn(
                'id',
                ScanBatchPage::query()->select('record_id')->where('scan_batch_id', (int) $batch)->whereNotNull('record_id'),
            ))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('submitted_at', '>=', LocalTime::dayStart($from)))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('submitted_at', '<=', LocalTime::dayEnd($to)));
    }

    public function index(Request $request): View
    {
        $validator = Validator::make($request->all(), $this->filterRules(), [], ['q' => 'search', 'from' => 'from date', 'to' => 'to date']);

        // The filters come from the query string, so an edited URL can carry a
        // bad one. Its message is shown on this page and the filter left out,
        // instead of the usual redirect back: a typed URL has no referrer, so
        // "back" could be another page, where the message would never show.
        if ($validator->fails()) {
            view()->share('errors', (new ViewErrorBag)->put('default', $validator->errors()));
        }

        $filters = $validator->valid();
        $words = SearchWords::from($filters['q'] ?? null);

        $records = $this->applyFilters(
            CivilRecord::query()
                ->withTitleField()
                ->with(['submitter', 'documentTypeDefinition'])
                ->withSearchMatches($words),
            $filters,
            $words,
        )
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('records.index', [
            'records' => $records,
            'details' => $this->listDetails($records),
            'batches' => $this->savedBatches(isset($filters['batch']) ? (int) $filters['batch'] : null),
            'filters' => $filters,
            'documentTypes' => DocumentTypeDefinition::ordered(),
            'statuses' => RecordStatus::cases(),
        ]);
    }

    /**
     * What the list says of each record on the page. A register page (several
     * people) is named by its file and pages, with how many people and the
     * first and last value of its first column ("1978-0001 to 1978-0020"); a
     * single certificate by its first value (a name), with its file under it.
     *
     * @return array<int, array{title: string, people: int, range: array{0: string, 1: string}|null, file: string|null}>
     */
    private function listDetails(LengthAwarePaginator $records): array
    {
        $page = $records->getCollection();
        $ids = $page->modelKeys();
        if ($ids === []) {
            return [];
        }

        $people = RecordField::query()
            ->whereIn('record_id', $ids)
            ->whereNotNull('person_group')
            ->groupBy('record_id')
            ->selectRaw('record_id, count(distinct person_group) as people')
            ->pluck('people', 'record_id');
        // The title field's column, down the register: its first and last value.
        $columns = $page->map(fn (CivilRecord $record) => $record->fields->first()?->line_column)->filter()->unique()->values();
        $lines = $columns->isEmpty() ? collect() : RecordField::query()
            ->whereIn('record_id', $ids)
            ->whereIn('line_column', $columns)
            ->whereNotNull('line_row')
            ->where('verified_value', '!=', '')
            ->orderBy('line_row')
            ->get(['record_id', 'page_no', 'line_column', 'line_row', 'verified_value'])
            ->groupBy('record_id');
        $extraPages = RecordPage::query()->whereIn('record_id', $ids)->get(['record_id', 'source_page'])->groupBy('record_id');

        return $page->mapWithKeys(function (CivilRecord $record) use ($people, $lines, $extraPages) {
            $count = (int) ($people[$record->getKey()] ?? 0);
            $titleField = $record->fields->first();

            $range = null;
            if ($count > 1 && $titleField?->line_column) {
                $values = ($lines[$record->getKey()] ?? collect())
                    // Page 2 of a register has columns of the same name: the title field's page only.
                    ->where('line_column', $titleField->line_column)
                    ->where('page_no', $titleField->page_no)
                    ->pluck('verified_value')
                    ->values();
                if ($values->count() > 1 && $values->first() !== $values->last()) {
                    $range = [(string) $values->first(), (string) $values->last()];
                }
            }

            // "page 3", "pages 1–2" (only for a file of several pages).
            $numbers = collect([$record->source_page])
                ->merge(($extraPages[$record->getKey()] ?? collect())->pluck('source_page'))
                ->filter()
                ->map(fn ($number) => (int) $number)
                ->unique()
                ->sort()
                ->values();
            $pages = null;
            if ((int) $record->source_page_count > 1 && $numbers->isNotEmpty()) {
                $pages = $numbers->count() === 1
                    ? 'page '.$numbers->first()
                    : ($numbers->last() - $numbers->first() + 1 === $numbers->count()
                        ? 'pages '.$numbers->first().'–'.$numbers->last()
                        : 'pages '.$numbers->implode(', '));
            }
            $file = filled($record->source_name) ? $record->source_name.($pages ? " · {$pages}" : '') : null;
            $register = $count > 1 && $file !== null;

            return [$record->getKey() => [
                'title' => $register ? $file : $record->title(),
                'people' => $count,
                'range' => $range,
                'file' => $register ? null : $file,
            ]];
        })->all();
    }

    /**
     * Batches that saved records, newest first, for the archive's Batch filter:
     * "22 Nov 2026, 1:13 am · 4 records". The one picked stays in the list.
     *
     * @return list<array{id: int, label: string}>
     */
    private function savedBatches(?int $picked): array
    {
        return ScanBatch::query()
            ->whereHas('pages', fn ($pages) => $pages->whereNotNull('record_id'))
            ->withCount(['pages as saved_records' => fn ($pages) => $pages
                ->whereNotNull('record_id')
                ->select(DB::raw('count(distinct record_id)'))])
            ->latest('id')
            ->limit(50)
            ->get(['id', 'created_at'])
            ->when($picked !== null, function ($batches) use ($picked) {
                if ($batches->contains('id', $picked)) {
                    return $batches;
                }
                $one = ScanBatch::query()
                    ->withCount(['pages as saved_records' => fn ($pages) => $pages
                        ->whereNotNull('record_id')
                        ->select(DB::raw('count(distinct record_id)'))])
                    ->find($picked, ['id', 'created_at']);

                return $one ? $batches->push($one) : $batches;
            })
            ->map(fn (ScanBatch $batch) => [
                'id' => $batch->getKey(),
                'label' => LocalTime::format($batch->created_at, 'j M Y, g:i a')
                    .' · '.$batch->saved_records.' '.str('record')->plural($batch->saved_records),
            ])
            ->values()
            ->all();
    }

    public function show(CivilRecord $record): View
    {
        $record->load([
            'fields',
            'extraPages',
            'documentTypeDefinition',
        ]);

        $fieldGroups = $this->fieldGrouper->groups($record->fields, $record->template);
        // Saves in Review & Export after the record was saved, newest first,
        // and which of them last changed each field.
        $edits = AuditLog::query()
            ->where('action', 'record.edited')
            ->where('auditable_type', $record::class)
            ->where('auditable_id', $record->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'actor_name', 'user_id', 'new_values', 'created_at']);
        $fieldChanges = collect();
        foreach ($edits as $edit) {
            foreach (array_keys($edit->new_values['fields'] ?? []) as $fieldId) {
                if (! $fieldChanges->has((int) $fieldId)) {
                    $fieldChanges->put((int) $fieldId, $edit);
                }
            }
        }

        return view('records.show', [
            'record' => $record,
            'fieldGroups' => $fieldGroups,
            'recordHeading' => $this->fieldGrouper->heading($record, $fieldGroups),
            'fieldChanges' => $fieldChanges,
            'edits' => $edits,
            'personCount' => collect($fieldGroups)->where('kind', 'person')->count(),
            'firstPersonGroupId' => collect($fieldGroups)->firstWhere('kind', 'person')['id'] ?? null,
            'ocrAdjustedCount' => $record->fields->filter->wasCorrected()->count(),
            'postSubmissionChangeCount' => $fieldChanges->count(),
        ]);
    }

    /**
     * Download one document's checked values as CSV, one row per person, for the
     * client's old system. UTF-8 with a byte-order mark, so Excel shows ñ and
     * accents correctly.
     */
    public function csv(CivilRecord $record, Request $request, RecordCsv $csv, AuditLogger $audit): StreamedResponse
    {
        $record->load(['fields', 'documentTypeDefinition']);
        $table = $csv->table($record);

        $audit->log(
            'record.exported',
            $record,
            new: ['rows' => count($table['rows'])],
            description: "Downloaded record #{$record->getKey()} as CSV.",
            actor: $request->user(),
        );

        return response()->streamDownload(function () use ($table) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\u{FEFF}");
            fputcsv($handle, $table['header']);
            foreach ($table['rows'] as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $csv->filename($record), [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * Several records' typed tables at once: Download selected and Download all
     * matching in the archive, and Download all at the end of a batch. Each
     * table is the one that record's own CSV download gives (RecordCsv), so later
     * edits are in it; the browser joins them into one CSV, or one Excel file with
     * a sheet for all of them and one per record. Oldest record first.
     *
     * `ids` picks the records; without it, `all` takes every record the archive's
     * filters (q, type, status, from, to) match. At most EXPORT_LIMIT records.
     */
    public function export(Request $request, RecordCsv $csv, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required_without:all', 'array', 'min:1', 'max:'.self::EXPORT_LIMIT],
            'ids.*' => ['integer', 'min:1'],
            'all' => ['sometimes', 'boolean'],
            ...$this->filterRules(),
        ], [
            'ids.max' => 'Pick at most '.number_format(self::EXPORT_LIMIT).' records for one download.',
        ]);

        $query = CivilRecord::query()->with(['fields', 'documentTypeDefinition']);
        if (! empty($validated['ids'])) {
            $query->whereKey(array_map('intval', $validated['ids']));
        } else {
            $this->applyFilters($query, $validated, SearchWords::from($validated['q'] ?? null));
        }

        $count = (clone $query)->count();
        if ($count > self::EXPORT_LIMIT) {
            return response()->json([
                'message' => number_format($count).' records match. One download takes at most '
                    .number_format(self::EXPORT_LIMIT).': narrow the search or the dates first.',
            ], 422);
        }

        $records = $query->orderBy('id')->get();
        $tables = $records->map(function (CivilRecord $record) use ($csv) {
            $table = $csv->table($record);

            return [
                'id' => $record->getKey(),
                'type' => $record->typeShortLabel(),
                'header' => $table['header'],
                'rows' => $table['rows'],
            ];
        })->values();

        $audit->log(
            'record.exported',
            new: [
                'records' => $records->count(),
                'rows' => $tables->sum(fn (array $table) => count($table['rows'])),
                'ids' => $records->modelKeys(),
            ],
            description: "Downloaded {$records->count()} ".str('record')->plural($records->count()).' as one table.',
            actor: $request->user(),
        );

        return response()->json(['records' => $tables], 200, ['Cache-Control' => 'no-store, no-cache']);
    }

    /**
     * Stream the stored scan. Scans hold personal data, so they live on the local
     * disk outside the web root and are served only to signed-in users who may
     * view the archive.
     */
    public function scan(CivilRecord $record)
    {
        abort_if($record->scan_path === null, 404);
        abort_unless(Storage::disk('local')->exists($record->scan_path), 404);

        return Response::file(
            Storage::disk('local')->path($record->scan_path),
            ['Content-Type' => $record->scan_mime ?? 'application/octet-stream'],
        );
    }

    /**
     * Stream the page image the record's field outlines were measured on.
     *
     * Detect may straighten a tilted page, so this can differ from the upload
     * that scan() serves; the scan card draws its boxes over this one. The same
     * access rule as the scan.
     */
    public function pageImage(CivilRecord $record)
    {
        abort_if($record->page_image_path === null, 404);
        abort_unless(Storage::disk('local')->exists($record->page_image_path), 404);

        return Response::file(
            Storage::disk('local')->path($record->page_image_path),
            ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    /** The page image of page 2 and on of a record saved from a spread. */
    public function extraPageImage(CivilRecord $record, int $pageNo)
    {
        $page = $record->extraPages()->where('page_no', $pageNo)->firstOrFail();
        abort_if($page->page_image_path === null, 404);
        abort_unless(Storage::disk('local')->exists($page->page_image_path), 404);

        return Response::file(
            Storage::disk('local')->path($page->page_image_path),
            ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    /** The stored upload of page 2 and on of a record saved from a spread. */
    public function extraPageScan(CivilRecord $record, int $pageNo)
    {
        $page = $record->extraPages()->where('page_no', $pageNo)->firstOrFail();
        abort_if($page->scan_path === null, 404);
        abort_unless(Storage::disk('local')->exists($page->scan_path), 404);

        return Response::file(
            Storage::disk('local')->path($page->scan_path),
            ['Content-Type' => $page->scan_mime ?? 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
