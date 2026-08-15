@extends('layouts.app')

@section('title', 'Change Requests')

@section('content')
    <main class="change-request-index">
        <x-page-header title="Change Requests"
                       :subtitle="$canModerate
                           ? 'Review proposed corrections before they update a verified record.'
                           : 'Track corrections you have proposed for verified records.'" />

        <section class="change-request-status-strip" aria-label="Change request status summary">
            @foreach ($statuses as $status)
                <a href="{{ route('change-requests.index', array_filter(['q' => $search, 'status' => $status->value])) }}"
                   class="change-request-status-card {{ $selectedStatus === $status ? 'is-active' : '' }}"
                   @if ($selectedStatus === $status) aria-current="page" @endif>
                    <span class="change-request-status-card__label">{{ $status->label() }}</span>
                    <strong>{{ $statusCounts->get($status->value, 0) }}</strong>
                    <small>
                        @switch($status)
                            @case(\App\Enums\ChangeRequestStatus::Pending) Waiting for review @break
                            @case(\App\Enums\ChangeRequestStatus::Approved) Applied to records @break
                            @case(\App\Enums\ChangeRequestStatus::Rejected) Closed without changes @break
                            @case(\App\Enums\ChangeRequestStatus::Withdrawn) Cancelled by requester @break
                        @endswitch
                    </small>
                </a>
            @endforeach
        </section>

        <x-card class="change-request-filter-card" title="Find a request">
            <form method="GET" action="{{ route('change-requests.index') }}" class="row g-3 align-items-end">
                <div class="col-lg-7">
                    <label for="change-request-search" class="form-label">Search</label>
                    <input type="search" id="change-request-search" name="q" value="{{ $search }}"
                           class="form-control"
                           placeholder="Request number, registry number, record type, reason{{ $canModerate ? ', or requester' : '' }}">
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label for="change-request-status" class="form-label">Status</label>
                    <select id="change-request-status" name="status" class="form-select">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected($selectedStatus === $status)>
                                {{ $status->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2 d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1" type="submit">
                        <i class="icon-base bx bx-search icon-sm me-1"></i> Search
                    </button>
                    @if ($search !== '' || $selectedStatus)
                        <a href="{{ route('change-requests.index') }}" class="btn btn-outline-secondary"
                           aria-label="Clear filters">Reset</a>
                    @endif
                </div>
            </form>
        </x-card>

        <x-card class="mt-4 change-request-list-card" title="Request queue"
                :subtitle="$requests->total() . ' request(s) found'">
            @if ($requests->isEmpty())
                <x-empty-state icon="bx-git-pull-request" title="No requests match"
                               :message="$search !== '' || $selectedStatus
                                   ? 'Try clearing or widening the current filters.'
                                   : ($canModerate
                                       ? 'No corrections are waiting in the request queue.'
                                       : 'You have not proposed any corrections yet.')" />
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle change-request-table">
                        <thead>
                            <tr>
                                <th>Request</th>
                                <th>Record</th>
                                <th>Requested by</th>
                                <th>Changes</th>
                                <th>Status</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($requests as $changeRequest)
                                <tr>
                                    <td>
                                        <a href="{{ route('change-requests.show', $changeRequest) }}" class="fw-semibold">
                                            Request #{{ $changeRequest->getKey() }}
                                        </a>
                                        <div class="change-request-table__reason">
                                            {{ Str::limit($changeRequest->reason, 72) }}
                                        </div>
                                    </td>
                                    <td>
                                        @if ($changeRequest->record)
                                            <a href="{{ route('records.show', $changeRequest->record) }}" class="change-request-record-link">
                                                <i class="icon-base bx {{ $changeRequest->record->typeIcon() }} icon-sm" aria-hidden="true"></i>
                                                <span>
                                                    <strong>{{ $recordHeadings->get($changeRequest->record_id, 'Record #'.$changeRequest->record_id) }}</strong>
                                                    <small>{{ $changeRequest->record->registry_number ?? 'No registry number' }}</small>
                                                </span>
                                            </a>
                                        @else
                                            <span class="text-muted">Record unavailable</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span>{{ $changeRequest->requester?->name ?? 'Unknown' }}</span>
                                        <small class="d-block text-muted" title="{{ $changeRequest->created_at->format('j M Y, H:i') }}">
                                            {{ $changeRequest->created_at->diffForHumans() }}
                                        </small>
                                    </td>
                                    <td>
                                        <span class="change-request-count">{{ $changeRequest->changeCount() }}</span>
                                        <small class="text-muted">value{{ $changeRequest->changeCount() === 1 ? '' : 's' }}</small>
                                    </td>
                                    <td>
                                        <span class="badge {{ $changeRequest->status->badgeClass() }}">
                                            {{ $changeRequest->status->label() }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('change-requests.show', $changeRequest) }}"
                                           class="btn btn-sm {{ $canModerate && $changeRequest->isOpen() ? 'btn-primary' : 'btn-outline-secondary' }}">
                                            {{ $canModerate && $changeRequest->isOpen() ? 'Review' : 'View details' }}
                                        </a>
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
