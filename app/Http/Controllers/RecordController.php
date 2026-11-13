<?php

namespace App\Http\Controllers;

use App\Enums\ChangeRequestStatus;
use App\Enums\RecordStatus;
use App\Models\CivilRecord;
use App\Models\DocumentTypeDefinition;
use App\Services\ArchiveExport;
use App\Services\AuditLogger;
use App\Services\RecordCsv;
use App\Services\RecordFieldGrouper;
use App\Support\LocalTime;
use App\Support\SearchWords;
use App\Support\TableDownload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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
    public function __construct(private readonly RecordFieldGrouper $fieldGrouper) {}

    public function index(Request $request): View
    {
        $validator = Validator::make($request->all(), [
            'q' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'exists:document_types,key'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(RecordStatus::cases(), 'value'))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'download' => ['nullable', 'string', 'in:pending,done'],
        ], [], ['q' => 'search', 'from' => 'from date', 'to' => 'to date', 'download' => 'download status']);

        // The filters come from the query string, so an edited URL can carry a
        // bad one. Its message is shown on this page and the filter left out,
        // instead of the usual redirect back: a typed URL has no referrer, so
        // "back" could be another page, where the message would never show.
        if ($validator->fails()) {
            view()->share('errors', (new ViewErrorBag)->put('default', $validator->errors()));
        }

        $filters = $validator->valid();
        $words = SearchWords::from($filters['q'] ?? null);

        // Records not yet downloaded come first: they are the ones still to be
        // taken out of the archive. Within each group, newest first.
        $records = $this->archiveQuery($filters, $words)
            ->withTitleField()
            ->with(['submitter', 'documentTypeDefinition'])
            ->withSearchMatches($words)
            ->withDownloadState()
            ->orderBy('is_downloaded')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('records.index', [
            'records' => $records,
            'pendingCount' => $this->archiveQuery($filters, $words)->doesntHave('downloads')->count(),
            'filters' => $filters,
            'documentTypes' => DocumentTypeDefinition::ordered(),
            'statuses' => RecordStatus::cases(),
        ]);
    }

    /**
     * Download the records the archive is showing (same search and filters) as
     * one CSV or Excel file holding their checked values, one row per person.
     *
     * ?format=csv|xlsx (default csv). ?scope=new takes only the records never
     * downloaded before, ?scope=done only those already downloaded, ?scope=all
     * (default) every matching one. Each record in the file is marked as
     * downloaded once the file is written.
     */
    public function export(Request $request, ArchiveExport $export, AuditLogger $audit)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'exists:document_types,key'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(RecordStatus::cases(), 'value'))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'download' => ['nullable', 'string', 'in:pending,done'],
            'format' => ['nullable', 'string', 'in:'.implode(',', TableDownload::FORMATS)],
            'scope' => ['nullable', 'string', 'in:new,done,all'],
        ]);
        $format = $filters['format'] ?? 'csv';
        $scope = $filters['scope'] ?? 'all';
        unset($filters['format'], $filters['scope']);

        $query = $this->archiveQuery($filters, SearchWords::from($filters['q'] ?? null));
        if ($scope === 'new') {
            $query->doesntHave('downloads');
        } elseif ($scope === 'done') {
            $query->has('downloads');
        }
        $count = (clone $query)->count();

        if ($count === 0) {
            return back()->with('error', match ($scope) {
                'new' => 'Every matching record has already been downloaded.',
                'done' => 'None of the matching records have been downloaded yet.',
                default => 'No records match, so there is nothing to download.',
            });
        }

        // Logged before the stream opens, as with the Reports export: a download
        // that fails halfway still happened as far as the trail is concerned.
        $audit->log(
            'records.exported',
            new: array_filter([...$filters, 'scope' => $scope, 'format' => $format, 'records' => $count], fn ($value) => $value !== null),
            description: "Downloaded {$count} archive record(s) as ".TableDownload::label($format).match ($scope) {
                'new' => ' (not yet downloaded only).',
                'done' => ' (already downloaded only).',
                default => '.',
            },
            actor: $request->user(),
        );

        $header = $export->header($query);

        return TableDownload::respond(
            $format,
            'crms-archive-'.LocalTime::format(now(), 'Ymd-His'),
            'Records archive',
            $header,
            $export->rows($query, $header, $format, $request->user()->getKey()),
        );
    }

    /**
     * The archive's search and filters, shared by the list and the download so
     * the file always holds what the page showed.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $words
     * @return Builder<CivilRecord>
     */
    private function archiveQuery(array $filters, array $words): Builder
    {
        return CivilRecord::query()
            ->search($words)
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->whereHas(
                'documentTypeDefinition',
                fn ($typeQuery) => $typeQuery->where('key', $type),
            ))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('submitted_at', '>=', LocalTime::dayStart($from)))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('submitted_at', '<=', LocalTime::dayEnd($to)))
            ->when(($filters['download'] ?? null) === 'pending', fn ($q) => $q->doesntHave('downloads'))
            ->when(($filters['download'] ?? null) === 'done', fn ($q) => $q->has('downloads'));
    }

    public function show(CivilRecord $record): View
    {
        $record->load([
            'fields',
            'extraPages',
            'documentTypeDefinition',
            'changeRequests.requester',
            'changeRequests.reviewer',
            'changeRequests.items.field',
        ]);

        $fieldGroups = $this->fieldGrouper->groups($record->fields, $record->template);
        $approvedRequests = $record->changeRequests
            ->filter(fn ($changeRequest) => $changeRequest->status === ChangeRequestStatus::Approved);
        $fieldChanges = $approvedRequests
            ->flatMap(fn ($changeRequest) => $changeRequest->items->map(
                fn ($item) => ['field_id' => $item->record_field_id, 'request' => $changeRequest],
            ))
            ->groupBy('field_id');

        return view('records.show', [
            'record' => $record,
            'fieldGroups' => $fieldGroups,
            'recordHeading' => $this->fieldGrouper->heading($record, $fieldGroups),
            'fieldChanges' => $fieldChanges,
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
        // A record taken out on its own counts as downloaded in the archive list too.
        ArchiveExport::markDownloaded([$record->getKey()], 'csv', $request->user()->getKey());

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
