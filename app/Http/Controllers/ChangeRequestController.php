<?php

namespace App\Http\Controllers;

use App\Enums\ChangeRequestStatus;
use App\Models\ChangeRequest;
use App\Models\CivilRecord;
use App\Services\ChangeRequestService;
use App\Services\RecordFieldGrouper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Corrections to locked records.
 *
 * Staff open requests, Admin and Super Admin decide them. Approving is what
 * applies the new values - a reviewer never edits a record directly, which is the
 * whole point of routing corrections through here.
 */
class ChangeRequestController extends Controller
{
    public function __construct(
        private readonly ChangeRequestService $service,
        private readonly RecordFieldGrouper $fieldGrouper,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $search = trim($request->string('q')->toString());
        $selectedStatus = ChangeRequestStatus::tryFrom($request->string('status')->toString());

        $visibleRequests = ChangeRequest::query()
            // Staff see their own requests; reviewers see everything.
            ->when(! $user->can('change-requests.moderate'),
                fn ($query) => $query->where('requested_by', $user->getKey()));

        $statusCounts = (clone $visibleRequests)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count);

        $requests = (clone $visibleRequests)
            ->with(['record.documentTypeDefinition', 'record.fields', 'requester', 'reviewer', 'items'])
            ->when($search !== '', function ($query) use ($search, $user): void {
                $term = "%{$search}%";

                $query->where(function ($query) use ($search, $term, $user): void {
                    $query->where('reason', 'like', $term)
                        ->orWhereHas('record', function ($recordQuery) use ($search, $term): void {
                            $recordQuery->where('registry_number', 'like', $term)
                                ->orWhereHas('documentTypeDefinition', fn ($typeQuery) => $typeQuery
                                    ->where('name', 'like', $term)
                                    ->orWhere('short_name', 'like', $term));

                            if (ctype_digit($search)) {
                                $recordQuery->orWhereKey((int) $search);
                            }
                        });

                    if (ctype_digit($search)) {
                        $query->orWhereKey((int) $search);
                    }

                    if ($user->can('change-requests.moderate')) {
                        $query->orWhereHas('requester', fn ($requesterQuery) => $requesterQuery
                            ->where('name', 'like', $term));
                    }
                });
            })
            ->when($selectedStatus,
                fn ($query) => $query->where('status', $selectedStatus->value))
            ->orderByRaw("FIELD(status, 'pending') DESC")
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        $recordHeadings = $requests->getCollection()
            ->pluck('record')
            ->filter()
            ->unique('id')
            ->mapWithKeys(function (CivilRecord $record): array {
                $groups = $this->fieldGrouper->groups($record->fields);

                return [$record->getKey() => $this->fieldGrouper->heading($record, $groups)];
            });

        return view('change-requests.index', [
            'requests' => $requests,
            'statuses' => ChangeRequestStatus::cases(),
            'statusCounts' => $statusCounts,
            'recordHeadings' => $recordHeadings,
            'selectedStatus' => $selectedStatus,
            'search' => $search,
            'canModerate' => $user->can('change-requests.moderate'),
        ]);
    }

    /**
     * Propose corrections to a locked record.
     */
    public function create(CivilRecord $record, Request $request): View|RedirectResponse
    {
        $this->authorize('change-requests.create');

        if (! $record->isLocked()) {
            return redirect()->route('records.show', $record)
                ->with('error', 'This record is not locked, so it needs no change request.');
        }

        $record->load(['fields', 'documentTypeDefinition']);

        if ($pendingRequest = $record->changeRequests()
            ->where('status', ChangeRequestStatus::Pending->value)
            ->first()) {
            if (! $request->user()->can('change-requests.moderate')
                && $pendingRequest->requested_by !== $request->user()->getKey()) {
                return redirect()
                    ->route('records.show', $record)
                    ->with('error', 'This record already has a change request waiting for review.');
            }

            return redirect()
                ->route('change-requests.show', $pendingRequest)
                ->with('info', 'This record already has a change request waiting for review.');
        }

        $fieldGroups = $this->fieldGrouper->groups($record->fields);

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
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'registry_number' => ['nullable', 'string', 'max:64'],
            'values' => ['nullable', 'array'],
            'values.*' => ['nullable', 'string', 'max:2000'],
        ], [
            'reason.min' => 'Explain the correction in a sentence or two — a reviewer needs the context.',
        ]);

        try {
            $changeRequest = $this->service->open(
                $record->load('fields'),
                $validated['values'] ?? [],
                $validated['reason'],
                $request->user(),
                array_key_exists('registry_number', $validated)
                    ? ['registry_number' => $validated['registry_number']]
                    : [],
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('change-requests.show', $changeRequest)
            ->with('success', 'Change request submitted for review.');
    }

    public function show(ChangeRequest $changeRequest, Request $request): View
    {
        // Staff may only read their own; reviewers may read all.
        abort_unless(
            $request->user()->can('change-requests.moderate')
                || $changeRequest->requested_by === $request->user()->getKey(),
            403,
        );

        $changeRequest->load([
            'record.fields', 'record.documentTypeDefinition', 'requester', 'reviewer', 'items.field',
        ]);
        $record = $changeRequest->record;
        $recordGroups = $this->fieldGrouper->groups($record->fields);
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
            'canModerate' => $request->user()->can('change-requests.moderate'),
        ]);
    }

    public function approve(Request $request, ChangeRequest $changeRequest): RedirectResponse
    {
        $this->authorize('change-requests.moderate');

        $validated = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->service->approve($changeRequest, $request->user(), $validated['decision_note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Approved. The record has been updated.');
    }

    public function reject(Request $request, ChangeRequest $changeRequest): RedirectResponse
    {
        $this->authorize('change-requests.moderate');

        $validated = $request->validate([
            'decision_note' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'decision_note.required' => 'Give a reason for rejecting, so the requester knows what to fix.',
        ]);

        try {
            $this->service->reject($changeRequest, $request->user(), $validated['decision_note']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Rejected. The record is unchanged.');
    }

    public function withdraw(Request $request, ChangeRequest $changeRequest): RedirectResponse
    {
        abort_unless($changeRequest->requested_by === $request->user()->getKey(), 403);

        try {
            $this->service->withdraw($changeRequest, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Change request withdrawn.');
    }
}
