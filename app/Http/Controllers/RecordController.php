<?php

namespace App\Http\Controllers;

use App\Enums\ChangeRequestStatus;
use App\Enums\RecordStatus;
use App\Models\CivilRecord;
use App\Models\DocumentTypeDefinition;
use App\Services\RecordFieldGrouper;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;

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
        ], [], ['q' => 'search', 'from' => 'from date', 'to' => 'to date']);

        // The filters come from the query string, so an edited URL can carry a
        // bad one. Its message is shown on this page and the filter left out,
        // instead of the usual redirect back: a typed URL has no referrer, so
        // "back" could be another page, where the message would never show.
        if ($validator->fails()) {
            view()->share('errors', (new ViewErrorBag)->put('default', $validator->errors()));
        }

        $filters = $validator->valid();

        $records = CivilRecord::query()
            ->with(['fields', 'submitter', 'documentTypeDefinition'])
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $query->where(function ($q) use ($term) {
                    $q->where('registry_number', 'like', $term)
                        ->orWhereHas('fields', fn ($f) => $f->where('verified_value', 'like', $term));
                });
            })
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->whereHas(
                'documentTypeDefinition',
                fn ($typeQuery) => $typeQuery->where('key', $type),
            ))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('submitted_at', '>=', LocalTime::dayStart($from)))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('submitted_at', '<=', LocalTime::dayEnd($to)))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('records.index', [
            'records' => $records,
            'filters' => $filters,
            'documentTypes' => DocumentTypeDefinition::ordered(),
            'statuses' => RecordStatus::cases(),
        ]);
    }

    public function show(CivilRecord $record): View
    {
        $record->load([
            'fields',
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
        $registryWasCorrected = $approvedRequests->contains->changes_registry_number;

        return view('records.show', [
            'record' => $record,
            'fieldGroups' => $fieldGroups,
            'recordHeading' => $this->fieldGrouper->heading($record, $fieldGroups),
            'fieldChanges' => $fieldChanges,
            'personCount' => collect($fieldGroups)->where('kind', 'person')->count(),
            'firstPersonGroupId' => collect($fieldGroups)->firstWhere('kind', 'person')['id'] ?? null,
            'registryWasCorrected' => $registryWasCorrected,
            'ocrAdjustedCount' => $record->fields->filter->wasCorrected()->count(),
            'postSubmissionChangeCount' => $fieldChanges->count() + ($registryWasCorrected ? 1 : 0),
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
}
