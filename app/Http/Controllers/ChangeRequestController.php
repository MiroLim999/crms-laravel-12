<?php

namespace App\Http\Controllers;

use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\CivilRecord;
use App\Services\ChangeRequestService;
use App\Services\RecordFieldGrouper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Corrections to saved records (stored as change requests).
 *
 * Saving a correction changes the record at once; the old and new values stay in
 * the record's Change history and the Audit Log. The list and detail pages are
 * read-only.
 */
class ChangeRequestController extends Controller
{
    public function __construct(
        private readonly ChangeRequestService $service,
        private readonly RecordFieldGrouper $fieldGrouper,
    ) {}

    public function index(Request $request): View
    {
        // A string only. An edited URL can send ?q[]=x, an array, which would
        // crash the string read below.
        $request->validate(['q' => ['nullable', 'string']], [], ['q' => 'search']);

        $search = trim($request->string('q')->toString());

        $corrections = ChangeRequest::query()
            ->with(['record.documentTypeDefinition', 'record.fields', 'record.template', 'requester', 'items'])
            ->when($search !== '', function ($query) use ($search): void {
                $term = "%{$search}%";

                $query->where(function ($query) use ($search, $term): void {
                    $query->where('reason', 'like', $term)
                        ->orWhereHas('record', function ($recordQuery) use ($search, $term): void {
                            $recordQuery->whereHas('documentTypeDefinition', fn ($typeQuery) => $typeQuery
                                ->where('name', 'like', $term)
                                ->orWhere('short_name', 'like', $term));

                            if (ctype_digit($search)) {
                                $recordQuery->orWhereKey((int) $search);
                            }
                        });

                    if (ctype_digit($search)) {
                        $query->orWhereKey((int) $search);
                    }
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $recordHeadings = $corrections->getCollection()
            ->pluck('record')
            ->filter()
            ->unique('id')
            ->mapWithKeys(function (CivilRecord $record): array {
                $groups = $this->fieldGrouper->groups($record->fields, $record->template);

                return [$record->getKey() => $this->fieldGrouper->heading($record, $groups)];
            });

        return view('change-requests.index', [
            'requests' => $corrections,
            'recordHeadings' => $recordHeadings,
            'search' => $search,
        ]);
    }

    /**
     * The Correct record form.
     */
    public function create(CivilRecord $record): View
    {
        $this->authorize('change-requests.create');

        $record->load(['fields', 'documentTypeDefinition']);
        $fieldGroups = $this->fieldGrouper->groups($record->fields, $record->template);

        return view('change-requests.create', [
            'record' => $record,
            'fieldGroups' => $fieldGroups,
            'recordHeading' => $this->fieldGrouper->heading($record, $fieldGroups),
            'personCount' => collect($fieldGroups)->where('kind', 'person')->count(),
        ]);
    }

    public function store(Request $request, CivilRecord $record): RedirectResponse
    {
        $this->authorize('change-requests.create');

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
            'values' => ['nullable', 'array'],
            'values.*' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->service->correct(
                $record->load('fields'),
                $validated['values'] ?? [],
                $validated['reason'] ?? null,
                $request->user(),
            );
        } catch (ChangeRequestException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('records.show', $record)
            ->with('success', 'Correction saved.');
    }

    public function show(ChangeRequest $changeRequest): View
    {
        $changeRequest->load([
            'record.fields', 'record.template', 'record.documentTypeDefinition', 'requester', 'reviewer', 'items.field',
        ]);
        $record = $changeRequest->record;
        $recordGroups = $this->fieldGrouper->groups($record->fields, $record->template);
        $itemsByField = $changeRequest->items
            ->filter(fn ($item) => $item->field !== null)
            ->keyBy('record_field_id');
        $changeGroups = collect($recordGroups)
            ->map(function (array $group) use ($itemsByField): ?array {
                $items = $group['fields']
                    ->map(fn ($field) => $itemsByField->get($field->getKey()))
                    ->filter()
                    ->values();

                return $items->isEmpty() ? null : [...$group, 'items' => $items];
            })
            ->filter()
            ->values();

        return view('change-requests.show', [
            'changeRequest' => $changeRequest,
            'changeGroups' => $changeGroups,
            'orphanItems' => $changeRequest->items->whereNull('field')->values(),
            'changedFields' => $changeRequest->items->pluck('field')->filter()->values(),
            'recordHeading' => $this->fieldGrouper->heading($record, $recordGroups),
        ]);
    }
}
