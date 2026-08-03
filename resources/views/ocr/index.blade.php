{{--
    The OCR workspace: choose the model Staff scan with, and manage the models
    installed on disk.

    One page, no tabs. Fine-tuning, evaluation, dataset preparation, and batch
    prediction are command-line work under ml/ and are deliberately not driven from
    here - a web request is the wrong place to pin a GPU for hours. CRMS does not
    start or stop the service process either; it reports whether it answers.
--}}
@extends('layouts.app')

@section('title', 'OCR Workspace')

@section('content')
    <x-page-header title="OCR Workspace"
                   subtitle="Choose the handwriting model Staff scan with, and manage what is installed.">
        <form method="POST" action="{{ route('ocr.rescan') }}">
            @csrf
            <button class="btn btn-outline-secondary" type="submit"
                    @disabled(! $engine['reachable'])
                    title="{{ $engine['reachable']
                        ? 'Re-read ml/models/ and reconcile the registry.'
                        : 'The OCR service has to be running to rescan.' }}">
                <i class="icon-base bx bx-refresh icon-sm me-1"></i> Rescan
            </button>
        </form>
    </x-page-header>

    @include('ocr.partials.engine')

    @include('ocr.partials.models')

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
                // __KEY__ is replaced client-side, so a model key with awkward
                // characters is encoded once rather than baked into markup.
                modelRename: @json(route('ocr.rename', '__KEY__')),
                modelDestroy: @json(route('ocr.destroy', '__KEY__')),
            },
            engineReachable: @json($engine['reachable']),
            // Largest slice this server accepts, read from php.ini rather than
            // assumed by the JS.
            chunkBytes: @json($chunkBytes),
            // The saved active key, so the form can tell a pending choice from the
            // one currently in force.
            activeModelKey: @json($activeModel?->key),
        };
    </script>
@endpush
