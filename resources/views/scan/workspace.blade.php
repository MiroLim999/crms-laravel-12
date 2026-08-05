@extends('layouts.app')

@section('title', 'Scan ' . $docType->shortLabel())

@section('content')
    <div id="documentPageHeader">
        <x-page-header :title="'Scan: ' . $docType->label()"
                       :subtitle="'Template: ' . $template->name">
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
              enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="doc_type" value="{{ $docType->value }}">
            <input type="hidden" name="document_template_id" value="{{ $template->getKey() }}">
            <input type="hidden" name="ocr_model_key" id="ocrModelKey">

            <div class="row g-4">
                <div class="col-lg-8">
                    <x-card class="document-validation-card" title="Validate extracted fields"
                            subtitle="Compare each crop with the reading and correct it. The corrected value is what gets stored.">
                        <div id="verifyRows"></div>
                    </x-card>
                </div>

                <div class="col-lg-4">
                    <div class="document-side-panel">
                    <x-card title="Scan summary" class="mb-4 document-summary-card">
                        <dl class="row mb-0">
                            <dt class="col-7 fw-normal text-muted">Model</dt>
                            <dd class="col-5"><code id="summaryModel">—</code></dd>

                            <dt class="col-7 fw-normal text-muted">Average confidence</dt>
                            <dd class="col-5" id="summaryConfidence">—</dd>

                            <dt class="col-7 fw-normal text-muted">Needs review</dt>
                            <dd class="col-5 mb-0" id="summaryReview">—</dd>
                        </dl>

                        <hr>
                        <p class="small text-muted mb-0">
                            Confidence is the model's certainty in its own output, not accuracy.
                            Fields under {{ $threshold }}% are flagged for a closer look.
                        </p>
                    </x-card>

                    <x-card title="Registry details" class="mb-4">
                        <label for="registry_number" class="form-label">Registry number</label>
                        <input type="text" id="registry_number" name="registry_number"
                               class="form-control" placeholder="As written on the certificate">
                    </x-card>

                    <div class="document-lock-notice">
                        <i class="icon-base bx bx-lock-alt"></i>
                        <span>Submitting locks this record. Later corrections need a change request approved by an Admin.</span>
                    </div>

                    <div class="d-grid gap-2 mt-4">
                        <button class="btn btn-primary btn-lg" type="submit" id="submitBtn">
                            <i class="icon-base bx bx-check-shield icon-sm me-1"></i> Submit &amp; lock record
                        </button>
                        <button class="btn btn-outline-secondary" type="button" id="backToMark">
                            <i class="icon-base bx bx-chevron-left icon-sm me-1"></i> Back to marking
                        </button>
                    </div>
                    </div>
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
                <div class="modal-body p-4">
                    <div class="reset-confirm-modal__icon" aria-hidden="true">
                        <i class="icon-base bx bx-refresh"></i>
                    </div>

                    <h5 class="mb-2" id="resetFieldsModalTitle">Restore template fields?</h5>
                    <p class="text-muted mb-0" id="resetFieldsModalDescription">
                        Added or copied fields will be removed, deleted fields will return, and every
                        marker will move back to its original position and size.
                    </p>

                    <div class="reset-confirm-modal__notice">
                        <i class="icon-base bx bx-history" aria-hidden="true"></i>
                        <span>You can press <kbd>Ctrl</kbd> + <kbd>Z</kbd> afterward to undo this reset.</span>
                    </div>

                    <div class="reset-confirm-modal__actions">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            Keep changes
                        </button>
                        <button type="button" class="btn btn-primary" id="confirmResetFieldsBtn">
                            <i class="icon-base bx bx-refresh icon-sm me-1" aria-hidden="true"></i>
                            Reset fields
                        </button>
                    </div>
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
    };

    const el = (id) => document.getElementById(id);
    const steps = { upload: el('step-upload'), mark: el('step-mark'), verify: el('step-verify') };

    let scanFile = null;
    let cropped = [];
    let readings = [];
    let fieldHistory = [];
    let currentFieldSnapshot = null;
    let restoringFieldHistory = false;
    let markerClipboard = [];
    let pasteSequence = 0;

    const marker = new FieldMarker({
        canvas: el('pageCanvas'),
        overlay: el('fieldOverlay'),
        viewport: el('docViewport'),
        onChange: handleMarkerChange,
        onSelectionChange: updateSelectionUI,
        onZoomChange: updateZoomUI,
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
        el('documentPageHeader').classList.toggle('d-none', marking);
        el('documentFlowSteps').classList.toggle('d-none', marking);
        el('layout-navbar')?.classList.toggle('d-none', marking);

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

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ---------------------------------------------------------------- step 1
    async function openDocument(file) {
        if (!file) return;

        if (file.size > 20 * 1024 * 1024) {
            alert('Choose a document smaller than 20 MB.');
            el('scanFile').value = '';
            return;
        }

        dropzone.classList.add('is-loading');
        dropzone.setAttribute('aria-busy', 'true');

        try {
            scanFile = file;
            await marker.load(file);
            el('selectedFileName').textContent = file.name;
            showStep('mark');
            resetFieldHistory();
            marker.setBoxes(cloneBoxes(templateBoxes));

            // The marking section was hidden while the file loaded, so fit only
            // after it becomes measurable in the layout.
            window.requestAnimationFrame(() => marker.resetZoom());
        } catch (error) {
            scanFile = null;
            el('scanFile').value = '';
            alert(error.message || 'That document could not be opened.');
        } finally {
            dropzone.classList.remove('is-loading');
            dropzone.removeAttribute('aria-busy');
        }
    }

    el('scanFile').addEventListener('change', (event) => {
        openDocument(event.target.files?.[0]);
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
            el('scanFile').click();
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
        el('selectAllFields').disabled = boxes.length === 0;
        el('scanNowBtn').disabled = boxes.length === 0;
        updateSelectionUI(marker.selectedIndexes());
    }

    function updateSelectionUI(indexes) {
        const selected = new Set(indexes);
        document.querySelectorAll('#fieldList .field-list-item').forEach((item) => {
            item.classList.toggle('is-selected', selected.has(Number(item.dataset.fieldIndex)));
        });

        const count = indexes.length;
        const total = marker.toJSON().length;
        const summary = el('selectionSummary');
        summary.querySelector('span').textContent = `${count} selected`;
        summary.classList.toggle('is-active', count > 0);
        el('selectAllFields').checked = total > 0 && count === total;
        el('selectAllFields').indeterminate = count > 0 && count < total;
        el('deleteSelectedBtn').disabled = count === 0;
        el('deleteFieldsBtn').disabled = count === 0;
    }

    function updateZoomUI(zoom) {
        el('zoomResetBtn').textContent = `${Math.round(zoom * 100)}%`;
        updateResetUI();
    }

    el('zoomOutBtn').addEventListener('click', () => marker.zoomBy(-0.1));
    el('zoomInBtn').addEventListener('click', () => marker.zoomBy(0.1));
    el('zoomResetBtn').addEventListener('click', () => marker.resetZoom());

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
    el('selectAllFields').addEventListener('change', (event) => {
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
        el('scanFile').value = '';
        showStep('upload');
    });
    el('backToMark').addEventListener('click', () => showStep('mark'));

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
                    model: el('modelSelect')?.value ?? null,
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

            readings = payload.results;
            el('ocrModelKey').value = payload.modelKey ?? '';
            el('summaryModel').textContent = payload.model || '—';

            setOcrProgress(96, 'Preparing validation', 'The handwriting results are ready for your review.');
            renderVerifyRows();
            await completeOcrProgress();
            showStep('verify');
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
    function renderVerifyRows() {
        const container = el('verifyRows');
        container.innerHTML = '';

        readings.forEach((reading, index) => {
            const crop = cropped[index] ?? {};
            const confidence = Number(reading.confidence ?? 0);
            const flagged = confidence < config.threshold;

            const row = document.createElement('div');
            row.className = 'validation-field row g-3 align-items-center';
            row.innerHTML = `
                <div class="col-md-5">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <label class="form-label mb-0 fw-semibold"></label>
                        <span class="badge confidence-badge"></span>
                    </div>
                    <div class="validation-crop">
                        <img alt="Scanned crop" class="img-fluid">
                    </div>
                    <div class="small text-muted">
                        Model read: <span class="fst-italic reading"></span>
                    </div>
                </div>
                <div class="col-md-7">
                    <label class="form-label mb-1 small fw-medium">Verified value</label>
                    <input type="text" class="form-control form-control-lg verified">
                    ${reading.error ? '<div class="text-danger small mt-1">This crop failed to read.</div>' : ''}
                </div>`;

            row.querySelector('label').textContent = reading.name;
            row.querySelector('img').src = crop.image ?? '';
            row.querySelector('.reading').textContent = reading.text || '(nothing read)';

            const badge = row.querySelector('.confidence-badge');
            badge.textContent = `${confidence.toFixed(1)}% confidence`;
            badge.classList.add(flagged ? 'bg-label-warning' : 'bg-label-success');

            const input = row.querySelector('.verified');
            input.value = reading.text || '';
            input.name = `fields[${index}][verified_value]`;
            if (flagged) input.classList.add('field-needs-review');

            // Hidden values submitted alongside the correction.
            const hidden = {
                name: reading.name,
                ocr_text: reading.text || '',
                ocr_confidence: confidence,
                x: (crop.x ?? 0).toFixed(5),
                y: (crop.y ?? 0).toFixed(5),
                width: (crop.w ?? 0).toFixed(5),
                height: (crop.h ?? 0).toFixed(5),
            };

            Object.entries(hidden).forEach(([key, value]) => {
                const node = document.createElement('input');
                node.type = 'hidden';
                node.name = `fields[${index}][${key}]`;
                node.value = value;
                row.appendChild(node);
            });

            container.appendChild(row);
        });

        const confidences = readings.map((r) => Number(r.confidence ?? 0));
        const average = confidences.length
            ? confidences.reduce((a, b) => a + b, 0) / confidences.length
            : 0;
        const flaggedCount = confidences.filter((c) => c < config.threshold).length;

        el('summaryConfidence').textContent = `${average.toFixed(1)}%`;
        el('summaryReview').textContent = `${flaggedCount} of ${confidences.length}`;
    }

    // The scan file lives in an input the form does not own, so attach it via
    // FormData on submit.
    el('submitForm').addEventListener('submit', (event) => {
        if (!scanFile) {
            event.preventDefault();
            alert('The scanned file is missing. Start again from the upload step.');
            return;
        }

        const transfer = new DataTransfer();
        transfer.items.add(scanFile);

        let input = el('scanInput');
        if (!input) {
            input = document.createElement('input');
            input.type = 'file';
            input.name = 'scan';
            input.id = 'scanInput';
            input.className = 'd-none';
            el('submitForm').appendChild(input);
        }
        input.files = transfer.files;

        el('submitBtn').disabled = true;
        el('submitBtn').textContent = 'Submitting...';
    });
</script>
@endpush
