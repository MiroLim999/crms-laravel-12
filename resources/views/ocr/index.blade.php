@extends('layouts.app')

@section('title', 'OCR Models')

@section('content')
    <x-page-header title="OCR Models"
                   subtitle="Manage TrOCR models, promote one for Staff scanning, and review evaluation metrics.">
        <form method="POST" action="{{ route('ocr.rescan') }}">
            @csrf
            <button class="btn btn-outline-secondary" type="submit">
                <i class="icon-base bx bx-refresh icon-sm me-1"></i> Rescan
            </button>
        </form>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModelModal">
            <i class="icon-base bx bx-plus icon-sm me-1"></i> Add Model
        </button>
    </x-page-header>

    {{-- Engine status. Staff scanning fails outright when this is down. --}}
    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            @if ($overview['reachable'])
                <span class="d-inline-flex align-items-center gap-2">
                    <span class="badge bg-success rounded-circle p-1"></span>
                    <strong>OCR service online</strong>
                </span>
                <span class="text-muted">
                    Device: <code>{{ $overview['device'] ?: 'not-loaded' }}</code>
                </span>
                <span class="text-muted">
                    Review threshold: <strong>{{ $threshold }}%</strong> confidence
                </span>
            @else
                <span class="d-inline-flex align-items-center gap-2">
                    <span class="badge bg-danger rounded-circle p-1"></span>
                    <strong>OCR service offline</strong>
                </span>
                <span class="text-muted small">{{ $overview['error'] }}</span>
            @endif
        </div>
    </div>

    @if (! $overview['models']->contains(fn ($m) => $m['is_active']))
        <div class="alert alert-warning d-flex align-items-center" role="alert">
            <i class="icon-base bx bx-error icon-md me-2"></i>
            <div>
                No active model. Staff cannot scan documents until one is promoted below.
            </div>
        </div>
    @endif

    <x-card title="Models" subtitle="Discovered from the Models folder, reconciled with the CRMS registry.">
        @if ($overview['models']->isEmpty())
            <x-empty-state icon="bx-brain" title="No models found"
                           message="Add a model folder, or drop one into Models/ and rescan." />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Model</th>
                            <th>State</th>
                            <th>Evaluation</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($overview['models'] as $model)
                            <tr>
                                <td>
                                    <div class="fw-medium">{{ $model['label'] }}</div>
                                    <code class="small text-muted">{{ $model['key'] }}</code>
                                    @if ($model['is_base'])
                                        <span class="badge bg-label-secondary ms-1">base</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($model['is_active'])
                                        <span class="badge bg-label-success">Active</span>
                                    @endif
                                    @if (! $model['on_disk'])
                                        <span class="badge bg-label-danger">Missing on disk</span>
                                    @elseif ($model['loaded'])
                                        <span class="badge bg-label-info">Loaded in memory</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($model['cer'] !== null || $model['wer'] !== null)
                                        <div class="small">
                                            CER {{ $model['cer'] !== null ? number_format($model['cer'] * 100, 2).'%' : '—' }}
                                            · WER {{ $model['wer'] !== null ? number_format($model['wer'] * 100, 2).'%' : '—' }}
                                            · Exact {{ $model['exact_match'] !== null ? number_format($model['exact_match'] * 100, 2).'%' : '—' }}
                                        </div>
                                        <small class="text-muted">{{ $model['evaluated_at']?->diffForHumans() }}</small>
                                    @else
                                        <span class="text-muted small">Not recorded</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-icon btn-text-secondary rounded-pill dropdown-toggle hide-arrow"
                                                data-bs-toggle="dropdown" aria-label="Actions">
                                            <i class="icon-base bx bx-dots-vertical-rounded"></i>
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-end">
                                            @unless ($model['is_active'])
                                                <form method="POST" action="{{ route('ocr.activate', $model['key']) }}">
                                                    @csrf
                                                    <button type="submit" class="dropdown-item"
                                                            @disabled(! $model['on_disk'])>
                                                        <i class="icon-base bx bx-check-circle icon-sm me-2"></i>
                                                        Set as active
                                                    </button>
                                                </form>
                                            @endunless

                                            <button type="button" class="dropdown-item"
                                                    data-bs-toggle="modal" data-bs-target="#evalModal"
                                                    data-model-key="{{ $model['key'] }}"
                                                    data-cer="{{ $model['cer'] }}"
                                                    data-wer="{{ $model['wer'] }}"
                                                    data-exact="{{ $model['exact_match'] }}"
                                                    data-notes="{{ $model['notes'] }}">
                                                <i class="icon-base bx bx-bar-chart-alt-2 icon-sm me-2"></i>
                                                Record evaluation
                                            </button>

                                            @unless ($model['is_base'])
                                                <button type="button" class="dropdown-item"
                                                        data-bs-toggle="modal" data-bs-target="#renameModal"
                                                        data-model-key="{{ $model['key'] }}"
                                                        @disabled($model['is_active'])>
                                                    <i class="icon-base bx bx-rename icon-sm me-2"></i> Rename
                                                </button>

                                                <div class="dropdown-divider"></div>

                                                <button type="button" class="dropdown-item text-danger"
                                                        data-bs-toggle="modal" data-bs-target="#deleteModal"
                                                        data-model-key="{{ $model['key'] }}"
                                                        @disabled($model['is_active'])>
                                                    <i class="icon-base bx bx-trash icon-sm me-2"></i> Delete
                                                </button>
                                            @endunless
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="small text-muted mb-0 mt-2">
                The base model cannot be renamed or deleted. The active model is locked
                against rename and delete — promote another model first.
            </p>
        @endif
    </x-card>

    {{-- Evaluation charts written by test_trocr.py / test_finetuned.py --}}
    <div class="row g-4 mt-2">
        @foreach ($charts as $variant => $files)
            <div class="col-lg-6">
                <x-card :title="ucfirst($variant) . ' model metrics'"
                        :subtitle="'Evaluation Metrics/' . $variant . '/'">
                    @forelse ($files as $chart)
                        <figure class="mb-3">
                            <img src="{{ $chart['url'] }}" alt="{{ $variant }} evaluation chart"
                                 class="img-fluid rounded border">
                            <figcaption class="small text-muted mt-1">
                                {{ $chart['name'] }} · {{ $chart['modified']->diffForHumans() }}
                            </figcaption>
                        </figure>
                    @empty
                        <x-empty-state icon="bx-bar-chart" title="No charts yet"
                                       message="Run test_{{ $variant === 'base' ? 'trocr' : 'finetuned' }}.py to generate one." />
                    @endforelse
                </x-card>
            </div>
        @endforeach
    </div>

    @include('ocr.partials.modals')
@endsection
