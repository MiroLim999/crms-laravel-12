{{--
    OCR Workspace: model performance, and the models Staff scan with. One card
    holds the service status, the benchmark chart, and the Models panel. Training,
    evaluation, and process control remain command-line work.
--}}
@extends('layouts.app')

@section('title', 'OCR Workspace')

@section('content')
    <div class="ocr-workspace">
        <div class="row g-4 ocr-workspace-grid align-items-stretch">
            <div class="col-12 col-xl-7">
                @include('ocr.partials.performance')
            </div>
            <div class="col-12 col-xl-5">
                @include('ocr.partials.models')
            </div>
        </div>
        @include('ocr.partials.modals')
    </div>
@endsection

@push('styles')
    @vite('resources/scss/pages/ocr-workspace.scss')
@endpush

@push('scripts')
    <script>
        // Endpoints and initial state are rendered here; the page module contains no
        // hardcoded application URLs.
        window.crmsOcr = {
            csrf: document.querySelector('meta[name="csrf-token"]').content,
            urls: {
                authorizeUpload: @json(route('ocr.uploads.authorize')),
                registerModel: @json(route('ocr.register')),
                engineStatus: @json(route('ocr.engine.status')),
                modelActivate: @json(route('ocr.models.activate', '__KEY__')),
                modelRename: @json(route('ocr.rename', '__KEY__')),
                modelDestroy: @json(route('ocr.destroy', '__KEY__')),
            },
            engineReachable: @json($engine['reachable']),
        };
    </script>

    {{-- Page-scoped interaction bundle. --}}
    @vite('resources/js/ocr-workspace.js')
@endpush
