@extends('layouts.app')

@section('title', 'Reports')

@php
    // Records have one status (Submitted), so there is no status filter, tile or
    // column. They are also created and submitted in the same step, by the same
    // person, so one "Submitted" column covers both.
    $hasFilters = collect($filters)->except('status')->filter(fn ($value) => $value !== null)->isNotEmpty();
    $threshold = \App\Models\OcrSetting::threshold();
    $total = $summary['total'];
@endphp

@section('content')
    <x-page-header title="Reports"
                   subtitle="Filter submitted records and export them as CSV.">
        @if ($total > 0)
            <a href="{{ route('reports.export', request()->query()) }}" class="btn btn-primary">
                <i class="icon-base bx bx-download icon-sm me-1" aria-hidden="true"></i>
                Export
            </a>
        @else
            <button type="button" class="btn btn-primary" disabled title="No records match the filters.">
                <i class="icon-base bx bx-download icon-sm me-1" aria-hidden="true"></i> Export
            </button>
        @endif
    </x-page-header>

    <x-card class="mb-4">
        <form method="GET" action="{{ route('reports.index') }}" class="row g-3 align-items-end">
            <div class="col-sm-6 col-xl-2">
                <label for="from" class="form-label">From</label>
                <input type="date" id="from" name="from" value="{{ $filters['from'] }}"
                       class="form-control @error('from') is-invalid @enderror">
            </div>
            <div class="col-sm-6 col-xl-2">
                <label for="to" class="form-label">To</label>
                <input type="date" id="to" name="to" value="{{ $filters['to'] }}"
                       class="form-control @error('to') is-invalid @enderror">
            </div>
            <div class="col-sm-6 col-xl-3">
                <label for="doc_type" class="form-label">Document type</label>
                <select id="doc_type" name="doc_type" class="form-select @error('doc_type') is-invalid @enderror">
                    <option value="">All types</option>
                    @foreach ($documentTypes as $type)
                        <option value="{{ $type->value }}" @selected($filters['doc_type'] === $type->value)>
                            {{ $type->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-xl-3">
                <label for="submitted_by" class="form-label">Submitted by</label>
                <select id="submitted_by" name="submitted_by" class="form-select @error('submitted_by') is-invalid @enderror">
                    <option value="">Anyone</option>
                    @foreach ($dataEntryUsers as $person)
                        <option value="{{ $person->id }}" @selected($filters['submitted_by'] === $person->id)>
                            {{ $person->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-xl-2 d-flex gap-2">
                <button class="btn btn-primary flex-grow-1" type="submit">
                    <i class="icon-base bx bx-filter-alt icon-sm me-1" aria-hidden="true"></i> Apply
                </button>
                @if ($hasFilters)
                    <a href="{{ route('reports.index') }}" class="btn btn-outline-secondary" title="Clear all filters">Reset</a>
                @endif
            </div>
        </form>
    </x-card>

    <x-card :title="number_format($total).' '.($hasFilters ? 'matching ' : '').str('record')->plural($total)">
        @if ($records->isEmpty())
            <x-empty-state icon="bx-file-find" title="No records match"
                           message="Widen the date range, or clear a filter." />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Record</th>
                            <th>Type</th>
                            <th>Submitted</th>
                            <th class="text-end">Avg. confidence</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($records as $record)
                            @php($confidence = $record->fields_avg_ocr_confidence === null ? null : round((float) $record->fields_avg_ocr_confidence, 1))
                            <tr>
                                <td>
                                    <a href="{{ route('records.show', $record) }}" class="fw-medium">{{ $record->title() }}</a>
                                    <div>
                                        <small class="text-muted">{{ $record->registry_number ?? 'No registry number' }}</small>
                                    </div>
                                </td>
                                <td>
                                    <span class="d-inline-flex align-items-center gap-1">
                                        <i class="icon-base bx {{ $record->typeIcon() }} icon-sm text-muted" aria-hidden="true"></i>
                                        {{ $record->typeShortLabel() }}
                                    </span>
                                </td>
                                <td>
                                    {{ \App\Support\LocalTime::format($record->submitted_at ?? $record->created_at, 'j M Y') }}
                                    <div>
                                        <small class="text-muted">{{ ($record->submitter ?? $record->creator)?->name }}</small>
                                    </div>
                                </td>
                                <td class="text-end">
                                    @if ($confidence === null)
                                        <span class="text-muted">—</span>
                                    @elseif ($confidence < $threshold)
                                        <span class="text-warning fw-medium" title="Below the {{ $threshold }}% review threshold">{{ $confidence }}%</span>
                                    @else
                                        {{ $confidence }}%
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($records->hasPages())
                <div class="mt-4">{{ $records->links() }}</div>
            @endif
        @endif
    </x-card>
@endsection
