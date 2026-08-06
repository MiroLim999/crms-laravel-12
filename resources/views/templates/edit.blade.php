@extends('layouts.app')

@section('title', $template ? 'Edit Template Layout' : 'New Template Layout')
@section('body-class', 'template-builder-focused')

@section('content')
    @php
        $workingFields = old('fields', $fields);
        $published = (bool) $template?->is_active;
        $builderConfig = [
            'initialFields' => $workingFields,
            'baselineFields' => $fields,
            'maxFields' => 100,
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
              id="templateBuilderForm" novalidate>
            @csrf
            @if ($template)
                @method('PUT')
            @endif

            <input type="hidden" name="doc_type" value="{{ $docType->value }}">
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
                                       title="Choose a sample document">
                                    <i class="icon-base bx bx-file" aria-hidden="true"></i>
                                    <span id="sampleFileName">Choose sample</span>
                                </label>
                                <input type="file" id="sampleScan" class="visually-hidden"
                                       accept="application/pdf,image/png,image/jpeg,image/webp,image/bmp,image/tiff">

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
                                    <label class="form-label">Certificate type</label>
                                    <div class="template-builder-locked-value">
                                        <i class="icon-base bx {{ $docType->icon() }}" aria-hidden="true"></i>
                                        <span>{{ $docType->label() }}</span>
                                        <i class="icon-base bx bx-lock-alt ms-auto" aria-hidden="true"></i>
                                    </div>
                                    <div class="form-text">The certificate type is fixed for this layout.</div>
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
                                    <span>Keep each marker tight around one handwritten value. The sample stays in your browser and is not stored.</span>
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
                        Restore the markers to the layout that was loaded when this editor opened. Added and copied fields will be removed.
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
