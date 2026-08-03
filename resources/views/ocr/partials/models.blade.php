{{--
    The one decision this page exists for: which model Staff scan with.

    Selecting in the dropdown changes nothing on its own - Save settings is what
    takes effect, and it stays disabled until something actually differs from what
    is saved. Installing, renaming, and deleting a model are housekeeping and act
    immediately, which is why they sit in the header rather than inside the form's
    save cycle.
--}}
@php
    $models = $overview['models'];
    // Only a folder the service can serve right now is selectable. A tombstoned or
    // missing model stays visible in the inventory below, but must not be offered as
    // something Staff could scan with.
    $selectable = $models->filter(fn ($m) => $m['on_disk']);
    $selected = old('model', $activeModel?->key);
@endphp

@if ($selectable->isNotEmpty() && ! $models->contains(fn ($m) => $m['is_active']))
    <div class="alert alert-warning d-flex align-items-start" role="alert">
        <i class="icon-base bx bx-error icon-md me-2"></i>
        <div>
            <strong>No model is in use.</strong>
            Staff cannot read handwriting until one is selected below and saved.
        </div>
    </div>
@endif

<form method="POST" action="{{ route('ocr.settings') }}" id="ocrSettingsForm">
    @csrf

    <div class="card mb-4">
        <div class="card-header d-flex flex-wrap align-items-start justify-content-between gap-3">
            <div>
                <h5 class="card-title mb-0">OCR model</h5>
                <small class="text-muted">
                    The handwriting model Staff scanning runs against.
                </small>
            </div>

            <div class="d-flex align-items-center gap-1">
                <button type="button" class="btn btn-sm btn-text-secondary"
                        data-bs-toggle="modal" data-bs-target="#renameModal"
                        id="model-rename-btn">
                    <i class="icon-base bx bx-rename icon-sm me-1"></i> Rename
                </button>

                <button type="button" class="btn btn-sm btn-text-danger"
                        data-bs-toggle="modal" data-bs-target="#deleteModal"
                        id="model-delete-btn">
                    <i class="icon-base bx bx-trash icon-sm me-1"></i> Delete
                </button>

                <button type="button" class="btn btn-sm btn-text-primary"
                        data-bs-toggle="modal" data-bs-target="#addModelModal">
                    <i class="icon-base bx bx-plus icon-sm me-1"></i> Add
                </button>
            </div>
        </div>

        <div class="card-body">
            <label for="model-select" class="form-label">Model used for scanning</label>

            <select class="form-select" id="model-select" name="model"
                    @disabled($selectable->isEmpty())>
                @if ($selectable->isEmpty())
                    <option value="">
                        {{ $engine['reachable']
                            ? 'No models installed'
                            : 'OCR engine offline' }}
                    </option>
                @else
                    {{--
                        With nothing promoted yet there must be an explicit "not chosen"
                        option. Without it the browser pre-selects the first model, the
                        form is never dirty, and Save settings can never be pressed - so
                        the one model in the list would be unselectable.
                    --}}
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
                        OCR engine offline &mdash; start the Python API
                    </span>
                @elseif ($selectable->isEmpty())
                    <span class="text-muted">
                        Nothing to select. Use <em>Add</em> to upload a model folder or a
                        <code>.zip</code>, or copy one into <code>ml/models/</code> and click
                        <em>Rescan</em>.
                    </span>
                @else
                    <span class="text-muted">
                        Takes effect on the next scan. Records already submitted keep their
                        values and their reference to the model that produced them.
                    </span>
                @endif
            </div>

            <hr class="my-4">

            <div class="form-check form-switch mb-1">
                <input class="form-check-input" type="checkbox" role="switch" value="1"
                       id="allow-staff-choice" name="allow_staff_model_choice"
                       @checked(old('allow_staff_model_choice', $settings->allow_staff_model_choice))>
                <label class="form-check-label" for="allow-staff-choice">
                    Let Staff choose a different model while scanning
                </label>
            </div>
            <p class="text-muted small ms-4 ps-2">
                Off means every reading in the archive came from the model selected above.
                On lets Staff pick any installed model per document, and the record stores
                whichever one they used.
            </p>

            <div class="row g-3 mt-2">
                <div class="col-md-4">
                    <label for="threshold-input" class="form-label">Review threshold</label>
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
                <div class="col-md-8">
                    <label class="form-label d-none d-md-block">&nbsp;</label>
                    <p class="text-muted small mb-0">
                        Fields the model was less certain about than this are flagged for a
                        closer look. Confidence is the model's certainty in its own output,
                        never a measure of accuracy. Leave it empty to use the configured
                        default of
                        {{ rtrim(rtrim(number_format($configThreshold, 2), '0'), '.') }}%.
                    </p>
                </div>
            </div>
        </div>

        <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
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

            <div class="d-flex align-items-center gap-2 ms-auto">
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

<x-card title="Installed models"
        subtitle="Discovered from ml/models/, reconciled with the CRMS registry.">
    @if ($models->isEmpty())
        <x-empty-state icon="bx-brain" title="No models found"
                       message="Use Add above to upload a model folder or a .zip, or copy one into ml/models/ and click Rescan." />
    @else
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Model</th>
                        <th>State</th>
                        <th>Installed</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($models as $model)
                        <tr @class(['table-active' => $model['is_active']])>
                            <td>
                                <div class="fw-medium">{{ $model['label'] }}</div>
                                <code class="small text-muted">{{ $model['key'] }}</code>
                                @if ($model['is_base'])
                                    <span class="badge bg-label-secondary ms-1">base</span>
                                @endif
                            </td>
                            <td>
                                @if ($model['is_active'])
                                    <span class="badge bg-label-success">
                                        <i class="icon-base bx bx-check-circle icon-xs me-1"></i>
                                        Used by Staff
                                    </span>
                                @endif
                                @if ($model['disk_deleted_at'])
                                    <span class="badge bg-label-danger">Deleted</span>
                                @elseif (! $model['on_disk'])
                                    <span class="badge bg-label-warning">
                                        {{ $engine['reachable'] ? 'Missing on disk' : 'Unknown - service offline' }}
                                    </span>
                                @elseif ($model['loaded'])
                                    <span class="badge bg-label-info">Loaded in VRAM</span>
                                @endif
                            </td>
                            <td class="small text-muted">
                                {{ $model['registered_at']?->toFormattedDayDateString() ?? '—' }}
                                @if ($model['registrar'])
                                    <div>by {{ $model['registrar'] }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="small text-muted mb-0 mt-2">
            The base model can never be renamed or deleted, and neither can the model
            currently in use - select another one and save first. Quality figures come
            from the evaluation scripts under <code>ml/</code>, not from this page.
        </p>
    @endif
</x-card>
