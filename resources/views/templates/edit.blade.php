@extends('layouts.app')

@section('title', $template ? 'Edit Template Layout' : 'New Template Layout')
@section('body-class', 'template-builder-focused')

@section('content')
    @php
        $workingFields = old('fields', $fields);
        $published = (bool) $template?->is_active;
        $currentPaperSize = old('paper_size', $template?->paper_size?->value ?? App\Enums\PaperSize::Letter->value);
        $currentOrientation = old('orientation', $template?->orientation?->value ?? App\Enums\PageOrientation::Portrait->value);
        $currentCustomWidth = old('custom_width_mm', $template?->custom_width_mm ?? 210);
        $currentCustomHeight = old('custom_height_mm', $template?->custom_height_mm ?? 297);
        $builderConfig = [
            'initialFields' => $workingFields,
            'baselineFields' => $fields,
            'maxFields' => 100,
            'paperSizes' => collect($paperSizes)->map(fn ($size) => [
                'value' => $size->value,
                'label' => $size->label(),
                'dimensionsLabel' => $size->dimensionsLabel(),
                ...$size->portraitDimensions(),
            ])->values(),
            'baselinePaperSize' => $template?->paper_size?->value ?? App\Enums\PaperSize::Letter->value,
            'baselineOrientation' => $template?->orientation?->value ?? App\Enums\PageOrientation::Portrait->value,
            'baselineCustomWidth' => $template?->custom_width_mm ?? 210,
            'baselineCustomHeight' => $template?->custom_height_mm ?? 297,
            'sample' => $template?->sample_path ? [
                'url' => route('templates.sample', $template),
                'originalName' => $template->sample_original_name,
                'mime' => $template->sample_mime,
                'size' => $template->sample_size,
            ] : null,
        ];
    @endphp

    <div class="template-builder-page">
        <header class="template-builder-page__header">
            <div class="template-builder-page__heading">
                <span class="template-builder-page__eyebrow">{{ $docType->label() }}</span>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <h1 class="h4 mb-0">
                        {{ $template ? $template->name : 'New template layout' }}
                    </h1>
                    @if ($published)
                        <span class="badge bg-label-success">Published for Staff</span>
                    @else
                        <span class="badge bg-label-secondary">Draft</span>
                    @endif
                </div>
                <p class="mb-0 text-muted">
                    Place each marker over the value Staff should extract. Coordinates are saved independently of scan resolution.
                </p>
            </div>

            <a href="{{ route('templates.index') }}" class="btn btn-outline-secondary">
                <i class="icon-base bx bx-chevron-left icon-sm me-1" aria-hidden="true"></i>
                Template library
            </a>
        </header>

        <form method="POST"
              action="{{ $template ? route('templates.update', $template) : route('templates.store') }}"
              id="templateBuilderForm" enctype="multipart/form-data" novalidate>
            @csrf
            @if ($template)
                @method('PUT')
            @endif

            <input type="hidden" name="doc_type" value="{{ $docType->value }}">
            <input type="hidden" name="document_type_id" value="{{ $docType->getKey() }}">
            <input type="hidden" name="publish" value="{{ $published ? 1 : 0 }}" id="publishIntent">
            <div id="fieldInputs"></div>

            <div class="row g-3 template-builder-grid">
                <div class="col-xl-9 col-lg-8">
                    <section class="card document-canvas-card template-builder-canvas-card"
                             aria-label="Template document editor">
                        <div class="marker-toolbar">
                            <div class="marker-toolbar__primary">
                                <button type="button"
                                        class="layout-menu-toggle marker-sidebar-toggle"
                                        data-menu-toggle-control
                                        aria-controls="layout-menu"
                                        aria-expanded="true"
                                        aria-label="Show or hide navigation"
                                        title="Show or hide navigation">
                                    <i class="icon-base bx bx-menu icon-sm" aria-hidden="true"></i>
                                </button>

                                <label for="sampleScan" class="document-file-chip template-sample-control"
                                       id="sampleScanLabel" tabindex="0" role="button"
                                       title="{{ $template?->sample_path ? 'Replace the stored sample document' : 'Choose a sample document' }}">
                                    <i class="icon-base bx bx-file" aria-hidden="true"></i>
                                    <span id="sampleFileName">{{ $template?->sample_original_name ?? 'Choose sample' }}</span>
                                </label>
                                <input type="file" id="sampleScan" name="sample_document" class="visually-hidden"
                                       accept="application/pdf,image/png,image/jpeg,image/webp,image/bmp,image/tiff">

                                @if ($template?->sample_path)
                                    <button type="button" class="btn btn-sm btn-outline-danger template-sample-delete-button"
                                            data-bs-toggle="modal" data-bs-target="#deleteTemplateSampleModal"
                                            title="Delete stored sample document">
                                        <i class="icon-base bx bx-trash icon-sm" aria-hidden="true"></i>
                                        <span>Delete sample</span>
                                    </button>
                                @endif

                                <span class="marker-toolbar__divider" aria-hidden="true"></span>
                                <div class="marker-zoom-controls" role="group" aria-label="Document zoom controls">
                                    <button type="button" class="marker-tool-button" id="zoomOutBtn"
                                            aria-label="Zoom out">&minus;</button>
                                    <button type="button" class="marker-tool-button marker-zoom-value"
                                            id="zoomResetBtn" title="Fit document to the workspace">100%</button>
                                    <button type="button" class="marker-tool-button" id="zoomInBtn"
                                            aria-label="Zoom in">+</button>
                                </div>
                            </div>

                            <div class="marker-toolbar__actions">
                                <div class="dropdown">
                                    <button type="button" class="marker-help-button" data-bs-toggle="dropdown"
                                            aria-expanded="false" aria-label="Show editor shortcuts">
                                        <i class="icon-base bx bx-terminal icon-sm" aria-hidden="true"></i>
                                        <span>Shortcuts</span>
                                        <i class="icon-base bx bx-chevron-down" aria-hidden="true"></i>
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-end marker-shortcuts-menu">
                                        <div class="marker-shortcuts-menu__title">Editor shortcuts</div>
                                        <div><span><kbd>Ctrl</kbd> + scroll</span><small>Zoom document</small></div>
                                        <div><span><kbd>Shift</kbd> + click</span><small>Select multiple</small></div>
                                        <div><span>Drag selection</span><small>Move selected fields</small></div>
                                        <div><span>Drag resize handle</span><small>Resize selected fields</small></div>
                                        <div><span><kbd>Ctrl</kbd> + <kbd>C</kbd></span><small>Copy selected</small></div>
                                        <div><span><kbd>Ctrl</kbd> + <kbd>V</kbd></span><small>Paste fields</small></div>
                                        <div><span><kbd>Del</kbd> or <kbd>Backspace</kbd></span><small>Delete selected</small></div>
                                        <div><span><kbd>Ctrl</kbd> + <kbd>Z</kbd></span><small>Undo last change</small></div>
                                    </div>
                                </div>

                                <button type="button" class="marker-reset-button" id="resetFieldsBtn"
                                        title="Restore the saved layout and document view" disabled>
                                    <i class="icon-base bx bx-refresh icon-sm" aria-hidden="true"></i>
                                    <span>Reset</span>
                                </button>

                                <span class="marker-selection-summary" id="selectionSummary">
                                    <i class="icon-base bx bx-check-circle" aria-hidden="true"></i>
                                    <span>0 selected</span>
                                </span>
                                <button type="button" class="marker-delete-button"
                                        id="deleteSelectedBtn" disabled>
                                    <i class="icon-base bx bx-trash icon-sm" aria-hidden="true"></i>
                                    <span>Delete</span>
                                </button>
                            </div>
                        </div>

                        @error('sample_document')
                            <div class="template-builder-sample-error" role="alert">
                                <i class="icon-base bx bx-error" aria-hidden="true"></i>
                                <span>{{ $message }}</span>
                            </div>
                        @enderror

                        <div class="doc-viewport" id="docViewport">
                            <div class="template-builder-canvas-note" id="sampleHint" role="status">
                                <i class="icon-base bx bx-cloud-upload" aria-hidden="true"></i>
                                <span><strong>Blank preview</strong> &mdash; choose a sample PDF or image to align markers precisely.</span>
                            </div>
                            <div class="doc-stage" id="docStage">
                                <canvas id="pageCanvas" width="900" height="1200"></canvas>
                                <div class="field-overlay" id="fieldOverlay"></div>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="col-xl-3 col-lg-4">
                    <aside class="template-builder-side-panel">
                        <section class="card template-builder-settings-card mb-3">
                            <div class="card-header">
                                <h2 class="card-title h5 mb-0">Layout details</h2>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="name" class="form-label">Layout name</label>
                                    <input type="text" id="name" name="name" maxlength="120"
                                           value="{{ old('name', $template?->name ?? $docType->label()) }}"
                                           class="form-control @error('name') is-invalid @enderror" required>
                                    @error('name')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Document type</label>
                                    <div class="template-builder-locked-value">
                                        <i class="icon-base bx {{ $docType->icon() }}" aria-hidden="true"></i>
                                        <span>{{ $docType->label() }}</span>
                                        <i class="icon-base bx bx-lock-alt ms-auto" aria-hidden="true"></i>
                                    </div>
                                    <div class="form-text">The document type is fixed for this layout.</div>
                                </div>

                                <div class="mb-3">
                                    <label for="paper_size" class="form-label">Paper size</label>
                                    <select id="paper_size" name="paper_size"
                                            class="form-select @error('paper_size') is-invalid @enderror" required>
                                        @foreach ($paperSizes as $paperSize)
                                            <option value="{{ $paperSize->value }}"
                                                    @selected($currentPaperSize === $paperSize->value)>
                                                {{ $paperSize->label() }} &mdash; {{ $paperSize->dimensionsLabel() }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('paper_size')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="template-custom-size mb-3 {{ $currentPaperSize === App\Enums\PaperSize::Custom->value ? '' : 'd-none' }}"
                                     id="customPaperSizeFields">
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label for="custom_width_mm" class="form-label">Width</label>
                                            <div class="input-group">
                                                <input type="number" id="custom_width_mm" name="custom_width_mm"
                                                       value="{{ $currentCustomWidth }}" min="50" max="2000" step="0.1"
                                                       class="form-control @error('custom_width_mm') is-invalid @enderror"
                                                       aria-describedby="customPaperUnit">
                                                <span class="input-group-text" id="customPaperUnit">mm</span>
                                                @error('custom_width_mm')
                                                    <div class="invalid-feedback">{{ $message }}</div>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <label for="custom_height_mm" class="form-label">Height</label>
                                            <div class="input-group">
                                                <input type="number" id="custom_height_mm" name="custom_height_mm"
                                                       value="{{ $currentCustomHeight }}" min="50" max="2000" step="0.1"
                                                       class="form-control @error('custom_height_mm') is-invalid @enderror">
                                                <span class="input-group-text">mm</span>
                                                @error('custom_height_mm')
                                                    <div class="invalid-feedback">{{ $message }}</div>
                                                @enderror
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-text">Enter the portrait dimensions. Landscape swaps the displayed width and height.</div>
                                </div>

                                <fieldset class="mb-3">
                                    <legend class="form-label">Orientation</legend>
                                    <div class="template-orientation-options">
                                        @foreach ($orientations as $orientation)
                                            <input type="radio" class="btn-check" name="orientation"
                                                   id="orientation_{{ $orientation->value }}"
                                                   value="{{ $orientation->value }}"
                                                   @checked($currentOrientation === $orientation->value) required>
                                            <label class="template-orientation-option"
                                                   for="orientation_{{ $orientation->value }}">
                                                <span class="template-orientation-sheet is-{{ $orientation->value }}"
                                                      aria-hidden="true"></span>
                                                <span>{{ $orientation->label() }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @error('orientation')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </fieldset>

                                <div class="template-paper-preview" id="paperPreviewStatus" role="status" aria-live="polite">
                                    <i class="icon-base bx bx-file" aria-hidden="true"></i>
                                    <div>
                                        <strong id="paperPreviewTitle"></strong>
                                        <span id="paperPreviewMessage"></span>
                                    </div>
                                </div>

                                <div class="template-sample-size d-none" id="samplePageSize" aria-live="polite">
                                    <div class="template-sample-size__heading">
                                        <div>
                                            <span class="template-sample-size__label">Uploaded sample</span>
                                            <strong id="samplePhysicalSize">Page size unavailable</strong>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                id="useSampleSizeBtn" disabled>
                                            Use as custom
                                        </button>
                                    </div>
                                    <span id="samplePixelSize" class="template-sample-size__meta"></span>
                                    <span id="sampleSizeNote" class="template-sample-size__meta"></span>
                                </div>

                                <div>
                                    <label for="description" class="form-label">Notes</label>
                                    <textarea id="description" name="description" rows="2" maxlength="1000"
                                              class="form-control @error('description') is-invalid @enderror"
                                              placeholder="Form revision or internal note">{{ old('description', $template?->description) }}</textarea>
                                    @error('description')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </section>

                        <section class="card document-fields-card template-builder-fields-card mb-3">
                            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                                <div>
                                    <h2 class="card-title h5 mb-0">Fields</h2>
                                    <small class="text-muted">This order is used during validation.</small>
                                </div>
                                <span class="badge bg-label-primary" id="fieldCount">0 fields</span>
                            </div>
                            <div class="card-body">
                                <div class="marker-field-bulk-actions">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" id="selectAllFields">
                                        <label class="form-check-label" for="selectAllFields">Select all</label>
                                    </div>
                                    <button type="button" class="marker-field-delete" id="deleteFieldsBtn" disabled>
                                        <i class="icon-base bx bx-trash" aria-hidden="true"></i>
                                        <span>Delete</span>
                                    </button>
                                </div>

                                <ul class="list-unstyled marker-field-list template-builder-field-list mb-3"
                                    id="fieldList"></ul>

                                <label class="form-label small fw-medium" for="newFieldName">Add another field</label>
                                <div class="input-group">
                                    <input type="text" id="newFieldName" class="form-control"
                                           maxlength="120" placeholder="Field name">
                                    <button class="btn btn-outline-primary" type="button" id="addFieldBtn">
                                        <i class="icon-base bx bx-plus me-1" aria-hidden="true"></i>Add
                                    </button>
                                </div>

                                <p class="document-tip mt-3 mb-0">
                                    <i class="icon-base bx bx-info-circle" aria-hidden="true"></i>
                                    <span>Keep each marker tight around one handwritten value. The sample is stored privately with this layout after you save.</span>
                                </p>
                            </div>
                        </section>

                        <div class="template-builder-error d-none" id="builderError" role="alert" aria-live="assertive">
                            <i class="icon-base bx bx-error" aria-hidden="true"></i>
                            <span id="builderErrorMessage"></span>
                        </div>

                        <div class="template-builder-save-panel">
                            @if (! $published)
                                <button type="submit" class="btn btn-outline-secondary" data-publish="0">
                                    Save draft
                                </button>
                                <button type="submit" class="btn btn-primary" data-publish="1">
                                    <i class="icon-base bx bx-check icon-sm me-1" aria-hidden="true"></i>
                                    Save &amp; publish for Staff
                                </button>
                            @else
                                <p class="template-builder-live-note">
                                    <i class="icon-base bx bx-info-circle" aria-hidden="true"></i>
                                    Saved changes become the Staff default immediately.
                                </p>
                                <button type="submit" class="btn btn-primary" data-publish="1">
                                    <i class="icon-base bx bx-check icon-sm me-1" aria-hidden="true"></i>
                                    Save published layout
                                </button>
                            @endif
                        </div>
                    </aside>
                </div>
            </div>
        </form>
    </div>

    @if ($template?->sample_path)
        <div class="modal fade" id="deleteTemplateSampleModal" tabindex="-1"
             aria-labelledby="deleteTemplateSampleModalTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="deleteTemplateSampleModalTitle">Delete stored sample?</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">Remove <strong>{{ $template->sample_original_name }}</strong> from this layout?</p>
                        <p class="mb-0 text-muted small">The field markers and layout settings will not be changed.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <form method="POST" action="{{ route('templates.sample.destroy', $template) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger">
                                <i class="icon-base bx bx-trash icon-sm me-1" aria-hidden="true"></i>
                                Delete sample
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="modal fade" id="resetFieldsModal" tabindex="-1"
         aria-labelledby="resetFieldsModalTitle" aria-describedby="resetFieldsModalDescription"
         aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered reset-confirm-dialog">
            <div class="modal-content reset-confirm-modal">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="resetFieldsModalTitle">Reset field layout?</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0 text-muted" id="resetFieldsModalDescription">
                        Restore the markers, paper size, and orientation that were loaded when this editor opened. Added and copied fields will be removed.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmResetFieldsBtn">
                        Reset fields
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script type="application/json" id="templateBuilderConfig">{!! Illuminate\Support\Js::encode($builderConfig) !!}</script>
@endsection

@push('scripts')
    @vite('resources/js/template-builder.js')
@endpush
