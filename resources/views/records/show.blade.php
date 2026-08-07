@extends('layouts.app')

@section('title', 'Record #' . $record->getKey())
@section('body-class', 'record-detail-focused')

@section('content')
    <header class="document-workspace-topbar record-detail-topbar">
        <div class="document-workspace-topbar__context">
            <strong>{{ $record->title() }}</strong>
            <small>{{ $record->typeLabel() }} · {{ $record->registry_number ?? 'No registry number' }}</small>
        </div>

        <nav class="document-flow-steps" aria-label="Document processing complete">
            <div class="document-flow-step is-complete">
                <span class="document-flow-step__number">1</span>
                <span class="document-flow-step__copy"><strong>Upload</strong></span>
            </div>
            <div class="document-flow-step is-complete">
                <span class="document-flow-step__number">2</span>
                <span class="document-flow-step__copy"><strong>Align</strong></span>
            </div>
            <div class="document-flow-step is-complete">
                <span class="document-flow-step__number">3</span>
                <span class="document-flow-step__copy"><strong>Verified</strong></span>
            </div>
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
    </header>

    @if ($record->isLocked())
        <div class="alert alert-info d-flex align-items-center" role="alert">
            <i class="icon-base bx bx-lock-alt icon-md me-2"></i>
            <div>
                This record is locked. Values change only through a change request approved
                by an Admin — nobody edits them in place.
            </div>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-7">
            <x-card title="Recorded values"
                    subtitle="Verified text is the value of record. The reading is kept for traceability.">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>Verified value</th>
                                <th>Model read</th>
                                <th class="text-end">Confidence</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($record->fields as $field)
                                <tr class="{{ $field->needsReview() ? 'table-warning' : '' }}">
                                    <td class="text-muted">{{ $field->name }}</td>
                                    <td class="fw-medium">{{ $field->verified_value ?: '—' }}</td>
                                    <td>
                                        <span class="fst-italic text-muted">{{ $field->ocr_text ?: '—' }}</span>
                                        @if ($field->wasCorrected())
                                            <span class="badge bg-label-info ms-1">corrected</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if ($field->ocr_confidence !== null)
                                            <span class="confidence-badge">
                                                {{ number_format($field->ocr_confidence, 1) }}%
                                            </span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="small text-muted mt-3 mb-0">
                    Confidence is the model's certainty in its own output, not accuracy.
                    Rows highlighted were below {{ $threshold }}% at capture time.
                </p>
            </x-card>

            @if ($record->changeRequests->isNotEmpty())
                <x-card class="mt-4" title="Change history">
                    @foreach ($record->changeRequests as $request)
                        <div class="{{ ! $loop->last ? 'mb-3 pb-3 border-bottom' : '' }}">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div>
                                    <a href="{{ route('change-requests.show', $request) }}" class="fw-medium">
                                        Request #{{ $request->getKey() }}
                                    </a>
                                    <div class="small text-muted">
                                        {{ $request->requester?->name }}
                                        · {{ $request->created_at->diffForHumans() }}
                                        · {{ $request->items->count() }} field(s)
                                    </div>
                                </div>
                                <span class="badge {{ $request->status->badgeClass() }}">
                                    {{ $request->status->label() }}
                                </span>
                            </div>
                            <p class="small mb-0 mt-2">{{ $request->reason }}</p>
                        </div>
                    @endforeach
                </x-card>
            @endif
        </div>

        <div class="col-lg-5">
            <x-card title="Provenance" class="mb-4">
                <dl class="row mb-0">
                    <dt class="col-5 fw-normal text-muted">Status</dt>
                    <dd class="col-7">
                        <span class="badge {{ $record->status->badgeClass() }}">
                            {{ $record->status->label() }}
                        </span>
                    </dd>

                    <dt class="col-5 fw-normal text-muted">Submitted by</dt>
                    <dd class="col-7">{{ $record->submitter?->name ?? '—' }}</dd>

                    <dt class="col-5 fw-normal text-muted">Submitted at</dt>
                    <dd class="col-7">{{ $record->submitted_at?->format('j M Y, H:i') ?? '—' }}</dd>

                    <dt class="col-5 fw-normal text-muted">Template</dt>
                    <dd class="col-7">{{ $record->template?->name ?? '—' }}</dd>

                    <dt class="col-5 fw-normal text-muted">OCR model</dt>
                    <dd class="col-7 mb-0">
                        <code>{{ $record->ocr_model_key ?? '—' }}</code>
                    </dd>
                </dl>
            </x-card>

            @if ($record->scan_path)
                <x-card title="Original scan"
                        subtitle="Held outside the web root and served only to signed-in users.">
                    @if (str_contains((string) $record->scan_mime, 'pdf'))
                        <a href="{{ route('records.scan', $record) }}" target="_blank"
                           class="btn btn-outline-secondary w-100">
                            <i class="icon-base bx bx-file icon-sm me-1"></i> Open scanned PDF
                        </a>
                    @else
                        <a href="{{ route('records.scan', $record) }}" target="_blank">
                            <img src="{{ route('records.scan', $record) }}" alt="Original scanned certificate"
                                 class="img-fluid rounded border">
                        </a>
                    @endif
                </x-card>
            @endif
        </div>
    </div>
@endsection
