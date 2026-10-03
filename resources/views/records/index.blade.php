@extends('layouts.app')

@section('title', 'Records Archive')

@section('content')
    <x-page-header title="Records Archive"
                   subtitle="Search submitted civil registry records. Read-only for every role.">
        @can('documents.process')
            <a href="{{ route('documents.create') }}" class="btn btn-primary">
                <i class="icon-base bx bx-scan icon-sm me-1"></i> New Document
            </a>
        @endcan
    </x-page-header>

    <x-card>
        <form method="GET" action="{{ route('records.index') }}" class="row g-3">
            {{-- Values come from the validated filters, so a bad one is not echoed back. --}}
            <div class="col-md-4">
                <label for="q" class="form-label">Search</label>
                <input type="search" id="q" name="q" value="{{ $filters['q'] ?? '' }}"
                       class="form-control @error('q') is-invalid @enderror" placeholder="Name or registry number">
                @error('q')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <label for="type" class="form-label">Type</label>
                <select id="type" name="type" class="form-select @error('type') is-invalid @enderror">
                    <option value="">All</option>
                    @foreach ($documentTypes as $type)
                        <option value="{{ $type->value }}" @selected(($filters['type'] ?? null) === $type->value)>
                            {{ $type->shortLabel() }}
                        </option>
                    @endforeach
                </select>
                @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <label for="status" class="form-label">Status</label>
                <select id="status" name="status" class="form-select @error('status') is-invalid @enderror">
                    <option value="">Any</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? null) === $status->value)>
                            {{ $status->label() }}
                        </option>
                    @endforeach
                </select>
                @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <label for="from" class="form-label">From</label>
                <input type="date" id="from" name="from" value="{{ $filters['from'] ?? '' }}"
                       class="form-control @error('from') is-invalid @enderror">
                @error('from')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <label for="to" class="form-label">To</label>
                <input type="date" id="to" name="to" value="{{ $filters['to'] ?? '' }}"
                       class="form-control @error('to') is-invalid @enderror">
                @error('to')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit">
                    <i class="icon-base bx bx-search icon-sm me-1"></i> Search
                </button>
                <a href="{{ route('records.index') }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </x-card>

    <x-card class="mt-4" :subtitle="$records->total() . ' record(s)'">
        @if ($records->isEmpty())
            <x-empty-state icon="bx-archive" title="No records match"
                           message="Adjust the search, or digitise a new certificate." />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Record</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($records as $record)
                            <tr>
                                <td>
                                    <a href="{{ route('records.show', $record) }}" class="fw-medium">
                                        {{ $record->title() }}
                                    </a>
                                    <div>
                                        <small class="text-muted">
                                            {{ $record->registry_number ?? 'No registry number' }}
                                        </small>
                                    </div>
                                </td>
                                <td>
                                    <span class="d-inline-flex align-items-center gap-1">
                                        <i class="icon-base bx {{ $record->typeIcon() }} icon-sm text-muted"></i>
                                        {{ $record->typeShortLabel() }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge {{ $record->status->badgeClass() }}">
                                        {{ $record->status->label() }}
                                    </span>
                                    @if ($record->isLocked())
                                        <i class="icon-base bx bx-lock-alt icon-sm text-muted ms-1"
                                           title="Locked — changes need a change request"></i>
                                    @endif
                                </td>
                                <td class="text-muted">
                                    {{ \App\Support\LocalTime::format($record->submitted_at, 'j M Y') ?: '—' }}
                                    @if ($record->submitter)
                                        <div><small>{{ $record->submitter->name }}</small></div>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('records.show', $record) }}"
                                       class="btn btn-sm btn-outline-secondary">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $records->links() }}
        @endif
    </x-card>
@endsection
