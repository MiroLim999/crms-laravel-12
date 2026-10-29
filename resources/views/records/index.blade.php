@extends('layouts.app')

@section('title', 'Records Archive')

@php
    // Every record has one status (Submitted), so there is no status filter or
    // column here, same as Reports.
    $hasFilters = collect($filters)->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty();
@endphp

@section('content')
    <x-page-header title="Records Archive"
                   subtitle="Search submitted civil registry records by name or registry number." />

    <x-card>
        <form method="GET" action="{{ route('records.index') }}" class="row g-3 align-items-end">
            {{-- Values come from the validated filters, so a bad one is not echoed back. --}}
            <div class="col-sm-12 col-xl-6">
                <label for="q" class="form-label">Search</label>
                <div class="d-flex gap-2">
                    {{-- data-record-search: the "/" shortcut lands here, since the top bar's search is hidden on this page. --}}
                    <input type="search" id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="255"
                           class="form-control flex-grow-1 @error('q') is-invalid @enderror" placeholder="Name or registry number"
                           aria-keyshortcuts="/" data-record-search>
                    <button class="btn btn-primary" type="submit">
                        <i class="icon-base bx bx-search icon-sm me-1" aria-hidden="true"></i> Search
                    </button>
                    @if ($hasFilters)
                        <a href="{{ route('records.index') }}" class="btn btn-outline-secondary" title="Clear all filters">Reset</a>
                    @endif
                </div>
                @error('q')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-4 col-xl-2">
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
            <div class="col-sm-4 col-xl-2">
                <label for="from" class="form-label">From</label>
                <input type="date" id="from" name="from" value="{{ $filters['from'] ?? '' }}"
                       class="form-control @error('from') is-invalid @enderror">
                @error('from')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-4 col-xl-2">
                <label for="to" class="form-label">To</label>
                <input type="date" id="to" name="to" value="{{ $filters['to'] ?? '' }}"
                       class="form-control @error('to') is-invalid @enderror">
                @error('to')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </form>
    </x-card>

    <x-card class="mt-4" :title="number_format($records->total()).' '.($hasFilters ? 'matching ' : '').str('record')->plural($records->total())">
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
                                    @if ($record->isLocked())
                                        <i class="icon-base bx bx-lock-alt icon-sm text-muted ms-1"
                                           title="Locked — changes need a change request"></i>
                                    @endif
                                    <div>
                                        <small class="text-muted">
                                            {{ $record->registry_number ?? 'No registry number' }}
                                        </small>
                                    </div>
                                    {{-- Why a search found this record, when the title above does not show it. --}}
                                    @php($match = $record->searchMatch())
                                    @if ($match)
                                        @php($matchValue = (string) $match['field']->verified_value)
                                        <div class="mt-1">
                                            <small>
                                                <span class="text-muted">{{ $match['field']->name }}:</span>
                                                <span @if (mb_strlen($matchValue) > 80) title="{{ $matchValue }}" @endif>{{ str($matchValue)->limit(80) }}</span>
                                                @if ($match['more'] > 0)
                                                    <span class="text-muted">· {{ $match['more'] }} more {{ str('match')->plural($match['more']) }}</span>
                                                @endif
                                            </small>
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <span class="d-inline-flex align-items-center gap-1">
                                        <i class="icon-base bx {{ $record->typeIcon() }} icon-sm text-muted"></i>
                                        {{ $record->typeShortLabel() }}
                                    </span>
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
