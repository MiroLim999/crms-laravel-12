@php
    $models = $overview['models'];
    $selectable = $models->filter(
        fn (array $model) => $model['on_disk'] && $model['disk_deleted_at'] === null
    );
    $selected = old('model', $activeModel?->key);
    $availableCount = $selectable->count();
@endphp

<div class="ocr-workspace-grid">
    {{-- ------------------------------------------------------- model inventory --}}
    <section class="card ocr-models-card" aria-labelledby="installed-models-title">
        <div class="card-header border-bottom">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-3">
                    <span class="ocr-section-icon bg-label-primary" aria-hidden="true">
                        <i class="icon-base bx bx-brain"></i>
                    </span>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <h2 class="h5 card-title mb-0" id="installed-models-title">Models</h2>
                            <span class="badge bg-label-secondary">
                                {{ $availableCount }} available
                            </span>
                        </div>
                        <p class="text-muted small mb-0 mt-1">Models available to the OCR service.</p>
                    </div>
                </div>

                <button type="button"
                        class="btn btn-sm btn-primary"
                        data-bs-toggle="modal"
                        data-bs-target="#addModelModal"
                        @disabled(! $engine['reachable'])
                        title="{{ $engine['reachable'] ? 'Install a model' : 'The OCR service must be online.' }}">
                    <i class="icon-base bx bx-plus icon-sm me-1"></i>Add model
                </button>
            </div>
        </div>

        @if ($models->isEmpty())
            <div class="card-body">
                <div class="ocr-empty-state">
                    <span class="ocr-empty-icon" aria-hidden="true">
                        <i class="icon-base bx bx-brain"></i>
                    </span>
                    <h3 class="h6 mb-1">No models available</h3>
                    <p class="text-muted small mb-3">
                        Upload a model, or copy its folder into <span class="font-monospace">ml/models/</span>
                        and rescan.
                    </p>
                    <button type="button" class="btn btn-sm btn-primary"
                            data-bs-toggle="modal" data-bs-target="#addModelModal"
                            @disabled(! $engine['reachable'])>
                        Add model
                    </button>
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
                            $blockedReason = 'Select another scanning model and save before managing this one.';
                        } elseif ($model['disk_deleted_at']) {
                            $blockedReason = 'This model was deleted from disk.';
                        } elseif (! $model['on_disk']) {
                            $blockedReason = 'The model folder is missing from disk.';
                        } else {
                            $blockedReason = '';
                        }

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
                        } elseif ($model['loaded']) {
                            $stateLabel = 'Loaded';
                            $stateColor = 'info';
                            $stateIcon = 'bx-chip';
                        } else {
                            $stateLabel = 'Available';
                            $stateColor = 'secondary';
                            $stateIcon = 'bx-check';
                        }
                    @endphp

                    <article class="ocr-model-row {{ $model['is_active'] ? 'is-active' : '' }}"
                             role="listitem"
                             data-model-key="{{ $model['key'] }}">
                        <div class="ocr-model-main">
                            <span class="ocr-model-icon bg-label-{{ $model['is_active'] ? 'success' : ($model['is_base'] ? 'secondary' : 'primary') }}"
                                  aria-hidden="true">
                                <i class="icon-base bx bx-brain"></i>
                            </span>

                            <div class="min-w-0">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <h3 class="ocr-model-name mb-0">{{ $model['label'] }}</h3>
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
                                        <span>Added {{ $model['registered_at']->diffForHumans() }}</span>
                                        @if ($model['registrar'])
                                            <span aria-hidden="true">·</span>
                                            <span>{{ $model['registrar'] }}</span>
                                        @endif
                                    @else
                                        <span>Discovered on disk</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="ocr-model-controls">
                            <span class="badge bg-label-{{ $stateColor }} ocr-model-state">
                                <i class="icon-base bx {{ $stateIcon }} icon-xs me-1"></i>{{ $stateLabel }}
                            </span>

                            <div class="dropdown">
                                <button type="button"
                                        class="btn p-0 dropdown-toggle hide-arrow"
                                        data-bs-toggle="dropdown"
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

                                    @if ($canManage)
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
                                    @else
                                        <div class="dropdown-item-text d-flex gap-2 py-3 text-muted small">
                                            <i class="icon-base bx bx-lock-alt icon-sm flex-shrink-0 mt-1"></i>
                                            <span>{{ $blockedReason }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    {{-- -------------------------------------------------------- scanning policy --}}
    <form method="POST" action="{{ route('ocr.settings') }}"
          class="card ocr-policy-card" id="ocrSettingsForm">
        @csrf

        <div class="card-header border-bottom">
            <div class="d-flex align-items-center gap-3">
                <span class="ocr-section-icon bg-label-info" aria-hidden="true">
                    <i class="icon-base bx bx-cog"></i>
                </span>
                <div class="min-w-0">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <h2 class="h5 card-title mb-0">Scanning policy</h2>
                        @if ($activeModel === null && $selectable->isNotEmpty())
                            <span class="badge bg-label-warning">Action needed</span>
                        @endif
                    </div>
                    <p class="text-muted small mb-0 mt-1">Applies to new scans.</p>
                </div>
            </div>
        </div>

        <div class="card-body">
            @if ($activeModel === null && $selectable->isNotEmpty())
                <div class="alert alert-warning py-2 px-3 small mb-4">
                    Choose a model and save to enable handwriting recognition.
                </div>
            @endif

            <div class="mb-4">
                <label for="model-select" class="form-label fw-medium">Approved model</label>
                <select class="form-select" id="model-select" name="model"
                        @disabled($selectable->isEmpty())>
                    @if ($selectable->isEmpty())
                        <option value="">
                            {{ $engine['reachable'] ? 'No models available' : 'OCR service offline' }}
                        </option>
                    @else
                        @if ($activeModel === null)
                            <option value="" @selected($selected === null || $selected === '')>
                                Select a model
                            </option>
                        @endif
                        @foreach ($selectable as $model)
                            <option value="{{ $model['key'] }}"
                                    @selected($selected === $model['key'])>
                                {{ $model['label'] }}{{ $model['is_base'] ? ' (base)' : '' }}
                            </option>
                        @endforeach
                    @endif
                </select>
                <div class="form-text" id="model-note">
                    New scans use this model after you save.
                </div>
            </div>

            <div class="ocr-setting-block mb-3">
                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" role="switch" value="1"
                           id="allow-staff-choice" name="allow_staff_model_choice"
                           @checked(old('allow_staff_model_choice', $settings->allow_staff_model_choice))>
                    <label class="form-check-label fw-medium" for="allow-staff-choice">
                        Allow Staff model choice
                    </label>
                </div>
                <p class="text-muted small mb-0 ps-4">
                    Let Staff override the approved model for an individual document.
                </p>
            </div>

            <div class="ocr-setting-block">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div class="flex-grow-1">
                        <label for="threshold-input" class="form-label fw-medium mb-1">Review threshold</label>
                        <p class="text-muted small mb-0">
                            Flag fields below this confidence for review. Confidence is not accuracy.
                        </p>
                    </div>
                    <div class="input-group ocr-threshold-control">
                        <input type="number" class="form-control" id="threshold-input"
                               name="confidence_review_threshold" min="1" max="100" step="0.5"
                               placeholder="{{ rtrim(rtrim(number_format($configThreshold, 2), '0'), '.') }}"
                               value="{{ old('confidence_review_threshold', $settings->confidence_review_threshold) }}"
                               aria-describedby="threshold-fallback">
                        <span class="input-group-text">%</span>
                    </div>
                </div>
                <div class="form-text mt-2" id="threshold-fallback">
                    Empty uses the configured {{ rtrim(rtrim(number_format($configThreshold, 2), '0'), '.') }}% default.
                </div>
                @error('confidence_review_threshold')
                    <div class="text-danger small mt-1">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="card-footer">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="small">
                    <span class="text-warning d-none" id="settings-dirty">
                        <i class="icon-base bx bx-edit icon-sm me-1"></i>Unsaved changes
                    </span>
                    <span class="text-muted" id="settings-clean">
                        @if ($settings->updated_by)
                            Saved {{ $settings->updated_at->diffForHumans() }}
                            @if ($settings->editor) by {{ $settings->editor->name }}@endif
                        @else
                            Not saved yet
                        @endif
                    </span>
                </div>
                <div class="d-flex align-items-center gap-2 ms-auto">
                    <button type="button" class="btn btn-sm btn-label-secondary"
                            id="settings-discard" disabled>
                        Discard
                    </button>
                    <button type="submit" class="btn btn-sm btn-primary"
                            id="settings-save" disabled>
                        <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                        <i class="icon-base bx bx-check icon-sm me-1"></i>
                        <span>Save policy</span>
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>
