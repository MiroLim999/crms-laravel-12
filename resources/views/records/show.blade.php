@extends('layouts.app')

@section('title', $recordHeading)
@section('body-class', 'record-detail-focused')

@section('workspace-navbar')
    <nav class="layout-navbar container-xxl navbar-detached navbar document-workspace-topbar record-detail-topbar"
         id="layout-navbar" aria-label="Completed document workflow">
        <div class="document-workspace-topbar__context">
            <strong>{{ $recordHeading }}</strong>
            <small>{{ $record->typeLabel() }} · {{ $record->registry_number ?? 'No registry number' }}</small>
        </div>

        <nav class="document-flow-steps" aria-label="Document processing complete">
            @foreach (['Upload', 'Align', 'Verified'] as $step)
                <div class="document-flow-step is-complete">
                    <span class="document-flow-step__number">{{ $loop->iteration }}</span>
                    <span class="document-flow-step__copy"><strong>{{ $step }}</strong></span>
                </div>
            @endforeach
        </nav>

        <div class="record-detail-topbar__actions">
            @if ($record->isLocked() && auth()->user()->can('change-requests.create'))
                @if ($record->hasPendingChangeRequest())
                    <span class="badge bg-label-warning align-self-center">Change request pending</span>
                @else
                    <a href="{{ route('records.change-requests.create', $record) }}"
                       class="btn btn-sm btn-outline-primary">
                        <i class="icon-base bx bx-edit icon-sm me-1"></i> Request a change
                    </a>
                @endif
            @endif
            <a href="{{ route('records.index') }}" class="btn btn-sm btn-outline-secondary">Back to archive</a>
        </div>
    </nav>
@endsection

@section('content')
    <main class="record-detail" data-record-detail>
        @if ($record->isLocked())
            <div class="alert alert-info record-lock-notice" role="alert">
                <i class="icon-base bx bx-lock-alt icon-md" aria-hidden="true"></i>
                <div>
                    <strong>Verified record</strong>
                    <span>Values are locked and can change only through an approved request.</span>
                </div>
            </div>
        @endif

        <section class="record-summary-strip" aria-label="Record summary">
            <div class="record-summary-item {{ $record->registry_number ? '' : 'is-warning' }}">
                <span>Registry number</span>
                <strong>{{ $record->registry_number ?? 'Not recorded' }}</strong>
                @if ($registryWasCorrected)
                    <small>Corrected after submission</small>
                @endif
            </div>
            @if ($personCount > 0)
                <div class="record-summary-item">
                    <span>People</span>
                    <strong>{{ $personCount }}</strong>
                    <small>Grouped registry entries</small>
                </div>
            @endif
            <div class="record-summary-item">
                <span>Verified fields</span>
                <strong>{{ $record->fields->count() }}</strong>
                <small>Human-confirmed values</small>
            </div>
            <div class="record-summary-item">
                <span>OCR adjustments</span>
                <strong>{{ $ocrAdjustedCount }}</strong>
                <small>Changed during verification</small>
            </div>
            <div class="record-summary-item">
                <span>Later corrections</span>
                <strong>{{ $postSubmissionChangeCount }}</strong>
                <small>Approved after submission</small>
            </div>
        </section>

        <div class="row g-4 align-items-start">
            <div class="col-xl-7">
                <x-card class="record-values-card" bodyClass="p-0"
                        title="Verified data"
                        subtitle="Verified values are the record. Open OCR comparison only when traceability is needed.">
                    <x-slot:actions>
                        <button type="button" class="btn btn-sm btn-outline-secondary record-ocr-toggle"
                                aria-pressed="false" data-ocr-toggle>
                            Show OCR comparison
                        </button>
                    </x-slot:actions>

                    <div class="record-group-list">
                        @forelse ($fieldGroups as $group)
                            <details class="record-field-group"
                                     data-group-id="{{ $group['id'] }}"
                                     @if (($firstPersonGroupId && $group['id'] === $firstPersonGroupId) || (!$firstPersonGroupId && $loop->first)) open @endif>
                                <summary class="record-field-group__summary">
                                    <span class="record-field-group__number">
                                        {{ $group['kind'] === 'person' ? str($group['label'])->after('Person ') : 'i' }}
                                    </span>
                                    <span class="record-field-group__copy">
                                        <strong>{{ $group['label'] }}</strong>
                                        <small>{{ $group['identity'] ?? $group['field_count'].' verified field(s)' }}</small>
                                    </span>
                                    <span class="record-field-group__meta">
                                        <span class="badge bg-label-success">{{ $group['field_count'] }}/{{ $group['field_count'] }} verified</span>
                                        @if ($group['corrected_count'] > 0)
                                            <span class="badge bg-label-info">{{ $group['corrected_count'] }} OCR adjusted</span>
                                        @endif
                                    </span>
                                    <i class="icon-base bx bx-chevron-down" aria-hidden="true"></i>
                                </summary>

                                <div class="record-field-group__body">
                                    @foreach ($group['fields'] as $field)
                                        @php($changes = $fieldChanges->get($field->getKey(), collect()))
                                        <div class="record-field-row"
                                             data-record-field="{{ $field->getKey() }}"
                                             data-group="{{ $group['id'] }}"
                                             @if ($record->scan_path && !str_contains((string) $record->scan_mime, 'pdf'))
                                                 role="button" tabindex="0"
                                                 aria-label="Show {{ $field->name }} on the original scan"
                                             @endif>
                                            <div class="record-field-row__label">
                                                <span>{{ $field->name }}</span>
                                                @if ($field->is_required)
                                                    <small>Required</small>
                                                @endif
                                            </div>

                                            <div class="record-field-row__verified">
                                                <strong>{{ filled($field->verified_value) ? $field->verified_value : 'Not recorded' }}</strong>
                                                @if ($changes->isNotEmpty())
                                                    <span class="record-field-row__change-note">
                                                        Updated by request #{{ $changes->last()['request']->getKey() }}
                                                    </span>
                                                @endif
                                            </div>

                                            <div class="record-field-row__ocr" aria-hidden="true">
                                                <div>
                                                    <span>Model read</span>
                                                    <strong>{{ filled($field->ocr_text) ? $field->ocr_text : 'Nothing read' }}</strong>
                                                </div>
                                                <div class="record-field-row__ocr-meta">
                                                    @if ($field->ocr_confidence !== null)
                                                        <span class="confidence-badge {{ $field->ocr_confidence < $threshold ? 'is-low' : '' }}">
                                                            {{ number_format($field->ocr_confidence, 1) }}%
                                                        </span>
                                                    @endif
                                                    @if ($field->wasCorrected())
                                                        <span class="badge bg-label-info">Adjusted</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @empty
                            <div class="p-4 text-muted">No verified fields were stored for this record.</div>
                        @endforelse
                    </div>

                    <div class="record-values-card__footnote">
                        Select a value to locate it on the original scan. OCR confidence describes the model’s
                        reading, not the reliability of the human-verified record.
                    </div>
                </x-card>

                @if ($record->changeRequests->isNotEmpty())
                    <details class="card record-history-card mt-4">
                        <summary class="card-header record-disclosure-summary">
                            <span>
                                <strong>Change history</strong>
                                <small>{{ $record->changeRequests->count() }} reviewed request(s)</small>
                            </span>
                            <i class="icon-base bx bx-chevron-down" aria-hidden="true"></i>
                        </summary>
                        <div class="card-body">
                            @foreach ($record->changeRequests as $request)
                                <div class="record-history-entry {{ ! $loop->last ? 'border-bottom' : '' }}">
                                    <div>
                                        <a href="{{ route('change-requests.show', $request) }}" class="fw-medium">
                                            Request #{{ $request->getKey() }}
                                        </a>
                                        <small>
                                            {{ $request->requester?->name }} · {{ $request->created_at->diffForHumans() }}
                                            · {{ $request->changeCount() }} change(s)
                                        </small>
                                        <p>{{ $request->reason }}</p>
                                    </div>
                                    <span class="badge {{ $request->status->badgeClass() }}">
                                        {{ $request->status->label() }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
            </div>

            <div class="col-xl-5">
                <div class="record-detail-sidebar">
                    @if ($record->scan_path)
                        <x-card class="record-scan-card" bodyClass="p-0" title="Original scan"
                                subtitle="Select a verified value to locate its source.">
                            @if (!str_contains((string) $record->scan_mime, 'pdf'))
                                <x-slot:actions>
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Scan zoom controls">
                                        <button type="button" class="btn btn-outline-secondary" data-scan-zoom-out aria-label="Zoom out">−</button>
                                        <button type="button" class="btn btn-outline-secondary record-scan-zoom-label" data-scan-zoom-reset>100%</button>
                                        <button type="button" class="btn btn-outline-secondary" data-scan-zoom-in aria-label="Zoom in">+</button>
                                    </div>
                                </x-slot:actions>

                                <div class="record-scan-viewport" data-scan-viewport>
                                    <div class="record-scan-stage" data-scan-stage>
                                        <img src="{{ route('records.scan', $record) }}"
                                             alt="Original scanned certificate" data-scan-image>
                                        <div class="record-scan-overlay" aria-label="Captured field positions">
                                            @foreach ($record->fields as $field)
                                                @if ($field->x !== null && $field->y !== null && $field->width !== null && $field->height !== null)
                                                    <button type="button" class="btn btn-outline-warning record-scan-marker"
                                                            data-scan-marker="{{ $field->getKey() }}"
                                                            aria-label="{{ $field->name }}"
                                                            style="--field-x: {{ (float) $field->x * 100 }}%; --field-y: {{ (float) $field->y * 100 }}%; --field-w: {{ (float) $field->width * 100 }}%; --field-h: {{ (float) $field->height * 100 }}%;"></button>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                <div class="record-scan-card__footer">
                                    <span><i class="icon-base bx bx-target-lock" aria-hidden="true"></i> Selected field</span>
                                    <a href="{{ route('records.scan', $record) }}" target="_blank" rel="noopener">Open full size</a>
                                </div>
                            @else
                                <div class="p-3">
                                    <a href="{{ route('records.scan', $record) }}" target="_blank" rel="noopener"
                                       class="btn btn-outline-secondary w-100">
                                        <i class="icon-base bx bx-file icon-sm me-1"></i> Open scanned PDF
                                    </a>
                                </div>
                            @endif
                        </x-card>
                    @endif

                    <details class="card record-provenance-card mt-4">
                        <summary class="card-header record-disclosure-summary">
                            <span>
                                <strong>Provenance</strong>
                                <small>Submission and OCR traceability</small>
                            </span>
                            <i class="icon-base bx bx-chevron-down" aria-hidden="true"></i>
                        </summary>
                        <div class="card-body">
                            <dl class="record-provenance-list">
                                <dt>Status</dt>
                                <dd><span class="badge {{ $record->status->badgeClass() }}">{{ $record->status->label() }}</span></dd>
                                <dt>Submitted by</dt>
                                <dd>{{ $record->submitter?->name ?? '—' }}</dd>
                                <dt>Submitted at</dt>
                                <dd>{{ $record->submitted_at?->format('j M Y, H:i') ?? '—' }}</dd>
                                <dt>Template</dt>
                                <dd>{{ $record->template?->name ?? '—' }}</dd>
                                <dt>OCR model</dt>
                                <dd><code class="record-model-key">{{ $record->ocr_model_key ?? '—' }}</code></dd>
                            </dl>
                        </div>
                    </details>
                </div>
            </div>
        </div>
    </main>
@endsection

@push('scripts')
<script>
    (() => {
        const root = document.querySelector('[data-record-detail]');
        if (!(root instanceof HTMLElement)) return;

        const comparisonToggle = root.querySelector('[data-ocr-toggle]');
        comparisonToggle?.addEventListener('click', () => {
            const visible = root.classList.toggle('show-ocr-comparison');
            comparisonToggle.setAttribute('aria-pressed', String(visible));
            comparisonToggle.textContent = visible ? 'Hide OCR comparison' : 'Show OCR comparison';
            root.querySelectorAll('.record-field-row__ocr').forEach((comparison) => {
                comparison.setAttribute('aria-hidden', String(!visible));
            });
        });

        const viewport = root.querySelector('[data-scan-viewport]');
        const stage = root.querySelector('[data-scan-stage]');
        const zoomLabel = root.querySelector('[data-scan-zoom-reset]');
        let zoom = 1;

        const setZoom = (nextZoom) => {
            zoom = Math.min(3, Math.max(1, Math.round(nextZoom * 10) / 10));
            if (stage instanceof HTMLElement) stage.style.inlineSize = `${zoom * 100}%`;
            if (zoomLabel instanceof HTMLButtonElement) zoomLabel.textContent = `${Math.round(zoom * 100)}%`;
        };

        root.querySelector('[data-scan-zoom-out]')?.addEventListener('click', () => setZoom(zoom - .25));
        root.querySelector('[data-scan-zoom-in]')?.addEventListener('click', () => setZoom(zoom + .25));
        zoomLabel?.addEventListener('click', () => setZoom(1));

        const activateField = (fieldId, scrollRow = false) => {
            const row = root.querySelector(`[data-record-field="${fieldId}"]`);
            const marker = root.querySelector(`[data-scan-marker="${fieldId}"]`);
            if (!(row instanceof HTMLElement) || !(marker instanceof HTMLElement)) return;

            root.querySelectorAll('.record-field-row.is-active, .record-scan-marker.is-active')
                .forEach((element) => element.classList.remove('is-active'));
            row.classList.add('is-active');
            marker.classList.add('is-active');
            row.closest('details')?.setAttribute('open', '');
            setZoom(Math.max(zoom, 1.6));

            window.requestAnimationFrame(() => {
                if (viewport instanceof HTMLElement) {
                    viewport.scrollTo({
                        left: Math.max(0, marker.offsetLeft + marker.offsetWidth / 2 - viewport.clientWidth / 2),
                        top: Math.max(0, marker.offsetTop + marker.offsetHeight / 2 - viewport.clientHeight / 2),
                        behavior: 'smooth',
                    });
                }
                if (scrollRow) row.scrollIntoView({ block: 'center', behavior: 'smooth' });
            });
        };

        root.querySelectorAll('[data-record-field]').forEach((row) => {
            const activate = () => activateField(row.dataset.recordField);
            row.addEventListener('click', activate);
            row.addEventListener('keydown', (event) => {
                if (!['Enter', ' '].includes(event.key)) return;
                event.preventDefault();
                activate();
            });
        });

        root.querySelectorAll('[data-scan-marker]').forEach((marker) => {
            marker.addEventListener('click', () => activateField(marker.dataset.scanMarker, true));
        });
    })();
</script>
@endpush
