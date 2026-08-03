@extends('layouts.app')

@section('title', 'New Document')

@section('content')
    <x-page-header title="New Document"
                   subtitle="Choose a certificate type, then mark and verify its fields." />

    @unless ($health['reachable'])
        <div class="alert alert-danger d-flex align-items-start" role="alert">
            <i class="icon-base bx bx-plug icon-md me-2"></i>
            <div>
                <h6 class="alert-heading mb-1">OCR service unavailable</h6>
                <p class="mb-0">{{ $health['error'] }}</p>
                <p class="mb-0 small">You can still mark fields, but handwriting cannot be read until it is back.</p>
            </div>
        </div>
    @endunless

    @if (! $activeModel)
        <div class="alert alert-warning d-flex align-items-center" role="alert">
            <i class="icon-base bx bx-error icon-md me-2"></i>
            <div>
                No OCR model has been promoted yet. A Super Admin needs to activate one
                before scanning will produce readings.
            </div>
        </div>
    @endif

    <div class="row g-4">
        @foreach ($documentTypes as $type)
            @php $template = $templates[$type->value]; @endphp

            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <span class="badge bg-label-primary rounded p-2 lh-1 align-self-start mb-3">
                            <i class="icon-base bx {{ $type->icon() }} icon-md"></i>
                        </span>

                        <h5 class="mb-1">{{ $type->label() }}</h5>

                        @if ($template)
                            <p class="text-muted small flex-grow-1">
                                {{ $template->name }} · {{ $template->fields->count() }} fields
                            </p>
                            <a href="{{ route('documents.workspace', ['type' => $type->value]) }}"
                               class="btn btn-primary">
                                Start scanning
                            </a>
                        @else
                            <p class="text-muted small flex-grow-1">
                                No active template. A Super Admin must publish one before this
                                type can be captured.
                            </p>
                            <button class="btn btn-outline-secondary" disabled>Unavailable</button>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if ($activeModel)
        <p class="text-muted small mt-4 mb-0">
            Readings come from <code>{{ $activeModel->key }}</code>.
            Confidence shown during review is the model's certainty in its own output,
            not a measure of accuracy.
        </p>
    @endif
@endsection
