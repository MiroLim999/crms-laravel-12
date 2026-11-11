@extends('layouts.app')

@section('title', 'Correction #' . $changeRequest->getKey())

@section('content')
    <main class="change-request-detail" data-record-detail>
        <x-page-header :title="'Correction #' . $changeRequest->getKey()" :subtitle="$recordHeading">
            <a href="{{ route('records.show', $changeRequest->record) }}" class="btn btn-outline-primary">
                View record
            </a>
            <a href="{{ route('change-requests.index') }}" class="btn btn-outline-secondary">
                Back to corrections
            </a>
        </x-page-header>

        <section class="record-summary-strip change-request-review-summary" aria-label="Correction summary">
            <div class="record-summary-item">
                <span>Record</span>
                <strong>#{{ $changeRequest->record->getKey() }}</strong>
                <small>{{ $changeRequest->record->typeShortLabel() }}</small>
            </div>
            <div class="record-summary-item">
                <span>Changed values</span>
                <strong>{{ $changeRequest->changeCount() }}</strong>
                <small>Value{{ $changeRequest->changeCount() === 1 ? '' : 's' }} changed</small>
            </div>
            <div class="record-summary-item">
                <span>Corrected by</span>
                <strong>{{ $changeRequest->requester?->name ?? 'Unknown' }}</strong>
                <small>{{ \App\Support\LocalTime::format($changeRequest->created_at, 'j M Y, H:i') }}</small>
            </div>
        </section>

        @if ($changeRequest->status === \App\Enums\ChangeRequestStatus::Approved)
            <div class="alert alert-success change-request-state-notice" role="status">
                <i class="icon-base bx bx-check-circle icon-md" aria-hidden="true"></i>
                <div>
                    <strong>Applied</strong>
                    <span>The new values are part of the record. The old values are kept here.</span>
                </div>
            </div>
        @else
            {{-- Older requests from before corrections were saved at once. --}}
            <div class="alert alert-secondary change-request-state-notice" role="status">
                <i class="icon-base bx bx-minus-circle icon-md" aria-hidden="true"></i>
                <div>
                    <strong>Not applied ({{ $changeRequest->status->label() }})</strong>
                    <span>This older request never changed the record.</span>
                </div>
            </div>
        @endif

        <div class="record-split-workspace change-request-review-workspace {{ $changeRequest->record->scan_path ? 'has-scan' : 'has-no-scan' }}"
             data-record-split>
            <section class="record-split-pane record-data-pane" id="recordDataPane">
                <x-card class="record-values-card change-request-diff-card" bodyClass="p-0" title="Changes">
                    @if ($changeRequest->record->scan_path)
                        <x-slot:actions>
                            <button type="button" class="btn btn-sm btn-outline-primary record-compare-toggle"
                                    data-original-toggle aria-pressed="false" aria-controls="recordScanPane">
                                <i class="icon-base bx bx-images icon-sm me-1" aria-hidden="true"></i>
                                <span data-original-toggle-label>Compare original</span>
                            </button>
                        </x-slot:actions>
                    @endif

                    <div class="change-request-diff-legend" aria-hidden="true">
                        <span>Field</span>
                        <span>Old value</span>
                        <span></span>
                        <span>New value</span>
                    </div>

                    <div class="record-group-list change-request-diff-groups">
                        @foreach ($changeGroups as $group)
                            <details class="record-field-group" data-group-id="{{ $group['id'] }}" @if ($loop->first) open @endif>
                                <summary class="record-field-group__summary">
                                    <span class="record-field-group__number">
                                        {{ $group['kind'] === 'person' ? str($group['label'])->after('Person ') : 'i' }}
                                    </span>
                                    <span class="record-field-group__copy">
                                        <strong>{{ $group['label'] }}</strong>
                                        <small>{{ $group['identity'] ?? 'Document-level values' }}</small>
                                    </span>
                                    <span class="record-field-group__meta">
                                        <span class="badge bg-label-info">
                                            {{ $group['items']->count() }} change{{ $group['items']->count() === 1 ? '' : 's' }}
                                        </span>
                                    </span>
                                    <i class="icon-base bx bx-chevron-down" aria-hidden="true"></i>
                                </summary>

                                <div class="record-field-group__body">
                                    @foreach ($group['items'] as $item)
                                        @php($field = $item->field)
                                        <div class="record-field-row change-request-diff-row"
                                             @if ($field && $field->x !== null && $field->y !== null && !str_contains((string) $changeRequest->record->scan_mime, 'pdf'))
                                                 data-record-field="{{ $field->getKey() }}"
                                                 role="button" tabindex="0"
                                                 aria-label="Show {{ $field->name }} on the original scan"
                                             @endif>
                                            <div class="record-field-row__label">
                                                <span>{{ $field?->name ?? 'Unavailable field' }}</span>
                                                <small>{{ $field?->is_required ? 'Required' : 'Optional' }}</small>
                                            </div>
                                            <div class="change-request-diff-value is-current">
                                                {{ filled($item->current_value) ? $item->current_value : 'Not recorded' }}
                                            </div>
                                            <i class="icon-base bx bx-right-arrow-alt change-request-diff-arrow" aria-hidden="true"></i>
                                            <div class="change-request-diff-value is-proposed">
                                                {{ filled($item->proposed_value) ? $item->proposed_value : 'Not recorded' }}
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endforeach

                        @if ($orphanItems->isNotEmpty())
                            <details class="record-field-group" open>
                                <summary class="record-field-group__summary">
                                    <span class="record-field-group__number">!</span>
                                    <span class="record-field-group__copy">
                                        <strong>Unavailable fields</strong>
                                        <small>These fields are no longer attached to the record.</small>
                                    </span>
                                    <span class="record-field-group__meta">
                                        <span class="badge bg-label-warning">{{ $orphanItems->count() }}</span>
                                    </span>
                                    <i class="icon-base bx bx-chevron-down" aria-hidden="true"></i>
                                </summary>
                                <div class="record-field-group__body">
                                    @foreach ($orphanItems as $item)
                                        <div class="change-request-diff-row">
                                            <div class="record-field-row__label"><span>Removed field</span></div>
                                            <div class="change-request-diff-value is-current">
                                                {{ filled($item->current_value) ? $item->current_value : 'Not recorded' }}
                                            </div>
                                            <i class="icon-base bx bx-right-arrow-alt change-request-diff-arrow" aria-hidden="true"></i>
                                            <div class="change-request-diff-value is-proposed">
                                                {{ filled($item->proposed_value) ? $item->proposed_value : 'Not recorded' }}
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </div>

                    <div class="record-values-card__footnote">
                        @if ($changeRequest->record->scan_path && !str_contains((string) $changeRequest->record->scan_mime, 'pdf'))
                            Select a changed field to locate its source in the original scan.
                        @else
                            Only values that differ from the verified record are shown.
                        @endif
                    </div>
                </x-card>
            </section>

            @if ($changeRequest->record->scan_path)
                <div class="record-splitter" data-record-splitter
                     role="separator" tabindex="0" aria-orientation="vertical"
                     aria-label="Resize original scan and changes panels"
                     aria-controls="recordScanPane recordDataPane"
                     aria-valuemin="35" aria-valuemax="75" aria-valuenow="58"
                     aria-valuetext="Original scan 58%, changes 42%"></div>
            @endif

            <section class="record-split-pane record-scan-pane" id="recordScanPane">
                <div class="record-detail-sidebar">
                    @if ($changeRequest->record->scan_path)
                        @include('records.partials.scan-card', [
                            'record' => $changeRequest->record,
                            'markerFields' => $changedFields,
                        ])
                    @endif
                </div>
            </section>
        </div>

        <div class="change-request-context-grid">
            <section class="change-request-context-stack">
                <x-card title="Correction details">
                    <dl class="change-request-detail-list mb-0">
                        <div>
                            <dt>Corrected by</dt>
                            <dd>{{ $changeRequest->requester?->name ?? 'Unknown' }}</dd>
                        </div>
                        <div>
                            <dt>Saved</dt>
                            <dd>{{ \App\Support\LocalTime::format($changeRequest->created_at, 'j M Y, H:i') }}</dd>
                        </div>
                        <div>
                            <dt>Record</dt>
                            <dd><a href="{{ route('records.show', $changeRequest->record) }}">{{ $recordHeading }}</a></dd>
                        </div>
                    </dl>
                </x-card>
            </section>

            <aside class="change-request-decision-column">
                <x-card title="Reason">
                    @if (filled($changeRequest->reason))
                        <p class="change-request-reason mb-0">{{ $changeRequest->reason }}</p>
                    @else
                        <p class="text-muted mb-0">No reason was given.</p>
                    @endif
                    @if ($changeRequest->decision_note)
                        <div class="change-request-decision-note mt-3">
                            <span>Decision note</span>
                            <p>{{ $changeRequest->decision_note }}</p>
                        </div>
                    @endif
                </x-card>
            </aside>
        </div>
    </main>
@endsection

@push('scripts')
    @vite(['resources/js/record-detail.js', 'resources/js/change-request.js'])
@endpush
