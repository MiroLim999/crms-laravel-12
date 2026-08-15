@extends('layouts.app')

@section('title', 'Change Request #' . $changeRequest->getKey())

@section('content')
    <main class="change-request-detail" data-record-detail>
        <x-page-header :title="'Change Request #' . $changeRequest->getKey()" :subtitle="$recordHeading">
            <span class="badge {{ $changeRequest->status->badgeClass() }} change-request-header-status">
                {{ $changeRequest->status->label() }}
            </span>
            <a href="{{ route('records.show', $changeRequest->record) }}" class="btn btn-outline-primary">
                View record
            </a>
            <a href="{{ route('change-requests.index') }}" class="btn btn-outline-secondary">
                Back to requests
            </a>
        </x-page-header>

        <section class="record-summary-strip change-request-review-summary" aria-label="Change request summary">
            <div class="record-summary-item">
                <span>Status</span>
                <strong class="change-request-status-text change-request-status-text--{{ $changeRequest->status->value }}">
                    {{ $changeRequest->status->label() }}
                </strong>
                <small>{{ $changeRequest->isOpen() ? 'Waiting for a decision' : 'Review completed' }}</small>
            </div>
            <div class="record-summary-item">
                <span>Record</span>
                <strong>{{ $changeRequest->record->registry_number ?? 'No registry number' }}</strong>
                <small>{{ $changeRequest->record->typeShortLabel() }}</small>
            </div>
            <div class="record-summary-item">
                <span>Proposed changes</span>
                <strong>{{ $changeRequest->changeCount() }}</strong>
                <small>Value{{ $changeRequest->changeCount() === 1 ? '' : 's' }} for review</small>
            </div>
            <div class="record-summary-item">
                <span>Requested by</span>
                <strong>{{ $changeRequest->requester?->name ?? 'Unknown' }}</strong>
                <small>{{ $changeRequest->created_at->format('j M Y, H:i') }}</small>
            </div>
            <div class="record-summary-item">
                <span>{{ $changeRequest->reviewed_at ? 'Reviewed by' : 'Review ownership' }}</span>
                <strong>{{ $changeRequest->reviewer?->name ?? ($canModerate ? 'Available to review' : 'Admin review') }}</strong>
                <small>{{ $changeRequest->reviewed_at?->format('j M Y, H:i') ?? 'No decision yet' }}</small>
            </div>
        </section>

        @if ($changeRequest->isOpen())
            <div class="alert alert-warning change-request-state-notice" role="status">
                <i class="icon-base bx bx-time-five icon-md" aria-hidden="true"></i>
                <div>
                    <strong>Pending review</strong>
                    <span>The verified record remains unchanged until this request is approved.</span>
                </div>
            </div>
        @elseif ($changeRequest->status === \App\Enums\ChangeRequestStatus::Approved)
            <div class="alert alert-success change-request-state-notice" role="status">
                <i class="icon-base bx bx-check-circle icon-md" aria-hidden="true"></i>
                <div>
                    <strong>Approved and applied</strong>
                    <span>The proposed values are now part of the verified record.</span>
                </div>
            </div>
        @elseif ($changeRequest->status === \App\Enums\ChangeRequestStatus::Rejected)
            <div class="alert alert-danger change-request-state-notice" role="status">
                <i class="icon-base bx bx-x-circle icon-md" aria-hidden="true"></i>
                <div>
                    <strong>Request rejected</strong>
                    <span>The verified record was not changed.</span>
                </div>
            </div>
        @else
            <div class="alert alert-secondary change-request-state-notice" role="status">
                <i class="icon-base bx bx-minus-circle icon-md" aria-hidden="true"></i>
                <div>
                    <strong>Request withdrawn</strong>
                    <span>The request was closed without changing the verified record.</span>
                </div>
            </div>
        @endif

        <div class="record-split-workspace change-request-review-workspace {{ $changeRequest->record->scan_path ? 'has-scan' : 'has-no-scan' }}"
             data-record-split>
            <section class="record-split-pane record-data-pane" id="recordDataPane">
                <x-card class="record-values-card change-request-diff-card" bodyClass="p-0" title="Proposed changes">
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
                        <span>On record when requested</span>
                        <span></span>
                        <span>Proposed value</span>
                    </div>

                    @if ($changeRequest->changes_registry_number)
                        <div class="change-request-diff-row change-request-diff-row--registry">
                            <div class="record-field-row__label">
                                <span>Registry number</span>
                                <small>Document detail</small>
                            </div>
                            <div class="change-request-diff-value is-current">
                                {{ filled($changeRequest->current_registry_number) ? $changeRequest->current_registry_number : 'Not recorded' }}
                            </div>
                            <i class="icon-base bx bx-right-arrow-alt change-request-diff-arrow" aria-hidden="true"></i>
                            <div class="change-request-diff-value is-proposed">
                                {{ filled($changeRequest->proposed_registry_number) ? $changeRequest->proposed_registry_number : 'Not recorded' }}
                            </div>
                        </div>
                    @endif

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
                     aria-label="Resize original scan and proposed changes panels"
                     aria-controls="recordScanPane recordDataPane"
                     aria-valuemin="35" aria-valuemax="75" aria-valuenow="58"
                     aria-valuetext="Original scan 58%, proposed changes 42%"></div>
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
                <x-card title="Reason for correction" subtitle="Submitted with the proposed values.">
                    <p class="change-request-reason mb-0">{{ $changeRequest->reason }}</p>
                </x-card>

                <x-card title="Request details">
                    <dl class="change-request-detail-list mb-0">
                        <div>
                            <dt>Requested by</dt>
                            <dd>{{ $changeRequest->requester?->name ?? 'Unknown' }}</dd>
                        </div>
                        <div>
                            <dt>Submitted</dt>
                            <dd>{{ $changeRequest->created_at->format('j M Y, H:i') }}</dd>
                        </div>
                        <div>
                            <dt>Record</dt>
                            <dd><a href="{{ route('records.show', $changeRequest->record) }}">{{ $recordHeading }}</a></dd>
                        </div>
                        @if ($changeRequest->reviewed_at)
                            <div>
                                <dt>Reviewed by</dt>
                                <dd>{{ $changeRequest->reviewer?->name ?? 'Unknown' }}</dd>
                            </div>
                            <div>
                                <dt>Reviewed</dt>
                                <dd>{{ $changeRequest->reviewed_at->format('j M Y, H:i') }}</dd>
                            </div>
                        @endif
                    </dl>
                </x-card>
            </section>

            <aside class="change-request-decision-column">
                @if ($changeRequest->isOpen() && $canModerate)
                    <x-card class="change-request-decision-card" title="Review decision"
                            subtitle="Approve to apply every proposed value, or reject without changing the record.">
                        <form method="POST" action="{{ route('change-requests.approve', $changeRequest) }}"
                              class="change-request-decision-form is-approve">
                            @csrf
                            <label for="approve-note" class="form-label">Approval note <span class="text-muted">(optional)</span></label>
                            <textarea id="approve-note" name="decision_note" rows="3" maxlength="2000"
                                      class="form-control" placeholder="Add verification details for the audit trail."></textarea>
                            <button type="submit" class="btn btn-primary w-100 mt-3">
                                <i class="icon-base bx bx-check icon-sm me-1"></i> Approve and apply
                            </button>
                        </form>

                        <div class="change-request-decision-divider"><span>or</span></div>

                        <form method="POST" action="{{ route('change-requests.reject', $changeRequest) }}"
                              class="change-request-decision-form is-reject">
                            @csrf
                            <label for="reject-note" class="form-label">Reason for rejection</label>
                            <textarea id="reject-note" name="decision_note" rows="3" maxlength="2000"
                                      class="form-control @error('decision_note') is-invalid @enderror"
                                      placeholder="Explain what the requester needs to correct." required>{{ old('decision_note') }}</textarea>
                            @error('decision_note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <button type="submit" class="btn btn-outline-danger w-100 mt-3">
                                <i class="icon-base bx bx-x icon-sm me-1"></i> Reject request
                            </button>
                        </form>
                    </x-card>
                @elseif ($changeRequest->isOpen() && $changeRequest->requested_by === auth()->id())
                    <x-card title="Waiting for review" subtitle="An Admin must decide this request.">
                        <p class="small text-muted">
                            You may withdraw it while it is still pending. This closes the request without changing the record.
                        </p>
                        <form method="POST" action="{{ route('change-requests.withdraw', $changeRequest) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger w-100">Withdraw request</button>
                        </form>
                    </x-card>
                @else
                    <x-card title="Review outcome">
                        <span class="badge {{ $changeRequest->status->badgeClass() }} mb-3">
                            {{ $changeRequest->status->label() }}
                        </span>
                        @if ($changeRequest->decision_note)
                            <div class="change-request-decision-note">
                                <span>Decision note</span>
                                <p>{{ $changeRequest->decision_note }}</p>
                            </div>
                        @elseif ($changeRequest->status !== \App\Enums\ChangeRequestStatus::Withdrawn)
                            <p class="text-muted mb-0">No decision note was added.</p>
                        @endif
                    </x-card>
                @endif
            </aside>
        </div>
    </main>
@endsection

@push('scripts')
    @vite('resources/js/record-detail.js')
@endpush
