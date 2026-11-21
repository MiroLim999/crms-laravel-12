@extends('layouts.app')

@section('title', 'Records Archive')

@php
    // Every record has one status (Submitted), so there is no status filter or
    // column here, same as Reports.
    $hasFilters = collect($filters)->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty();
@endphp

@section('content')
    <x-page-header title="Records Archive"
                   subtitle="Search submitted civil registry records by name or any verified value." />

    <x-card>
        <form method="GET" action="{{ route('records.index') }}" class="row g-3 align-items-end">
            {{-- Values come from the validated filters, so a bad one is not echoed back. --}}
            <div class="col-sm-12 col-xl-6">
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
            {{-- Several records' typed tables as one download: those ticked (kept across pages), or all that match. --}}
            @php($exportLimit = \App\Http\Controllers\RecordController::EXPORT_LIMIT)
            <div class="records-export" id="recordsExport" data-export-url="{{ route('records.export') }}"
                 data-filters="{{ http_build_query(array_filter($filters, fn ($value) => $value !== null && $value !== '')) }}">
                <span class="records-export__count" id="recordsSelectedCount" aria-live="polite">Tick records to download them together</span>
                <button type="button" class="btn btn-sm btn-link d-none" id="recordsClearSelection">Clear</button>
                <div class="records-export__actions">
                    <div class="dropdown">
                        <button type="button" class="btn btn-sm btn-primary dropdown-toggle" id="recordsDownloadSelected"
                                data-bs-toggle="dropdown" aria-expanded="false" disabled>
                            <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                            <i class="icon-base bx bx-download icon-sm me-1" aria-hidden="true"></i>Download selected
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="recordsDownloadSelected">
                            <li><button type="button" class="dropdown-item" data-export="selected" data-format="csv">
                                <i class="icon-base bx bx-file icon-sm me-2" aria-hidden="true"></i>CSV (.csv)</button></li>
                            <li><button type="button" class="dropdown-item" data-export="selected" data-format="xlsx">
                                <i class="icon-base bx bx-spreadsheet icon-sm me-2" aria-hidden="true"></i>Excel (.xlsx)</button></li>
                        </ul>
                    </div>
                    <div class="dropdown">
                        <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" id="recordsDownloadAll"
                                data-bs-toggle="dropdown" aria-expanded="false"
                                @if ($records->total() > $exportLimit) disabled title="One download takes at most {{ number_format($exportLimit) }} records: narrow the search or the dates first" @endif>
                            <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                            Download all {{ number_format($records->total()) }}
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="recordsDownloadAll">
                            <li><button type="button" class="dropdown-item" data-export="all" data-format="csv">
                                <i class="icon-base bx bx-file icon-sm me-2" aria-hidden="true"></i>CSV (.csv)</button></li>
                            <li><button type="button" class="dropdown-item" data-export="all" data-format="xlsx">
                                <i class="icon-base bx bx-spreadsheet icon-sm me-2" aria-hidden="true"></i>Excel (.xlsx)</button></li>
                        </ul>
                    </div>
                </div>
                <div class="records-export__error text-danger small d-none" id="recordsExportError" role="alert"></div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th class="records-export__check">
                                <input type="checkbox" class="form-check-input" id="recordsSelectPage"
                                       aria-label="Tick every record on this page">
                            </th>
                            <th>Record</th>
                            <th>Type</th>
                            <th>Submitted</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($records as $record)
                            <tr>
                                <td class="records-export__check">
                                    <input type="checkbox" class="form-check-input" data-record-id="{{ $record->getKey() }}"
                                           aria-label="Tick record #{{ $record->getKey() }} to download it">
                                </td>
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

@push('scripts')
    <script type="module">
        import { downloadRecordTables, exportFileName, fetchRecordTables } from '{{ Vite::asset('resources/js/review-table.js') }}';

        const bar = document.getElementById('recordsExport');
        if (bar) {
            // What is ticked stays ticked from page to page (in this tab), until Clear.
            const KEY = 'crms-records-selected';
            const load = () => {
                try {
                    return new Set(JSON.parse(sessionStorage.getItem(KEY) || '[]').map(Number).filter(Boolean));
                } catch {
                    return new Set();
                }
            };
            const selected = load();
            const save = () => {
                try {
                    sessionStorage.setItem(KEY, JSON.stringify([...selected]));
                } catch {
                    // Not kept between pages, then: the download still works on this one.
                }
            };
            const boxes = [...document.querySelectorAll('input[data-record-id]')];
            const pageBox = document.getElementById('recordsSelectPage');
            const errorLine = document.getElementById('recordsExportError');

            function render() {
                boxes.forEach((box) => { box.checked = selected.has(Number(box.dataset.recordId)); });
                const onPage = boxes.filter((box) => box.checked).length;
                pageBox.checked = onPage > 0 && onPage === boxes.length;
                pageBox.indeterminate = onPage > 0 && onPage < boxes.length;
                const count = selected.size;
                document.getElementById('recordsSelectedCount').textContent = count === 0
                    ? 'Tick records to download them together'
                    : `${count.toLocaleString()} record${count === 1 ? '' : 's'} ticked`;
                document.getElementById('recordsClearSelection').classList.toggle('d-none', count === 0);
                document.getElementById('recordsDownloadSelected').disabled = count === 0;
            }

            boxes.forEach((box) => box.addEventListener('change', () => {
                const id = Number(box.dataset.recordId);
                if (box.checked) selected.add(id);
                else selected.delete(id);
                save();
                render();
            }));
            pageBox.addEventListener('change', () => {
                boxes.forEach((box) => {
                    const id = Number(box.dataset.recordId);
                    if (pageBox.checked) selected.add(id);
                    else selected.delete(id);
                });
                save();
                render();
            });
            document.getElementById('recordsClearSelection').addEventListener('click', () => {
                selected.clear();
                save();
                render();
            });

            bar.querySelectorAll('[data-export]').forEach((item) => item.addEventListener('click', async () => {
                const which = item.dataset.export;
                const toggle = document.getElementById(which === 'all' ? 'recordsDownloadAll' : 'recordsDownloadSelected');
                const spinner = toggle.querySelector('.spinner-border');
                const params = new URLSearchParams(which === 'all' ? bar.dataset.filters : '');
                if (which === 'all') params.set('all', '1');
                else [...selected].sort((a, b) => a - b).forEach((id) => params.append('ids[]', String(id)));

                errorLine.classList.add('d-none');
                toggle.disabled = true;
                spinner?.classList.remove('d-none');
                try {
                    const records = await fetchRecordTables(bar.dataset.exportUrl, params);
                    if (records.length === 0) throw new Error('none of those records could be found.');
                    await downloadRecordTables(records, exportFileName('records', new Date().toISOString().slice(0, 10)), item.dataset.format);
                } catch (error) {
                    errorLine.textContent = `The download could not be made: ${error.message || 'try again.'}`;
                    errorLine.classList.remove('d-none');
                } finally {
                    spinner?.classList.add('d-none');
                    toggle.disabled = which === 'all' ? false : selected.size === 0;
                }
            }));

            render();
        }
    </script>
@endpush
