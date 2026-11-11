@extends('layouts.app')

@section('title', 'Corrections')

@section('content')
    <main class="change-request-index">
        <x-page-header title="Corrections"
                       subtitle="Changes made to saved records. Each one keeps the old values." />

        <x-card class="change-request-filter-card" title="Find a correction">
            <form method="GET" action="{{ route('change-requests.index') }}" class="row g-3 align-items-end">
                <div class="col-lg-10">
                    <label for="change-request-search" class="form-label">Search</label>
                    <input type="search" id="change-request-search" name="q" value="{{ $search }}"
                           class="form-control"
                           placeholder="Correction number, record number, record type or reason">
                </div>
                <div class="col-lg-2 d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1" type="submit">
                        <i class="icon-base bx bx-search icon-sm me-1"></i> Search
                    </button>
                    @if ($search !== '')
                        <a href="{{ route('change-requests.index') }}" class="btn btn-outline-secondary"
                           aria-label="Clear the search">Reset</a>
                    @endif
                </div>
            </form>
        </x-card>

        <x-card class="mt-4 change-request-list-card" title="All corrections"
                :subtitle="$requests->total() . ' correction(s) found'">
            @if ($requests->isEmpty())
                <x-empty-state icon="bx-edit" title="No corrections"
                               :message="$search !== ''
                                   ? 'Try clearing or widening the search.'
                                   : 'Open a record and choose Correct record to change a saved value.'" />
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle change-request-table">
                        <thead>
                            <tr>
                                <th>Correction</th>
                                <th>Record</th>
                                <th>Corrected by</th>
                                <th>Changes</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($requests as $changeRequest)
                                <tr>
                                    <td>
                                        <a href="{{ route('change-requests.show', $changeRequest) }}" class="fw-semibold">
                                            Correction #{{ $changeRequest->getKey() }}
                                        </a>
                                        {{-- Older requests from before corrections were saved at once. --}}
                                        @unless ($changeRequest->status === \App\Enums\ChangeRequestStatus::Approved)
                                            <span class="badge {{ $changeRequest->status->badgeClass() }} ms-1">
                                                {{ $changeRequest->status->label() }}
                                            </span>
                                        @endunless
                                        @if (filled($changeRequest->reason))
                                            <div class="change-request-table__reason">
                                                {{ Str::limit($changeRequest->reason, 72) }}
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($changeRequest->record)
                                            <a href="{{ route('records.show', $changeRequest->record) }}" class="change-request-record-link">
                                                <i class="icon-base bx {{ $changeRequest->record->typeIcon() }} icon-sm" aria-hidden="true"></i>
                                                <span>
                                                    <strong>{{ $recordHeadings->get($changeRequest->record_id, 'Record #'.$changeRequest->record_id) }}</strong>
                                                    <small>Record #{{ $changeRequest->record_id }}</small>
                                                </span>
                                            </a>
                                        @else
                                            <span class="text-muted">Record unavailable</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span>{{ $changeRequest->requester?->name ?? 'Unknown' }}</span>
                                        <small class="d-block text-muted" title="{{ \App\Support\LocalTime::format($changeRequest->created_at, 'j M Y, H:i') }}">
                                            {{ $changeRequest->created_at->diffForHumans() }}
                                        </small>
                                    </td>
                                    <td>
                                        <span class="change-request-count">{{ $changeRequest->changeCount() }}</span>
                                        <small class="text-muted">value{{ $changeRequest->changeCount() === 1 ? '' : 's' }}</small>
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('change-requests.show', $changeRequest) }}"
                                           class="btn btn-sm btn-outline-secondary">View details</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{ $requests->links() }}
            @endif
        </x-card>
    </main>
@endsection
