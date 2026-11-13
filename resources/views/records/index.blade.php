@extends('layouts.app')

@section('title', 'Records Archive')

@php
    // Every record has one status (Submitted), so there is no status filter or
    // column here, same as Reports.
    $hasFilters = collect($filters)->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty();
@endphp

@section('content')
    @push('styles')
        <style>
            /* Download status panel above the results. */
            .archive-status { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem 1.5rem;
                padding: 1rem 1.25rem; border: 1px solid var(--bs-border-color); border-radius: .5rem; }
            .archive-status__summary { flex: 1 1 18rem; min-width: 0; }
            .archive-status__figure { font-family: 'Rubik', sans-serif; font-size: 1.75rem; font-weight: 600; line-height: 1; color: var(--bs-warning); }
            .archive-status__done { display: inline-grid; place-items: center; width: 1.5rem; height: 1.5rem; border-radius: 50%;
                background: var(--bs-success); color: #fff; }
            .archive-status__bar { height: .5rem; border-radius: 1rem; }
    
            /* Download panel: its own filters and format. */
        .archive-export { width: 22rem; max-width: calc(100vw - 2rem); padding: 1rem; }
        .archive-export__title { margin-bottom: .75rem; font-family: 'Rubik', sans-serif; font-size: 1rem; font-weight: 600; }
        .archive-export__field { margin-bottom: .75rem; }
        .archive-export__label { display: block; margin-bottom: .25rem; font-size: .75rem; font-weight: 600; letter-spacing: .04em;
            text-transform: uppercase; color: var(--bs-secondary-color, #8592a3); }
        .archive-export__dates { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
        .archive-choice { display: flex; flex-wrap: wrap; gap: .375rem; }

        /* Table: group dividers, accent on records still to download, badges. */
            .archive-group-row td { padding: .4rem 1rem; font-size: .6875rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase;
                background: var(--bs-tertiary-bg, rgba(67, 89, 113, .06)); color: var(--bs-secondary-color, #8592a3); }
            .archive-row--pending > td:first-child { box-shadow: inset 3px 0 0 var(--bs-warning); }
            .archive-badge { display: inline-flex; align-items: center; gap: .25rem; padding: .25rem .6rem; border-radius: 1rem;
                font-size: .75rem; font-weight: 600; white-space: nowrap; }
            .archive-badge--done { background: rgba(113, 221, 55, .16); color: #4a9a1f; }
            .archive-badge--pending { background: rgba(255, 171, 0, .18); color: #b87a00; }
            [data-bs-theme="dark"] .archive-badge--done { color: #8ee06a; }
            [data-bs-theme="dark"] .archive-badge--pending { color: #ffc447; }
        </style>
    @endpush
    
    <x-page-header title="Records Archive"
                   subtitle="Search submitted civil registry records by name or any verified value." />

    <x-card>
        <form method="GET" action="{{ route('records.index') }}" class="row g-3 align-items-end">
            {{-- Values come from the validated filters, so a bad one is not echoed back. --}}
            <div class="col-sm-12 col-xl-4">
                <label for="q" class="form-label">Search</label>
                <div class="d-flex gap-2">
                    {{-- data-record-search: the "/" shortcut lands here, since the top bar's search is hidden on this page. --}}
                    <input type="search" id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="255"
                           class="form-control flex-grow-1 @error('q') is-invalid @enderror" placeholder="Name or any verified value"
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
                <label for="download" class="form-label">Download</label>
                <select id="download" name="download" class="form-select @error('download') is-invalid @enderror">
                    <option value="">All</option>
                    <option value="pending" @selected(($filters['download'] ?? null) === 'pending')>Not downloaded</option>
                    <option value="done" @selected(($filters['download'] ?? null) === 'done')>Downloaded</option>
                </select>
                @error('download')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
        @if ($records->isNotEmpty())
            @php
                $exportQuery = request()->only(['q', 'type', 'status', 'from', 'to', 'download']);
                $total = $records->total();
                $doneCount = max(0, $total - $pendingCount);
                $donePercent = $total > 0 ? (int) floor($doneCount / $total * 100) : 0;
            @endphp

            <div class="archive-status mb-4">
                <div class="archive-status__summary">
                    <div class="d-flex flex-wrap align-items-baseline gap-2 mb-2">
                        @if ($pendingCount > 0)
                            <span class="archive-status__figure">{{ number_format($pendingCount) }}</span>
                            <span class="fw-medium">{{ str('record')->plural($pendingCount) }} waiting to be downloaded</span>
                        @else
                            <span class="archive-status__done" aria-hidden="true"><i class="icon-base bx bx-check icon-md"></i></span>
                            <span class="fw-medium">Everything here has been downloaded</span>
                        @endif
                    </div>
                    <div class="progress archive-status__bar" role="progressbar" aria-label="Records downloaded"
                         aria-valuenow="{{ $donePercent }}" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar bg-success" style="width: {{ $donePercent }}%"></div>
                    </div>
                    <div class="d-flex flex-wrap justify-content-between gap-2 mt-2 small text-muted">
                        <span>{{ number_format($doneCount) }} of {{ number_format($total) }} downloaded</span>
                        @if (($filters['download'] ?? null) === 'pending')
                            <a href="{{ route('records.index', request()->except(['download', 'page'])) }}">Show all records</a>
                        @elseif ($pendingCount > 0)
                            <a href="{{ route('records.index', [...request()->except('page'), 'download' => 'pending']) }}">Show only not downloaded</a>
                        @endif
                    </div>
                </div>

                <div class="dropdown archive-status__action">
                    <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown"
                            data-bs-auto-close="outside" aria-expanded="false">
                        <i class="icon-base bx bx-download icon-sm me-1" aria-hidden="true"></i> Download data
                    </button>
                    {{-- Its own filters, started from the page's, so what to export is chosen right here. --}}
                    <form method="GET" action="{{ route('records.export') }}" class="dropdown-menu dropdown-menu-end archive-export">
                        @if (filled($filters['q'] ?? null))
                            <input type="hidden" name="q" value="{{ $filters['q'] }}">
                        @endif
                        <div class="archive-export__title">Download data</div>

                        <div class="archive-export__field">
                            <span class="archive-export__label">Records</span>
                            <div class="archive-choice">
                                @foreach ([
                                    'new' => ['Not downloaded', $pendingCount],
                                    'done' => ['Downloaded', null],
                                    'all' => ['All', null],
                                ] as $value => [$label, $count])
                                    <input type="radio" class="btn-check" name="scope" id="export-scope-{{ $value }}" value="{{ $value }}"
                                           @checked($value === ($pendingCount > 0 ? 'new' : 'all'))>
                                    <label class="btn btn-sm btn-outline-secondary" for="export-scope-{{ $value }}">
                                        {{ $label }}@if ($count !== null) <span class="badge bg-label-warning ms-1">{{ number_format($count) }}</span>@endif
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="archive-export__field">
                            <label for="export-type" class="archive-export__label">Type</label>
                            <select id="export-type" name="type" class="form-select form-select-sm">
                                <option value="">All types</option>
                                @foreach ($documentTypes as $type)
                                    <option value="{{ $type->value }}" @selected(($filters['type'] ?? null) === $type->value)>{{ $type->shortLabel() }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="archive-export__field archive-export__dates">
                            <div>
                                <label for="export-from" class="archive-export__label">From</label>
                                <input type="date" id="export-from" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control form-control-sm">
                            </div>
                            <div>
                                <label for="export-to" class="archive-export__label">To</label>
                                <input type="date" id="export-to" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control form-control-sm">
                            </div>
                        </div>

                        <div class="archive-export__field">
                            <span class="archive-export__label">Format</span>
                            <div class="archive-choice">
                                @foreach (['csv' => ['CSV', 'bx-file'], 'xlsx' => ['Excel', 'bx-grid-alt']] as $value => [$label, $icon])
                                    <input type="radio" class="btn-check" name="format" id="export-format-{{ $value }}" value="{{ $value }}" @checked($value === 'xlsx')>
                                    <label class="btn btn-sm btn-outline-secondary" for="export-format-{{ $value }}">
                                        <i class="icon-base bx {{ $icon }} icon-sm me-1" aria-hidden="true"></i>{{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="icon-base bx bx-download icon-sm me-1" aria-hidden="true"></i> Download
                        </button>
                    </form>
                </div>
            </div>
        @endif
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
                            <th>Download</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @php($lastGroup = null)
                        @foreach ($records as $record)
                            @php($group = $record->is_downloaded ? 'done' : 'pending')
                            @if ($group !== $lastGroup)
                                <tr class="archive-group-row" aria-hidden="true">
                                    <td colspan="5">
                                        <i class="icon-base bx {{ $group === 'done' ? 'bx-check-circle text-success' : 'bx-time-five text-warning' }} icon-xs me-1"></i>
                                        {{ $group === 'done' ? 'Already downloaded' : 'Not downloaded yet' }}
                                    </td>
                                </tr>
                                @php($lastGroup = $group)
                            @endif
                            <tr class="archive-row archive-row--{{ $group }}">
                                <td>
                                    <a href="{{ route('records.show', $record) }}" class="fw-medium">
                                        {{ $record->title() }}
                                    </a>
                                    @if ($record->isLocked())
                                        <i class="icon-base bx bx-lock-alt icon-sm text-muted ms-1"
                                           title="Locked — change values with Correct record"></i>
                                    @endif
                                    <div>
                                        <small class="text-muted">
                                            Record #{{ $record->getKey() }}
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
                                </td>
                                <td>
                                    @if ($record->is_downloaded)
                                        <span class="archive-badge archive-badge--done"
                                              title="Last downloaded {{ \App\Support\LocalTime::format(\Illuminate\Support\Carbon::parse($record->last_downloaded_at), 'j M Y, g:i A') }}">
                                            <i class="icon-base bx bx-check icon-xs" aria-hidden="true"></i>Downloaded
                                        </span>
                                        <div class="small text-muted mt-1">
                                            {{ \App\Support\LocalTime::format(\Illuminate\Support\Carbon::parse($record->last_downloaded_at), 'j M Y') }}
                                        </div>
                                    @else
                                        <span class="archive-badge archive-badge--pending">
                                            <i class="icon-base bx bx-time-five icon-xs" aria-hidden="true"></i>Not downloaded
                                        </span>
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
