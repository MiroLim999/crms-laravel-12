@extends('layouts.app')

@section('title', 'Change Request #' . $changeRequest->getKey())

@section('content')
    <x-page-header :title="'Change Request #' . $changeRequest->getKey()"
                   :subtitle="'Raised by ' . ($changeRequest->requester?->name ?? 'Unknown') . ' · ' . $changeRequest->created_at->diffForHumans()">
        <a href="{{ route('change-requests.index') }}" class="btn btn-outline-secondary">Back</a>
    </x-page-header>

    <div class="row g-4">
        <div class="col-lg-8">
            <x-card title="Proposed changes"
                    :subtitle="'Record #' . $changeRequest->record->getKey() . ' · ' . $changeRequest->record->typeLabel()">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>Current</th>
                                <th>Proposed</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($changeRequest->items as $item)
                                <tr>
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

        <div class="col-lg-4">
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
                   class="btn btn-sm btn-outline-secondary w-100">View the record</a>
            </x-card>

            @if ($changeRequest->isOpen() && $canModerate)
                <x-card title="Decision"
                        subtitle="Approving applies the values. You are not editing the record.">
                    <form method="POST" action="{{ route('change-requests.approve', $changeRequest) }}" class="mb-3">
                        @csrf
                        <label for="approve-note" class="form-label">Note (optional)</label>
                        <textarea id="approve-note" name="decision_note" rows="2" class="form-control mb-2"></textarea>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="icon-base bx bx-check icon-sm me-1"></i> Approve &amp; apply
                        </button>
                    </form>

                    <hr>

                    <form method="POST" action="{{ route('change-requests.reject', $changeRequest) }}">
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
                    <form method="POST" action="{{ route('change-requests.withdraw', $changeRequest) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-danger w-100">Withdraw request</button>
                    </form>
                </x-card>
            @endif
        </div>
    </div>
@endsection
