<?php

namespace App\Http\Controllers;

use App\Enums\RecordStatus;
use App\Models\AuditLog;
use App\Models\BirthRecord;
use App\Models\CivilRecord;
use App\Models\DeathRecord;
use App\Models\DocumentTypeDefinition;
use App\Models\MarriageRecord;
use App\Models\RecordField;
use App\Models\RecordPage;
use App\Models\ScanBatch;
use App\Models\ScanBatchPage;
use App\Services\AuditLogger;
use App\Support\RegistryFields;
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

    /** See batchesKeptSince(): false until asked. */
    private \Illuminate\Support\Carbon|false|null $batchesKeptSince = false;

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
            // A batch's id, or "single": records saved one file at a time.
            'batch' => ['nullable', 'string', 'regex:/^(single|[1-9][0-9]{0,9})$/'],
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
            // The records one batch saved (its pages know their record), or those saved
            // one file at a time since batches are kept (older ones cannot be told apart).
            ->when($filters['batch'] ?? null, fn ($q, $batch) => $batch === 'single'
                ? $q->whereNotIn('id', ScanBatchPage::query()->select('record_id')->whereNotNull('record_id'))
                    ->where('submitted_at', '>=', $this->batchesKeptSince() ?? now()->addYear())
                : $q->whereIn('id', ScanBatchPage::query()->select('record_id')->where('scan_batch_id', (int) $batch)->whereNotNull('record_id')))
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

        // valid() also hands back every other value in the address (the results page, say),
        // which is not a filter: only the filters themselves are kept.
        $filters = array_intersect_key($validator->valid(), $this->filterRules());
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
            'batches' => $this->savedBatches(isset($filters['batch']) && $filters['batch'] !== 'single' ? (int) $filters['batch'] : null),
            'filters' => $filters,
            'documentTypes' => DocumentTypeDefinition::ordered(),
            'statuses' => RecordStatus::cases(),
            // Distinct book numbers per certificate type, for the "Download one book as CSV" dropdowns.
            'booksByType' => [
                'birth' => BirthRecord::query()->distinct()->pluck('book_no')->sort()->values()->all(),
                'death' => DeathRecord::query()->distinct()->pluck('book_no')->sort()->values()->all(),
                'marriage' => MarriageRecord::query()->distinct()->pluck('book_no')->sort()->values()->all(),
            ],
        ]);
    }

    /**
     * What the list says of each record on the page. A register page (several
     * people) is named by its file and pages, with how many people and the
     * first and last value of its first column ("1978-0001 to 1978-0020"); a
     * single certificate by its first value (a name), with its file under it.
     *
     * Each also says where it came from: the batch that saved it (with when),
     * "single" (one file at a time), or "unknown" (saved before batches were
     * kept, so either).
     *
     * @return array<int, array{title: string, people: int, range: array{0: string, 1: string}|null, file: string|null, source: string, batch: array{id: int, label: string}|null}>
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
        // The batch each one was saved from (its pages know their record).
        $batchOf = ScanBatchPage::query()
            ->whereIn('record_id', $ids)
            ->join('scan_batches', 'scan_batches.id', '=', 'scan_batch_pages.scan_batch_id')
            ->get(['scan_batch_pages.record_id', 'scan_batches.id as batch_id', 'scan_batches.created_at as batch_created'])
            ->keyBy('record_id');
        $keptSince = $this->batchesKeptSince();

        return $page->mapWithKeys(function (CivilRecord $record) use ($people, $lines, $extraPages, $batchOf, $keptSince) {
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

            $fromBatch = $batchOf[$record->getKey()] ?? null;
            $source = $fromBatch ? 'batch'
                : ($keptSince !== null && $record->submitted_at !== null && $record->submitted_at->gte($keptSince) ? 'single' : 'unknown');

            return [$record->getKey() => [
                'title' => $register ? $file : $record->title(),
                'people' => $count,
                'range' => $range,
                'file' => $register ? null : $file,
                'source' => $source,
                'batch' => $fromBatch ? [
                    'id' => (int) $fromBatch->batch_id,
                    'label' => LocalTime::format(\Illuminate\Support\Carbon::parse($fromBatch->batch_created), 'j M, g:i a'),
                ] : null,
            ]];
        })->all();
    }

    /**
     * Since when a batch is kept on the server, so a record saved since then
     * without one was saved one file at a time. Null: no batch yet.
     */
    private function batchesKeptSince(): ?\Illuminate\Support\Carbon
    {
        // Asked once per request (never kept between requests: batches come and go).
        if ($this->batchesKeptSince === false) {
            $first = ScanBatch::query()->min('created_at');
            $this->batchesKeptSince = $first ? \Illuminate\Support\Carbon::parse($first) : null;
        }

        return $this->batchesKeptSince;
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
     * Download one book's entries as one UTF-8 CSV. The type and book number
     * are picked from dropdowns on the archive page. One row per register
     * entry, with the certificate's typed columns as headers.
     */
    public function downloadBook(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:birth,death,marriage'],
            'book_no' => ['required', 'string', 'max:32'],
        ]);

        $model = match ($validated['type']) {
            'birth' => BirthRecord::class,
            'death' => DeathRecord::class,
            'marriage' => MarriageRecord::class,
        };

        $records = $model::where('book_no', $validated['book_no'])
            ->orderBy('page_no')
            ->orderBy('reg_no')
            ->get();

        // The register's own columns, in the order its downloads always use.
        $columns = RegistryFields::keys($validated['type']);

        app(AuditLogger::class)->log(
            'record.exported',
            new: [
                'type' => $validated['type'],
                'book_no' => $validated['book_no'],
                'rows' => $records->count(),
            ],
            description: "Downloaded book {$validated['book_no']} ({$validated['type']}) as CSV.",
            actor: $request->user(),
        );

        return response()->streamDownload(function () use ($records, $columns) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM: Excel shows ñ and accents correctly
            fputcsv($handle, $columns);
            foreach ($records as $record) {
                $row = [];
                foreach ($columns as $column) {
                    $row[] = $this->bookCsvCell($record->getAttribute($column));
                }
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, RegistryFields::fileName(
            \App\Models\DocumentTypeDefinition::where('key', $validated['type'])->first()?->label() ?? ucfirst($validated['type']),
            "Book {$validated['book_no']}",
            now()->toDateString(),
        ).'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * One CSV cell's text: true / false, plain digits, blank for none (as
     * step 4's downloads write it).
     */
    private function bookCsvCell(mixed $value): string
    {
        return RegistryFields::cell($value);
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
