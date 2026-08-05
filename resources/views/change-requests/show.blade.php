@extends('layouts.app')

@section('title', 'Change Request #' . $changeRequest->getKey())

@section('content')
    <x-page-header :title="'Change Request #' . $changeRequest->getKey()"
                   :subtitle="'Raised by ' . ($changeRequest->requester?->name ?? 'Unknown') . ' · ' . $changeRequest->created_at->diffForHumans()">
        <a href="{{ route('change-requests.index') }}" class="btn btn-outline-secondary">Back</a>
    </x-page-header>

    @if (session('error'))
        <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
            <i class="icon-base bx bx-error-circle icon-md me-2"></i>
            {{ session('error') }}
        </div>
    @endif

    <div class="row g-4">
        {{-- ─────────────────── LEFT COLUMN ─────────────────── --}}
        <div class="col-lg-8">

            {{-- Proposed changes table (with checkboxes when open + moderator) --}}
            <x-card title="Proposed changes"
                    :subtitle="'Record #' . $changeRequest->record->getKey() . ' · ' . $changeRequest->record->doc_type->label()">

                @if ($changeRequest->isOpen() && $canModerate)
                    {{-- Select-All / Deselect-All toolbar --}}
                    <div class="d-flex align-items-center gap-3 mb-3 small">
                        <button type="button" id="btn-select-all"
                                class="btn btn-sm btn-outline-secondary">
                            <i class="icon-base bx bx-check-square icon-xs me-1"></i> Select all
                        </button>
                        <button type="button" id="btn-deselect-all"
                                class="btn btn-sm btn-outline-secondary">
                            <i class="icon-base bx bx-square icon-xs me-1"></i> Deselect all
                        </button>
                        <span class="text-muted ms-auto" id="selection-hint">
                            All <strong id="selected-count">{{ $changeRequest->items->count() }}</strong>
                            of {{ $changeRequest->items->count() }} selected
                        </span>
                    </div>
                @endif

                <div class="table-responsive">
                    <table class="table align-middle mb-0" id="items-table">
                        <thead>
                            <tr>
                                @if ($changeRequest->isOpen() && $canModerate)
                                    <th style="width:2.5rem;"></th>
                                @endif
                                <th>Field</th>
                                <th>Current (on record)</th>
                                <th>Proposed</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($changeRequest->items as $item)
                                <tr class="item-row" data-item-id="{{ $item->getKey() }}">
                                    @if ($changeRequest->isOpen() && $canModerate)
                                        <td>
                                            <div class="form-check mb-0">
                                                <input class="form-check-input item-checkbox"
                                                       type="checkbox"
                                                       name="item_ids[]"
                                                       value="{{ $item->getKey() }}"
                                                       id="item-{{ $item->getKey() }}"
                                                       checked
                                                       aria-label="Include {{ $item->field?->name ?? 'field' }} in approval">
                                            </div>
                                        </td>
                                    @endif
                                    <td class="text-muted">{{ $item->field?->name ?? 'Removed field' }}</td>
                                    <td><del class="text-danger">{{ $item->current_value ?: '—' }}</del></td>
                                    <td><span class="text-success fw-medium">{{ $item->proposed_value ?: '—' }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>

            <x-card class="mt-4" title="Reason given">
                <p class="mb-0">{{ $changeRequest->reason }}</p>
            </x-card>

            @if ($changeRequest->decision_note)
                <x-card class="mt-4" title="Decision note">
                    <p class="mb-0">{{ $changeRequest->decision_note }}</p>
                </x-card>
            @endif
        </div>

        {{-- ─────────────────── RIGHT COLUMN ─────────────────── --}}
        <div class="col-lg-4">

            {{-- ── Original scan for comparison ── --}}
            @if ($changeRequest->record->scan_path)
                <x-card title="Original scan" class="mb-4"
                        subtitle="Compare against the proposed values.">
                    @if (str_contains((string) $changeRequest->record->scan_mime, 'pdf'))
                        <a href="{{ route('records.scan', $changeRequest->record) }}"
                           target="_blank"
                           class="btn btn-outline-secondary w-100">
                            <i class="icon-base bx bx-file icon-sm me-1"></i> Open scanned PDF
                        </a>
                    @else
                        {{-- Inline image with lightbox on click --}}
                        <a href="{{ route('records.scan', $changeRequest->record) }}"
                           target="_blank"
                           id="scan-lightbox-trigger"
                           title="Open full-size scan">
                            <img src="{{ route('records.scan', $changeRequest->record) }}"
                                 alt="Original scanned certificate"
                                 class="img-fluid rounded border w-100"
                                 style="cursor:zoom-in;"
                                 id="scan-thumbnail">
                        </a>
                        <p class="small text-muted mt-2 mb-0 text-center">
                            Click image to open full size
                        </p>
                    @endif
                </x-card>
            @endif

            {{-- ── Status & metadata ── --}}
            <x-card title="Status" class="mb-4">
                <span class="badge {{ $changeRequest->status->badgeClass() }} mb-3">
                    {{ $changeRequest->status->label() }}
                </span>

                <dl class="row mb-0">
                    <dt class="col-5 fw-normal text-muted">Requested by</dt>
                    <dd class="col-7">{{ $changeRequest->requester?->name ?? '—' }}</dd>

                    @if ($changeRequest->reviewed_at)
                        <dt class="col-5 fw-normal text-muted">Reviewed by</dt>
                        <dd class="col-7">{{ $changeRequest->reviewer?->name ?? '—' }}</dd>

                        <dt class="col-5 fw-normal text-muted">Reviewed at</dt>
                        <dd class="col-7 mb-0">{{ $changeRequest->reviewed_at->format('j M Y, H:i') }}</dd>
                    @endif
                </dl>

                <hr>
                <a href="{{ route('records.show', $changeRequest->record) }}"
                   class="btn btn-sm btn-outline-secondary w-100">View the full record</a>
            </x-card>

            {{-- ── Decision panel (moderator only, open requests) ── --}}
            @if ($changeRequest->isOpen() && $canModerate)
                <x-card title="Decision"
                        subtitle="Approving applies the selected fields. You are not editing the record.">

                    {{-- Approve form — item_ids come from the checkboxes in the left column --}}
                    <form method="POST"
                          action="{{ route('change-requests.approve', $changeRequest) }}"
                          id="approve-form"
                          class="mb-3">
                        @csrf
                        {{-- Hidden relay: JS copies checked item IDs here so a single form
                             spans the two-column layout without nesting forms. --}}
                        <div id="approve-items-relay"></div>

                        <label for="approve-note" class="form-label">Note (optional)</label>
                        <textarea id="approve-note" name="decision_note"
                                  rows="2" class="form-control mb-2"></textarea>

                        <button type="submit" class="btn btn-primary w-100" id="approve-btn">
                            <i class="icon-base bx bx-check icon-sm me-1"></i>
                            Approve &amp; apply <span id="approve-label-count"
                                                      class="badge bg-white text-primary ms-1">
                                {{ $changeRequest->items->count() }}
                            </span>
                        </button>
                    </form>

                    <hr>

                    <form method="POST"
                          action="{{ route('change-requests.reject', $changeRequest) }}">
                        @csrf
                        <label for="reject-note" class="form-label">Reason for rejecting</label>
                        <textarea id="reject-note" name="decision_note" rows="2"
                                  class="form-control mb-2 @error('decision_note') is-invalid @enderror"
                                  required></textarea>
                        @error('decision_note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <button type="submit" class="btn btn-outline-danger w-100">
                            <i class="icon-base bx bx-x icon-sm me-1"></i> Reject
                        </button>
                    </form>
                </x-card>

            @elseif ($changeRequest->isOpen() && $changeRequest->requested_by === auth()->id())
                <x-card title="Withdraw">
                    <p class="small text-muted">
                        Changed your mind? Withdrawing closes the request without a decision.
                    </p>
                    <form method="POST"
                          action="{{ route('change-requests.withdraw', $changeRequest) }}">
                        @csrf
                        <button type="submit"
                                class="btn btn-outline-secondary w-100">Withdraw request</button>
                    </form>
                </x-card>
            @endif
        </div>
    </div>

@push('scripts')
<script>
(function () {
    'use strict';

    const checkboxes = document.querySelectorAll('.item-checkbox');
    const approveForm = document.getElementById('approve-form');
    const relay = document.getElementById('approve-items-relay');
    const selectedCount = document.getElementById('selected-count');
    const approveLabelCount = document.getElementById('approve-label-count');
    const approveBtn = document.getElementById('approve-btn');
    const btnSelectAll = document.getElementById('btn-select-all');
    const btnDeselectAll = document.getElementById('btn-deselect-all');
    const totalItems = checkboxes.length;

    if (!approveForm) return;  // not a moderator view

    // ── Sync the relay hidden inputs so the approve form picks up the checked IDs ──
    function syncRelay() {
        relay.innerHTML = '';
        let n = 0;
        checkboxes.forEach(function (cb) {
            // Dim the row when deselected for quick visual feedback
            const row = cb.closest('tr');
            if (cb.checked) {
                const hidden = document.createElement('input');
                hidden.type  = 'hidden';
                hidden.name  = 'item_ids[]';
                hidden.value = cb.value;
                relay.appendChild(hidden);
                if (row) row.style.opacity = '1';
                n++;
            } else {
                if (row) row.style.opacity = '0.4';
            }
        });

        // Update counters
        if (selectedCount)       selectedCount.textContent = n;
        if (approveLabelCount)   approveLabelCount.textContent = n;

        // Disable the approve button when nothing is selected
        if (approveBtn) approveBtn.disabled = (n === 0);
    }

    // ── Wire checkboxes ──
    checkboxes.forEach(function (cb) {
        cb.addEventListener('change', syncRelay);
    });

    // ── Select / Deselect All ──
    if (btnSelectAll) {
        btnSelectAll.addEventListener('click', function () {
            checkboxes.forEach(function (cb) { cb.checked = true; });
            syncRelay();
        });
    }
    if (btnDeselectAll) {
        btnDeselectAll.addEventListener('click', function () {
            checkboxes.forEach(function (cb) { cb.checked = false; });
            syncRelay();
        });
    }

    // ── Initialise on page load ──
    syncRelay();
})();
</script>
@endpush
@endsection
