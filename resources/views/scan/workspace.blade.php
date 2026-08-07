@extends('layouts.app')

@section('title', 'Scan ' . $docType->shortLabel())

@section('content')
    <div id="documentPageHeader">
        <x-page-header :title="'Scan: ' . $docType->label()"
                       :subtitle="'Template: ' . $template->name . ' · ' . $template->paper_size->label() . ' (' . $template->paperDimensionsLabel() . ') · ' . $template->orientation->label()">
            <a href="{{ route('documents.create') }}" class="btn btn-outline-secondary">Cancel</a>
        </x-page-header>
    </div>

    <nav class="document-flow-steps" id="documentFlowSteps" aria-label="Document processing progress">
        <div class="document-flow-step is-active" data-flow-step="upload" aria-current="step">
            <span class="document-flow-step__number">1</span>
            <span class="document-flow-step__copy"><strong>Upload</strong><small>Choose a scan</small></span>
        </div>
        <div class="document-flow-step" data-flow-step="mark">
            <span class="document-flow-step__number">2</span>
            <span class="document-flow-step__copy"><strong>Align fields</strong><small>Check the markers</small></span>
        </div>
        <div class="document-flow-step" data-flow-step="verify">
            <span class="document-flow-step__number">3</span>
            <span class="document-flow-step__copy"><strong>Validate</strong><small>Review OCR results</small></span>
        </div>
    </nav>

    {{-- Step 1: upload --}}
    <section id="step-upload" class="document-step-panel">
        <div class="document-upload-wrap">
            <x-card class="document-upload-card" bodyClass="p-0">
                <label for="scanFile" class="document-dropzone" id="documentDropzone" tabindex="0">
                    <span class="document-dropzone__icon" aria-hidden="true">
                        <i class="icon-base bx bx-cloud-upload"></i>
                    </span>
                    <span class="document-dropzone__title">Drop a scanned document here</span>
                    <span class="document-dropzone__text">or choose a file from your computer</span>
                    <span class="btn btn-primary document-dropzone__button">Choose document</span>
                    <span class="document-dropzone__formats">
                        <span>PDF</span><span>PNG</span><span>JPG</span><span>WEBP</span><span>TIFF</span>
                    </span>
                </label>
                <input type="file" id="scanFile" class="visually-hidden"
                       accept="application/pdf,image/png,image/jpeg,image/webp,image/bmp,image/tiff">

                <div class="document-upload-note">
                    <span><i class="icon-base bx bx-file me-1"></i> Maximum file size: 20 MB</span>
                    <span><i class="icon-base bx bx-lock-alt me-1"></i> Stored only after validation</span>
                </div>
            </x-card>

            <p class="document-upload-help">
                For the best handwriting results, use a straight, well-lit scan with the full certificate visible.
            </p>
        </div>
    </section>

    {{-- Step 2: mark the fields --}}
    <section id="step-mark" class="document-step-panel d-none">
        <div class="row g-4">
            <div class="col-lg-8">
                <x-card class="document-canvas-card" bodyClass="p-0">
                    <div class="marker-toolbar">
                        <div class="marker-toolbar__primary">
                            <span class="document-file-chip" title="Current document">
                                <i class="icon-base bx bx-file"></i>
                                <span id="selectedFileName">Document</span>
                            </span>
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
                                    <i class="icon-base bx bx-terminal icon-sm"></i>
                                    <span>Shortcuts</span>
                                    <i class="icon-base bx bx-chevron-down"></i>
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
                                    title="Restore the original template fields and document view" disabled>
                                <i class="icon-base bx bx-refresh icon-sm" aria-hidden="true"></i>
                                <span>Reset</span>
                            </button>

                            <span class="marker-selection-summary" id="selectionSummary">
                                <i class="icon-base bx bx-check-circle"></i>
                                <span>0 selected</span>
                            </span>
                            <button type="button" class="marker-delete-button"
                                    id="deleteSelectedBtn" disabled>
                                <i class="icon-base bx bx-trash icon-sm"></i>
                                <span>Delete</span>
                            </button>
                        </div>
                    </div>

                    <div class="doc-viewport" id="docViewport">
                        <div class="doc-stage" id="docStage">
                            <canvas id="pageCanvas"></canvas>
                            <div class="field-overlay" id="fieldOverlay"></div>
                        </div>
                    </div>
                </x-card>
            </div>

            <div class="col-lg-4">
                <div class="document-side-panel">
                <x-card title="Fields" class="mb-4 document-fields-card">
                    <x-slot:actions>
                        <span class="badge bg-label-primary" id="fieldCount">0 fields</span>
                    </x-slot:actions>

                    <div class="document-paper-spec">
                        <i class="icon-base bx bx-file" aria-hidden="true"></i>
                        <div>
                            <strong>{{ $template->paper_size->label() }} &middot; {{ $template->orientation->label() }}</strong>
                            <span>{{ $template->paperDimensionsLabel() }} expected</span>
                        </div>
                    </div>

                    <div class="document-paper-warning d-none" id="paperMismatchWarning"
                         role="alert" aria-live="polite">
                        <i class="icon-base bx bx-error" aria-hidden="true"></i>
                        <span id="paperMismatchMessage"></span>
                    </div>

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

                    <ul class="list-unstyled marker-field-list mb-3" id="fieldList"></ul>

                    <label class="form-label small fw-medium" for="newFieldName">Add another field</label>
                    <div class="input-group">
                        <input type="text" id="newFieldName" class="form-control" placeholder="Field name">
                        <button class="btn btn-outline-primary" type="button" id="addFieldBtn">
                            <i class="icon-base bx bx-plus me-1"></i>Add
                        </button>
                    </div>

                    <p class="document-tip mt-3 mb-0">
                        <i class="icon-base bx bx-info-circle"></i>
                        <span>Position each box tightly around the handwriting. Loose boxes pick up
                        neighbouring text and read badly.</span>
                    </p>
                </x-card>

                {{--
                    Only rendered when a Super Admin has allowed Staff to choose.
                    Otherwise every reading in the archive comes from the one promoted
                    model, and there is nothing here to decide.
                --}}
                @if ($selectableModels !== [])
                    <x-card title="OCR model" class="mb-4">
                        <label for="modelSelect" class="form-label visually-hidden">
                            Model to read with
                        </label>
                        <select class="form-select" id="modelSelect">
                            @foreach ($selectableModels as $model)
                                <option value="{{ $model['key'] }}" @selected($model['is_active'])>
                                    {{ $model['label'] }}@if ($model['is_active']) (default)@endif
                                </option>
                            @endforeach
                        </select>
                        <p class="small text-muted mb-0 mt-2">
                            The record stores whichever model produced its readings. Switching
                            here affects this document only.
                        </p>
                    </x-card>
                @endif

                <div class="ocr-action-status d-none" id="ocrActionStatus" role="alert" aria-live="assertive">
                    <i class="icon-base bx bx-error" aria-hidden="true"></i>
                    <span id="ocrActionMessage"></span>
                </div>

                <div class="d-grid gap-2">
                    <button class="btn btn-primary btn-lg" type="button" id="scanNowBtn">
                        <i class="icon-base bx bx-scan icon-sm me-1"></i> Scan with OCR
                    </button>
                    <button class="btn btn-outline-secondary" type="button" id="backToUpload">
                        <i class="icon-base bx bx-chevron-left icon-sm me-1"></i> Choose another file
                    </button>
                </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Step 3: verify and submit --}}
    <section id="step-verify" class="document-step-panel d-none">
        <form method="POST" action="{{ route('documents.store') }}" id="submitForm"
              enctype="multipart/form-data" novalidate>
            @csrf
            <input type="hidden" name="doc_type" value="{{ $docType->value }}">
            <input type="hidden" name="document_template_id" value="{{ $template->getKey() }}">
            <input type="hidden" name="ocr_model_key" id="ocrModelKey">

            <div class="validation-workspace">
                <header class="validation-workspace__header">
                    <div class="validation-workspace__heading">
                        <div>
                            <span class="validation-eyebrow">Final review</span>
                            <h2 class="h4 mb-1">Compare and verify</h2>
                            <p class="text-muted mb-0">
                                Select a marker or extracted field to compare them. Only checked fields are submitted.
                            </p>
                        </div>
                    </div>

                    <div class="validation-progress-summary" aria-live="polite">
                        <div>
                            <strong id="verifiedCount">0 of 0</strong>
                            <span>fields verified</span>
                        </div>
                        <div class="progress" role="progressbar" aria-label="Field verification progress"
                             aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
                             id="verifiedProgress">
                            <div class="progress-bar" id="verifiedProgressBar" style="width: 0%"></div>
                        </div>
                    </div>
                </header>

                <div class="validation-submit-error d-none" id="validationSubmitError"
                     role="alert" aria-live="assertive">
                    <i class="icon-base bx bx-error-circle" aria-hidden="true"></i>
                    <div>
                        <strong>Unable to submit this record</strong>
                        <div id="validationSubmitMessage"></div>
                    </div>
                </div>

                <div class="validation-comparison-grid">
                    <section class="card validation-pane validation-document-pane"
                             aria-labelledby="originalDocumentTitle">
                        <header class="validation-pane__header">
                            <div class="min-w-0">
                                <span class="validation-pane__eyebrow">Source</span>
                                <h3 class="h5 mb-1" id="originalDocumentTitle">Original document</h3>
                                <span class="validation-file-name" id="validationFileName">Document</span>
                            </div>

                            <div class="marker-zoom-controls" role="group"
                                 aria-label="Original document zoom controls">
                                <button type="button" class="marker-tool-button" id="validationZoomOutBtn"
                                        aria-label="Zoom out">&minus;</button>
                                <button type="button" class="marker-tool-button marker-zoom-value"
                                        id="validationZoomResetBtn" title="Fit document to panel">100%</button>
                                <button type="button" class="marker-tool-button" id="validationZoomInBtn"
                                        aria-label="Zoom in">+</button>
                            </div>
                        </header>

                        <div class="doc-viewport validation-doc-viewport" id="validationDocViewport">
                            <div class="doc-stage" id="validationDocStage">
                                <canvas id="validationPageCanvas"></canvas>
                                <div class="field-overlay validation-field-overlay"
                                     id="validationFieldOverlay"></div>
                            </div>
                        </div>

                        <footer class="validation-pane__hint">
                            <i class="icon-base bx bx-mouse" aria-hidden="true"></i>
                            Click a marker to locate its TrOCR output. Hold <kbd>Ctrl</kbd> and scroll to zoom.
                        </footer>
                    </section>

                    <section class="card validation-pane validation-output-pane"
                             aria-labelledby="digitalDocumentTitle">
                        <header class="validation-pane__header validation-output-header">
                            <div>
                                <span class="validation-pane__eyebrow">Transcription</span>
                                <h3 class="h5 mb-1" id="digitalDocumentTitle">Digital text output</h3>
                                <p class="small text-muted mb-0">Correct the text, then check Verified.</p>
                            </div>

                            <div class="validation-model-summary" aria-label="OCR summary">
                                <span><small>Model</small><code id="summaryModel">&mdash;</code></span>
                                <span><small>Confidence</small><strong id="summaryConfidence">&mdash;</strong></span>
                                <span><small>Review</small><strong id="summaryReview">&mdash;</strong></span>
                            </div>
                        </header>

                        <div class="validation-registry-row">
                            <label for="registry_number">
                                Registry number <span>optional</span>
                            </label>
                            <input type="text" id="registry_number" name="registry_number"
                                   class="form-control" maxlength="64"
                                   placeholder="As written on the certificate">
                        </div>

                        <div class="validation-list-heading" aria-hidden="true">
                            <span>Field</span>
                            <span>TrOCR output</span>
                            <span>Verified</span>
                        </div>
                        <div class="validation-result-list" id="verifyRows"></div>

                        <footer class="validation-submit-bar">
                            <div class="validation-lock-copy">
                                <i class="icon-base bx bx-lock-alt" aria-hidden="true"></i>
                                <span>Submitted fields are locked. Corrections require an approved change request.</span>
                            </div>

                            <div class="validation-submit-actions">
                                <button class="btn btn-outline-secondary" type="button" id="backToMark">
                                    <i class="icon-base bx bx-chevron-left icon-sm me-1"></i> Back to marking
                                </button>
                                <button class="btn btn-primary" type="submit" id="submitBtn" disabled>
                                    <i class="icon-base bx bx-check-shield icon-sm me-1"></i>
                                    Submit <span id="submitVerifiedCount">0</span> verified
                                </button>
                            </div>
                        </footer>
                    </section>
                </div>
            </div>
        </form>
    </section>

    {{-- Confirm restoring the original template layout --}}
    <div class="modal fade" id="resetFieldsModal" tabindex="-1"
        aria-labelledby="resetFieldsModalTitle" aria-describedby="resetFieldsModalDescription"
         aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered reset-confirm-dialog">
            <div class="modal-content reset-confirm-modal">
                <div class="modal-header">
                    <h5 class="modal-title" id="resetFieldsModalTitle">Reset field layout?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <p class="mb-2" id="resetFieldsModalDescription">
                        Restore all field markers to the positions and sizes configured in the original template?
                    </p>
                    <p class="small text-muted mb-0">
                        Added or copied fields will be removed, and deleted fields will be restored.
                    </p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="button" class="btn btn-primary" id="confirmResetFieldsBtn">
                        <i class="icon-base bx bx-refresh icon-sm me-1" aria-hidden="true"></i>
                        Reset fields
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- OCR progress while the model runs --}}
    <div class="modal fade" id="scanningModal" tabindex="-1" data-bs-backdrop="static"
         data-bs-keyboard="false" aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content ocr-progress-modal text-center">
                <div class="modal-body p-4 p-sm-5">
                    <div class="ocr-progress-ring mx-auto mb-4" id="ocrProgressRing"
                         role="progressbar" aria-label="OCR progress" aria-valuemin="0"
                         aria-valuemax="100" aria-valuenow="0">
                        <div class="ocr-progress-ring__center">
                            <strong id="ocrProgressValue">0%</strong>
                            <small>estimated</small>
                        </div>
                    </div>

                    <h5 class="mb-2" id="ocrProgressTitle">Preparing document</h5>
                    <p class="small text-muted mb-3" id="scanningNote" aria-live="polite">
                        Creating clear image crops for each marked field.
                    </p>

                    <div class="ocr-progress-fields">
                        <i class="icon-base bx bx-scan" aria-hidden="true"></i>
                        <span id="ocrProgressFields">Preparing marked fields</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script type="module">
    import { FieldMarker } from '{{ Vite::asset('resources/js/field-marker.js') }}';

    const config = {
        boxes: @json($boxes),
        threshold: @json($threshold),
        recogniseUrl: @json(route('documents.recognise')),
        csrf: @json(csrf_token()),
        paper: {!! Illuminate\Support\Js::encode([
            'sizeLabel' => $template->paper_size->label(),
            'dimensionsLabel' => $template->paperDimensionsLabel(),
            'orientation' => $template->orientation->value,
            'orientationLabel' => $template->orientation->label(),
            'aspectRatio' => $template->paperAspectRatio(),
        ]) !!},
    };

    const el = (id) => {
        const node = document.getElementById(id);
        if (!node) throw new Error(`Required interface element #${id} was not found.`);
        return node;
    };

    const requiredPart = (root, selector) => {
        const node = root.querySelector(selector);
        if (!(node instanceof HTMLElement)) {
            throw new Error(`Required interface element ${selector} was not found.`);
        }
        return node;
    };

    const requiredInput = (root, selector) => {
        const node = root.querySelector(selector);
        if (!(node instanceof HTMLInputElement)) {
            throw new Error(`Required input ${selector} was not found.`);
        }
        return node;
    };
    const steps = { upload: el('step-upload'), mark: el('step-mark'), verify: el('step-verify') };
    const modelSelect = document.getElementById('modelSelect');
    const scanFileInput = requiredInput(document, '#scanFile');
    const selectAllFieldsInput = requiredInput(document, '#selectAllFields');

    let scanFile = null;
    let cropped = [];
    let readings = [];
    let fieldHistory = [];
    let currentFieldSnapshot = null;
    let restoringFieldHistory = false;
    let markerClipboard = [];
    let pasteSequence = 0;
    let activeValidationIndex = null;
    let syncingValidationSelection = false;
    let recordSubmitting = false;

    const marker = new FieldMarker({
        canvas: el('pageCanvas'),
        overlay: el('fieldOverlay'),
        viewport: el('docViewport'),
        onChange: handleMarkerChange,
        onSelectionChange: updateSelectionUI,
        onZoomChange: updateZoomUI,
    });

    const validationMarker = new FieldMarker({
        canvas: el('validationPageCanvas'),
        overlay: el('validationFieldOverlay'),
        viewport: el('validationDocViewport'),
        readOnly: true,
        onSelectionChange: handleValidationMarkerSelection,
        onZoomChange: updateValidationZoomUI,
    });

    const cloneBoxes = (boxes) => boxes.map(({ name, x, y, w, h }) => ({ name, x, y, w, h }));
    const templateBoxes = config.boxes.map((box) => ({
        name: box.name,
        x: +box.x,
        y: +box.y,
        w: +box.w,
        h: +box.h,
    }));

    function fieldsMatchTemplate() {
        return JSON.stringify(marker.toJSON()) === JSON.stringify(templateBoxes);
    }

    function updateResetUI() {
        const layoutChanged = !fieldsMatchTemplate();
        const zoomChanged = Math.abs(marker.zoom - 1) > 0.001;
        el('resetFieldsBtn').disabled = !layoutChanged && !zoomChanged;
    }

    function handleMarkerChange(boxes) {
        const next = cloneBoxes(boxes);

        if (!restoringFieldHistory && currentFieldSnapshot !== null
            && JSON.stringify(next) !== JSON.stringify(currentFieldSnapshot)) {
            fieldHistory.push(currentFieldSnapshot);
            if (fieldHistory.length > 100) fieldHistory.shift();
        }

        currentFieldSnapshot = next;
        renderFieldList(boxes);
        updateResetUI();
    }

    function resetFieldHistory() {
        fieldHistory = [];
        currentFieldSnapshot = null;
        markerClipboard = [];
        pasteSequence = 0;
    }

    function undoFieldChange() {
        const previous = fieldHistory.pop();
        if (!previous) return;

        restoringFieldHistory = true;
        marker.setBoxes(cloneBoxes(previous));
        restoringFieldHistory = false;
    }

    function copySelectedFields() {
        const boxes = marker.toJSON();
        markerClipboard = marker.selectedIndexes().map((index) => ({ ...boxes[index] }));
        pasteSequence = 0;

        const count = markerClipboard.length;
        if (count > 0) {
            el('selectionSummary').querySelector('span').textContent = `${count} copied`;
        }
    }

    function nextCopyName(name, takenNames) {
        let candidate = `${name} copy`;
        let suffix = 2;

        while (takenNames.has(candidate.toLocaleLowerCase())) {
            candidate = `${name} copy ${suffix}`;
            suffix += 1;
        }

        takenNames.add(candidate.toLocaleLowerCase());
        return candidate;
    }

    function pasteCopiedFields() {
        if (markerClipboard.length === 0) return;

        const existing = marker.toJSON();
        const minX = Math.min(...markerClipboard.map((box) => box.x));
        const minY = Math.min(...markerClipboard.map((box) => box.y));
        const maxX = Math.max(...markerClipboard.map((box) => box.x + box.w));
        const maxY = Math.max(...markerClipboard.map((box) => box.y + box.h));
        const distance = Math.min(0.1, 0.018 * (pasteSequence + 1));
        const dx = maxX + distance <= 1 ? distance : (minX - distance >= 0 ? -distance : 0);
        const dy = maxY + distance <= 1 ? distance : (minY - distance >= 0 ? -distance : 0);
        const takenNames = new Set(existing.map((box) => box.name.toLocaleLowerCase()));
        const copies = markerClipboard.map((box) => ({
            ...box,
            name: nextCopyName(box.name, takenNames),
            x: box.x + dx,
            y: box.y + dy,
        }));
        const firstCopyIndex = existing.length;

        pasteSequence += 1;
        marker.setBoxes([...existing, ...copies]);
        copies.forEach((box, index) => marker.selectBox(firstCopyIndex + index, {
            additive: index > 0,
        }));
    }

    function showStep(name) {
        Object.entries(steps).forEach(([key, node]) => node.classList.toggle('d-none', key !== name));

        const marking = name === 'mark';
        const focusedWorkspace = marking || name === 'verify';
        el('documentPageHeader').classList.toggle('d-none', focusedWorkspace);
        el('documentFlowSteps').classList.toggle('d-none', focusedWorkspace);
        el('layout-navbar')?.classList.toggle('d-none', focusedWorkspace);
        document.querySelector('.content-footer')?.classList.toggle('d-none', focusedWorkspace);

        const order = ['upload', 'mark', 'verify'];
        const activeIndex = order.indexOf(name);
        document.querySelectorAll('[data-flow-step]').forEach((node) => {
            const stepIndex = order.indexOf(node.dataset.flowStep);
            node.classList.toggle('is-active', stepIndex === activeIndex);
            node.classList.toggle('is-complete', stepIndex < activeIndex);
            if (stepIndex === activeIndex) {
                node.setAttribute('aria-current', 'step');
            } else {
                node.removeAttribute('aria-current');
            }
        });

        window.scrollTo({ top: 0, behavior: 'auto' });
    }

    // ---------------------------------------------------------------- step 1
    function updatePaperMatchWarning() {
        const warning = el('paperMismatchWarning');
        const message = el('paperMismatchMessage');
        const actualRatio = marker.canvas.width / marker.canvas.height;
        const actualOrientation = actualRatio >= 1 ? 'landscape' : 'portrait';
        const orientationMismatch = actualOrientation !== config.paper.orientation;
        const ratioDifference = Math.abs(actualRatio - config.paper.aspectRatio) / config.paper.aspectRatio;

        if (!orientationMismatch && ratioDifference <= 0.1) {
            warning.classList.add('d-none');
            message.textContent = '';
            return;
        }

        message.textContent = orientationMismatch
            ? `This scan looks ${actualOrientation}, but the published template expects ${config.paper.orientation}. The image is not stretched; align the markers carefully or choose an upright scan.`
            : `This scan's proportions differ from ${config.paper.sizeLabel} (${config.paper.dimensionsLabel}). Check that the correct template and paper size were selected.`;
        warning.classList.remove('d-none');
    }

    async function openDocument(file) {
        if (!file) return;

        if (file.size > 20 * 1024 * 1024) {
            alert('Choose a document smaller than 20 MB.');
            scanFileInput.value = '';
            return;
        }

        dropzone.classList.add('is-loading');
        dropzone.setAttribute('aria-busy', 'true');

        try {
            scanFile = file;
            await marker.load(file);
            updatePaperMatchWarning();
            el('selectedFileName').textContent = file.name;
            showStep('mark');
            resetFieldHistory();
            marker.setBoxes(cloneBoxes(templateBoxes));

            // The marking section was hidden while the file loaded, so fit only
            // after it becomes measurable in the layout.
            window.requestAnimationFrame(() => marker.resetZoom());
        } catch (error) {
            scanFile = null;
            scanFileInput.value = '';
            alert(error.message || 'That document could not be opened.');
        } finally {
            dropzone.classList.remove('is-loading');
            dropzone.removeAttribute('aria-busy');
        }
    }

    scanFileInput.addEventListener('change', (event) => {
        if (!(event.currentTarget instanceof HTMLInputElement)) return;
        openDocument(event.currentTarget.files?.[0]);
    });

    const dropzone = el('documentDropzone');
    ['dragenter', 'dragover'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.add('is-dragging');
        });
    });
    ['dragleave', 'drop'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.remove('is-dragging');
        });
    });
    dropzone.addEventListener('drop', (event) => openDocument(event.dataTransfer?.files?.[0]));
    dropzone.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            scanFileInput.click();
        }
    });

    // ---------------------------------------------------------------- step 2
    function renderFieldList(boxes) {
        const list = el('fieldList');
        list.innerHTML = '';

        if (!boxes.length) {
            const empty = document.createElement('li');
            empty.className = 'marker-field-empty';
            empty.innerHTML = '<i class="icon-base bx bx-list-check"></i><span>No fields yet. Add one below.</span>';
            list.appendChild(empty);
        }

        boxes.forEach((box, index) => {
            const li = document.createElement('li');
            li.className = 'field-list-item d-flex align-items-center gap-2 py-1 px-2 rounded';
            li.dataset.fieldIndex = String(index);
            li.innerHTML = `
                <span class="badge bg-label-primary">${index + 1}</span>
                <span class="flex-grow-1 small"></span>
                <button type="button" class="btn btn-sm btn-icon btn-text-danger" aria-label="Remove field">
                    <i class="icon-base bx bx-x icon-sm"></i>
                </button>`;
            li.querySelector('span.flex-grow-1').textContent = box.name;
            li.addEventListener('click', (event) => marker.selectBox(index, {
                additive: event.shiftKey,
                toggle: event.shiftKey,
            }));
            li.querySelector('button').addEventListener('click', (event) => {
                event.stopPropagation();
                marker.removeBox(index);
            });
            list.appendChild(li);
        });

        el('fieldCount').textContent = `${boxes.length} field${boxes.length === 1 ? '' : 's'}`;
        selectAllFieldsInput.disabled = boxes.length === 0;
        el('scanNowBtn').disabled = boxes.length === 0;
        updateSelectionUI(marker.selectedIndexes());
    }

    function centerFieldListRow(index) {
        const list = el('fieldList');
        const row = list.querySelector(`[data-field-index="${index}"]`);
        if (!(row instanceof HTMLElement)) return;

        const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const behavior = smooth ? 'smooth' : 'auto';
        const listRect = list.getBoundingClientRect();
        const rowRect = row.getBoundingClientRect();
        const listTarget = list.scrollTop
            + rowRect.top - listRect.top
            - (list.clientHeight - rowRect.height) / 2;
        const clampedListTarget = Math.min(
            Math.max(0, list.scrollHeight - list.clientHeight),
            Math.max(0, listTarget),
        );
        const listDelta = clampedListTarget - list.scrollTop;

        list.scrollTo({ top: clampedListTarget, behavior });

        const panel = list.closest('.document-side-panel');
        if (!(panel instanceof HTMLElement) || panel.scrollHeight <= panel.clientHeight) return;

        const panelRect = panel.getBoundingClientRect();
        const eventualRowTop = rowRect.top - listDelta;
        const panelTarget = panel.scrollTop
            + eventualRowTop - panelRect.top
            - (panel.clientHeight - rowRect.height) / 2;
        panel.scrollTo({
            top: Math.min(
                Math.max(0, panel.scrollHeight - panel.clientHeight),
                Math.max(0, panelTarget),
            ),
            behavior,
        });
    }

    function updateSelectionUI(indexes, context = {}) {
        const selected = new Set(indexes);
        document.querySelectorAll('#fieldList .field-list-item').forEach((item) => {
            item.classList.toggle('is-selected', selected.has(Number(item.dataset.fieldIndex)));
        });

        const count = indexes.length;
        const total = marker.toJSON().length;
        const summary = el('selectionSummary');
        summary.querySelector('span').textContent = `${count} selected`;
        summary.classList.toggle('is-active', count > 0);
        selectAllFieldsInput.checked = total > 0 && count === total;
        selectAllFieldsInput.indeterminate = count > 0 && count < total;
        el('deleteSelectedBtn').disabled = count === 0;
        el('deleteFieldsBtn').disabled = count === 0;

        if (context.source === 'marker' && Number.isInteger(context.activeIndex)) {
            centerFieldListRow(context.activeIndex);
        }
    }

    function updateZoomUI(zoom) {
        el('zoomResetBtn').textContent = `${Math.round(zoom * 100)}%`;
        updateResetUI();
    }

    function updateValidationZoomUI(zoom) {
        el('validationZoomResetBtn').textContent = `${Math.round(zoom * 100)}%`;
    }

    el('zoomOutBtn').addEventListener('click', () => marker.zoomBy(-0.1));
    el('zoomInBtn').addEventListener('click', () => marker.zoomBy(0.1));
    el('zoomResetBtn').addEventListener('click', () => marker.resetZoom());
    el('validationZoomOutBtn').addEventListener('click', () => validationMarker.zoomBy(-0.1));
    el('validationZoomInBtn').addEventListener('click', () => validationMarker.zoomBy(0.1));
    el('validationZoomResetBtn').addEventListener('click', () => validationMarker.resetZoom());

    function restoreTemplateFields() {
        marker.setBoxes(cloneBoxes(templateBoxes));
        marker.resetZoom();
        el('docViewport').scrollTo({ top: 0, left: 0 });
        clearOcrError();
    }

    el('resetFieldsBtn').addEventListener('click', () => {
        const layoutChanged = !fieldsMatchTemplate();
        const zoomChanged = Math.abs(marker.zoom - 1) > 0.001;
        if (!layoutChanged && !zoomChanged) return;

        if (!layoutChanged) {
            restoreTemplateFields();
            return;
        }

        window.bootstrap.Modal.getOrCreateInstance(el('resetFieldsModal')).show();
    });
    el('confirmResetFieldsBtn').addEventListener('click', () => {
        window.bootstrap.Modal.getInstance(el('resetFieldsModal'))?.hide();
        restoreTemplateFields();
    });
    el('deleteSelectedBtn').addEventListener('click', () => marker.removeSelected());
    el('deleteFieldsBtn').addEventListener('click', () => marker.removeSelected());
    selectAllFieldsInput.addEventListener('change', (event) => {
        if (event.target.checked) {
            marker.selectAll();
        } else {
            marker.clearSelection();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (steps.mark.classList.contains('d-none')) {
            return;
        }

        const target = event.target;
        const isEditing = target instanceof HTMLElement && (
            target.matches('input, textarea, select') || target.isContentEditable
        );

        if (isEditing) {
            return;
        }

        const commandPressed = event.ctrlKey || event.metaKey;
        const key = event.key.toLowerCase();

        if (commandPressed && !event.shiftKey && key === 'c' && marker.selectedIndexes().length > 0) {
            event.preventDefault();
            copySelectedFields();
            return;
        }

        if (commandPressed && !event.shiftKey && key === 'v' && markerClipboard.length > 0) {
            event.preventDefault();
            pasteCopiedFields();
            return;
        }

        const undoPressed = commandPressed
            && !event.shiftKey
            && key === 'z';

        if (undoPressed && fieldHistory.length > 0) {
            event.preventDefault();
            undoFieldChange();
            return;
        }

        if (['Backspace', 'Delete'].includes(event.key) && marker.selectedIndexes().length > 0) {
            event.preventDefault();
            marker.removeSelected();
        }
    });

    el('addFieldBtn').addEventListener('click', () => {
        const input = el('newFieldName');
        const name = input.value.trim();
        if (!name) return;
        marker.addBox(name);
        input.value = '';
        input.focus();
    });
    el('newFieldName').addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            el('addFieldBtn').click();
        }
    });

    el('backToUpload').addEventListener('click', () => {
        scanFileInput.value = '';
        showStep('upload');
    });
    el('backToMark').addEventListener('click', () => {
        clearValidationSubmitError();
        showStep('mark');
    });

    let scanInProgress = false;
    let ocrProgressTimer = null;
    let ocrProgress = 0;

    function showOcrError(message) {
        el('ocrActionMessage').textContent = message;
        el('ocrActionStatus').classList.remove('d-none');
    }

    function clearOcrError() {
        el('ocrActionMessage').textContent = '';
        el('ocrActionStatus').classList.add('d-none');
    }

    function setOcrProgress(value, title = null, note = null) {
        ocrProgress = Math.max(0, Math.min(100, value));
        const rounded = Math.round(ocrProgress);
        const ring = el('ocrProgressRing');

        ring.style.setProperty('--ocr-progress', ocrProgress);
        ring.setAttribute('aria-valuenow', String(rounded));
        el('ocrProgressValue').textContent = `${rounded}%`;

        if (title) el('ocrProgressTitle').textContent = title;
        if (note) el('scanningNote').textContent = note;
    }

    function beginOcrProgress(fieldCount) {
        if (ocrProgressTimer !== null) window.clearInterval(ocrProgressTimer);

        el('ocrProgressFields').textContent = `${fieldCount} marked field${fieldCount === 1 ? '' : 's'}`;
        setOcrProgress(4, 'Preparing document', 'Creating clear image crops for each marked field.');

        ocrProgressTimer = window.setInterval(() => {
            if (ocrProgress >= 92) return;

            const increment = ocrProgress < 30 ? 4 : (ocrProgress < 70 ? 2 : 1);
            const next = Math.min(92, ocrProgress + increment);

            if (next < 18) {
                setOcrProgress(next, 'Preparing document', 'Creating clear image crops for each marked field.');
            } else if (next < 30) {
                setOcrProgress(next, 'Sending marked fields', 'Uploading the field crops securely to the OCR service.');
            } else if (next < 78) {
                setOcrProgress(next, 'Reading handwriting', 'The selected TrOCR model is reading the marked areas.');
            } else {
                setOcrProgress(next, 'Finalizing results', 'Checking the OCR response before validation.');
            }
        }, 350);
    }

    function stopOcrProgress() {
        if (ocrProgressTimer === null) return;
        window.clearInterval(ocrProgressTimer);
        ocrProgressTimer = null;
    }

    async function completeOcrProgress() {
        stopOcrProgress();
        await new Promise((resolve) => window.setTimeout(resolve, 180));
        setOcrProgress(100, 'Scan complete', 'Opening the validation results.');
        await new Promise((resolve) => window.setTimeout(resolve, 420));
    }

    function responseErrorMessage(response, payload) {
        if (response.status === 419) {
            return 'Your session expired. Refresh the page, reopen the document, and try again.';
        }
        if (response.status === 413) {
            return 'The marked image crops are too large to send. Use tighter field boxes and try again.';
        }
        if (response.status === 422) {
            const validationMessage = payload?.errors
                ? Object.values(payload.errors).flat()[0]
                : null;
            return validationMessage || payload?.message || 'One or more marked fields are invalid.';
        }

        return payload?.message || payload?.error || `OCR failed with HTTP ${response.status}.`;
    }

    el('scanNowBtn').addEventListener('click', async () => {
        if (scanInProgress) return;

        const button = el('scanNowBtn');
        const originalButtonContent = button.innerHTML;
        const controller = new AbortController();
        let modal = null;
        let timeoutId = null;

        scanInProgress = true;
        clearOcrError();
        button.disabled = true;
        button.innerHTML = '<i class="icon-base bx bx-scan icon-sm me-1" aria-hidden="true"></i> OCR in progress...';

        try {
            // Cropping and modal creation used to happen outside the try block. A
            // browser-side failure there made the button appear to do nothing.
            const Modal = window.bootstrap?.Modal;
            if (!Modal) {
                throw new Error('The OCR loading interface did not finish loading. Refresh the page and try again.');
            }

            modal = Modal.getOrCreateInstance(el('scanningModal'));
            beginOcrProgress(marker.toJSON().length);
            modal.show();

            await new Promise((resolve) => window.requestAnimationFrame(resolve));
            cropped = marker.crop();

            if (!cropped.length) {
                throw new Error('Add at least one field before reading.');
            }

            setOcrProgress(Math.max(ocrProgress, 18), 'Sending marked fields', 'Uploading the field crops securely to the OCR service.');
            timeoutId = window.setTimeout(() => controller.abort(), 125000);

            const response = await fetch(config.recogniseUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': config.csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                signal: controller.signal,
                body: JSON.stringify({
                    fields: cropped.map((c) => ({ name: c.name, image: c.image })),
                    // Absent unless Staff choice is enabled; the server falls back to
                    // the promoted model and re-checks that the key is one it allows.
                    model: modelSelect instanceof HTMLSelectElement ? modelSelect.value : null,
                }),
            });

            const contentType = response.headers.get('content-type') || '';
            const payload = contentType.includes('application/json')
                ? await response.json()
                : null;

            if (!response.ok) {
                throw new Error(responseErrorMessage(response, payload));
            }

            if (!Array.isArray(payload?.results) || payload.results.length === 0) {
                throw new Error('The OCR service returned no field readings. Please try again.');
            }

            if (typeof payload.modelKey !== 'string' || payload.modelKey.trim() === '') {
                throw new Error('The OCR service did not identify the model used. No data was saved; please scan again.');
            }

            if (payload.results.length !== cropped.length) {
                throw new Error('The OCR service returned an incomplete result. No data was saved; please scan again.');
            }

            const hasMismatchedResult = payload.results.some((result, index) => (
                !result
                || typeof result !== 'object'
                || String(result?.name ?? '') !== String(cropped[index]?.name ?? '')
            ));
            if (hasMismatchedResult) {
                throw new Error('The OCR fields no longer match their markers. No data was saved; please scan again.');
            }

            readings = payload.results;
            requiredInput(document, '#ocrModelKey').value = payload.modelKey.trim();
            el('summaryModel').textContent = payload.model || '—';

            setOcrProgress(96, 'Preparing validation', 'The handwriting results are ready for your review.');
            renderVerifyRows();
            await completeOcrProgress();
            showStep('verify');
            try {
                prepareValidationComparison();
            } catch (error) {
                showStep('mark');
                throw error;
            }
        } catch (error) {
            const message = error.name === 'AbortError'
                ? 'OCR timed out after two minutes. Check the OCR service and try again.'
                : (error.message || 'The document could not be scanned.');
            console.error('Document OCR failed:', error);
            showOcrError(message);
        } finally {
            if (timeoutId !== null) window.clearTimeout(timeoutId);
            stopOcrProgress();
            modal?.hide();
            scanInProgress = false;
            button.innerHTML = originalButtonContent;
            button.disabled = marker.toJSON().length === 0;
        }
    });

    // ---------------------------------------------------------------- step 3
    function normaliseConfidence(reading) {
        const confidence = Number(reading?.confidence ?? 0);
        return Number.isFinite(confidence) ? Math.max(0, Math.min(100, confidence)) : 0;
    }

    function prepareValidationComparison() {
        const source = el('pageCanvas');
        const target = el('validationPageCanvas');
        const context = target.getContext('2d');

        if (!context || source.width === 0 || source.height === 0) {
            throw new Error('The original document could not be prepared for comparison. Please scan again.');
        }

        activeValidationIndex = null;
        target.width = source.width;
        target.height = source.height;
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, target.width, target.height);
        context.drawImage(source, 0, 0);

        validationMarker.setBoxes(cropped.map(({ name, x, y, w, h }) => ({ name, x, y, w, h })));
        validationMarker.resetZoom();
        el('validationDocViewport').scrollTo({ top: 0, left: 0 });
        el('validationFileName').textContent = scanFile?.name || 'Document';

        window.requestAnimationFrame(() => {
            validationMarker.layout();
            makeValidationMarkersAccessible();
            updateValidationMarkerStates();
            if (readings.length > 0) activateValidationField(0, 'initial');
        });
    }

    function makeValidationMarkersAccessible() {
        const boxes = validationMarker.toJSON();
        el('validationFieldOverlay').querySelectorAll('.field-box').forEach((box, index) => {
            box.tabIndex = 0;
            box.setAttribute('role', 'button');
            box.setAttribute('aria-label', `Compare ${boxes[index]?.name ?? `field ${index + 1}`}`);
            box.title = `Compare ${boxes[index]?.name ?? `field ${index + 1}`}`;
        });
    }

    function handleValidationMarkerSelection(indexes) {
        if (syncingValidationSelection) return;
        if (indexes.length === 0) {
            activeValidationIndex = null;
            el('verifyRows').querySelectorAll('.validation-field').forEach((row) => {
                row.classList.remove('is-active');
                row.removeAttribute('aria-current');
            });
            return;
        }
        activateValidationField(indexes[0], 'marker');
    }

    function revealValidationRow(index) {
        const list = el('verifyRows');
        const row = list.querySelector(`[data-field-index="${index}"]`);
        if (!row) return;

        const rowTop = row.offsetTop;
        const rowBottom = rowTop + row.offsetHeight;
        if (rowTop < list.scrollTop) {
            list.scrollTo({ top: rowTop - 8, behavior: 'smooth' });
        } else if (rowBottom > list.scrollTop + list.clientHeight) {
            list.scrollTo({ top: rowBottom - list.clientHeight + 8, behavior: 'smooth' });
        }
    }

    function revealValidationMarker(index) {
        const viewport = el('validationDocViewport');
        const box = el('validationFieldOverlay').querySelector(`[data-index="${index}"]`);
        if (!box) return;

        viewport.scrollTo({
            left: Math.max(0, box.offsetLeft + (box.offsetWidth / 2) - (viewport.clientWidth / 2)),
            top: Math.max(0, box.offsetTop + (box.offsetHeight / 2) - (viewport.clientHeight / 2)),
            behavior: 'smooth',
        });
    }

    function activateValidationField(index, source = 'row') {
        if (!Number.isInteger(index) || index < 0 || index >= readings.length) return;
        activeValidationIndex = index;

        if (source !== 'marker') {
            syncingValidationSelection = true;
            validationMarker.selectBox(index);
            syncingValidationSelection = false;
        }

        el('verifyRows').querySelectorAll('.validation-field').forEach((row) => {
            const active = Number(row.dataset.fieldIndex) === index;
            row.classList.toggle('is-active', active);
            row.setAttribute('aria-current', active ? 'true' : 'false');
        });

        if (source === 'marker') revealValidationRow(index);
        if (source === 'row') revealValidationMarker(index);
    }

    function updateValidationMarkerStates() {
        el('validationFieldOverlay').querySelectorAll('.field-box').forEach((box, index) => {
            const checkbox = el('verifyRows')
                .querySelector(`[data-field-index="${index}"] .validation-verified`);
            const checked = checkbox instanceof HTMLInputElement && checkbox.checked;
            box.classList.toggle('is-verified', checked);
        });
    }

    function clearValidationFieldError(index) {
        const row = el('verifyRows').querySelector(`[data-field-index="${index}"]`);
        if (!row) return;
        row.classList.remove('is-invalid');
        requiredInput(row, '.verified').classList.remove('is-invalid');
        const message = requiredPart(row, '.validation-field__error');
        message.textContent = '';
        message.classList.add('d-none');
    }

    function setValidationFieldError(index, message) {
        const row = el('verifyRows').querySelector(`[data-field-index="${index}"]`);
        if (!row) return;
        row.classList.add('is-invalid');
        requiredInput(row, '.verified').classList.add('is-invalid');
        const error = requiredPart(row, '.validation-field__error');
        error.textContent = message;
        error.classList.remove('d-none');
    }

    function clearValidationSubmitError() {
        el('validationSubmitMessage').textContent = '';
        el('validationSubmitError').classList.add('d-none');
    }

    function showValidationSubmitError(message) {
        el('validationSubmitMessage').textContent = message;
        el('validationSubmitError').classList.remove('d-none');
        el('validationSubmitError').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function updateVerificationSummary() {
        const checkboxes = [...el('verifyRows').querySelectorAll('.validation-verified')];
        const verified = checkboxes.filter((checkbox) => checkbox.checked).length;
        const total = checkboxes.length;
        const percentage = total > 0 ? Math.round((verified / total) * 100) : 0;

        el('verifiedCount').textContent = `${verified} of ${total}`;
        el('verifiedProgress').setAttribute('aria-valuenow', String(percentage));
        el('verifiedProgressBar').style.width = `${percentage}%`;
        el('submitVerifiedCount').textContent = String(verified);
        el('submitBtn').disabled = verified === 0 || recordSubmitting;
        updateValidationMarkerStates();
    }

    function renderVerifyRows() {
        const container = el('verifyRows');
        container.innerHTML = '';
        activeValidationIndex = null;
        clearValidationSubmitError();

        readings.forEach((reading, index) => {
            const confidence = normaliseConfidence(reading);
            const flagged = confidence < config.threshold;
            const inputId = `verifiedField${index}`;
            const checkboxId = `verifyField${index}`;
            const row = document.createElement('article');

            row.className = 'validation-field';
            row.dataset.fieldIndex = String(index);
            row.tabIndex = 0;
            row.innerHTML = `
                <div class="validation-field__identity">
                    <span class="validation-field__number"></span>
                    <div class="min-w-0">
                        <strong class="validation-field__name"></strong>
                        <span class="confidence-badge validation-field__confidence"></span>
                    </div>
                </div>
                <div class="validation-field__value">
                    <label class="visually-hidden" for="${inputId}"></label>
                    <input type="text" id="${inputId}" class="form-control verified"
                           maxlength="2000" autocomplete="off">
                    <div class="validation-field__reading">
                        TrOCR read: <span></span>
                    </div>
                    <div class="validation-field__ocr-error d-none">
                        TrOCR could not read this marker. Enter the value manually.
                    </div>
                    <div class="validation-field__error d-none" role="alert"></div>
                </div>
                <div class="validation-field__check">
                    <label class="validation-verified-control" for="${checkboxId}">
                        <input class="form-check-input validation-verified" type="checkbox"
                               id="${checkboxId}">
                        <span>Verified</span>
                    </label>
                </div>`;

            requiredPart(row, '.validation-field__number').textContent = String(index + 1).padStart(2, '0');
            requiredPart(row, '.validation-field__name').textContent = reading.name;
            requiredPart(row, `label[for="${inputId}"]`).textContent = `Verified value for ${reading.name}`;

            const badge = requiredPart(row, '.validation-field__confidence');
            badge.textContent = `${confidence.toFixed(1)}%`;
            badge.classList.add(flagged ? 'is-low' : 'is-ready');

            const readingText = String(reading.text ?? '');
            requiredPart(row, '.validation-field__reading span').textContent = readingText || '(nothing read)';

            const input = requiredInput(row, '.verified');
            const checkbox = requiredInput(row, '.validation-verified');
            input.value = readingText;
            if (flagged) row.classList.add('needs-review');
            if (reading.error) {
                row.classList.add('has-ocr-error');
                requiredPart(row, '.validation-field__ocr-error').classList.remove('d-none');
            }

            row.addEventListener('click', () => activateValidationField(index, 'row'));
            row.addEventListener('focusin', () => activateValidationField(index, 'row'));
            row.addEventListener('keydown', (event) => {
                if (event.target !== row || !['Enter', ' '].includes(event.key)) return;
                event.preventDefault();
                activateValidationField(index, 'row');
                input.focus();
            });

            input.addEventListener('input', () => {
                clearValidationFieldError(index);
                clearValidationSubmitError();
                if (checkbox.checked) {
                    checkbox.checked = false;
                    row.classList.remove('is-verified');
                    updateVerificationSummary();
                }
            });

            checkbox.addEventListener('change', () => {
                activateValidationField(index, 'row');
                clearValidationFieldError(index);
                clearValidationSubmitError();

                if (checkbox.checked && input.value.trim() === '') {
                    checkbox.checked = false;
                    setValidationFieldError(index, 'Enter or confirm a value before marking this field as verified.');
                    input.focus();
                }

                row.classList.toggle('is-verified', checkbox.checked);
                updateVerificationSummary();
            });

            container.appendChild(row);
        });

        const confidences = readings.map(normaliseConfidence);
        const average = confidences.length
            ? confidences.reduce((a, b) => a + b, 0) / confidences.length
            : 0;
        const flaggedCount = confidences.filter((confidence) => confidence < config.threshold).length;

        el('summaryConfidence').textContent = `${average.toFixed(1)}%`;
        el('summaryReview').textContent = `${flaggedCount}/${confidences.length}`;
        updateVerificationSummary();
    }

    el('validationFieldOverlay').addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) return;
        const box = event.target.closest('.field-box');
        if (!box) return;
        validationMarker.selectBox(Number(box.dataset.index));
    });

    el('validationFieldOverlay').addEventListener('keydown', (event) => {
        if (!(event.target instanceof Element)) return;
        const box = event.target.closest('.field-box');
        if (!box || !['Enter', ' '].includes(event.key)) return;
        event.preventDefault();
        validationMarker.selectBox(Number(box.dataset.index));
    });

    function submissionErrorMessage(response, payload) {
        if (response.status === 419) return 'Your session expired. Refresh the page and scan the document again.';
        if (response.status === 413) return 'The uploaded document is too large. The maximum size is 20 MB.';
        if (response.status === 422) {
            const messages = payload?.errors ? Object.values(payload.errors).flat() : [];
            return messages[0] || payload?.message || 'Review the highlighted fields and try again.';
        }
        return payload?.message || 'The record could not be submitted. Nothing was saved.';
    }

    function applyServerFieldErrors(errors, submittedIndexes) {
        Object.entries(errors ?? {}).forEach(([key, messages]) => {
            const match = key.match(/^fields\.(\d+)\./);
            if (!match) return;
            const originalIndex = submittedIndexes[Number(match[1])];
            if (originalIndex === undefined) return;
            setValidationFieldError(originalIndex, Array.isArray(messages) ? messages[0] : String(messages));
        });
    }

    el('submitForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        if (recordSubmitting) return;

        clearValidationSubmitError();
        const rows = [...el('verifyRows').querySelectorAll('.validation-field')];
        rows.forEach((row) => clearValidationFieldError(Number(row.dataset.fieldIndex)));

        const verifiedIndexes = rows
            .filter((row) => requiredInput(row, '.validation-verified').checked)
            .map((row) => Number(row.dataset.fieldIndex));

        if (verifiedIndexes.length === 0) {
            showValidationSubmitError('Check Verified on at least one field before submitting.');
            if (rows[0]) requiredInput(rows[0], '.validation-verified').focus();
            return;
        }

        const invalidIndexes = verifiedIndexes.filter((index) => {
            const row = rows[index];
            if (!row) return true;
            const input = requiredInput(row, '.verified');
            if (input.value.trim()) return false;
            setValidationFieldError(index, 'A verified field must contain a value.');
            return true;
        });
        if (invalidIndexes.length > 0) {
            showValidationSubmitError('Some checked fields still need a value.');
            activateValidationField(invalidIndexes[0], 'marker');
            const invalidRow = rows[invalidIndexes[0]];
            if (invalidRow) requiredInput(invalidRow, '.verified').focus();
            return;
        }

        if (!scanFile) {
            showValidationSubmitError('The original scan is no longer available. Return to marking and choose the file again.');
            return;
        }

        const form = el('submitForm');
        const data = new FormData(form);
        data.set('scan', scanFile, scanFile.name);

        verifiedIndexes.forEach((sourceIndex, submitIndex) => {
            const reading = readings[sourceIndex];
            const crop = cropped[sourceIndex];
            const value = requiredInput(rows[sourceIndex], '.verified').value.trim();
            const fields = {
                verified: '1',
                name: String(reading.name ?? ''),
                ocr_text: String(reading.text ?? ''),
                ocr_confidence: normaliseConfidence(reading).toFixed(1),
                verified_value: value,
                x: Number(crop.x ?? 0).toFixed(5),
                y: Number(crop.y ?? 0).toFixed(5),
                width: Number(crop.w ?? 0).toFixed(5),
                height: Number(crop.h ?? 0).toFixed(5),
            };

            Object.entries(fields).forEach(([key, fieldValue]) => {
                data.append(`fields[${submitIndex}][${key}]`, fieldValue);
            });
        });

        const button = el('submitBtn');
        const idleButton = button.innerHTML;
        const controller = new AbortController();
        const timeoutId = window.setTimeout(() => controller.abort(), 120000);
        let submissionSucceeded = false;
        recordSubmitting = true;
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Submitting verified fields...';

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: controller.signal,
                body: data,
            });
            const contentType = response.headers.get('content-type') || '';
            const payload = contentType.includes('application/json') ? await response.json() : null;

            if (!response.ok) {
                applyServerFieldErrors(payload?.errors, verifiedIndexes);
                throw new Error(submissionErrorMessage(response, payload));
            }

            if (!payload?.redirect) {
                throw new Error('The record was saved, but the archive location was not returned. Open Records Archive to confirm it.');
            }

            button.innerHTML = '<i class="icon-base bx bx-check me-2" aria-hidden="true"></i>Saved. Opening record...';
            window.location.assign(payload.redirect);
            submissionSucceeded = true;
        } catch (error) {
            console.error('Record submission failed:', error);
            const message = error.name === 'AbortError'
                ? 'Submission timed out. Check your connection, then confirm the record in Records Archive before trying again.'
                : (error instanceof TypeError
                    ? 'The server could not be reached. Check your connection and try again.'
                    : (error.message || 'The record could not be submitted. Nothing was saved.'));
            showValidationSubmitError(message);
        } finally {
            window.clearTimeout(timeoutId);
            if (!submissionSucceeded) {
                recordSubmitting = false;
                button.innerHTML = idleButton;
                updateVerificationSummary();
            }
        }
    });
</script>
@endpush
