@extends('layouts.app')

@section('title', 'Change Requests')

@section('content')
    <x-page-header title="Change Requests"
                   :subtitle="$canModerate
                       ? 'Approve or reject corrections proposed by Staff.'
                       : 'Corrections you have proposed to locked records.'" />

    <x-card>
        <form method="GET" action="{{ route('change-requests.index') }}" class="row g-3 mb-4">
            <div class="col-md-4">
                <select name="status" class="form-select" aria-label="Filter by status">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>
                            {{ $status->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-primary" type="submit">Filter</button>
            </div>
        </form>

        @if ($requests->isEmpty())
            <x-empty-state icon="bx-git-pull-request" title="Nothing here"
                           message="{{ $canModerate ? 'No change requests are waiting on a decision.' : 'You have not proposed any corrections yet.' }}" />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Request</th>
                            <th>Record</th>
                            <th>Fields</th>
                            <th>Requested</th>
                            <th>Status</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($requests as $request)
                            <tr>
                                <td>
                                    <a href="{{ route('change-requests.show', $request) }}" class="fw-medium">
                                        #{{ $request->getKey() }}
                                    </a>
                                    <div><small class="text-muted">{{ Str::limit($request->reason, 60) }}</small></div>
                                </td>
                                <td>
                                    @if ($request->record)
                                        <a href="{{ route('records.show', $request->record) }}">
                                            {{ $request->record->typeShortLabel() }} #{{ $request->record->getKey() }}
                                        </a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>{{ $request->items->count() }}</td>
                                <td class="text-muted">
                                    {{ $request->requester?->name }}
                                    <div><small>{{ $request->created_at->diffForHumans() }}</small></div>
                                </td>
                                <td>
                                    <span class="badge {{ $request->status->badgeClass() }}">
                                        {{ $request->status->label() }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('change-requests.show', $request) }}"
                                       class="btn btn-sm btn-outline-secondary">
                                        {{ $canModerate && $request->isOpen() ? 'Review' : 'View' }}
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
@endsection
