<?php

namespace App\Http\Controllers;

use App\Enums\ChangeRequestStatus;
use App\Models\ChangeRequest;
use App\Models\CivilRecord;
use App\Services\ChangeRequestService;
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
    public function __construct(private readonly ChangeRequestService $service) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $requests = ChangeRequest::query()
            ->with(['record', 'requester', 'reviewer', 'items'])
            // Staff see their own requests; reviewers see everything.
            ->when(! $user->can('change-requests.moderate'),
                fn ($q) => $q->where('requested_by', $user->getKey()))
            ->when($request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status')))
            ->orderByRaw("FIELD(status, 'pending') DESC")
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return view('change-requests.index', [
            'requests' => $requests,
            'statuses' => ChangeRequestStatus::cases(),
            'canModerate' => $user->can('change-requests.moderate'),
        ]);
    }

    /**
     * Propose corrections to a locked record.
     */
    public function create(CivilRecord $record): View|RedirectResponse
    {
        $this->authorize('change-requests.create');

        if (! $record->isLocked()) {
            return redirect()->route('records.show', $record)
                ->with('error', 'This record is not locked, so it needs no change request.');
        }

        return view('change-requests.create', ['record' => $record->load('fields')]);
    }

    public function store(Request $request, CivilRecord $record): RedirectResponse
    {
        $this->authorize('change-requests.create');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'string', 'max:2000'],
        ], [
            'reason.min' => 'Explain the correction in a sentence or two — a reviewer needs the context.',
        ]);

        try {
            $changeRequest = $this->service->open(
                $record->load('fields'),
                $validated['values'],
                $validated['reason'],
                $request->user(),
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

        return view('change-requests.show', [
            'changeRequest' => $changeRequest->load([
                'record.fields', 'requester', 'reviewer', 'items.field',
            ]),
            'canModerate' => $request->user()->can('change-requests.moderate'),
        ]);
    }

    public function approve(Request $request, ChangeRequest $changeRequest): RedirectResponse
    {
        $this->authorize('change-requests.moderate');

        $validated = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:2000'],
            'item_ids'      => ['nullable', 'array'],
            'item_ids.*'    => ['integer'],
        ]);

        // Resolve which items are in scope.
        // If the form sent item_ids, intersect with the actual items on this
        // request so a hand-crafted POST cannot reference a foreign item.
        $selectedItemIds = null;
        if (array_key_exists('item_ids', $validated) && is_array($validated['item_ids'])) {
            $validIds = $changeRequest->items->pluck('id')->all();
            $selectedItemIds = array_values(
                array_intersect(
                    array_map('intval', $validated['item_ids']),
                    $validIds,
                )
            );

            if (empty($selectedItemIds)) {
                return back()
                    ->withInput()
                    ->with('error', 'Select at least one field to approve.');
            }
        }

        try {
            $this->service->approve(
                $changeRequest,
                $request->user(),
                $validated['decision_note'] ?? null,
                $selectedItemIds,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $count = $selectedItemIds !== null ? count($selectedItemIds) : $changeRequest->items->count();

        return back()->with('success', "Approved. {$count} field(s) applied to the record.");
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
