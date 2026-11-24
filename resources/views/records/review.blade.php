@extends('layouts.app')

@section('title', 'Review & Export · ' . $recordHeading)
@section('body-class', 'record-detail-focused record-review-focused')

@section('workspace-navbar')
    <nav class="layout-navbar container-xxl navbar-detached navbar document-workspace-topbar record-detail-topbar"
         id="layout-navbar" aria-label="Review and export a saved record">
        <div class="document-workspace-topbar__context">
            <strong>{{ $recordHeading }}</strong>
            @php($recordContext = implode(' · ', array_filter([$record->typeLabel(), 'Record #'.$record->getKey(), $record->sourceLabel()])))
            <small title="{{ $recordContext }}">{{ $recordContext }}</small>
        </div>

        <nav class="document-flow-steps" aria-label="Document processing">
            @foreach (['Document', 'Align', 'Verify'] as $step)
                <div class="document-flow-step is-complete">
                    <span class="document-flow-step__number">{{ $loop->iteration }}</span>
                    <span class="document-flow-step__copy"><strong>{{ $step }}</strong></span>
                </div>
            @endforeach
            <div class="document-flow-step is-active" aria-current="step">
                <span class="document-flow-step__number">4</span>
                <span class="document-flow-step__copy"><strong>Review &amp; Export</strong></span>
            </div>
        </nav>

        <div class="record-detail-topbar__actions">
            <a href="{{ route('records.show', $record) }}" class="btn btn-sm btn-outline-secondary">Back to record</a>
        </div>
    </nav>
@endsection

@section('content')
    <main class="record-review" data-record-review>
        <div class="validation-submit-error d-none mb-3" id="reviewError" role="alert" aria-live="assertive">
            <i class="icon-base bx bx-error" aria-hidden="true"></i>
            <div>
                <strong>Not saved</strong>
                <div id="reviewErrorMessage"></div>
            </div>
        </div>

        {{-- The scan (the record page's own card) on the left, the table on the right:
             50/50 to start, the bar between drags; double-click it for 50/50 again. --}}
        <div class="review-split" data-review-split>
            <section class="record-review__scan" aria-label="Original scan">
                @if ($record->scan_path)
                    @include('records.partials.scan-card', ['record' => $record])
                @else
                    <div class="card p-4 text-muted">This record has no scan.</div>
                @endif
            </section>

            <div class="review-splitter" id="reviewSplitter" role="separator" tabindex="0"
                 aria-orientation="vertical" aria-controls="reviewPane" aria-label="Resize the scan and the table"
                 aria-valuemin="20" aria-valuemax="65" aria-valuenow="30"
                 title="Drag to resize · double-click for 50/50"></div>

            <section class="card validation-pane review-pane" id="reviewPane" aria-labelledby="reviewPaneTitle">
                <header class="validation-pane__header review-pane__header">
                    <div class="min-w-0">
                        <span class="validation-pane__eyebrow">Review &amp; Export</span>
                        <h3 class="h5 mb-0" id="reviewPaneTitle">Records table</h3>
                        <p class="small text-muted mb-0">
                            {{ count($review['people']) }} {{ count($review['people']) === 1 ? 'person' : 'people' }}
                            @if (count($review['pages']) > 1) · {{ count($review['pages']) }} pages @endif
                        </p>
                    </div>
                    <div class="review-pane__tools">
                        @include('records.partials.review-shortcuts', ['savedRecord' => true])
                        @include('records.partials.review-add-column')
                        <div class="dropdown">
                            <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" id="reviewDownloadBtn"
                                    data-bs-toggle="dropdown" aria-expanded="false">
                                <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                                <i class="icon-base bx bx-download icon-sm me-1" aria-hidden="true"></i>Download
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="reviewDownloadBtn">
                                <li>
                                    <button type="button" class="dropdown-item" id="reviewExportCsv">
                                        <i class="icon-base bx bx-file icon-sm me-2" aria-hidden="true"></i>CSV (.csv)
                                    </button>
                                </li>
                                <li>
                                    <button type="button" class="dropdown-item" id="reviewExportXlsx">
                                        <i class="icon-base bx bx-spreadsheet icon-sm me-2" aria-hidden="true"></i>Excel (.xlsx)
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                </header>

                <div class="review-table-scroll" id="reviewTable"></div>

                <footer class="validation-submit-bar">
                    <div class="validation-submit-actions">
                        <span class="small text-muted me-auto align-self-center" id="reviewChangeCount" role="status">No changes yet</span>
                        <a href="{{ route('records.show', $record) }}" class="btn btn-outline-secondary">Cancel</a>
                        <button type="button" class="btn btn-primary" id="reviewSaveBtn" disabled>
                            <i class="icon-base bx bx-check-shield icon-sm me-1" aria-hidden="true"></i>
                            <span>Save changes</span>
                        </button>
                    </div>
                </footer>
            </section>
        </div>
    </main>

    <script type="application/json" id="recordReviewData">@json($review)</script>
@endsection

@push('scripts')
    @vite('resources/js/record-review.js')
@endpush
