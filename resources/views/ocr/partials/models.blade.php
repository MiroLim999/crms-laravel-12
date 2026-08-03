{{--
    OCR model management + scanning settings in two cards.

    Card 1 – Installed models
        A table of every discovered model with inline Rename / Delete icon buttons on
        each row. No separate dropdown picker needed: the row button carries the key
        as a data attribute. The + Add button stays in the header.

    Card 2 – Scanning settings
        The "Model used for scanning" select, the staff-choice toggle, and the
        review threshold. Save settings is the only thing that commits a change here;
        the button stays disabled until something actually differs.
--}}
@php
    $models  = $overview['models'];
    $selectable = $models->filter(fn ($m) => $m['on_disk']);
    $selected   = old('model', $activeModel?->key);
@endphp

{{-- No-model-in-use warning --}}
@if ($selectable->isNotEmpty() && ! $models->contains(fn ($m) => $m['is_active']))
    <div class="alert alert-warning d-flex align-items-start mb-4" role="alert">
        <i class="icon-base bx bx-error icon-md me-2 mt-1 flex-shrink-0"></i>
        <div>
            <strong>No model is in use.</strong>
            Staff cannot read handwriting until one is selected in <em>Scanning settings</em>
            below and saved.
        </div>
    </div>
@endif

{{-- ---------------------------------------------------------------- installed models --}}
<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <div>
            <h5 class="card-title mb-0">Installed models</h5>
            <small class="text-muted">
                Discovered from <code>ml/models/</code>, reconciled with the CRMS registry.
            </small>
        </div>
        <button type="button"
                class="btn btn-sm btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#addModelModal">
            <i class="icon-base bx bx-plus icon-sm me-1"></i> Add model
        </button>
    </div>

    @if ($models->isEmpty())
        <div class="card-body">
            <div class="text-center py-4 text-muted">
                <i class="icon-base bx bx-brain icon-lg d-block mb-2"></i>
                <p class="mb-1 fw-medium">No models found</p>
                <p class="small mb-0">
                    Upload one with <em>Add model</em>, or copy a folder into
                    <code>ml/models/</code> and click <em>Rescan</em>.
                </p>
            </div>
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Model</th>
                        <th>Status</th>
                        <th>Installed</th>
                        <th class="text-end pe-4" style="width:110px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($models as $model)
                        @php
                            $canEdit = $model['on_disk']
                                    && ! $model['is_base']
                                    && ! $model['is_active']
                                    && ! $model['disk_deleted_at']
                                    && $engine['reachable'];

                            $blockReason = ! $engine['reachable']
                                ? 'OCR service must be running.'
                                : ($model['is_base']
                                    ? 'The base model cannot be renamed or deleted.'
                                    : ($model['is_active']
                                        ? 'In use by Staff — select another model and save settings first.'
                                        : (! $model['on_disk']
                                            ? 'Model folder is missing from disk.'
                                            : '')));
                        @endphp
                        <tr>
                            <td class="ps-4">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="avatar avatar-sm flex-shrink-0">
                                        <span class="avatar-initial rounded bg-label-{{ $model['is_active'] ? 'success' : ($model['is_base'] ? 'secondary' : 'primary') }}">
                                            <i class="icon-base bx bx-brain icon-sm"></i>
                                        </span>
                                    </div>
                                    <div>
                                        <div class="fw-medium lh-sm">{{ $model['label'] }}</div>
                                        <code class="small text-muted">{{ $model['key'] }}</code>
                                        @if ($model['is_base'])
                                            <span class="badge bg-label-secondary ms-1 align-middle small">base</span>
                                        @endif
                                    </div>
                                </div>
                            </td>

                            <td>
                                @if ($model['is_active'])
                                    <span class="badge bg-label-success">
                                        <i class="icon-base bx bx-check-circle icon-xs me-1"></i>
                                        Active
                                    </span>
                                @endif
                                @if ($model['disk_deleted_at'])
                                    <span class="badge bg-label-danger">Deleted</span>
                                @elseif (! $model['on_disk'])
                                    <span class="badge bg-label-warning">
                                        {{ $engine['reachable'] ? 'Missing' : 'Unknown' }}
                                    </span>
                                @elseif ($model['loaded'])
                                    <span class="badge bg-label-info">In VRAM</span>
                                @else
                                    <span class="badge bg-label-secondary">Ready</span>
                                @endif
                            </td>

                            <td class="small text-muted">
                                @if ($model['registered_at'])
                                    {{ $model['registered_at']->toFormattedDayDateString() }}
                                    @if ($model['registrar'])
                                        <div class="text-muted">{{ $model['registrar'] }}</div>
                                    @endif
                                @else
                                    —
                                @endif
                            </td>

                            <td class="text-end pe-4">
                                @if (! $model['disk_deleted_at'])
                                    <button type="button"
                                            class="btn btn-icon btn-sm btn-text-secondary model-rename-trigger"
                                            data-key="{{ $model['key'] }}"
                                            data-bs-toggle="modal"
                                            data-bs-target="#renameModal"
                                            @disabled(! $canEdit)
                                            title="{{ $canEdit ? 'Rename' : $blockReason }}">
                                        <i class="icon-base bx bx-edit icon-sm"></i>
                                    </button>

                                    <button type="button"
                                            class="btn btn-icon btn-sm btn-text-danger model-delete-trigger"
                                            data-key="{{ $model['key'] }}"
                                            data-label="{{ $model['label'] }}"
                                            data-bs-toggle="modal"
                                            data-bs-target="#deleteModal"
                                            @disabled(! $canEdit)
                                            title="{{ $canEdit ? 'Delete' : $blockReason }}">
                                        <i class="icon-base bx bx-trash icon-sm"></i>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="card-footer text-muted small">
            The base model and the model currently in use cannot be renamed or deleted.
            To free one up, select a different model in <em>Scanning settings</em> and save.
        </div>
    @endif
</div>

{{-- ---------------------------------------------------------- scanning settings --}}
<form method="POST" action="{{ route('ocr.settings') }}" id="ocrSettingsForm">
    @csrf

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">Scanning settings</h5>
            <small class="text-muted">Controls what model Staff scan with and how readings are reviewed.</small>
        </div>

        <div class="card-body">
            {{-- Model used for scanning --}}
            <div class="mb-4">
                <label for="model-select" class="form-label fw-medium">Model used for scanning</label>
                <select class="form-select" id="model-select" name="model"
                        @disabled($selectable->isEmpty())>
                    @if ($selectable->isEmpty())
                        <option value="">
                            {{ $engine['reachable'] ? 'No models installed' : 'OCR engine offline' }}
                        </option>
                    @else
                        @if ($activeModel === null)
                            <option value="" @selected($selected === null || $selected === '')>
                                — none selected —
                            </option>
                        @endif
                        @foreach ($selectable as $model)
                            <option value="{{ $model['key'] }}"
                                    data-base="{{ $model['is_base'] ? '1' : '' }}"
                                    data-active="{{ $model['is_active'] ? '1' : '' }}"
                                    data-loaded="{{ $model['loaded'] ? '1' : '' }}"
                                    data-registrar="{{ $model['registrar'] }}"
                                    @selected($selected === $model['key'])>
                                {{ $model['label'] }}@if ($model['is_base']) (not fine-tuned)@endif
                            </option>
                        @endforeach
                    @endif
                </select>
                <div class="mt-2 small" id="model-note">
                    @if (! $engine['reachable'])
                        <span class="text-danger">
                            <i class="icon-base bx bx-circle icon-xs me-1"></i>
                            OCR engine offline — start the Python API
                        </span>
                    @elseif ($selectable->isEmpty())
                        <span class="text-muted">
                            Nothing to select. Add a model above, or copy one into
                            <code>ml/models/</code> and click <em>Rescan</em>.
                        </span>
                    @else
                        <span class="text-muted">
                            Takes effect on the next scan. Records already submitted keep their
                            values and their reference to the model that produced them.
                        </span>
                    @endif
                </div>
            </div>

            <hr class="my-3">

            {{-- Staff model choice --}}
            <div class="mb-4">
                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" role="switch" value="1"
                           id="allow-staff-choice" name="allow_staff_model_choice"
                           @checked(old('allow_staff_model_choice', $settings->allow_staff_model_choice))>
                    <label class="form-check-label fw-medium" for="allow-staff-choice">
                        Let Staff choose a different model while scanning
                    </label>
                </div>
                <p class="text-muted small ms-4 ps-2 mb-0">
                    Off means every reading in the archive came from the model selected above.
                    On lets Staff pick any installed model per document, and the record stores
                    whichever one they used.
                </p>
            </div>

            {{-- Review threshold --}}
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="threshold-input" class="form-label fw-medium">Review threshold</label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="threshold-input"
                               name="confidence_review_threshold" min="1" max="100" step="0.5"
                               placeholder="{{ rtrim(rtrim(number_format($configThreshold, 2), '0'), '.') }}"
                               value="{{ old('confidence_review_threshold', $settings->confidence_review_threshold) }}">
                        <span class="input-group-text">%</span>
                    </div>
                    @error('confidence_review_threshold')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-8 d-flex align-items-end">
                    <p class="text-muted small mb-0">
                        Fields the model was less certain about than this are flagged for a closer
                        look. Confidence is the model's certainty in its own output, never a
                        measure of accuracy. Leave empty to use the configured default of
                        {{ rtrim(rtrim(number_format($configThreshold, 2), '0'), '.') }}%.
                    </p>
                </div>
            </div>
        </div>

        <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <span class="small text-warning d-none" id="settings-dirty">
                    <i class="icon-base bx bx-edit icon-sm me-1"></i> Unsaved changes
                </span>
                <span class="small text-muted" id="settings-clean">
                    @if ($settings->updated_by)
                        Last saved {{ $settings->updated_at->diffForHumans() }}
                        @if ($settings->editor) by {{ $settings->editor->name }} @endif
                    @else
                        No settings saved yet.
                    @endif
                </span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-outline-secondary" id="settings-discard" disabled>
                    Discard
                </button>
                <button type="submit" class="btn btn-primary" id="settings-save" disabled>
                    <i class="icon-base bx bx-save icon-sm me-1"></i> Save settings
                </button>
            </div>
        </div>
    </div>
</form>
