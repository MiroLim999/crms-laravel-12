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

        <div class="record-split-workspace {{ $record->scan_path ? 'has-scan' : 'has-no-scan' }}" data-record-split>
            <section class="record-split-pane record-data-pane" id="recordDataPane">
                <x-card class="record-values-card" bodyClass="p-0"
                        title="Verified data"
                        subtitle="Verified values are the official record. Compare with the original scan only when needed.">
                    @if ($record->scan_path)
                        <x-slot:actions>
                            <button type="button" class="btn btn-sm btn-outline-primary record-compare-toggle"
                                    data-original-toggle aria-pressed="false" aria-controls="recordScanPane">
                                <i class="icon-base bx bx-images icon-sm me-1" aria-hidden="true"></i>
                                <span data-original-toggle-label>Compare original</span>
                            </button>
                        </x-slot:actions>
                    @endif

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

                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @empty
                            <div class="p-4 text-muted">No verified fields were stored for this record.</div>
                        @endforelse
                    </div>

                    <div class="record-values-card__footnote">
                        @if ($record->scan_path)
                            Select a value to open the original scan and locate its source.
                        @else
                            These values were confirmed during document verification.
                        @endif
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
            </section>

            @if ($record->scan_path)
                <div class="record-splitter" data-record-splitter
                     role="separator" tabindex="0" aria-orientation="vertical"
                     aria-label="Resize original scan and verified data panels"
                     aria-controls="recordScanPane recordDataPane"
                     aria-valuemin="35" aria-valuemax="75" aria-valuenow="58"
                     aria-valuetext="Original scan 58%, verified data 42%">
                    <span class="record-splitter__grip" aria-hidden="true">
                        <i class="icon-base bx bx-dots-vertical-rounded"></i>
                    </span>
                </div>
            @endif

            <section class="record-split-pane record-scan-pane" id="recordScanPane">
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
            </section>
        </div>
    </main>
@endsection

@push('scripts')
    @vite('resources/js/record-detail.js')
@endpush
