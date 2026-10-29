@extends('layouts.app')

@section('title', 'New Document')

@php
    $online = $health['reachable'];
    $modelName = $activeModel ? ($activeModel->label ?: $activeModel->key) : null;
@endphp

@section('content')
    <x-page-header title="New Document" subtitle="Choose a document type." />

    {{-- What scanning will use, in one quiet line. Problems get an alert below. --}}
    <div class="scan-type-status mb-4" role="status">
        <span class="scan-type-status__item">
            <span class="scan-type-status__dot {{ $online ? 'is-online' : 'is-offline' }}" aria-hidden="true"></span>
            OCR {{ $online ? 'online' : 'offline' }}
        </span>
        @if ($modelName)
            <span class="scan-type-status__item text-muted" title="Active OCR model">{{ $modelName }}</span>
        @endif
    </div>

    @unless ($online)
        <div class="alert alert-danger d-flex align-items-start" role="alert">
            <i class="icon-base bx bx-plug icon-md me-2" aria-hidden="true"></i>
            <div>
                <h6 class="alert-heading mb-1">OCR service unavailable</h6>
                <p class="mb-0">{{ $health['error'] }}</p>
                <p class="mb-0 small">You can still mark fields, but handwriting cannot be read until it is back.</p>
            </div>
        </div>
    @endunless

    @if (! $activeModel)
        <div class="alert alert-warning d-flex align-items-center" role="alert">
            <i class="icon-base bx bx-error icon-md me-2" aria-hidden="true"></i>
            <div>
                No OCR model is active. A Super Admin needs to activate one before scanning
                produces readings.
            </div>
        </div>
    @endif

    <div class="row g-4">
        @foreach ($documentTypes as $type)
            @php
                $template = $templates[$type->value];
                // The template's own name only when it says something the type does not.
                $templateName = $template && strcasecmp(trim($template->name), trim($type->label())) !== 0
                    ? $template->name
                    : null;
            @endphp

            <div class="col-sm-6 col-xl-4">
                @if ($template)
                    <a href="{{ route('documents.workspace', ['type' => $type->value]) }}"
                       class="card h-100 scan-type-card">
                        <div class="card-body">
                            <div class="scan-type-card__head">
                                <span class="scan-type-card__icon" aria-hidden="true">
                                    <i class="icon-base bx {{ $type->icon() }}"></i>
                                </span>
                                <h5 class="scan-type-card__title">{{ $type->label() }}</h5>
                            </div>

                            @if ($templateName)
                                <p class="scan-type-card__meta">{{ $templateName }}</p>
                            @endif

                            <span class="scan-type-card__action">
                                Start scanning
                                <i class="icon-base bx bx-right-arrow-alt" aria-hidden="true"></i>
                            </span>
                        </div>
                    </a>
                @else
                    <div class="card h-100 scan-type-card is-unavailable">
                        <div class="card-body">
                            <div class="scan-type-card__head">
                                <span class="scan-type-card__icon" aria-hidden="true">
                                    <i class="icon-base bx {{ $type->icon() }}"></i>
                                </span>
                                <h5 class="scan-type-card__title">{{ $type->label() }}</h5>
                            </div>

                            <p class="scan-type-card__meta">No published template</p>

                            @can('templates.manage')
                                <a href="{{ route('templates.index') }}" class="scan-type-card__action">
                                    Create template
                                    <i class="icon-base bx bx-right-arrow-alt" aria-hidden="true"></i>
                                </a>
                            @else
                                <span class="scan-type-card__action text-muted">
                                    <i class="icon-base bx bx-lock-alt" aria-hidden="true"></i>
                                    Unavailable
                                </span>
                            @endcan
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endsection
