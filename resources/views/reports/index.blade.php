@extends('layouts.app')

@section('title', 'Reports')

@section('content')
    <x-page-header title="Reports"
                   subtitle="Filter the archive, review the summary, then export it as CSV.">
        <a href="{{ route('reports.export', request()->query()) }}" class="btn btn-primary">
            <i class="icon-base bx bx-download icon-sm me-1"></i> Export CSV
        </a>
    </x-page-header>

    <x-card>
        <form method="GET" action="{{ route('reports.index') }}" class="row g-3">
            <div class="col-md-3">
                <label for="from" class="form-label">From</label>
                <input type="date" id="from" name="from" value="{{ $filters['from'] }}" class="form-control">
            </div>
            <div class="col-md-3">
                <label for="to" class="form-label">To</label>
                <input type="date" id="to" name="to" value="{{ $filters['to'] }}" class="form-control">
            </div>
            <div class="col-md-3">
                <label for="doc_type" class="form-label">Document type</label>
                <select id="doc_type" name="doc_type" class="form-select">
                    <option value="">All types</option>
                    @foreach ($documentTypes as $type)
                        <option value="{{ $type->value }}" @selected($filters['doc_type'] === $type->value)>
                            {{ $type->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">Any status</option>
                    @foreach (\App\Enums\RecordStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>
                            {{ $status->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label for="submitted_by" class="form-label">Submitted by</label>
                <select id="submitted_by" name="submitted_by" class="form-select">
                    <option value="">Anyone</option>
                    @foreach ($dataEntryUsers as $person)
                        <option value="{{ $person->id }}" @selected($filters['submitted_by'] === $person->id)>
                            {{ $person->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-8 d-flex align-items-end gap-2">
                <button class="btn btn-outline-secondary" type="submit">
                    <i class="icon-base bx bx-filter-alt icon-sm me-1"></i> Apply filters
                </button>
                <a href="{{ route('reports.index') }}" class="btn btn-text-secondary">Reset</a>
            </div>
        </form>
    </x-card>

    <div class="row g-4 mt-2 mb-2">
        @php
            $tiles = [
                ['Matching records', number_format($summary['total'])],
                ['Submitted', number_format($summary['submitted'])],
                ['Drafts', number_format($summary['drafts'])],
                ['Average OCR confidence',
                    $summary['average_confidence'] === null ? '—' : $summary['average_confidence'].'%'],
            ];
        @endphp

        @foreach ($tiles as [$label, $value])
            <div class="col-sm-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body">
                        <span class="text-muted">{{ $label }}</span>
                        <h4 class="mb-0 mt-1">{{ $value }}</h4>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <x-card title="Matching records"
            subtitle="Read-only. Corrections to locked records go through a change request.">
        @if ($records->isEmpty())
            <x-empty-state icon="bx-file-find" title="No records match"
                           message="Widen the date range, or clear a filter." />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Record</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Submitted</th>
                            <th class="text-end">Avg. confidence</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($records as $record)
                            @php
                                $confidences = $record->fields
                                    ->pluck('ocr_confidence')
                                    ->filter(fn ($value) => $value !== null);
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-medium">{{ $record->title() }}</div>
                                    <small class="text-muted">
                                        {{ $record->registry_number ?? 'No registry number' }}
                                    </small>
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
                                </td>
                                <td class="text-muted">
                                    {{ $record->created_at?->format('j M Y') }}
                                    <div><small>{{ $record->creator?->name }}</small></div>
                                </td>
                                <td class="text-muted">
                                    {{ $record->submitted_at?->format('j M Y') ?? '—' }}
                                    @if ($record->submitter)
                                        <div><small>{{ $record->submitter->name }}</small></div>
                                    @endif
                                </td>
                                <td class="text-end">
                                    {{ $confidences->isEmpty() ? '—' : round($confidences->avg(), 1).'%' }}
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
