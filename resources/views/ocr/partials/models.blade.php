{{--
    Model inventory: fleet management panel.

    Everything a Super Admin does with models lives here: activate one (the model
    Staff scan with, always through the confirmation dialog in ocr.partials.modals),
    add one, rescan ml/models/, and click any model row to inspect its benchmark.
--}}
@php
    $models = $overview['models'];
    $selectable = $models->filter(
        fn (array $model) => $model['on_disk'] && $model['disk_deleted_at'] === null
    );
    // A fresh install has models on disk but none activated, so Staff cannot scan.
    $needsActivation = $activeModel === null && $selectable->isNotEmpty();
    $selectedKey = $modelPerformance['selected'] ?? ($activeModel?->key ?? null);
@endphp

<section class="card ocr-models-card h-100" aria-labelledby="installed-models-title">
    <div class="card-header border-bottom">
        <div class="d-flex align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3 min-w-0">
                <span class="ocr-section-icon bg-label-primary" aria-hidden="true">
                    <i class="icon-base bx bx-brain"></i>
                </span>
                <div class="min-w-0">
                    <h3 class="h5 card-title mb-0" id="installed-models-title">Models</h3>
                </div>
            </div>

            <div class="flex-shrink-0">
                @include('ocr.partials.engine')
            </div>
        </div>

        <div class="ocr-models-toolbar">
            <button type="button"
                    class="btn btn-sm btn-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#addModelModal"
                    {{ ! $engine['reachable'] ? 'disabled' : '' }}
                    title="{{ $engine['reachable'] ? 'Install a model' : 'The OCR service must be online.' }}">
                <i class="icon-base bx bx-plus icon-sm me-1" aria-hidden="true"></i>Add model
            </button>

            <form method="POST" action="{{ route('ocr.rescan') }}" id="ocr-rescan-form" class="d-inline">
                @csrf
                <button type="submit"
                        class="btn btn-sm btn-outline-secondary"
                        id="ocr-rescan-btn"
                        {{ ! $engine['reachable'] ? 'disabled' : '' }}
                        title="{{ $engine['reachable'] ? 'Re-read ml/models/ and reconcile the registry.' : 'The OCR service must be online before models can be rescanned.' }}">
                    <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                    <i class="icon-base bx bx-refresh icon-sm me-1" aria-hidden="true"></i>
                    <span>Rescan models</span>
                </button>
            </form>
        </div>

        @if ($needsActivation)
            <div class="alert alert-warning d-flex align-items-start gap-2 py-2 px-3 small mb-0 mt-3" role="status">
                <i class="icon-base bx bx-error icon-sm flex-shrink-0 mt-1" aria-hidden="true"></i>
                <span>No model is active yet. Activate one so Staff can scan handwriting.</span>
            </div>
        @endif
    </div>

    <div class="ocr-models-scroll flex-grow-1">
        @if ($models->isEmpty())
            <div class="card-body">
                <div class="ocr-empty-state">
                    <span class="ocr-empty-icon" aria-hidden="true">
                        <i class="icon-base bx bx-brain"></i>
                    </span>
                    <h4 class="h6 mb-1">No models available</h4>
                    <p class="text-muted small mb-0">
                        Add a model, or copy its folder into <span class="font-monospace">ml/models/</span>
                        and rescan.
                    </p>
                </div>
            </div>
        @else
            <div class="ocr-model-list" role="list">
                @foreach ($models as $model)
                    @php
                        $showKey = strcasecmp((string) $model['label'], (string) $model['key']) !== 0;
                        $canManage = $engine['reachable']
                            && $model['on_disk']
                            && ! $model['is_base']
                            && ! $model['is_active']
                            && $model['disk_deleted_at'] === null;

                        if (! $engine['reachable']) {
                            $blockedReason = 'The OCR service is offline.';
                        } elseif ($model['is_base']) {
                            $blockedReason = 'The built-in base model is protected.';
                        } elseif ($model['is_active']) {
                            $blockedReason = 'Activate another model before renaming or deleting this one.';
                        } elseif ($model['disk_deleted_at']) {
                            $blockedReason = 'This model was deleted from disk.';
                        } elseif (! $model['on_disk']) {
                            $blockedReason = 'The model folder is missing from disk.';
                        } else {
                            $blockedReason = '';
                        }

                        // A badge for every state that cannot be activated. A model
                        // that is usable and not active gets the Activate button
                        // instead. Missing is checked before Active on purpose: an
                        // active model whose folder is gone must not look healthy.
                        $stateLabel = null;
                        if ($model['disk_deleted_at']) {
                            $stateLabel = 'Deleted';
                            $stateColor = 'danger';
                            $stateIcon = 'bx-trash';
                        } elseif (! $model['on_disk']) {
                            $stateLabel = $engine['reachable'] ? 'Missing' : 'Unknown';
                            $stateColor = 'warning';
                            $stateIcon = 'bx-error';
                        } elseif ($model['is_active']) {
                            $stateLabel = 'Active';
                            $stateColor = 'success';
                            $stateIcon = 'bx-check-circle';
                        }
                    @endphp

                    <article class="ocr-model-row {{ $model['is_active'] ? 'is-active' : '' }} {{ $model['key'] === $selectedKey ? 'is-inspected' : '' }}"
                             role="button"
                             tabindex="0"
                             data-model-key="{{ $model['key'] }}"
                             title="Click to inspect this model's benchmark performance">
                        <div class="ocr-model-main">
                            <span class="ocr-model-icon {{ $model['is_active'] ? 'is-active' : ($model['is_base'] ? 'is-base' : '') }}"
                                  aria-hidden="true">
                                <i class="icon-base bx bx-brain"></i>
                            </span>

                            <div class="min-w-0">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <h4 class="ocr-model-name mb-0">{{ $model['label'] }}</h4>
                                    @if ($model['is_base'])
                                        <span class="badge bg-label-secondary">Base</span>
                                    @endif
                                </div>

                                @if ($showKey)
                                    <div class="ocr-model-key">{{ $model['key'] }}</div>
                                @endif

                                <div class="ocr-model-meta">
                                    @if ($model['is_base'])
                                        <span>Built-in model</span>
                                    @elseif ($model['registered_at'])
                                        {{-- One text run, so it wraps by words and never strands a separator. --}}
                                        <span>Added {{ $model['registered_at']->diffForHumans() }}@if ($model['registrar']) by {{ $model['registrar'] }}@endif</span>
                                    @else
                                        <span>Discovered on disk</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="ocr-model-controls d-flex align-items-center gap-2">
                            @if ($stateLabel)
                                <span class="badge bg-label-{{ $stateColor }} ocr-model-state">
                                    <i class="icon-base bx {{ $stateIcon }} icon-xs me-1" aria-hidden="true"></i>{{ $stateLabel }}
                                </span>
                            @else
                                <button type="button"
                                        class="btn btn-sm btn-outline-primary ocr-model-state"
                                        data-bs-toggle="modal"
                                        data-bs-target="#activateModal"
                                        data-key="{{ $model['key'] }}"
                                        data-label="{{ $model['label'] }}"
                                        aria-label="Activate {{ $model['label'] }}"
                                        {{ ! $engine['reachable'] ? 'disabled' : '' }}
                                        title="{{ $engine['reachable'] ? 'Make this the model Staff scan with' : 'The OCR service must be online.' }}">
                                    Activate
                                </button>
                            @endif

                            @if ($canManage)
                                {{-- Fixed positioning lets the menu overflow the short, scrolling list. --}}
                                <div class="dropdown">
                                    <button type="button"
                                            class="btn p-0 dropdown-toggle hide-arrow"
                                            data-bs-toggle="dropdown"
                                            data-bs-popper-config='{"strategy":"fixed"}'
                                            aria-expanded="false"
                                            aria-label="Manage {{ $model['label'] }}">
                                        <i class="icon-base bx bx-dots-vertical-rounded"></i>
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-end ocr-model-menu">
                                        <div class="px-3 py-2 border-bottom">
                                            <div class="fw-medium text-truncate">{{ $model['label'] }}</div>
                                            @if ($showKey)
                                                <div class="text-muted small font-monospace text-truncate">{{ $model['key'] }}</div>
                                            @endif
                                        </div>

                                        <button type="button"
                                                class="dropdown-item model-rename-trigger"
                                                data-key="{{ $model['key'] }}"
                                                data-label="{{ $model['label'] }}"
                                                data-bs-toggle="modal"
                                                data-bs-target="#renameModal">
                                            <i class="icon-base bx bx-rename icon-sm me-2"></i>Rename
                                        </button>
                                        <div class="dropdown-divider"></div>
                                        <button type="button"
                                                class="dropdown-item text-danger model-delete-trigger"
                                                data-key="{{ $model['key'] }}"
                                                data-label="{{ $model['label'] }}"
                                                data-bs-toggle="modal"
                                                data-bs-target="#deleteModal">
                                            <i class="icon-base bx bx-trash icon-sm me-2"></i>Delete
                                        </button>
                                    </div>
                                </div>
                            @else
                                <span class="ocr-model-lock text-muted"
                                      data-bs-toggle="tooltip"
                                      data-bs-placement="top"
                                      title="{{ $blockedReason }}">
                                    <i class="icon-base bx bx-lock-alt icon-xs"></i>
                                </span>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</section>
