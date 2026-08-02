{{--
    Models tab.

    Promoting a model is the only action here that changes what Staff see. Everything
    else - upload, rename, delete - is housekeeping. That is why "Use for scanning"
    is a primary button on the row rather than buried in the dropdown.
--}}
@if (! $overview['models']->contains(fn ($m) => $m['is_active']))
    <div class="alert alert-warning d-flex align-items-start" role="alert">
        <i class="icon-base bx bx-error icon-md me-2"></i>
        <div>
            <strong>No active model.</strong>
            Staff cannot scan documents until one is promoted below. Fine-tune and
            evaluate first, then use it for scanning.
        </div>
    </div>
@endif

<x-card title="Models"
        subtitle="Discovered from ml/models/, reconciled with the CRMS registry.">
    <x-slot:actions>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModelModal">
            <i class="icon-base bx bx-plus icon-sm me-1"></i> Add model
        </button>
    </x-slot:actions>

    @if ($overview['models']->isEmpty())
        <x-empty-state icon="bx-brain" title="No models found"
                       message="Fine-tune one on the Fine-tuning tab, upload a folder, or drop one into ml/models/ and rescan." />
    @else
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Model</th>
                        <th>State</th>
                        <th>Measured quality</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($overview['models'] as $model)
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
                                    <span class="badge bg-label-warning">Missing on disk</span>
                                @elseif ($model['loaded'])
                                    <span class="badge bg-label-info">Loaded in VRAM</span>
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
                                    <span class="text-muted small">Not evaluated</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex align-items-center gap-2">
                                    @unless ($model['is_active'])
                                        {{-- The "save it for Staff" step. --}}
                                        <button type="button" class="btn btn-sm btn-primary"
                                                data-bs-toggle="modal" data-bs-target="#activateModal"
                                                data-model-key="{{ $model['key'] }}"
                                                data-model-label="{{ $model['label'] }}"
                                                data-model-cer="{{ $model['cer'] }}"
                                                data-model-evaluated="{{ $model['evaluated_at']?->toDayDateTimeString() }}"
                                                @disabled(! $model['on_disk'])>
                                            <i class="icon-base bx bx-check-circle icon-sm me-1"></i>
                                            Use for scanning
                                        </button>
                                    @endunless

                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-icon btn-text-secondary rounded-pill dropdown-toggle hide-arrow"
                                                data-bs-toggle="dropdown" aria-label="More actions">
                                            <i class="icon-base bx bx-dots-vertical-rounded"></i>
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-end">
                                            <button type="button" class="dropdown-item"
                                                    data-bs-toggle="modal" data-bs-target="#evalModal"
                                                    data-model-key="{{ $model['key'] }}"
                                                    data-cer="{{ $model['cer'] }}"
                                                    data-wer="{{ $model['wer'] }}"
                                                    data-exact="{{ $model['exact_match'] }}"
                                                    data-notes="{{ $model['notes'] }}"
                                                    @disabled(! $model['on_disk'])>
                                                <i class="icon-base bx bx-bar-chart-alt-2 icon-sm me-2"></i>
                                                Enter metrics by hand
                                            </button>

                                            @unless ($model['is_base'])
                                                <button type="button" class="dropdown-item"
                                                        data-bs-toggle="modal" data-bs-target="#renameModal"
                                                        data-model-key="{{ $model['key'] }}"
                                                        @disabled($model['is_active'] || ! $model['on_disk'])>
                                                    <i class="icon-base bx bx-rename icon-sm me-2"></i> Rename
                                                </button>

                                                <div class="dropdown-divider"></div>

                                                <button type="button" class="dropdown-item text-danger"
                                                        data-bs-toggle="modal" data-bs-target="#deleteModal"
                                                        data-model-key="{{ $model['key'] }}"
                                                        @disabled($model['is_active'] || ! $model['on_disk'])>
                                                    <i class="icon-base bx bx-trash icon-sm me-2"></i> Delete
                                                </button>
                                            @endunless
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="small text-muted mb-0 mt-2">
            The base model can never be renamed or deleted. The active model is locked
            against both - promote another model first. CER, WER, and exact match come
            from an evaluation run against labelled ground truth; they are the only
            quality figures here. Confidence is not accuracy.
        </p>
    @endif
</x-card>
