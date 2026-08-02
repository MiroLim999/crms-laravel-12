{{--
    The OCR workspace: the whole ML lifecycle on one page, no CLI.

    Tabs are sections of one page rather than separate routes, because the engine
    status and any running job have to stay visible whichever section you are in.
    The open tab is carried in ?tab= so a redirect after an action returns you to
    where you were.
--}}
@extends('layouts.app')

@section('title', 'OCR Workspace')

@php
    $tabs = [
        'models' => ['label' => 'Models', 'icon' => 'bx-brain'],
        'datasets' => ['label' => 'Datasets', 'icon' => 'bx-folder'],
        'training' => ['label' => 'Fine-tuning', 'icon' => 'bx-dumbbell'],
        'evaluation' => ['label' => 'Evaluation', 'icon' => 'bx-bar-chart-alt-2'],
        'predict' => ['label' => 'Predict', 'icon' => 'bx-search-alt'],
    ];
@endphp

@section('content')
    <x-page-header title="OCR Workspace"
                   subtitle="Upload datasets, fine-tune a model, evaluate it, then promote it for Staff scanning.">
        <form method="POST" action="{{ route('ocr.rescan') }}">
            @csrf
            <button class="btn btn-outline-secondary" type="submit"
                    @disabled(! $engine['reachable'])>
                <i class="icon-base bx bx-refresh icon-sm me-1"></i> Rescan
            </button>
        </form>
    </x-page-header>

    @include('ocr.partials.engine')

    {{-- A running job matters on every tab, so it sits above them. --}}
    @include('ocr.partials.job-banner')

    <ul class="nav nav-pills flex-column flex-md-row mb-4" role="tablist" id="ocrTabs">
        @foreach ($tabs as $key => $meta)
            <li class="nav-item">
                <button type="button"
                        class="nav-link @if ($tab === $key) active @endif"
                        data-bs-toggle="tab"
                        data-bs-target="#tab-{{ $key }}"
                        data-tab-key="{{ $key }}"
                        role="tab"
                        aria-selected="{{ $tab === $key ? 'true' : 'false' }}">
                    <i class="icon-base bx {{ $meta['icon'] }} icon-sm me-1"></i>
                    {{ $meta['label'] }}
                </button>
            </li>
        @endforeach
    </ul>

    <div class="tab-content p-0 bg-transparent shadow-none">
        @foreach ($tabs as $key => $meta)
            <div class="tab-pane fade @if ($tab === $key) show active @endif"
                 id="tab-{{ $key }}" role="tabpanel">
                @include('ocr.partials.'.$key)
            </div>
        @endforeach
    </div>

    {{--
        Run history sits outside the tabs. Every pane is rendered, so including it
        from both Fine-tuning and Evaluation would emit each row's id twice.
    --}}
    @include('ocr.partials.history')

    @include('ocr.partials.modals')
@endsection

@push('scripts')
    {{--
        Own Vite entry, listed as an input in vite.config.js. Anything referenced by
        @vite() and not declared there throws "Unable to locate file in Vite
        manifest" - a 500, not a silent miss.
    --}}
    @vite('resources/js/ocr-workspace.js')

    <script>
        // Endpoints and state the module needs, rendered here so the JS file itself
        // contains no hardcoded URLs.
        window.crmsOcr = {
            csrf: document.querySelector('meta[name="csrf-token"]').content,
            urls: {
                chunk: @json(route('ocr.uploads.chunk')),
                discardUpload: @json(route('ocr.uploads.discard')),
                engineStatus: @json(route('ocr.engine.status')),
                predict: @json(route('ocr.predict')),
                index: @json(route('ocr.index')),
                // __KEY__ / __ID__ are replaced client-side, so a model key with
                // awkward characters is encoded once rather than baked into markup.
                jobStatus: @json(route('ocr.jobs.status', ['job' => '__ID__'])),
                jobCancel: @json(route('ocr.jobs.cancel', ['job' => '__ID__'])),
                modelActivate: @json(route('ocr.activate', '__KEY__')),
                modelRename: @json(route('ocr.rename', '__KEY__')),
                modelDestroy: @json(route('ocr.destroy', '__KEY__')),
                modelEvaluation: @json(route('ocr.evaluation', '__KEY__')),
                datasetDestroy: @json(route('ocr.datasets.destroy', '__KEY__')),
            },
            activeJobId: @json($activeJob?->getKey()),
            engineReachable: @json($engine['reachable']),
            // Process-control state is tracked separately from reachability. Windows
            // may discover an externally started listener PID on a later poll; when
            // that happens the page must reload to enable Stop.
            engineOwned: @json($engine['owned']),
            engineStoppable: @json($engine['stoppable']),
            enginePid: @json($engine['pid']),
            engineListenerPid: @json($engine['listener_pid']),
            threshold: @json((float) $threshold),
        };
    </script>
@endpush
