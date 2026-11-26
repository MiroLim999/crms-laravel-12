@extends('layouts.app')

@section('title', 'Saved Records')

@php
    // Every record has one status (Submitted), so there is no status filter or
    // column here.
    $hasFilters = collect($filters)->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty();
    // A book picked (birth, death or marriage): Download gives that whole book, in page order.
    $book = $books !== [] && in_array((string) ($filters['book'] ?? ''), $books, true) ? (string) $filters['book'] : null;
@endphp

@section('content')
    <x-page-header title="Saved Records" />

    {{-- Download one register book as one UTF-8 CSV: type + book number from dropdowns.
         Its columns are the register's, in its fixed order (RegistryFields::keys). --}}
    <x-card title="Download one book as CSV" subtitle="One CSV per book, one row per register entry. UTF-8.">
        <form id="bookDownload" method="GET" action="{{ route('records.books.download') }}"
              class="row g-3 align-items-end">
            <input type="hidden" name="format" value="csv">
            <div class="col-sm-6 col-lg-3">
                <label for="bookType" class="form-label">Document type</label>
                <select id="bookType" name="type" class="form-select">
                    @foreach (['birth' => 'Birth', 'death' => 'Death', 'marriage' => 'Marriage'] as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['type'] ?? null) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label for="bookNo" class="form-label">Book number</label>
                <select id="bookNo" name="book_no" class="form-select" required>
                    <option value="" disabled selected>Select a type first</option>
                </select>
            </div>
            <div class="col-sm-12 col-lg-3 d-grid">
                <button type="submit" class="btn btn-primary" id="bookDownloadBtn">
                    <i class="icon-base bx bx-download icon-sm me-1" aria-hidden="true"></i> Download CSV
                </button>
            </div>
        </form>
    </x-card>

    <script>
        // Fill the book-number dropdown for the chosen type; nothing to download without a book.
        (() => {
            const booksByType = @js($booksByType ?? []);
            const typeSelect = document.getElementById('bookType');
            const bookSelect = document.getElementById('bookNo');
            const button = document.getElementById('bookDownloadBtn');

            function fillBooks() {
                const books = booksByType[typeSelect.value] ?? [];
                const option = (value, text, disabled = false) => {
                    const item = new Option(text, value, disabled, disabled);
                    item.disabled = disabled;
                    return item;
                };
                bookSelect.replaceChildren(
                    books.length === 0 ? option('', 'No books for this type', true) : option('', 'Select a book number', true),
                    ...books.map((book) => option(book, `Book ${book}`)),
                );
                button.disabled = books.length === 0;
            }

            typeSelect.addEventListener('change', fillBooks);
            fillBooks();
        })();
    </script>

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
            <div class="col-sm-6 col-lg-3 col-xl">
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
            {{-- A register type's books (Birth, Death, Marriage): one book's records, downloaded as that book. --}}
            @if ($books !== [])
                <div class="col-sm-6 col-lg-3 col-xl">
                    <label for="book" class="form-label">Book</label>
                    <select id="book" name="book" class="form-select @error('book') is-invalid @enderror">
                        <option value="">All</option>
                        @foreach ($books as $number)
                            <option value="{{ $number }}" @selected($book === $number)>Book {{ $number }}</option>
                        @endforeach
                    </select>
                    @error('book')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            @endif
            {{-- The records one batch saved (Save & finish batch), newest batch first. --}}
            <div class="col-sm-6 col-lg-3 col-xl">
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
            <div class="col-sm-6 col-lg-3 col-xl">
                <label for="from" class="form-label">From</label>
                {{-- Typed or picked as yyyy-mm-dd (a browser's own date box shows its region's order, mm/dd/yyyy). --}}
                <div class="records-date">
                    <div class="input-group @error('from') has-validation @enderror">
                        <input type="text" id="from" name="from" value="{{ $filters['from'] ?? '' }}"
                               class="form-control @error('from') is-invalid @enderror" placeholder="yyyy-mm-dd"
                               maxlength="10" inputmode="numeric" autocomplete="off" pattern="\d{4}-\d{2}-\d{2}"
                               data-date-text>
                        <button type="button" class="btn btn-outline-secondary" data-date-pick="from"
                                aria-label="Pick the From date on a calendar" title="Pick on a calendar">
                            <i class="icon-base bx bx-calendar" aria-hidden="true"></i>
                        </button>
                        @error('from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    {{-- Only opens the calendar (kept outside the group's rounded corners): what is picked goes in the box. --}}
                    <input type="date" class="records-date__picker" data-date-for="from" tabindex="-1" aria-hidden="true">
                </div>
            </div>
            <div class="col-sm-6 col-lg-3 col-xl">
                <label for="to" class="form-label">To</label>
                {{-- Typed or picked as yyyy-mm-dd (a browser's own date box shows its region's order, mm/dd/yyyy). --}}
                <div class="records-date">
                    <div class="input-group @error('to') has-validation @enderror">
                        <input type="text" id="to" name="to" value="{{ $filters['to'] ?? '' }}"
                               class="form-control @error('to') is-invalid @enderror" placeholder="yyyy-mm-dd"
                               maxlength="10" inputmode="numeric" autocomplete="off" pattern="\d{4}-\d{2}-\d{2}"
                               data-date-text>
                        <button type="button" class="btn btn-outline-secondary" data-date-pick="to"
                                aria-label="Pick the To date on a calendar" title="Pick on a calendar">
                            <i class="icon-base bx bx-calendar" aria-hidden="true"></i>
                        </button>
                        @error('to')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    {{-- Only opens the calendar (kept outside the group's rounded corners): what is picked goes in the box. --}}
                    <input type="date" class="records-date__picker" data-date-for="to" tabindex="-1" aria-hidden="true">
                </div>
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
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Record</th>
                            <th>Type</th>
                            <th>Source</th>
                            <th>Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($records as $record)
                            <tr>
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
            // Another type: a book of the old one does not carry over (the new type's books load).
            if (field.matches('#type')) {
                const book = document.getElementById('book');
                if (book) book.value = '';
            }
            if (field.matches('select')) submitFilters();
            // A date picked on the calendar: written as yyyy-mm-dd into its box, then filtered.
            if (field.matches('[data-date-for]')) {
                const box = document.getElementById(field.dataset.dateFor);
                if (box && field.value) {
                    box.value = field.value;
                    box.classList.remove('is-invalid');
                    submitFilters();
                }
            }
        });
        // A date typed by hand: digits become yyyy-mm-dd as they are typed (19570211 -> 1957-02-11),
        // and it filters once it is a whole date (a 4-digit year from 1800), or emptied.
        filterCard.addEventListener('input', (event) => {
            const box = event.target;
            if (!box.matches('[data-date-text]')) return;
            const digits = box.value.replace(/\D/g, '').slice(0, 8);
            const shaped = [digits.slice(0, 4), digits.slice(4, 6), digits.slice(6, 8)].filter(Boolean).join('-');
            if (shaped !== box.value && event.inputType !== 'deleteContentBackward') box.value = shaped;
            const whole = /^\d{4}-\d{2}-\d{2}$/.test(box.value) && Number(box.value.slice(0, 4)) >= 1800
                && !Number.isNaN(Date.parse(`${box.value}T00:00:00`));
            box.classList.toggle('is-invalid', box.value.length === 10 && !whole);
            if (box.value === '' || whole) submitFilters();
        });
        // The calendar button opens the browser's calendar, starting at the date in the box.
        filterCard.addEventListener('click', (event) => {
            const button = event.target.closest('[data-date-pick]');
            if (!button) return;
            const picker = filterCard.querySelector(`[data-date-for="${button.dataset.datePick}"]`);
            const box = document.getElementById(button.dataset.datePick);
            if (!picker) return;
            picker.value = /^\d{4}-\d{2}-\d{2}$/.test(box?.value ?? '') ? box.value : '';
            try {
                picker.showPicker();
            } catch {
                // An older browser without showPicker: the date is typed instead.
                box?.focus();
            }
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

        // Results swapped in: nothing more to set up on them.
        function setUpResults() {}

        setUpResults();
    </script>
@endpush
