@extends('layouts.app')

@section('title', 'Saved Records')

@php
    // Every record has one status (Submitted), so there is no status filter or
    // column here.
    $hasFilters = collect($filters)->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty();
@endphp

@section('content')
    <x-page-header title="Saved Records" />

    <div id="recordsFilterCard">
    <x-card>
        {{-- Type, Batch and the dates filter as soon as they change; the search words on Search or Enter. --}}
        <form method="GET" action="{{ route('records.index') }}" class="row g-3 align-items-end" id="recordsFilters">
            {{-- Values come from the validated filters, so a bad one is not echoed back. --}}
            <div class="col-sm-12 col-xl-4">
                <label for="q" class="form-label">Search</label>
                <div class="d-flex gap-2">
                    {{-- data-record-search: the "/" shortcut lands here, since the top bar's search is hidden on this page. --}}
                    <input type="search" id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="255"
                           class="form-control flex-grow-1 @error('q') is-invalid @enderror" placeholder="Name, any value, or file name"
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
            <div class="col-sm-6 col-lg-3 col-xl-2">
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
            {{-- The records one batch saved (Save & finish batch), newest batch first. --}}
            <div class="col-sm-6 col-lg-3 col-xl-2">
                <label for="batch" class="form-label">Batch</label>
                <select id="batch" name="batch" class="form-select @error('batch') is-invalid @enderror">
                    <option value="">All</option>
                    <option value="single" @selected(($filters['batch'] ?? null) === 'single')>Single files only</option>
                    @foreach ($batches as $batch)
                        <option value="{{ $batch['id'] }}" @selected((string) ($filters['batch'] ?? '') === (string) $batch['id'])>Batch · {{ $batch['label'] }}</option>
                    @endforeach
                </select>
                @error('batch')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-lg-3 col-xl-2">
                <label for="from" class="form-label">From</label>
                <input type="date" id="from" name="from" value="{{ $filters['from'] ?? '' }}"
                       class="form-control @error('from') is-invalid @enderror">
                @error('from')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-lg-3 col-xl-2">
                <label for="to" class="form-label">To</label>
                <input type="date" id="to" name="to" value="{{ $filters['to'] ?? '' }}"
                       class="form-control @error('to') is-invalid @enderror">
                @error('to')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </form>
    </x-card>
    </div>

    {{-- Swapped in place by the script below (filters, search, pages): no whole-page reload. --}}
    <div id="recordsResults" aria-live="polite">
    <x-card class="mt-4" :title="number_format($records->total()).' '.($hasFilters ? 'matching ' : '').str('record')->plural($records->total())">
        @if ($records->isEmpty())
            <x-empty-state icon="bx-folder-open" title="No records match"
                           message="Adjust the search, or digitise a new certificate." />
        @else
            {{-- Several records' typed tables as one download: those ticked (kept across pages), or all that match. --}}
            @php($exportLimit = \App\Http\Controllers\RecordController::EXPORT_LIMIT)
            <div class="records-export" id="recordsExport" data-export-url="{{ route('records.export') }}"
                 data-filters="{{ http_build_query(array_filter($filters, fn ($value) => $value !== null && $value !== '')) }}">
                @php($overLimit = $records->total() > $exportLimit)
                <span class="records-export__count" id="recordsSelectedCount" aria-live="polite">Tick records to download only some</span>
                <button type="button" class="btn btn-sm btn-link d-none" id="recordsClearSelection">Clear</button>
                <div class="records-export__actions">
                    {{-- Shown only once something is ticked; then it is the main button. --}}
                    <div class="dropdown d-none" id="recordsDownloadSelectedWrap">
                        <button type="button" class="btn btn-sm btn-primary dropdown-toggle" id="recordsDownloadSelected"
                                data-bs-toggle="dropdown" aria-expanded="false" disabled>
                            <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                            <i class="icon-base bx bx-download icon-sm me-1" aria-hidden="true"></i><span id="recordsDownloadSelectedLabel">Download ticked</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="recordsDownloadSelected">
                            <li><button type="button" class="dropdown-item" data-export="selected" data-format="csv">
                                <i class="icon-base bx bx-file icon-sm me-2" aria-hidden="true"></i>CSV (.csv)</button></li>
                            <li><button type="button" class="dropdown-item" data-export="selected" data-format="xlsx">
                                <i class="icon-base bx bx-spreadsheet icon-sm me-2" aria-hidden="true"></i>Excel (.xlsx)</button></li>
                        </ul>
                    </div>
                    @if ($overLimit)
                        {{-- Said in plain text: a tooltip on a switched-off button often never shows. --}}
                        <span class="records-export__limit small text-muted align-self-center">
                            At most {{ number_format($exportLimit) }} per download: narrow the search or dates
                        </span>
                    @endif
                    <div class="dropdown">
                        {{-- Main (solid) button while nothing is ticked; outline once something is. --}}
                        <button type="button" class="btn btn-sm btn-primary dropdown-toggle" id="recordsDownloadAll"
                                data-bs-toggle="dropdown" aria-expanded="false" @disabled($overLimit)>
                            <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                            <i class="icon-base bx bx-download icon-sm me-1" aria-hidden="true"></i>{{ $hasFilters ? 'Download these' : 'Download all' }} {{ number_format($records->total()) }}
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
                            <th>Source</th>
                            <th>Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($records as $record)
                            <tr>
                                <td class="records-export__check">
                                    <input type="checkbox" class="form-check-input" data-record-id="{{ $record->getKey() }}"
                                           aria-label="Tick record #{{ $record->getKey() }} to download it">
                                </td>
                                @php($detail = $details[$record->getKey()] ?? ['title' => $record->title(), 'people' => 0, 'range' => null, 'file' => null, 'source' => 'unknown', 'batch' => null])
                                <td class="records-list__record">
                                    <a href="{{ route('records.show', $record) }}" class="fw-medium records-list__title">{{ $detail['title'] }}</a>
                                    {{-- Every record is locked once submitted: only one that is not says so. --}}
                                    @unless ($record->isLocked())
                                        <span class="badge bg-label-warning ms-1">Not locked</span>
                                    @endunless
                                    <div>
                                        <small class="text-muted">
                                            Record #{{ $record->getKey() }}
                                            @if ($detail['people'] > 1)
                                                · {{ $detail['people'] }} people
                                            @endif
                                            @if ($detail['range'])
                                                · {{ $detail['range'][0] }} to {{ $detail['range'][1] }}
                                            @endif
                                            @if ($detail['file'])
                                                · {{ $detail['file'] }}
                                            @endif
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
                                    <span class="records-list__type" title="{{ $record->typeLabel() }}">
                                        <i class="icon-base bx {{ $record->typeIcon() }} icon-sm text-muted" aria-hidden="true"></i>
                                        <span class="text-truncate">{{ $record->typeShortLabel() }}</span>
                                    </span>
                                </td>
                                {{-- From a batch (click: that batch's records), one file at a time, or not known. --}}
                                <td class="records-list__source">
                                    @if ($detail['source'] === 'batch')
                                        <a href="{{ route('records.index', ['batch' => $detail['batch']['id']]) }}" class="badge bg-label-primary"
                                           data-records-link title="Show the records this batch saved">Batch</a>
                                        <small class="d-block text-muted">{{ $detail['batch']['label'] }}</small>
                                    @elseif ($detail['source'] === 'single')
                                        <span class="badge bg-label-secondary">Single file</span>
                                    @else
                                        <span class="text-muted" title="Saved before batches were kept, so it cannot be told">—</span>
                                    @endif
                                </td>
                                <td class="text-muted text-nowrap">
                                    {{ \App\Support\LocalTime::format($record->submitted_at, 'j M Y, g:i a') ?: '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $records->links() }}
        @endif
    </x-card>
    </div>
@endsection

@push('scripts')
    <script type="module">
        import { downloadRecordTables, exportFileName, fetchRecordTables } from '{{ Vite::asset('resources/js/review-table.js') }}';

        // ---------------------------------------------------------------- in place
        // A filter, a search, a page of results or a Batch link loads the results
        // into this page (no whole-page reload): the page is fetched, its filters
        // and results swapped in, and the address bar follows (Back works).
        const filterCard = document.getElementById('recordsFilterCard');
        const results = document.getElementById('recordsResults');
        let loading = null;

        async function loadRecords(url, { push = true, scroll = false } = {}) {
            loading?.abort();
            const controller = new AbortController();
            loading = controller;
            results.classList.add('is-loading');
            try {
                const response = await fetch(url, {
                    headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error(`The server answered ${response.status}.`);
                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                const nextResults = page.getElementById('recordsResults');
                const nextFilters = page.getElementById('recordsFilterCard');
                if (!nextResults || !nextFilters) throw new Error('Not the saved records page.');

                // The field being typed in keeps what was typed meanwhile, and its caret.
                const active = document.activeElement;
                const keep = active && filterCard.contains(active) && active.id
                    ? { id: active.id, value: active.value, start: active.selectionStart, end: active.selectionEnd }
                    : null;
                filterCard.replaceChildren(...nextFilters.childNodes);
                results.replaceChildren(...nextResults.childNodes);
                if (keep) {
                    const field = document.getElementById(keep.id);
                    if (field) {
                        if (field.type === 'search' || field.type === 'text') field.value = keep.value;
                        field.focus({ preventScroll: true });
                        try { if (keep.start !== null) field.setSelectionRange(keep.start, keep.end); } catch { /* not a text box */ }
                    }
                }
                if (push) window.history.pushState({ records: true }, '', response.url || url);
                document.title = page.title || document.title;
                setUpResults();
                if (scroll) results.scrollIntoView({ block: 'start', behavior: 'smooth' });
            } catch (error) {
                if (error.name === 'AbortError') return;
                // Something went wrong: the page loads the usual way.
                window.location.assign(url);
            } finally {
                if (loading === controller) {
                    loading = null;
                    results.classList.remove('is-loading');
                }
            }
        }

        // Type, Batch and the dates filter as soon as they change (no Search press). A date
        // typed by hand waits until it is whole (a 4-digit year), so 0002 is never sent.
        let filterTimer = null;
        const submitFilters = () => {
            window.clearTimeout(filterTimer);
            filterTimer = window.setTimeout(() => {
                const form = document.getElementById('recordsFilters');
                if (!form) return;
                const params = new URLSearchParams(new FormData(form));
                // Empty filters are left out of the address.
                [...params.keys()].forEach((key) => { if (params.get(key) === '') params.delete(key); });
                const query = params.toString();
                loadRecords(`${form.action}${query ? `?${query}` : ''}`);
            }, 300);
        };
        filterCard.addEventListener('change', (event) => {
            const field = event.target;
            if (field.matches('select')) submitFilters();
            if (field.matches('input[type="date"]')
                && (field.value === '' || (/^\d{4}-/.test(field.value) && Number(field.value.slice(0, 4)) >= 1800))) submitFilters();
        });
        filterCard.addEventListener('submit', (event) => {
            if (!event.target.matches('#recordsFilters')) return;
            event.preventDefault();
            submitFilters();
        });
        // Reset (clear every filter), the pages of results, and a record's Batch link.
        document.addEventListener('click', (event) => {
            const paging = 'nav[aria-label="Pagination Navigation"] a[href], .pagination a[href]';
            const link = event.target.closest(`#recordsFilterCard a[href], #recordsResults ${paging.split(', ').join(', #recordsResults ')}, #recordsResults a[data-records-link]`);
            if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) return;
            event.preventDefault();
            loadRecords(link.href, { scroll: link.matches(paging) });
        });
        window.addEventListener('popstate', () => loadRecords(window.location.href, { push: false }));
        window.history.replaceState({ records: true }, '', window.location.href);

        // ---------------------------------------------------------------- ticks and downloads
        // What is ticked stays ticked from page to page (in this tab), until Clear.
        const KEY = 'crms-records-selected';
        const selected = (() => {
            try {
                return new Set(JSON.parse(sessionStorage.getItem(KEY) || '[]').map(Number).filter(Boolean));
            } catch {
                return new Set();
            }
        })();
        const save = () => {
            try {
                sessionStorage.setItem(KEY, JSON.stringify([...selected]));
            } catch {
                // Not kept between pages, then: the download still works on this one.
            }
        };
        const boxes = () => [...results.querySelectorAll('input[data-record-id]')];

        function render() {
            const bar = document.getElementById('recordsExport');
            if (!bar) return;
            const shown = boxes();
            shown.forEach((box) => { box.checked = selected.has(Number(box.dataset.recordId)); });
            const onPage = shown.filter((box) => box.checked).length;
            const pageBox = document.getElementById('recordsSelectPage');
            pageBox.checked = onPage > 0 && onPage === shown.length;
            pageBox.indeterminate = onPage > 0 && onPage < shown.length;
            const count = selected.size;
            // Ticks stay from page to page: say how many are on this one.
            const where = onPage === count ? '' : (onPage === 0 ? ' (none on this page)' : ` (${onPage} on this page)`);
            document.getElementById('recordsSelectedCount').textContent = count === 0
                ? 'Tick records to download only some'
                : `${count.toLocaleString()} record${count === 1 ? '' : 's'} ticked${where}`;
            document.getElementById('recordsClearSelection').classList.toggle('d-none', count === 0);
            // Nothing ticked: only "Download all" shows, as the main button. Something
            // ticked: "Download N ticked" appears as the main one, "Download all" goes outline.
            document.getElementById('recordsDownloadSelectedWrap').classList.toggle('d-none', count === 0);
            document.getElementById('recordsDownloadSelected').disabled = count === 0;
            document.getElementById('recordsDownloadSelectedLabel').textContent = `Download ${count.toLocaleString()} ticked`;
            const all = document.getElementById('recordsDownloadAll');
            all.classList.toggle('btn-primary', count === 0);
            all.classList.toggle('btn-outline-primary', count > 0);
        }
        const setUpResults = render;

        results.addEventListener('change', (event) => {
            const box = event.target;
            if (box.matches('input[data-record-id]')) {
                const id = Number(box.dataset.recordId);
                if (box.checked) selected.add(id);
                else selected.delete(id);
            } else if (box.matches('#recordsSelectPage')) {
                boxes().forEach((one) => {
                    const id = Number(one.dataset.recordId);
                    if (box.checked) selected.add(id);
                    else selected.delete(id);
                });
            } else {
                return;
            }
            save();
            render();
        });
        results.addEventListener('click', async (event) => {
            if (event.target.closest('#recordsClearSelection')) {
                selected.clear();
                save();
                render();
                return;
            }
            const item = event.target.closest('[data-export]');
            const bar = document.getElementById('recordsExport');
            if (!item || !bar) return;
            const which = item.dataset.export;
            const toggle = document.getElementById(which === 'all' ? 'recordsDownloadAll' : 'recordsDownloadSelected');
            const spinner = toggle.querySelector('.spinner-border');
            const errorLine = document.getElementById('recordsExportError');
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
                render();
                toggle.disabled = which === 'all' ? false : selected.size === 0;
            }
        });

        setUpResults();
    </script>
@endpush
