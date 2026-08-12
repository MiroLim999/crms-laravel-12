{{--
    OCR Workspace: manage the service-facing model folders and the policy Staff
    scan with. Training, evaluation, and process control remain command-line work.
--}}
@extends('layouts.app')

@section('title', 'OCR Workspace')

@section('content')
    <div class="ocr-workspace">
        <x-page-header title="OCR Workspace"
                       subtitle="Manage handwriting models and review behavior.">
            <form method="POST" action="{{ route('ocr.rescan') }}">
                @csrf
                <button class="btn btn-label-secondary" type="submit"
                        @disabled(! $engine['reachable'])
                        title="{{ $engine['reachable']
                            ? 'Re-read ml/models/ and reconcile the registry.'
                            : 'The OCR service must be online before models can be rescanned.' }}">
                    <i class="icon-base bx bx-refresh icon-sm me-1"></i>
                    Rescan models
                </button>
            </form>
        </x-page-header>

        @include('ocr.partials.engine')
        @include('ocr.partials.performance')
        @include('ocr.partials.models')
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
                modelRename: @json(route('ocr.rename', '__KEY__')),
                modelDestroy: @json(route('ocr.destroy', '__KEY__')),
            },
            engineReachable: @json($engine['reachable']),
        };
    </script>

    {{-- Page-scoped interaction bundle. --}}
    @vite('resources/js/ocr-workspace.js')
@endpush
