@extends('layouts.app')

@section('title', 'Scan ' . $docType->shortLabel())
@section('body-class', 'document-workspace-focused')

@section('workspace-navbar')
    @include('scan.partials.topbar', ['cancelable' => true])
@endsection

@section('content')
    {{-- Step 1: the document type and the scan --}}
    @include('scan.partials.document-step', ['currentType' => $docType])

    {{-- Step 2: mark the fields --}}
    <section id="step-mark" class="document-step-panel d-none">
        <div class="field-marker-comparison-grid">
            <div class="field-marker-document-pane">
                <x-card class="document-canvas-card" bodyClass="p-0">
                    <div class="marker-toolbar">
                        {{-- The file's name is in the top bar, beside the document type. --}}
                        <div class="marker-toolbar__primary">
                            <div class="btn-group marker-zoom-controls" role="group" aria-label="Document zoom controls">
                                <button type="button" class="btn btn-sm btn-icon btn-outline-secondary marker-tool-button" id="zoomOutBtn"
                                        aria-label="Zoom out">
                                    <i class="icon-base bx bx-minus icon-sm" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary marker-tool-button marker-zoom-value"
                                        id="zoomResetBtn" title="Fit document to the workspace">100%</button>
                                <button type="button" class="btn btn-sm btn-icon btn-outline-secondary marker-tool-button" id="zoomInBtn"
                                        aria-label="Zoom in">
                                    <i class="icon-base bx bx-plus icon-sm" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <div class="marker-toolbar__actions">
                            <div class="dropdown">
                                <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle marker-help-button" data-bs-toggle="dropdown"
                                        aria-expanded="false" aria-label="Show editor shortcuts">
                                    <i class="icon-base bx bx-terminal icon-sm me-1" aria-hidden="true"></i>
                                    <span>Shortcuts</span>
                                </button>
                                <div class="dropdown-menu dropdown-menu-end marker-shortcuts-menu">
                                    <div class="marker-shortcuts-menu__title">Page and fields</div>
                                    {{-- Mac keys beside the Windows ones: ⌘ Command for Ctrl, ⌥ Option for Alt. --}}
                                    <p class="marker-shortcuts-menu__note">On a Mac, <kbd>⌘</kbd> is Command, <kbd>⌥</kbd> is Option, and Backspace is the delete key.</p>
                                    <div><span><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + scroll</span><small>Zoom document (or pinch the trackpad)</small></div>
                                    <div><span><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + drag</span><small>Move document</small></div>
                                    <div><span><kbd>Shift</kbd> + click</span><small>Select multiple</small></div>
                                    <div><span><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>A</kbd></span><small>Select all fields</small></div>
                                    <div><span>Drag empty document area</span><small>Select fields in a rectangle</small></div>
                                    <div><span><kbd>Shift</kbd> + drag</span><small>Add fields to selection</small></div>
                                    <div><span>Drag selection</span><small>Move selected fields</small></div>
                                    <div><span>Drag resize handle</span><small>Resize selected fields</small></div>
                                    <div><span><kbd>Alt</kbd> / <kbd>⌥</kbd> + drag</span><small>Place without snapping to printed lines</small></div>
                                    <div><span>Drag the rotate knob below a field</span><small>Tilt that field (<kbd>Shift</kbd>: 5° steps)</small></div>
                                    <div><span><kbd>[</kbd> or <kbd>]</kbd></span><small>Tilt selected 0.5° (<kbd>Shift</kbd>: 5°)</small></div>
                                    <div><span>Double-click the knob</span><small>Straighten</small></div>
                                    <div><span><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>C</kbd> / <kbd>V</kbd></span><small>Copy / paste selected</small></div>
                                    <div><span><kbd>Del</kbd> or <kbd>Backspace</kbd></span><small>Delete selected</small></div>
                                    <div><span><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>Z</kbd></span><small>Undo last change</small></div>

                                    @if (count($ruledYs) >= 2)
                                        <div class="marker-shortcuts-menu__title">Row lines</div>
                                        <div><span>Drag the dot at a line's end</span><small>Move that line (<kbd>Shift</kbd>: all lines)</small></div>
                                        <div><span>Click a dot, then <kbd>↑</kbd> or <kbd>↓</kbd></span><small>Nudge it (<kbd>Shift</kbd>: 10 px)</small></div>
                                        <div><span><kbd>Del</kbd> or double-click a dot</span><small>Remove that line</small></div>
                                        <div><span><kbd>Esc</kbd></span><small>Let go of the line</small></div>
                                    @endif

                                    <div class="marker-shortcuts-menu__title">Line crops, after Detect</div>
                                    <div><span><kbd>Tab</kbd> / <kbd>Shift</kbd> + <kbd>Tab</kbd></span><small>Next / previous line</small></div>
                                    <div><span><kbd>+</kbd> or <kbd>−</kbd></span><small>Grow / shrink the outline</small></div>
                                    <div><span><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>S</kbd></span><small>Save the crop</small></div>
                                    <div><span><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>Z</kbd> / <kbd>Y</kbd></span><small>Undo / redo</small></div>
                                    <div><span><kbd>Del</kbd> or <kbd>Backspace</kbd></span><small>Remove the line (in Points: a corner)</small></div>
                                    <div><span><kbd>Enter</kbd></span><small>Finish a drawn outline</small></div>
                                    <div><span><kbd>Esc</kbd></span><small>Step back</small></div>
                                </div>
                            </div>

                            <button type="button" class="btn btn-sm btn-outline-primary marker-snap-button" id="snapTableBtn"
                                    title="Fit the column and row markers onto the page's printed table lines">
                                <i class="icon-base bx bx-grid-alt icon-sm me-1" aria-hidden="true"></i>
                                <span>Snap to table</span>
                                <span class="spinner-border marker-snap-spinner" aria-hidden="true"></span>
                            </button>

                            <button type="button" class="btn btn-sm btn-outline-secondary marker-reset-button" id="resetFieldsBtn"
                                    title="Restore the original template fields and document view" disabled>
                                <i class="icon-base bx bx-refresh icon-sm me-1" aria-hidden="true"></i>
                                <span>Reset</span>
                            </button>
                            {{-- Selecting and deleting fields: Select all and Delete in the Fields card. --}}
                        </div>
                    </div>

                    <div class="doc-viewport" id="docViewport">
                        <div class="doc-stage" id="docStage">
                            <canvas id="pageCanvas"></canvas>
                            <div class="field-overlay" id="fieldOverlay">
                                <div class="field-selection-marquee" id="staffFieldSelectionMarquee"
                                     aria-hidden="true"></div>
                            </div>
                        </div>
                    </div>
                </x-card>
            </div>

            <div class="document-side-panel field-marker-controls-pane">
                {{--
                    What Staff should know about the scan itself, shown only when
                    it applies: it may be sideways, or only its first page is read.
                --}}
                <div class="document-scan-notice d-none" id="scanSidewaysNotice" role="alert">
                    <i class="icon-base bx bx-error" aria-hidden="true"></i>
                    <span id="scanSidewaysMessage"></span>
                </div>
                <div class="document-scan-notice d-none" id="scanPagesNotice" role="status">
                    <i class="icon-base bx bx-file" aria-hidden="true"></i>
                    <span id="scanPagesMessage"></span>
                </div>

                {{--
                    The fields to read, in order. On a ledger page the table's
                    rows are set here too: they start as the template's, Snap
                    to table takes them from the page, and Staff can add, move
                    or remove rows and columns. The template is not changed.
                    The tips are behind the ?.
                --}}
                <div class="card mb-4 document-fields-card">
                    <div class="card-header d-flex align-items-center gap-1 position-relative">
                        <h5 class="card-title mb-0">Fields</h5>
                        <div class="card-help">
                            <button type="button" class="btn btn-link card-help__toggle"
                                    aria-label="Fields tips" aria-describedby="fieldTips">
                                <i class="icon-base bx bx-help-circle" aria-hidden="true"></i>
                            </button>
                            <div class="card-help__tips" id="fieldTips" role="tooltip">
                                <strong>Tips</strong>
                                <ul>
                                    <li>Keep each box tight around the handwriting. Loose boxes pick up neighbouring text and read badly.</li>
                                    <li>Click a name to select its box on the page. <kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>A</kbd> selects them all.</li>
                                    @if (count($ruledYs) >= 2)
                                        <li>+ Field is read once (e.g. Page No.). + Column is read on every row (e.g. Remarks).</li>
                                        <li>Stretch a new field down two or more rows, between columns, to read it as a column.</li>
                                        <li>Page scanned a little turned? Snap to table turns the table with it; Tilt fine-tunes it.</li>
                                    @endif
                                </ul>
                            </div>
                        </div>
                        <span class="badge bg-label-primary ms-auto" id="fieldCount" aria-live="polite">0 fields</span>
                    </div>

                    <div class="card-body">
                        @if (count($ruledYs) >= 2)
                            <div class="document-grid-card document-rows-control">
                                <div class="document-grid-card__line">
                                    <span class="document-grid-card__label" id="gridRowsLabel">Rows</span>
                                    <div class="btn-group btn-group-sm" role="group" aria-labelledby="gridRowsLabel">
                                        <button type="button" class="btn btn-outline-secondary" id="removeRowBtn"
                                                title="Remove the last row" aria-label="Remove a row">
                                            <i class="icon-base bx bx-minus icon-sm" aria-hidden="true"></i>
                                        </button>
                                        <input type="number" class="document-grid-card__count" id="gridRowCount"
                                               min="1" max="40" step="1" value="0" inputmode="numeric"
                                               aria-label="Number of rows" title="Type how many rows the table has">
                                        <button type="button" class="btn btn-outline-secondary" id="addRowBtn"
                                                title="Add a row line under the selected one, or a row at the bottom"
                                                aria-label="Add a row">
                                            <i class="icon-base bx bx-plus icon-sm" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                                {{-- A page scanned a little turned: the columns and row lines turn with it. --}}
                                <div class="document-grid-card__line" id="gridTiltLine">
                                    {{-- "Tilt" for the table; "Line 3" while row line 3 is selected: Tilt then turns that line alone. --}}
                                    <span class="document-grid-card__label" id="gridTiltLabel">Tilt</span>
                                    <div class="btn-group btn-group-sm" role="group" id="gridTiltGroup" aria-label="Tilt of the table">
                                        <button type="button" class="btn btn-outline-secondary" id="tiltLeftBtn"
                                                title="Turn the table anticlockwise by 0.1° (Shift: 1°)"
                                                aria-label="Turn the table anticlockwise">
                                            <i class="icon-base bx bx-rotate-left icon-sm" aria-hidden="true"></i>
                                        </button>
                                        <input type="text" class="document-grid-card__count" id="gridTiltValue"
                                               value="0.0°" inputmode="decimal" autocomplete="off"
                                               aria-label="How far the table is turned, in degrees"
                                               title="How far the table is turned. Snap to table measures it from the page.">
                                        <button type="button" class="btn btn-outline-secondary" id="tiltRightBtn"
                                                title="Turn the table clockwise by 0.1° (Shift: 1°)"
                                                aria-label="Turn the table clockwise">
                                            <i class="icon-base bx bx-rotate-right icon-sm" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                                {{-- Only when a row line has no printed line under it. --}}
                                <p class="document-grid-card__source d-none" id="gridSource" aria-live="polite"></p>
                            </div>
                        @endif

                        <div class="marker-field-bulk-actions">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" id="selectAllFields">
                                <label class="form-check-label" for="selectAllFields">Select all</label>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger marker-field-delete" id="deleteFieldsBtn" disabled>
                                <i class="icon-base bx bx-trash icon-sm me-1" aria-hidden="true"></i>
                                <span>Delete</span>
                            </button>
                        </div>

                        <ul class="list-unstyled marker-field-list" id="fieldList"></ul>

                        {{-- One name box: + Field reads it once, + Column on every row of the table. --}}
                        <div class="field-add">
                            <label class="visually-hidden" for="newFieldName">New field or column name</label>
                            <div class="input-group input-group-sm">
                                <input type="text" id="newFieldName" class="form-control"
                                       placeholder="{{ count($ruledYs) >= 2 ? 'New name' : 'e.g. Page No.' }}"
                                       maxlength="500">
                                <button class="btn btn-outline-primary" type="button" id="addFieldBtn"
                                        title="A field: one box, read once (e.g. Page No.). Drag it onto the value on the page">
                                    <i class="icon-base bx bx-plus icon-sm" aria-hidden="true"></i>Field
                                </button>
                                @if (count($ruledYs) >= 2)
                                    <button class="btn btn-outline-primary" type="button" id="addColumnBtn"
                                            title="A column down the table, read once per row (e.g. Remarks). It splits the selected column in two, or goes at the right of the table (with no columns, over the table)">
                                        <i class="icon-base bx bx-plus icon-sm" aria-hidden="true"></i>Column
                                    </button>
                                @endif
                            </div>
                            {{-- Why a field, column or row could not be added: here, where the buttons are. --}}
                            <p class="field-add__message text-danger small d-none" id="fieldAddMessage" role="alert"></p>
                        </div>
                    </div>
                </div>

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

                {{-- Filled in after Detect: what was found on this page. --}}
                <div class="detect-summary d-none" id="detectSummary" role="status" aria-live="polite">
                    <i class="icon-base bx bx-radar" aria-hidden="true"></i>
                    <div class="detect-summary__copy">
                        <strong id="detectSummaryTitle"></strong>
                        <span id="detectSummaryText"></span>
                        <ul class="grid-notes__list d-none" id="detectSummaryNotes"></ul>
                        <div class="form-check form-switch mb-0 mt-1">
                            <input class="form-check-input" type="checkbox" id="lineEditToggle">
                            <label class="form-check-label" for="lineEditToggle"
                                   title="Click a line on the page to fix its outline before it is read">Adjust line crops</label>
                        </div>
                    </div>
                </div>

                {{--
                    Line editing after Detect: click an outline on the page, stretch it
                    by its handles or grow/shrink it, and see the crop TrOCR will read.
                    Saved as you go; nothing is read until Scan with OCR.
                --}}
                <div class="line-adjust-card d-none" id="lineAdjustCard" aria-live="polite">
                    <div class="line-adjust-card__head">
                        <strong id="lineAdjustName">Click a line on the page</strong>
                        <span class="line-adjust-card__position" id="lineAdjustPosition"></span>
                    </div>
                    <p class="line-adjust-card__note d-none" id="lineAdjustNote"></p>
                    <div class="line-adjust-card__crop">
                        <canvas id="lineAdjustThumb" aria-label="What TrOCR will read for this line"></canvas>
                    </div>
                    <div class="btn-group btn-group-sm line-adjust-card__modes" role="radiogroup" aria-label="How to change the outline" id="lineModeGroup">
                        <input type="radio" class="btn-check" name="alignLineMode" id="lineModeBox" value="box" autocomplete="off" checked>
                        <label class="btn btn-outline-primary" for="lineModeBox" title="Stretch the outline by its handles">Stretch</label>
                        <input type="radio" class="btn-check" name="alignLineMode" id="lineModePoints" value="points" autocomplete="off">
                        <label class="btn btn-outline-primary" for="lineModePoints" title="Drag the outline's own corners">Points</label>
                        <input type="radio" class="btn-check" name="alignLineMode" id="lineModeDraw" value="draw" autocomplete="off">
                        <label class="btn btn-outline-primary" for="lineModeDraw" title="Draw a new outline round the writing">Draw</label>
                    </div>
                    <div class="line-adjust-card__draw d-none" id="lineDrawActions">
                        <button type="button" class="btn btn-sm btn-success" id="lineDrawUseBtn" disabled
                                title="Make this drawing the line's outline and crop (Enter)">
                            <i class="icon-base bx bx-check icon-sm me-1" aria-hidden="true"></i>Use this outline
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="lineDrawCancelBtn"
                                title="Throw the drawing away (Esc)">Cancel</button>
                    </div>
                    <div class="line-adjust-card__actions" id="lineAdjustActions">
                        <div class="btn-group btn-group-sm" role="group" aria-label="Outline size">
                            <button type="button" class="btn btn-outline-secondary" id="lineShrinkBtn" title="Shrink the outline (−)">
                                <i class="icon-base bx bx-minus icon-sm" aria-hidden="true"></i><span class="visually-hidden">Shrink</span>
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="lineGrowBtn" title="Grow the outline (+)">
                                <i class="icon-base bx bx-plus icon-sm" aria-hidden="true"></i><span class="visually-hidden">Grow</span>
                            </button>
                        </div>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Undo outline changes">
                            <button type="button" class="btn btn-outline-secondary" id="lineUndoBtn" title="Undo (Ctrl+Z)" disabled>
                                <i class="icon-base bx bx-undo icon-sm" aria-hidden="true"></i><span class="visually-hidden">Undo</span>
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="lineRedoBtn" title="Redo (Ctrl+Y)" disabled>
                                <i class="icon-base bx bx-redo icon-sm" aria-hidden="true"></i><span class="visually-hidden">Redo</span>
                            </button>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="lineResetBtn" disabled
                                title="Back to the outline Detect drew">
                            <i class="icon-base bx bx-refresh icon-sm me-1" aria-hidden="true"></i>Reset line
                        </button>
                    </div>
                    <div class="line-adjust-card__structure">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="lineAddBtn"
                                title="Draw a line the detector missed">
                            <i class="icon-base bx bx-plus icon-sm me-1" aria-hidden="true"></i>Add line
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="lineDeleteBtn" disabled
                                title="Remove this line (Delete). Ctrl+Z puts it back">
                            <i class="icon-base bx bx-trash icon-sm me-1" aria-hidden="true"></i>Delete line
                        </button>
                    </div>
                    <div class="line-adjust-card__save">
                        <button type="button" class="btn btn-sm btn-primary" id="lineSaveBtn" disabled
                                title="Make this outline the line's crop (Ctrl+S)">
                            <i class="icon-base bx bx-save icon-sm me-1" aria-hidden="true"></i>Save crop
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="lineDiscardBtn" disabled
                                title="Back to the last saved outline">Discard</button>
                    </div>
                    <span class="line-adjust-card__status" id="lineAdjustStatus"></span>
                    <small class="line-adjust-card__hint" id="lineAdjustHint"></small>
                </div>

                <div class="d-grid gap-2 field-marker-actions">
                    <button class="btn btn-outline-primary" type="button" id="detectBtn"
                            title="Straighten this page, find its table, and outline every handwritten line before reading">
                        <i class="icon-base bx bx-radar icon-sm me-1" aria-hidden="true"></i> Detect fields and ink
                    </button>
                    <button class="btn btn-primary btn-lg" type="button" id="scanNowBtn">
                        <i class="icon-base bx bx-scan icon-sm me-1"></i> Scan with OCR
                    </button>
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
            <input type="hidden" name="document_page_id" id="documentPageId">

            <div class="validation-workspace">
                <div class="validation-submit-error d-none" id="validationSubmitError"
                     role="alert" aria-live="assertive">
                    <i class="icon-base bx bx-error" aria-hidden="true"></i>
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

                            <div class="validation-pane__tools">
                                {{-- Writing the line detector missed: TrOCR reads a box drawn over it. --}}
                                <button type="button" class="btn btn-sm btn-outline-primary validation-add-field"
                                        id="addMissedFieldBtn" aria-pressed="false"
                                        title="Drag a box over writing that has no reading. TrOCR reads that box alone.">
                                    <i class="icon-base bx bx-plus icon-sm me-1" aria-hidden="true"></i><span>Add missed field</span>
                                </button>
                                <div class="btn-group marker-zoom-controls" role="group"
                                     aria-label="Original document zoom controls">
                                    <button type="button" class="btn btn-sm btn-icon btn-outline-secondary marker-tool-button" id="validationZoomOutBtn"
                                            aria-label="Zoom out">
                                        <i class="icon-base bx bx-minus icon-sm" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary marker-tool-button marker-zoom-value"
                                            id="validationZoomResetBtn" title="Fit document to panel">100%</button>
                                    <button type="button" class="btn btn-sm btn-icon btn-outline-secondary marker-tool-button" id="validationZoomInBtn"
                                            aria-label="Zoom in">
                                        <i class="icon-base bx bx-plus icon-sm" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>
                        </header>

                        {{--
                            Shown while Add missed field is on: draw one or more
                            boxes, then Read sends them to TrOCR (nothing is read before).
                        --}}
                        <div class="line-edit-toolbar add-field-toolbar d-none" id="addFieldToolbar" role="toolbar"
                             aria-label="Add missed field">
                            <div class="line-edit-toolbar__copy">
                                <strong>Add missed field</strong>
                            </div>
                            <div class="btn-group btn-group-sm" role="radiogroup" aria-label="How to mark the writing">
                                <input type="radio" class="btn-check" name="addFieldMode" id="addFieldRectangle"
                                       value="rectangle" checked>
                                <label class="btn btn-outline-secondary" for="addFieldRectangle" title="Drag a box over the writing">Rectangle</label>
                                <input type="radio" class="btn-check" name="addFieldMode" id="addFieldDraw" value="draw">
                                <label class="btn btn-outline-secondary" for="addFieldDraw"
                                       title="Click round the writing or hold and trace; Enter or double-click closes it">Draw</label>
                            </div>
                            <div class="line-edit-toolbar__actions">
                                <button type="button" class="btn btn-sm btn-text-secondary" id="addFieldCancel">Cancel</button>
                                <button type="button" class="btn btn-sm btn-primary" id="addFieldRead" disabled>
                                    <i class="icon-base bx bx-scan icon-sm me-1" aria-hidden="true"></i><span>Read boxes</span>
                                </button>
                            </div>
                            <div class="line-edit-toolbar__status" id="addFieldStatus" role="status" aria-live="polite"></div>
                        </div>

                        {{-- Shown while a reviewer redraws one line's outline. --}}
                        <div class="line-edit-toolbar d-none" id="lineEditToolbar" role="toolbar"
                             aria-label="Adjust outline">
                            <div class="line-edit-toolbar__copy">
                                <span class="line-edit-toolbar__eyebrow">Adjust outline</span>
                                <strong id="lineEditName"></strong>
                            </div>
                            <div class="btn-group btn-group-sm" role="group" aria-label="Outline tool">
                                <input type="radio" class="btn-check" name="verifyLineMode" id="lineEditBox"
                                       value="box" checked>
                                <label class="btn btn-outline-secondary" for="lineEditBox" title="Stretch the outline by its handles">Stretch</label>
                                <input type="radio" class="btn-check" name="verifyLineMode" id="lineEditPoints"
                                       value="points">
                                <label class="btn btn-outline-secondary" for="lineEditPoints"
                                       title="Drag corners; drag a dot between corners to add one; Delete removes one">Move points</label>
                                <input type="radio" class="btn-check" name="verifyLineMode" id="lineEditDraw"
                                       value="draw">
                                <label class="btn btn-outline-secondary" for="lineEditDraw"
                                       title="Draw a new outline: click points or hold and trace; Enter or double-click closes it">Draw</label>
                                <input type="radio" class="btn-check" name="verifyLineMode" id="lineEditRectangle"
                                       value="rectangle">
                                <label class="btn btn-outline-secondary" for="lineEditRectangle" title="Drag a box over the writing">Rectangle</label>
                            </div>
                            <div class="line-edit-toolbar__actions">
                                <button type="button" class="btn btn-sm btn-text-secondary" id="lineEditCancel">Cancel</button>
                                <button type="button" class="btn btn-sm btn-primary" id="lineEditSave">
                                    <i class="icon-base bx bx-check icon-sm me-1" aria-hidden="true"></i>Save and re-read
                                </button>
                            </div>
                            <div class="line-edit-toolbar__status" id="lineEditStatus" role="status" aria-live="polite"></div>
                        </div>

                        <div class="doc-viewport validation-doc-viewport" id="validationDocViewport">
                            <div class="doc-stage" id="validationDocStage">
                                <canvas id="validationPageCanvas"></canvas>
                                <div class="field-overlay validation-field-overlay"
                                     id="validationFieldOverlay"></div>
                            </div>
                        </div>

                        <footer class="validation-pane__hint">
                            <i class="icon-base bx bx-scan" aria-hidden="true"></i>
                            Orange shows the complete person row; green identifies the exact field;
                            red outlines need review.
                            Hold <kbd>Ctrl</kbd> (<kbd>⌘</kbd> on a Mac) and scroll to zoom or drag to move.
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

                            <div class="validation-output-summary">
                                <div class="validation-model-summary" aria-label="OCR summary">
                                    <span><small>Model</small><code id="summaryModel">&mdash;</code></span>
                                    <span><small>Confidence</small><strong id="summaryConfidence">&mdash;</strong></span>
                                    <span><small>Review</small><strong id="summaryReview">&mdash;</strong></span>
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
                            </div>
                        </header>

                        {{-- Filled in when the page job has something to say about the grid as a whole. --}}
                        <div class="grid-notes d-none" id="verifyNotes" role="status" aria-live="polite">
                            <i class="icon-base bx bx-info-circle" aria-hidden="true"></i>
                            <div>
                                <strong>Check the grid before you verify</strong>
                                <ul class="grid-notes__list" id="verifyNotesList"></ul>
                            </div>
                        </div>

                        <div class="validation-record-list-heading">
                            <div>
                                <i class="icon-base bx bx-list-check" aria-hidden="true"></i>
                                <span>Registry records</span>
                            </div>
                            <small>Select a person to compare the complete row.</small>
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
                                    {{-- One inline span: .btn is a flex container, which drops the
                                         spaces between separate text and element children. --}}
                                    <span>Submit <span id="submitVerifiedCount">0</span> verified</span>
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
                        Restore all field markers and row lines to the positions and sizes configured in the original template?
                    </p>
                    <p class="small text-muted mb-0">
                        Added or copied fields, columns and rows will be removed, and deleted ones will be restored.
                    </p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">
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

    {{-- Confirm submitting a record that leaves required fields out --}}
    <div class="modal fade" id="missingRequiredModal" tabindex="-1"
         aria-labelledby="missingRequiredModalTitle" aria-describedby="missingRequiredModalDescription"
         aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable missing-required-dialog">
            <div class="modal-content missing-required-modal">
                <div class="modal-header">
                    <div class="missing-required-modal__heading">
                        <span class="missing-required-modal__icon bg-label-warning" aria-hidden="true">
                            <i class="icon-base bx bx-error"></i>
                        </span>
                        <div>
                            <h5 class="modal-title" id="missingRequiredModalTitle">Submit without required fields?</h5>
                            <p class="missing-required-modal__summary" id="missingRequiredModalDescription">
                                The record would be saved without the following.
                            </p>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <ul class="missing-required-list" id="missingRequiredList"></ul>
                    <p class="missing-required-modal__note">
                        <i class="icon-base bx bx-history" aria-hidden="true"></i>
                        <span>Submit anyway only if the page really leaves them blank. The audit log records what was left out.</span>
                    </p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"
                            id="missingRequiredBackBtn">
                        <i class="icon-base bx bx-chevron-left icon-sm me-1" aria-hidden="true"></i>
                        Go back
                    </button>
                    <button type="button" class="btn btn-warning" id="confirmMissingRequiredBtn">
                        Submit anyway
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Confirm leaving a scan that is not submitted (Cancel, or a link out) --}}
    <div class="modal fade" id="leaveScanModal" tabindex="-1"
         aria-labelledby="leaveScanModalTitle" aria-describedby="leaveScanModalDescription"
         aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered leave-scan-dialog">
            <div class="modal-content leave-scan-modal">
                <div class="modal-body">
                    <span class="leave-scan-modal__icon bg-label-warning" aria-hidden="true">
                        <i class="icon-base bx bx-error"></i>
                    </span>
                    <h5 class="modal-title" id="leaveScanModalTitle">Leave this scan?</h5>
                    <p class="leave-scan-modal__text" id="leaveScanModalDescription">
                        Your markers and checked values are not saved yet.
                    </p>

                    <div class="leave-scan-modal__actions">
                        <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"
                                id="leaveScanStayBtn">
                            Stay
                        </button>
                        <button type="button" class="btn btn-danger" id="confirmLeaveScanBtn">
                            Leave
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
                <div class="modal-body p-4 p-sm-5 pb-sm-4">
                    <div class="ocr-progress-ring mx-auto mb-4" id="ocrProgressRing"
                         role="progressbar" aria-label="OCR progress" aria-valuemin="0"
                         aria-valuemax="100" aria-valuenow="0">
                        <div class="ocr-progress-ring__center">
                            <strong id="ocrProgressValue">0%</strong>
                            <small>estimated</small>
                        </div>
                    </div>

                    <h5 class="mb-2" id="ocrProgressTitle">Preparing document</h5>
                    <p class="small text-muted mb-3 ocr-progress-note" id="scanningNote" aria-live="polite">
                        Creating clear image crops for each marked field.
                    </p>

                    <div class="ocr-progress-fields">
                        <i class="icon-base bx bx-scan" aria-hidden="true"></i>
                        <span id="ocrProgressFields">Preparing marked fields</span>
                    </div>
                </div>
                {{-- Stops this run and returns to Align; the label names what is running. --}}
                <div class="ocr-progress-actions">
                    <button type="button" class="btn btn-danger w-100" id="ocrProgressCancel">Cancel</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script type="module">
    import {
        canVerifyValue,
        FieldMarker,
        markerAngleMetadata,
        markerColumnMetadata,
        markerPersonMetadata,
        verificationGroupState,
    } from '{{ Vite::asset('resources/js/field-marker.js') }}';
    import { attachMarqueeSelection } from '{{ Vite::asset('resources/js/marquee-selection.js') }}';
    import { setDisclosureExpanded } from '{{ Vite::asset('resources/js/disclosure-motion.js') }}';
    import {
        alignedGeometry,
        detectionSummary,
        flagExplanation,
        FLAG_NO_ROW,
        geometryMarkers,
        SOURCE_MANUAL,
        gridNotes,
        snapRowsMessage,
        verificationItems,
    } from '{{ Vite::asset('resources/js/line-geometry.js') }}';
    import { LineOverlay, polygonBounds } from '{{ Vite::asset('resources/js/line-overlay.js') }}';
    import {
        addColumn,
        clampRowAngle,
        clampRuledY,
        columnBand,
        fieldsToColumns,
        fitTilts,
        followFirstAndLastLine,
        gridTilt,
        insertRuledY,
        insertTilt,
        distributeRuledYs,
        remapRuledYs,
        respaceTilts,
        sameBand,
        tiltColumns,
    } from '{{ Vite::asset('resources/js/ledger-grid.js') }}';
    import { RowLineLayer, rowKey } from '{{ Vite::asset('resources/js/row-lines.js') }}';
    import { valueProblem } from '{{ Vite::asset('resources/js/value-types.js') }}';

    const config = {
        typeLabel: @json($docType->label()),
        boxes: @json($boxes),
        ruledYs: @json($ruledYs),
        templateId: @json($template->getKey()),
        groupingMode: @json(
            $template->grouping_mode instanceof \BackedEnum
                ? $template->grouping_mode->value
                : ($template->grouping_mode ?? 'auto')
        ),
        threshold: @json($threshold),
        maxFields: @json(App\Support\Limits::MAX_FIELDS),
        maxFieldNameLength: 500,
        pagesUrl: @json(route('documents.pages.store')),
        pageGeometryUrl: @json(route('documents.pages.reoutline', ['page' => '__PAGE__'])),
        snapUrl: @json(route('documents.pages.snap')),
        lineUpdateUrl: @json(route('documents.pages.lines.update', ['page' => '__PAGE__', 'line' => '__LINE__'])),
        lineDeleteUrl: @json(route('documents.pages.lines.destroy', ['page' => '__PAGE__', 'line' => '__LINE__'])),
        lineRestoreUrl: @json(route('documents.pages.lines.restore', ['page' => '__PAGE__', 'line' => '__LINE__'])),
        lineStoreUrl: @json(route('documents.pages.lines.store', ['page' => '__PAGE__'])),
        linePlaceUrl: @json(route('documents.pages.lines.place', ['page' => '__PAGE__', 'line' => '__LINE__'])),
        pageCancelUrl: @json(route('documents.pages.cancel', ['page' => '__PAGE__'])),
        csrf: @json(csrf_token()),
        // The layout's page shape (width : height), from its sample.
        pageRatio: @json($template->page_ratio),
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
    // One entry per outlined line of the processed page (see verificationItems).
    let cropped = [];
    let readings = [];
    let processedPage = null;
    let editingLineIndex = null;
    // Verify's Add missed field: a box is being drawn, or is being read.
    let addingMissedField = false;
    let fieldHistory = [];
    let currentFieldSnapshot = null;
    let restoringFieldHistory = false;
    let markerClipboard = [];
    let pasteSequence = 0;
    let activeValidationIndex = null;
    let activeValidationGroupId = null;
    let validationGroups = [];
    let validationGroupByField = new Map();
    let validationRowByIndex = new Map();
    let syncingValidationSelection = false;
    let recordSubmitting = false;
    let markerConstraintMessage = null;

    const markerOverlay = el('fieldOverlay');
    const marker = new FieldMarker({
        canvas: el('pageCanvas'),
        overlay: markerOverlay,
        viewport: el('docViewport'),
        onChange: handleMarkerChange,
        onSelectionChange: updateSelectionUI,
        onZoomChange: updateZoomUI,
        // While the table is dragged, its row lines are drawn moving with it.
        onDrag: (boxes) => previewRowsFollow(boxes),
    });

    attachMarqueeSelection({
        marker,
        overlay: markerOverlay,
        marquee: el('staffFieldSelectionMarquee'),
    });

    // Zoom and pan for the Verify page. It holds no boxes: the outlines are
    // drawn by lineOverlay, one SVG stretched over the same canvas.
    const validationMarker = new FieldMarker({
        canvas: el('validationPageCanvas'),
        overlay: el('validationFieldOverlay'),
        viewport: el('validationDocViewport'),
        readOnly: true,
        onSelectionChange: handleValidationMarkerSelection,
        onZoomChange: updateValidationZoomUI,
    });

    const lineOverlay = new LineOverlay({
        container: el('validationFieldOverlay'),
        onSelect: (index) => activateValidationField(index, 'marker'),
        onModeChange: (mode) => showVerifyLineMode(mode),
        onDraftChange: (points) => (addingMissedField ? showAddFieldDraft(points) : showVerifyDraft(points)),
        // Only a new box (Add missed field) arrives here finished and with no
        // line being edited; a redrawn outline is saved by Save and re-read.
        onEdit: (polygon, final) => {
            if (final && addingMissedField && !lineOverlay.editing) addPendingBox(polygon);
        },
    });

    // Detect's preview in the Align step: outlines and fitted row lines over
    // the page, read-only so the markers underneath stay draggable.
    const alignPreview = new LineOverlay({
        container: markerOverlay,
        preview: true,
        onSelect: (index) => selectLine(index),
        onBackground: () => selectLine(null),
        onEdit: (polygon, final) => lineEdited(polygon, final),
        onModeChange: (mode) => showLineMode(mode),
        onDraftChange: (points) => showDraft(points),
        selectWhileEditing: true,
    });

    // What Detect found on this page and the markers it fitted. Scan with OCR
    // reads Detect's crops only while the markers are still exactly these;
    // once Staff move one, the page is outlined again from their markers.
    let detection = null;

    const cloneBoxes = (boxes) => boxes.map(({
        name, x, y, w, h, personGroup, personFieldOrder, kind, columnIndex, angle,
    }) => ({
        name,
        x,
        y,
        w,
        h,
        ...markerPersonMetadata({ personGroup, personFieldOrder }),
        ...markerColumnMetadata({ kind, columnIndex }),
        ...markerAngleMetadata({ angle }),
    }));
    const templateBoxes = config.boxes.map((box) => ({
        name: box.name,
        x: +box.x,
        y: +box.y,
        w: +box.w,
        h: +box.h,
        ...markerPersonMetadata(box),
        ...markerColumnMetadata(box),
        ...markerAngleMetadata(box),
    }));
    const templateColumns = templateBoxes.filter((box) => box.kind === 'column');

    // What each template field and ledger column holds and how its value is
    // checked (Template Builder settings), by name: a line is read under its
    // field's or column's name.
    const nameKey = (name) => String(name ?? '').trim().toLowerCase();
    const templateSettings = new Map(config.boxes.map((box) => [nameKey(box.name), box]));
    const settingsFor = (index) => templateSettings.get(nameKey(cropped[index]?.label)) ?? null;
    // The template's own rectangle fields: never turned into columns.
    const templateFieldNames = new Set(config.boxes.filter((box) => box.kind !== 'column').map((box) => nameKey(box.name)));

    // ------------------------------------------------------------ ledger rows
    // The ledger's row lines on this page (page fractions, top to bottom). They
    // start as the template's and go with the columns when the table is moved
    // or stretched; Snap to table takes them from the page; Staff can move,
    // add or remove them. Nothing here changes the template.
    // As the Rows and Columns card is shown: the template has columns and row lines.
    const isLedger = config.ruledYs.length >= 2;
    const templateRows = config.ruledYs.map(Number);
    let gridRows = [...templateRows];
    // How many degrees each row line is turned beyond the table's own tilt
    // (one per line, in step with gridRows): paper is curved, and no two
    // printed rules run quite alike. Staff set one by selecting its line and
    // using Tilt; Snap to table, Detect and Reset start every line at 0.
    let rowTilts = templateRows.map(() => 0);
    // Row lines with no printed line under them (rowKey), drawn amber.
    let estimatedRows = new Set();
    // Staff moved, added or removed a row line themselves: Detect keeps theirs.
    let rowsByHand = false;
    // What the last Snap to table said about where the rows came from.
    let rowsSource = null;
    let selectedRow = null;
    // The columns' band and count the rows were last placed in.
    let gridBand = null;
    let gridColumnCount = 0;
    // Markers and rows are being set together: the rows are not carried along again.
    let settingGrid = false;

    const rowLines = new RowLineLayer({
        overlay: markerOverlay,
        printedLines: () => marker.snapLines,
        onSelect: (index) => selectRow(index),
        onMove: (rows, change) => moveRows(rows, change),
        onRemove: (index) => removeRow(index),
    });

    const ledgerColumns = (boxes) => boxes.filter((box) => box.kind === 'column');

    function fieldsMatchTemplate() {
        return JSON.stringify(marker.toJSON()) === JSON.stringify(templateBoxes)
            && JSON.stringify(gridRows) === JSON.stringify(templateRows)
            && rowTilts.every((tilt) => tilt === 0);
    }

    /** Markers and row lines, as one undoable state. */
    function gridState() {
        return {
            boxes: cloneBoxes(marker.toJSON()),
            rows: [...gridRows],
            tilts: [...rowTilts],
            estimated: [...estimatedRows],
            byHand: rowsByHand,
        };
    }

    /** The markers and row lines as one string, to tell one grid from another. */
    function gridSnapshot() {
        return JSON.stringify({ boxes: marker.toJSON(), rows: gridRows, tilts: rowTilts });
    }

    function rememberBand(boxes) {
        const columns = ledgerColumns(boxes);
        gridBand = columnBand(columns);
        gridColumnCount = columns.length;
    }

    /**
     * The columns were moved or stretched as a table (their band changed): the
     * row lines go with them. A column added or removed only resets the band.
     */
    function followColumns(boxes) {
        const columns = ledgerColumns(boxes);
        const band = columnBand(columns);
        if (gridBand && band && columns.length === gridColumnCount && gridRows.length >= 2
            && !sameBand(band, gridBand)) {
            const from = gridBand;
            const lineCount = gridRows.length;
            gridRows = remapRuledYs(gridRows, from, band);
            // A line squeezed off the page by the move would put the tilts out of step.
            if (gridRows.length !== lineCount) rowTilts = gridRows.map(() => 0);
            estimatedRows = new Set([...estimatedRows].map((key) => rowKey(remapRuledYs([Number(key)], from, band)[0])));
        }
        rememberBand(boxes);
    }

    function previewRowsFollow(boxes) {
        const columns = ledgerColumns(boxes);
        const band = columnBand(columns);
        if (!gridBand || !band || columns.length !== gridColumnCount || gridRows.length < 2 || sameBand(band, gridBand)) {
            // A column being turned moves no band: the lines only take its tilt.
            renderRows(boxes);
            return;
        }
        const moved = (ys) => remapRuledYs(ys, gridBand, band);
        renderRows(boxes, moved(gridRows), new Set([...estimatedRows].map((key) => rowKey(moved([Number(key)])[0]))));
    }

    /** The page's width over its height in pixels: a tilt is an angle in pixels. */
    function pageAspect() {
        const { width, height } = marker.canvas;
        return width > 0 && height > 0 ? width / height : 1;
    }

    function renderRows(boxes = marker.toJSON(), rows = gridRows, estimated = estimatedRows) {
        const columns = ledgerColumns(boxes);
        if (!isLedger || columns.length === 0 || rows.length < 2) {
            rowLines.render({ rows: [] });
            return;
        }
        rowLines.render({
            rows,
            left: Math.min(...columns.map((column) => column.x)),
            right: Math.max(...columns.map((column) => column.x + column.w)),
            selected: selectedRow,
            estimated,
            locked: lineEditing,
            // On a page scanned a little turned, the lines turn with the columns.
            tilt: gridTilt(columns),
            tilts: rowTilts,
            aspect: pageAspect(),
        });
    }

    /**
     * Markers and row lines set together, as one change: on a new page, by
     * Snap to table and Detect, by Reset, and by undo.
     */
    function setGrid(boxes, rows, { estimated = [], byHand = false, tilts = null, selected = null } = {}) {
        gridRows = [...rows];
        rowTilts = tilts ? fitTilts(tilts, gridRows.length) : gridRows.map(() => 0);
        estimatedRows = new Set(estimated);
        rowsByHand = byHand;
        // The row line that stays selected, if any (the table's tilt keeps it).
        selectedRow = Number.isInteger(selected) && selected >= 0 && selected < gridRows.length ? selected : null;
        settingGrid = true;
        try {
            marker.setBoxes(cloneBoxes(boxes));
        } finally {
            settingGrid = false;
        }
    }

    /** Record the grid as it now stands, so Ctrl+Z can return to the one before. */
    function recordGridChange() {
        const next = gridState();
        if (!restoringFieldHistory && currentFieldSnapshot !== null
            && JSON.stringify(next) !== JSON.stringify(currentFieldSnapshot)) {
            fieldHistory.push(currentFieldSnapshot);
            if (fieldHistory.length > 100) fieldHistory.shift();
        }
        currentFieldSnapshot = next;
    }

    /** After the row lines alone changed. */
    function rowsChanged() {
        recordGridChange();
        renderRows();
        updateGridCard();
        updateResetUI();
        syncDetection();
    }

    /**
     * Row lines changed by hand, as one undoable change. Columns on the first or
     * last line follow it, so the table's top and bottom stay on its lines.
     */
    function applyRows(next, { estimated = estimatedRows, tilts = rowTilts } = {}) {
        // A line almost on top of the one above it is dropped, with its tilt.
        const kept = next.map((y, index, all) => index === 0 || y - all[index - 1] > 0.0005);
        const rows = next.filter((_, index) => kept[index]);
        const nextTilts = fitTilts(tilts, next.length).filter((_, index) => kept[index]);
        const boxes = marker.toJSON();
        const halfRow = rows.length >= 2 ? (rows[rows.length - 1] - rows[0]) / (rows.length - 1) / 2 : 0.01;
        const followed = followFirstAndLastLine(boxes, gridRows, rows, halfRow);
        gridRows = rows;
        rowTilts = nextTilts;
        estimatedRows = new Set([...estimated].filter((key) => rows.some((y) => rowKey(y) === key)));
        rowsByHand = true;
        if (selectedRow !== null && selectedRow >= rows.length) selectedRow = null;

        if (followed === boxes) {
            rowsChanged();
            return;
        }
        const selected = selectedRow;
        settingGrid = true;
        try {
            marker.setBoxes(cloneBoxes(followed));
        } finally {
            settingGrid = false;
        }
        selectedRow = selected;
        renderRows();
        updateGridCard();
    }

    function moveRows(rows, { index, together }) {
        const dy = rows[0] - gridRows[0];
        // A line placed by hand is no longer an estimate.
        const estimated = together
            ? new Set([...estimatedRows].map((key) => rowKey(Number(key) + dy)))
            : new Set([...estimatedRows].filter((key) => key !== rowKey(gridRows[index])));
        applyRows(rows, { estimated });
    }

    /** The row line the arrow keys and Delete act on (null: none). Never together with markers. */
    function selectRow(index) {
        selectedRow = Number.isInteger(index) && index >= 0 && index < gridRows.length ? index : null;
        if (selectedRow !== null) marker.clearSelection();
        renderRows();
        updateGridCard();
    }

    /** Remove a row line: the selected one, else the last. A ledger keeps at least two. */
    function removeRow(index = selectedRow ?? gridRows.length - 1) {
        if (!isLedger || gridRows.length <= 2 || !Number.isInteger(index) || index < 0 || index >= gridRows.length) return;
        selectedRow = null;
        applyRows(gridRows.filter((_, candidate) => candidate !== index),
            { tilts: rowTilts.filter((_, candidate) => candidate !== index) });
    }

    /**
     * Why a field, column or row could not be added, under the name box in the
     * Fields card: further down, it was out of sight, and the button seemed to
     * do nothing. null clears it.
     */
    function showGridMessage(message) {
        el('fieldAddMessage').textContent = message ?? '';
        el('fieldAddMessage').classList.toggle('d-none', !message);
    }

    /** A row line under the selected one (half way to the next), or a row at the bottom. */
    function addRow() {
        if (gridRows.length - 1 >= MAX_ROWS) {
            showGridMessage(`You can have at most ${MAX_ROWS} rows.`);
            return;
        }
        let inserted = insertRuledY(gridRows, selectedRow);
        // The table already reaches the page's bottom: split the last row instead.
        if (!inserted && selectedRow === null && gridRows.length >= 2) inserted = insertRuledY(gridRows, gridRows.length - 2);
        if (!inserted) {
            showGridMessage(selectedRow === null || selectedRow >= gridRows.length - 1
                ? 'There is no room for another row below the last line: the page ends there.'
                : 'There is no room for another row between these two lines.');
            return;
        }
        showGridMessage(null);
        // The new line is turned like the two it lies between.
        applyRows(inserted.ys, { tilts: insertTilt(rowTilts, inserted.index) });
        selectRow(inserted.index);
    }

    // ------------------------------------------------------------ table tilt
    // A page scanned a little turned: the table's columns and row lines turn
    // with it, in tenths of a degree. Snap to table measures it from the page;
    // Scan with OCR then straightens the page by it before reading.
    const MAX_TILT = 10;

    function formatTilt(degrees) {
        const value = Math.round(degrees * 10) / 10;
        return `${(value === 0 ? 0 : value).toFixed(1).replace('-', '−')}°`;
    }

    function parseTilt(text) {
        const value = Number.parseFloat(String(text).replace(/[−–]/g, '-').replace(',', '.').replace(/[^0-9.+-]/g, ''));
        return Number.isFinite(value) ? value : null;
    }

    const tenths = (value) => Math.round(value * 10) / 10;
    const limitTilt = (degrees) => tenths(Math.min(MAX_TILT, Math.max(-MAX_TILT, Number(degrees) || 0)));

    /**
     * The tilt the Tilt control shows and changes: the selected row line's
     * own (the table's and its extra turn), or the table's when no line is
     * selected.
     */
    function shownTilt(boxes = marker.toJSON()) {
        const table = gridTilt(ledgerColumns(boxes));
        return selectedRow === null ? table : table + (rowTilts[selectedRow] ?? 0);
    }

    /** Turn the table to `degrees` clockwise, as one undoable change. */
    function setTableTilt(degrees) {
        const boxes = marker.toJSON();
        const turned = tiltColumns(boxes, limitTilt(degrees) - gridTilt(ledgerColumns(boxes)), pageAspect());
        if (turned === boxes) {
            updateGridCard();
            return;
        }
        const selected = marker.selectedIndexes();
        // Every line turns with the table, keeping its own extra turn; the selected one stays selected.
        setGrid(turned, gridRows, {
            estimated: [...estimatedRows], byHand: rowsByHand, tilts: rowTilts, selected: selectedRow,
        });
        marker.selectIndexes(selected);
    }

    /**
     * Turn one row line to `degrees` clockwise, as one undoable change, and
     * leave it selected. The others stay as they are. A line stops short of
     * touching its neighbours or leaving the page.
     */
    function setRowTilt(index, degrees) {
        if (!Number.isInteger(index) || index < 0 || index >= gridRows.length) return;
        const columns = ledgerColumns(marker.toJSON());
        if (columns.length === 0) return;

        const table = gridTilt(columns);
        const wanted = limitTilt(degrees);
        const allowed = clampRowAngle(gridRows, rowTilts.map((own) => table + own), index, wanted, {
            left: Math.min(...columns.map((column) => column.x)),
            right: Math.max(...columns.map((column) => column.x + column.w)),
            aspect: pageAspect(),
        });
        showGridMessage(allowed === wanted ? null : 'This row line cannot turn any further: it would touch the next one.');

        const own = tenths(allowed - table);
        if (own === rowTilts[index]) {
            updateGridCard();
            return;
        }
        rowTilts = rowTilts.map((tilt, line) => (line === index ? own : tilt));
        // A line turned by hand is one Detect keeps, like a line moved by hand.
        rowsByHand = true;
        rowsChanged();
    }

    /** What the Tilt control does: the selected row line alone, else the whole table. */
    function setTilt(degrees) {
        if (selectedRow === null) setTableTilt(degrees);
        else setRowTilt(selectedRow, degrees);
    }

    const MAX_ROWS = 40;

    /** Set the number of rows to a typed value: add rows at the bottom or remove from the end. */
    function setRowCount(wanted) {
        if (!isLedger || !Number.isInteger(wanted) || wanted < 1) {
            updateGridCard();
            return;
        }
        let ys = [...gridRows];
        let tilts = [...rowTilts];
        let stopped = false;
        wanted = Math.min(wanted, MAX_ROWS);
        while (ys.length - 1 < wanted && !stopped) {
            const inserted = insertRuledY(ys, null);
            if (inserted) {
                tilts = insertTilt(tilts, inserted.index);
                ys = inserted.ys;
            } else {
                stopped = true;
            }
        }
        if (stopped) {
            // No room below the last line: space the rows evenly between the first and last line.
            const even = distributeRuledYs(gridRows, wanted);
            if (even) {
                ys = even;
                tilts = respaceTilts(rowTilts, even.length);
                stopped = false;
            }
        }
        if (ys.length - 1 > wanted) {
            ys = ys.slice(0, wanted + 1);
            tilts = tilts.slice(0, wanted + 1);
        }
        showGridMessage(stopped ? 'There is no room for more rows: they would be too thin.' : null);
        if (ys.length !== gridRows.length) {
            selectedRow = null;
            applyRows(ys, { tilts });
        } else {
            updateGridCard();
        }
    }

    function nudgeRow(pixels) {
        if (selectedRow === null) return;
        const index = selectedRow;
        const height = Math.max(1, markerOverlay.getBoundingClientRect().height);
        const y = clampRuledY(gridRows, index, gridRows[index] + pixels / height);
        if (Math.abs(y - gridRows[index]) < 1e-9) return;
        moveRows(gridRows.map((value, i) => (i === index ? y : value)), { index, together: false });
    }

    /**
     * A column Staff add for this page: it splits the selected column, or goes
     * at the right of the table. Its cells are read under the name they give it.
     */
    function addLedgerColumn() {
        const input = el('newFieldName');
        const name = input.value.trim();
        const boxes = marker.toJSON();
        if (!name) {
            showGridMessage('Type the new column’s name in the box first.');
            input.focus();
            return;
        }
        if (name.length > config.maxFieldNameLength) {
            showGridMessage(`Column names must not exceed ${config.maxFieldNameLength} characters.`);
            return;
        }
        if (boxes.some((box) => box.name.trim().toLocaleLowerCase() === name.toLocaleLowerCase())) {
            showGridMessage('Every field and column needs its own name. Choose a different name.');
            return;
        }
        if (boxes.length >= config.maxFields) {
            showGridMessage(`You can use at most ${config.maxFields} fields. Delete a marker before adding a column.`);
            return;
        }

        const indexes = ledgerColumns(boxes).map((box) => Number(box.columnIndex)).filter(Number.isInteger);
        const selected = marker.selectedIndexes();
        const added = addColumn(boxes, { name, kind: 'column', columnIndex: Math.max(-1, ...indexes) + 1 },
            selected.length === 1 ? selected[0] : null, firstColumnPlace());
        if (!added) {
            showGridMessage('That column is too narrow to split. Select a wider column, or make room at the right of the table.');
            return;
        }

        marker.setBoxes(cloneBoxes(added.boxes));
        marker.selectBox(added.index);
        input.value = '';
        showGridMessage(null);
    }

    /**
     * Where a column goes when the table has none left: over the table's rows,
     * at the left of the template's table and a typical column wide. Staff
     * then drag it into place and add its rows.
     */
    function firstColumnPlace() {
        const top = gridRows[0];
        const bottom = gridRows[gridRows.length - 1];
        const x = templateColumns.length > 0 ? Math.min(...templateColumns.map((column) => column.x)) : 0.05;
        const w = templateColumns.length > 0 ? median(templateColumns.map((column) => column.w)) : 0.15;
        return { x, y: top, w: Math.min(w, 1 - x), h: bottom - top };
    }

    /**
     * The Rows line in the Fields card: how many rows, and a note only when
     * there is something to check (amber lines, or Snap kept the template's rows).
     */
    function updateGridCard(boxes = marker.toJSON()) {
        if (!isLedger) return;
        const columns = ledgerColumns(boxes).length;
        const rowCountInput = el('gridRowCount');
        if (document.activeElement !== rowCountInput) rowCountInput.value = String(Math.max(0, gridRows.length - 1));
        rowCountInput.disabled = columns === 0;

        // Tilt turns the selected row line alone, else the whole table.
        const line = selectedRow !== null && selectedRow < gridRows.length ? selectedRow : null;
        const subject = line === null ? 'the table' : `row line ${line + 1}`;
        const tiltInput = el('gridTiltValue');
        if (document.activeElement !== tiltInput) tiltInput.value = formatTilt(shownTilt(boxes));
        tiltInput.disabled = columns === 0;
        tiltInput.title = line === null
            ? 'How far the table is turned. Snap to table measures it from the page.'
            : `How far row line ${line + 1} is turned. Click the page to turn the whole table instead.`;
        el('tiltLeftBtn').disabled = columns === 0;
        el('tiltRightBtn').disabled = columns === 0;
        el('tiltLeftBtn').title = `Turn ${subject} anticlockwise by 0.1° (Shift: 1°)`;
        el('tiltRightBtn').title = `Turn ${subject} clockwise by 0.1° (Shift: 1°)`;
        el('gridTiltLine').classList.toggle('is-row-scope', line !== null);
        el('gridTiltGroup').setAttribute('aria-label', `Tilt of ${subject}`);
        el('gridTiltLabel').textContent = line === null ? 'Tilt' : `Line ${line + 1}`;

        const message = !rowsByHand && rowsSource && !rowsSource.from_page
            // Compared with the template itself, not the grid Snap was sent.
            ? snapRowsMessage({ ...rowsSource, template: templateRows.length - 1 }, estimatedRows.size)
            : snapRowsMessage(null, estimatedRows.size);
        el('gridSource').textContent = message;
        el('gridSource').classList.toggle('d-none', message === '');

        el('addRowBtn').disabled = columns === 0;
        el('removeRowBtn').disabled = columns === 0 || gridRows.length <= 2;
        el('removeRowBtn').title = selectedRow !== null
            ? `Remove row line ${selectedRow + 1} (Delete)`
            : 'Remove the last row';
    }

    function updateResetUI() {
        const layoutChanged = !fieldsMatchTemplate();
        const zoomChanged = Math.abs(marker.zoom - 1) > 0.001;
        el('resetFieldsBtn').disabled = !layoutChanged && !zoomChanged;
    }

    function handleMarkerChange(boxes) {
        // A field Staff stretched down two or more rows of the table is read row
        // by row, as a column. Setting the markers again runs this once more,
        // with the columns, so the move and the change are one undo step.
        if (!settingGrid && isLedger) {
            const asColumns = fieldsToColumns(boxes, gridRows, (box) => templateFieldNames.has(nameKey(box.name)));
            if (asColumns !== boxes) {
                const selected = marker.selectedIndexes();
                marker.setBoxes(cloneBoxes(asColumns));
                marker.selectIndexes(selected);
                return;
            }
        }

        // The rows go with the table, unless they were just set with the markers.
        if (settingGrid) rememberBand(boxes);
        else followColumns(boxes);

        recordGridChange();
        renderFieldList(boxes);
        renderRows(boxes);
        updateGridCard(boxes);
        updateResetUI();
        syncDetection();
    }

    // ------------------------------------------------------------- detection
    // Detect's outlines were split at, and placed by, the markers as Detect
    // left them. Moving a marker sets the result aside (Scan with OCR then
    // outlines the page afresh); undoing back to those markers brings it back,
    // line adjustments included. `stale` is for good: the detected page can no
    // longer be read (a failed read, a cancelled run).
    function detectionIsCurrent() {
        return detection !== null && !detection.stale && gridSnapshot() === detection.snapshot;
    }

    function syncDetection() {
        if (!detection || detection.stale) return;
        const current = gridSnapshot() === detection.snapshot;
        if (!current && !detection.setAside) setDetectionAside();
        else if (current && detection.setAside) restoreDetection();
    }

    function setDetectionAside() {
        const changed = detection.page.lines.filter((line) => line.adjusted || isLineUnsaved(line)).length;
        setLineEditing(false);
        detection.setAside = true;
        alignPreview.setVisible(false);
        el('lineEditToggle').disabled = true;
        el('detectSummaryTitle').textContent = 'Markers or rows moved after Detect';
        // Outlining again reuses the lines Detect found on this page, so it
        // takes seconds; only the adjusted outlines cannot come with it.
        el('detectSummaryText').textContent = 'Scan with OCR will outline the page again from your markers, '
            + 'reusing the lines Detect found. Undo (Ctrl+Z) to bring the Detect result back'
            + (changed > 0 ? ` with your ${changed} line adjustment${changed === 1 ? '' : 's'}` : '')
            + ', or Detect again to refit the markers.';
        el('detectSummary').classList.add('is-stale');
        // The notes describe Detect's grid, which is set aside now.
        fillGridNotes(el('detectSummaryNotes'), []);
    }

    /** The detected page can no longer be read: undo cannot bring it back either. */
    function retireDetection() {
        if (!detection) return;
        detection.stale = true;
        if (detection.setAside) {
            el('detectSummaryText').textContent = 'Scan with OCR will outline the page again from your markers. '
                + 'Detect again to refit them.';
        }
    }

    function restoreDetection() {
        detection.setAside = false;
        alignPreview.setVisible(true);
        el('lineEditToggle').disabled = detection.page.lines.length === 0;
        el('detectSummary').classList.remove('is-stale');
        showDetectionSummary();
    }

    function clearDetection() {
        setLineEditing(false);
        resetLineHistory();
        detection = null;
        alignPreview.clear();
        el('detectSummary').classList.add('d-none');
        el('detectSummary').classList.remove('is-stale', 'has-review', 'has-notes');
        fillGridNotes(el('detectSummaryNotes'), []);
    }

    /**
     * Show what Detect found: the page as it was straightened, the markers
     * fitted to this page's own table, and every outline it will read.
     */
    async function applyDetection(page) {
        // The same pixels the outlines were found on, and that go on to TrOCR.
        await marker.loadFromUrl(page.imageUrl);
        updateScanNotices();
        fetchSnapLines();
        // The markers and row lines Detect fitted (the page's own rows, unless
        // Staff set theirs by hand), as one change Ctrl+Z can take back.
        const rows = page.geometry?.ruled_ys ?? gridRows;
        rowsSource = null;
        setGrid(geometryMarkers(page.geometry, page), rows, {
            estimated: (page.geometry?.estimated_rows ?? []).filter((index) => rows[index] !== undefined)
                .map((index) => rowKey(rows[index])),
            byHand: rowsByHand,
        });
        window.requestAnimationFrame(() => marker.resetZoom());

        setLineEditing(false);
        detection = {
            page,
            snapshot: gridSnapshot(),
            stale: false,
            setAside: false,
            // The detector's outlines, for Reset line.
            originalOutlines: new Map(page.lines.map((line) => [line.id, line.polygon.map((point) => [...point])])),
        };
        resetLineHistory();

        alignPreview.setLines(page, verificationItems(page.lines, page));
        // The row lines are drawn, and can be moved, by the row layer.
        alignPreview.setGuides([]);
        alignPreview.setVisible(true);

        el('detectSummary').classList.remove('d-none', 'is-stale');
        el('lineEditToggle').disabled = page.lines.length === 0;
        showDetectionSummary();
    }

    // ------------------------------------------------------- line editing
    // After Detect, each outline can be clicked, stretched by its handles,
    // grown/shrunk, have its corners moved, or be drawn anew, with a live
    // preview of the crop TrOCR will read. Changes stay here until the
    // reviewer presses Save crop: only then is the line re-cropped on the
    // server (not read). Scan with OCR reads the saved crops, and offers to
    // save any line still unsaved. The field markers are locked meanwhile:
    // moving one sets Detect's result and these adjustments aside until the
    // move is undone.

    const LINE_GROW_STEP = 2;
    const LINE_MODE_HINTS = {
        box: 'Drag the handles to stretch. + / − grow or shrink. Save crop (Ctrl+S) keeps the change. Ctrl+Z undo, Ctrl+Y redo. Tab: next line. Esc: done.',
        points: 'Drag a corner to move it. Drag a small dot between corners to add one. Delete (or double-click) removes the corner last touched. Ctrl+Z undo.',
        draw: 'Click to place as many points as you like, or hold and trace. Then press Use this outline, Enter, or double-click, or click the first point. Ctrl+Z takes back the last point. Esc cancels.',
    };
    let lineEditing = false;
    let editedLineIndex = null;
    let lineSaveQueue = Promise.resolve();
    // Undo and redo of outline changes: each entry is one line before and
    // after one change. lineShown is each line as last changed here; a line
    // whose shown outline differs from its saved one is unsaved.
    // Each entry is one undoable step: an outline change ({id, before, after}),
    // or a line removed or drawn ({kind, id, position}), which are put back and
    // taken away again on the server.
    let lineUndo = [];
    let lineRedo = [];
    let lineShown = new Map();
    // True while a new outline is being drawn for a line that does not exist yet.
    let addingLine = false;

    function resetLineHistory() {
        lineUndo = [];
        lineRedo = [];
        lineShown = new Map();
        updateLineHistoryButtons();
    }

    function updateLineHistoryButtons() {
        el('lineUndoBtn').disabled = lineUndo.length === 0;
        el('lineRedoBtn').disabled = lineRedo.length === 0;
    }

    function shownLine(index) {
        const line = detection.page.lines[index];
        return lineShown.get(line.id) ?? { polygon: line.polygon.map((point) => [...point]), adjusted: Boolean(line.adjusted) };
    }

    /** Remember one change to a line, so it can be undone. */
    function recordLineChange(index, polygon, adjusted) {
        const id = detection.page.lines[index].id;
        const after = { polygon: polygon.map((point) => [...point]), adjusted };
        lineUndo.push({ id, before: shownLine(index), after });
        if (lineUndo.length > 200) lineUndo.shift();
        lineRedo = [];
        lineShown.set(id, after);
        updateLineHistoryButtons();
        lineChanged(index);
    }

    /** Is this line's outline here different from the one saved on the server? */
    function isLineUnsaved(line) {
        const shown = lineShown.get(line.id);
        if (!shown) return false;
        return shown.adjusted !== Boolean(line.adjusted)
            || JSON.stringify(shown.polygon) !== JSON.stringify(line.polygon);
    }

    function unsavedLineCount() {
        return detection ? detection.page.lines.filter(isLineUnsaved).length : 0;
    }

    /** Redraw a changed line and the save state everywhere it shows. */
    function lineChanged(index) {
        alignPreview.updateLine(index, lineItems()[index]);
        showDetectionSummary();
        if (editedLineIndex !== index) return;
        const unsaved = isLineUnsaved(detection.page.lines[index]);
        el('lineSaveBtn').disabled = !unsaved;
        el('lineDiscardBtn').disabled = !unsaved;
        el('lineResetBtn').disabled = !shownLine(index).adjusted;
        const status = el('lineAdjustStatus');
        status.classList.remove('is-error');
        status.textContent = unsaved
            ? 'Not saved yet. Press Save crop (Ctrl+S) to make this the crop.'
            : 'Same as the saved crop.';
    }

    /** Put a line back to a remembered outline (undo, redo, reset, discard). */
    function applyLineState(id, state) {
        const index = detection?.page.lines.findIndex((line) => line.id === id) ?? -1;
        if (index < 0) return;
        if (editedLineIndex !== index) selectLine(index);
        if (alignPreview.editing?.mode !== 'box') alignPreview.setEditMode('box');
        alignPreview.setEditedPolygon(state.polygon, { notify: false });
        drawLineThumbnail(state.polygon);
        lineShown.set(id, { polygon: state.polygon.map((point) => [...point]), adjusted: state.adjusted });
        lineChanged(index);
    }

    /**
     * One step back or forward. An outline change is undone here; a line
     * removed or drawn is put back or taken away on the server, so undo means
     * the same thing to Scan with OCR as it does on screen.
     */
    function stepLineHistory(from, to, forward) {
        const entry = from.pop();
        if (!entry) return false;
        to.push(entry);
        updateLineHistoryButtons();

        // 'delete' undone puts the line back; 'add' undone takes it away.
        const puttingBack = entry.kind === 'delete' ? !forward : forward;
        if (entry.kind === 'delete' || entry.kind === 'add') {
            runLineChange(
                () => (puttingBack ? restoreLine(entry.id) : removeLine(entry.id)),
                puttingBack ? 'The line could not be put back.' : 'The line could not be taken away.',
            );
            return true;
        }

        applyLineState(entry.id, forward ? entry.after : entry.before);
        updateLineHistoryButtons();
        return true;
    }

    function undoLineChange() {
        return stepLineHistory(lineUndo, lineRedo, false);
    }

    function redoLineChange() {
        return stepLineHistory(lineRedo, lineUndo, true);
    }

    function lineItems() {
        if (!detection) return [];
        const lines = detection.page.lines.map((line) => {
            const shown = lineShown.get(line.id);
            return shown ? { ...line, polygon: shown.polygon, adjusted: shown.adjusted } : line;
        });
        return verificationItems(lines, detection.page).map((item, index) => ({
            ...item,
            unsaved: isLineUnsaved(detection.page.lines[index]),
        }));
    }

    function adjustedLineCount() {
        return detection ? detection.page.lines.filter((line) => line.adjusted).length : 0;
    }

    function showDetectionSummary() {
        const summary = detectionSummary(detection.page);
        const adjusted = adjustedLineCount();
        const unsaved = unsavedLineCount();
        el('detectSummaryTitle').textContent = summary.title;
        el('detectSummaryText').textContent = summary.text
            + (adjusted > 0 ? ` · ${adjusted} line${adjusted === 1 ? '' : 's'} adjusted` : '')
            + (unsaved > 0 ? ` · ${unsaved} not saved` : '');
        el('detectSummary').classList.toggle('has-review', summary.flagged > 0);
        el('detectSummary').classList.toggle('has-notes', summary.notes.length > 0);
        fillGridNotes(el('detectSummaryNotes'), summary.notes);
    }

    /** One list item per note; the list hides itself when there is nothing to say. */
    function fillGridNotes(list, notes) {
        list.replaceChildren(...notes.map((text) => {
            const item = document.createElement('li');
            item.textContent = text;
            return item;
        }));
        list.classList.toggle('d-none', notes.length === 0);
    }

    /** In Verify: the same notes, above the people, so they are read before anything is verified. */
    function showGridNotes(page) {
        const notes = gridNotes(page?.notes);
        fillGridNotes(el('verifyNotesList'), notes);
        el('verifyNotes').classList.toggle('d-none', notes.length === 0);
    }

    function setLineEditing(on) {
        const allowed = on && detection !== null && !detection.stale && !detection.setAside
            && detection.page.lines.length > 0;
        if (!allowed && lineEditing) applyOpenDrawing();
        if (!allowed) {
            addingLine = false;
            editedLineIndex = null;
            alignPreview.cancelEdit();
            alignPreview.setSelection([]);
        }
        lineEditing = allowed;
        el('lineEditToggle').checked = allowed;
        updateAddLineButton();
        alignPreview.setInteractive(allowed);
        markerOverlay.classList.toggle('is-editing-lines', allowed);
        // Like the markers, the row lines stay put while lines are edited.
        if (allowed) selectedRow = null;
        renderRows();
        updateGridCard();
        if (allowed) {
            // Editing needs the outlines on screen.
            alignPreview.setVisible(true);
            marker.clearSelection();
            el('lineAdjustCard').classList.remove('d-none');
            if (editedLineIndex === null) showLineCard(null);
            revealLineCard();
        } else {
            el('lineAdjustCard').classList.add('d-none');
        }
    }

    el('lineEditToggle').addEventListener('change', (event) => {
        setLineEditing(event.currentTarget.checked);
        // Off the switch, so the line keys (Tab, +, -, Esc) work at once.
        event.currentTarget.blur();
    });

    function selectLine(index) {
        if (!lineEditing) return;
        if (index !== editedLineIndex) applyOpenDrawing();
        addingLine = false;
        if (index === null || !detection?.page.lines[index]) {
            editedLineIndex = null;
            alignPreview.cancelEdit();
            alignPreview.setSelection([]);
            showLineCard(null);
            return;
        }
        editedLineIndex = index;
        alignPreview.setSelection([index], index);
        alignPreview.beginEdit(index, 'box');
        showLineCard(index);
        showLineMode('box');
        // Bring it into view when Tab moved to a line off screen.
        alignPreview.groups[index]?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
        revealLineCard();
    }

    /**
     * Scroll the side panel down to the line tools: the Detect summary at the
     * top of the view (or as near as the panel scrolls), then the line card
     * and the Detect / Scan buttons below it.
     */
    function revealLineCard() {
        const card = el('lineAdjustCard');
        if (card.classList.contains('d-none')) return;
        window.requestAnimationFrame(() => {
            const panel = card.closest('.document-side-panel');
            if (!panel || panel.scrollHeight <= panel.clientHeight + 1) {
                // A stacked (narrow) layout scrolls the page instead.
                card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                return;
            }
            const summary = el('detectSummary');
            const summaryTop = summary.getBoundingClientRect().top - panel.getBoundingClientRect().top + panel.scrollTop;
            const bottom = panel.scrollHeight - panel.clientHeight;
            panel.scrollTo({ top: Math.max(0, Math.min(bottom, summaryTop - 12)), behavior: 'smooth' });
        });
    }

    function showLineCard(index) {
        const items = lineItems();
        const item = index === null ? null : items[index];
        const line = index === null ? null : detection.page.lines[index];
        el('lineAdjustName').textContent = item ? item.name : 'Click a line on the page';
        el('lineAdjustPosition').textContent = item ? `${index + 1} of ${items.length}` : '';
        const note = item ? flagExplanation(item.flags) : '';
        el('lineAdjustNote').textContent = note;
        el('lineAdjustNote').classList.toggle('d-none', note === '');
        el('lineAdjustActions').querySelectorAll('button').forEach((button) => { button.disabled = !item; });
        el('lineDeleteBtn').disabled = !item;
        updateAddLineButton();
        // Undo and redo follow the history, whichever line is selected.
        updateLineHistoryButtons();
        el('lineModeGroup').querySelectorAll('input').forEach((input) => { input.disabled = !item; });
        if (!item) el('lineDrawActions').classList.add('d-none');
        el('lineAdjustHint').textContent = item
            ? LINE_MODE_HINTS.box
            : 'Click an outline on the page to adjust it, or Add line to draw one the detector missed.';
        const unsaved = Boolean(line && isLineUnsaved(line));
        el('lineResetBtn').disabled = !(line && shownLine(index).adjusted);
        el('lineSaveBtn').disabled = !unsaved;
        el('lineDiscardBtn').disabled = !unsaved;
        el('lineAdjustStatus').textContent = unsaved ? 'Not saved yet. Press Save crop (Ctrl+S) to make this the crop.' : '';
        el('lineAdjustStatus').classList.remove('is-error');
        drawLineThumbnail(item ? item.polygon : null);
    }

    /** The crop TrOCR will read: the page inside the outline, with a margin. */
    function drawLineThumbnail(polygon) {
        const canvas = el('lineAdjustThumb');
        const source = marker.canvas;
        if (!polygon || polygon.length < 3 || !source.width) {
            canvas.width = 1;
            canvas.height = 1;
            canvas.classList.add('is-empty');
            return;
        }
        const pad = 8;
        const bounds = polygonBounds(polygon);
        const left = Math.max(0, Math.floor(bounds.left - pad));
        const top = Math.max(0, Math.floor(bounds.top - pad));
        const right = Math.min(source.width, Math.ceil(bounds.right + pad));
        const bottom = Math.min(source.height, Math.ceil(bounds.bottom + pad));
        canvas.width = Math.max(1, right - left);
        canvas.height = Math.max(1, bottom - top);
        canvas.classList.remove('is-empty');
        const context = canvas.getContext('2d');
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.save();
        context.beginPath();
        polygon.forEach(([x, y], i) => (i === 0 ? context.moveTo(x - left, y - top) : context.lineTo(x - left, y - top)));
        context.closePath();
        context.clip();
        context.drawImage(source, -left, -top);
        context.restore();
    }

    function lineEdited(polygon, final) {
        if (!lineEditing) return;
        // A line being drawn from nothing belongs to no line yet: closing it
        // makes one, rather than changing an outline.
        if (addingLine) {
            drawLineThumbnail(polygon);
            if (final) createDrawnLine(polygon);
            return;
        }
        if (editedLineIndex === null) return;
        drawLineThumbnail(polygon);
        if (!final) return;
        // Pressing a corner without moving it (to pick it for Delete) changes nothing.
        if (JSON.stringify(polygon) === JSON.stringify(shownLine(editedLineIndex).polygon)) return;
        recordLineChange(editedLineIndex, polygon, true);
    }

    /** Save crop: the selected line's outline becomes its crop. */
    function saveSelectedLine() {
        if (!lineEditing || editedLineIndex === null) return;
        applyOpenDrawing();
        const index = editedLineIndex;
        if (!isLineUnsaved(detection.page.lines[index])) return;
        const shown = shownLine(index);
        // Back to the detector's own outline counts as not adjusted.
        saveLine(index, shown.polygon, { reset: !shown.adjusted });
    }

    /** Discard: back to the last saved outline (undoable). */
    function discardSelectedLine() {
        if (!lineEditing || editedLineIndex === null) return;
        if (alignPreview.editing?.mode === 'draw') alignPreview.setEditMode('box');
        const line = detection.page.lines[editedLineIndex];
        if (!isLineUnsaved(line)) return;
        const saved = { polygon: line.polygon.map((point) => [...point]), adjusted: Boolean(line.adjusted) };
        recordLineChange(editedLineIndex, saved.polygon, saved.adjusted);
        applyLineState(line.id, saved);
    }

    /** Save every unsaved line, and wait for all saves to finish. */
    function saveAllUnsavedLines() {
        detection?.page.lines.forEach((line, index) => {
            if (!isLineUnsaved(line)) return;
            const shown = shownLine(index);
            saveLine(index, shown.polygon, { reset: !shown.adjusted });
        });
        return lineSaveQueue;
    }

    /**
     * Take a deleted line out of everything that remembers it: the page's own
     * line list, its outline history, and what the overlay draws. Moves the
     * card on to whichever line now sits where the deleted one was, so a run
     * of stray lines can be cleared one after another.
     */
    function removeLineLocally(id, flagsById = {}) {
        if (!detection) return null;
        const removedIndex = detection.page.lines.findIndex((line) => line.id === id);
        if (removedIndex === -1) return null;

        detection.page.lines = detection.page.lines
            .filter((line) => line.id !== id)
            .map((line) => {
                const flags = flagsById[line.id];
                return Array.isArray(flags) ? { ...line, flags } : line;
            });
        // Its outline history is kept: a line put back is the same line, and
        // the delete is always the newest step on it, so undo reaches it first.
        lineShown.delete(id);

        editedLineIndex = null;
        alignPreview.setLines(detection.page, lineItems());
        el('lineEditToggle').disabled = detection.page.lines.length === 0;
        showDetectionSummary();

        if (detection.page.lines.length === 0) {
            setLineEditing(false);
            return removedIndex;
        }
        selectLine(Math.min(removedIndex, detection.page.lines.length - 1));
        return removedIndex;
    }

    /**
     * Put a line into the page where the server has it: back after a delete,
     * or newly drawn. Lines are held in the server's own order (position), so
     * one that comes back lands where it was.
     */
    function insertLineLocally(line, flagsById = {}) {
        if (!detection) return;
        const lines = detection.page.lines
            .filter((existing) => existing.id !== line.id)
            .map((existing) => {
                const flags = flagsById[existing.id];
                return Array.isArray(flags) ? { ...existing, flags } : existing;
            });
        const at = lines.findIndex((existing) => Number(existing.position) > Number(line.position));
        const index = at === -1 ? lines.length : at;
        lines.splice(index, 0, line);
        detection.page.lines = lines;
        detection.originalOutlines.set(line.id, line.polygon.map((point) => [...point]));
        lineShown.delete(line.id);

        if (!lineEditing) setLineEditing(true);
        alignPreview.setLines(detection.page, lineItems());
        el('lineEditToggle').disabled = false;
        showDetectionSummary();
        selectLine(index);
    }

    /** One call to the server about a whole line, with the usual headers. */
    async function lineRequest(url, method) {
        const response = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': config.csrf,
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
        });
        const payload = (response.headers.get('content-type') || '').includes('application/json')
            ? await response.json()
            : null;
        if (!response.ok) {
            throw new Error(payload?.message || `The line could not be changed (HTTP ${response.status}).`);
        }
        return payload ?? {};
    }

    const lineUrl = (template, id) => template
        .replace('__PAGE__', String(detection.page.id))
        .replace('__LINE__', String(id));

    /** Tell the reviewer what happened to a line, in the card's status line. */
    function showLineStatus(message, isError = false) {
        const status = el('lineAdjustStatus');
        status.textContent = message;
        status.classList.toggle('is-error', isError);
    }

    /**
     * Delete: take the selected line out of the page. It is kept on the server
     * until the page is outlined again, so Ctrl+Z puts it back; nothing reads
     * or submits it meanwhile.
     */
    function deleteSelectedLine() {
        if (!lineEditing || editedLineIndex === null) return Promise.resolve();
        applyOpenDrawing();
        const line = detection?.page.lines[editedLineIndex];
        if (!line) return Promise.resolve();

        const name = lineItems()[editedLineIndex]?.name ?? 'That line';
        return runLineChange(async () => {
            // A second press before the first was applied has nothing to do.
            if (!detection?.page.lines.some((existing) => existing.id === line.id)) return;
            const payload = await lineRequest(lineUrl(config.lineDeleteUrl, line.id), 'DELETE');
            const at = removeLineLocally(line.id, payload.flags ?? {});
            if (at === null) return;
            lineUndo.push({ kind: 'delete', id: line.id });
            lineRedo = [];
            updateLineHistoryButtons();
            showLineStatus(`${name} deleted. Ctrl+Z puts it back.`);
        }, 'The line could not be deleted.');
    }

    /** Put a deleted line back where it was (undo), or take a drawn one away again. */
    async function restoreLine(id) {
        const payload = await lineRequest(lineUrl(config.lineRestoreUrl, id), 'POST');
        if (payload.line) insertLineLocally(payload.line, payload.flags ?? {});
    }

    async function removeLine(id) {
        const payload = await lineRequest(lineUrl(config.lineDeleteUrl, id), 'DELETE');
        removeLineLocally(id, payload.flags ?? {});
    }

    /** Start drawing a line where the detector found none. */
    function startAddingLine() {
        if (!lineEditing) return;
        applyOpenDrawing();
        addingLine = true;
        editedLineIndex = null;
        alignPreview.setSelection([]);
        alignPreview.beginDraw();
        el('lineAdjustName').textContent = 'Drawing a new line';
        el('lineAdjustPosition').textContent = '';
        el('lineAdjustNote').classList.add('d-none');
        el('lineAdjustActions').querySelectorAll('button').forEach((button) => { button.disabled = true; });
        el('lineDeleteBtn').disabled = true;
        el('lineModeGroup').querySelectorAll('input').forEach((input) => { input.disabled = true; });
        el('lineAdjustHint').textContent = LINE_MODE_HINTS.draw;
        updateAddLineButton();
        revealLineCard();
    }

    function cancelAddingLine() {
        if (!addingLine) return;
        addingLine = false;
        alignPreview.cancelEdit();
        updateAddLineButton();
        showLineCard(null);
    }

    function updateAddLineButton() {
        const button = el('lineAddBtn');
        button.disabled = !lineEditing;
        button.classList.toggle('active', addingLine);
    }

    /** A drawn outline becomes a line of its own on the server. */
    function createDrawnLine(polygon) {
        addingLine = false;
        updateAddLineButton();
        if (!detection) return Promise.resolve();

        showLineStatus('Adding the line…');
        return runLineChange(async () => {
            const response = await fetch(config.lineStoreUrl.replace('__PAGE__', String(detection.page.id)), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': config.csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ polygon }),
            });
            const payload = (response.headers.get('content-type') || '').includes('application/json')
                ? await response.json()
                : null;
            if (!response.ok || !payload?.line) {
                throw new Error(payload?.message || `The line could not be added (HTTP ${response.status}).`);
            }
            insertLineLocally(payload.line, payload.flags ?? {});
            lineUndo.push({ kind: 'add', id: payload.line.id });
            lineRedo = [];
            updateLineHistoryButtons();
            showLineStatus('Line added. Ctrl+Z takes it away again.');
        }, 'The line could not be added.');
    }

    /** Run one whole-line change on the save queue, reporting failures in the card. */
    function runLineChange(work, failure) {
        lineSaveQueue = lineSaveQueue.then(work).catch((error) => {
            console.error(failure, error);
            showLineStatus(error.message || failure, true);
        });
        return lineSaveQueue;
    }

    el('lineSaveBtn').addEventListener('click', saveSelectedLine);
    el('lineDiscardBtn').addEventListener('click', discardSelectedLine);
    el('lineDeleteBtn').addEventListener('click', deleteSelectedLine);
    el('lineAddBtn').addEventListener('click', () => (addingLine ? cancelAddingLine() : startAddingLine()));

    /** Keep the Stretch / Points / Draw switch and its hint in step with the overlay. */
    function showLineMode(mode) {
        const input = el('lineModeGroup').querySelector(`input[value="${mode}"]`);
        if (input) input.checked = true;
        el('lineDrawActions').classList.toggle('d-none', mode !== 'draw' || editedLineIndex === null);
        if (editedLineIndex !== null) {
            el('lineAdjustHint').textContent = LINE_MODE_HINTS[mode] ?? '';
            // The preview shows the outline in force until a drawing has three points.
            const polygon = alignPreview.editedPolygon();
            if (polygon) drawLineThumbnail(polygon);
        }
    }

    function showDraft(points) {
        el('lineDrawUseBtn').disabled = points < 3;
        if (editedLineIndex === null || alignPreview.editing?.mode !== 'draw') return;
        const status = el('lineAdjustStatus');
        status.classList.remove('is-error');
        status.textContent = points === 0
            ? 'Click round the writing, or hold and trace.'
            : points < 3
                ? `${points} point${points === 1 ? '' : 's'}. At least 3 to make an outline.`
                : `${points} points. The preview shows the new crop: press Use this outline (or Enter) to keep it.`;
        if (points < 3) drawLineThumbnail(alignPreview.editedPolygon());
    }

    /**
     * A drawing of three points or more is applied, not lost, when the
     * reviewer moves on without closing it (another mode, another line,
     * Adjust line crops off, Scan with OCR). Only Esc or Cancel throw it away.
     */
    function applyOpenDrawing() {
        return alignPreview.isDrawing() && alignPreview.finishDrawing();
    }

    /** Close the drawing, or say why it cannot be an outline yet. */
    function useDrawing() {
        if (alignPreview.finishDrawing()) return;
        const status = el('lineAdjustStatus');
        status.textContent = 'This drawing has no height or width to crop. Add points round the writing.';
        status.classList.add('is-error');
    }

    el('lineDrawUseBtn').addEventListener('click', useDrawing);
    el('lineDrawCancelBtn').addEventListener('click', () => alignPreview.setEditMode('box'));

    el('lineModeGroup').addEventListener('change', (event) => {
        if (!(event.target instanceof HTMLInputElement) || editedLineIndex === null) return;
        const target = event.target.value;
        if (target !== 'draw' && applyOpenDrawing()) {
            // The drawing is the outline now (and the mode is Stretch).
            if (target !== 'box') alignPreview.setEditMode(target);
        } else {
            alignPreview.setEditMode(target);
        }
        // Off the switch, so Enter and Esc reach the drawing.
        event.target.blur();
    });

    function growLine(distance) {
        if (!lineEditing || editedLineIndex === null || alignPreview.editing?.mode === 'draw') return;
        alignPreview.growEdit(distance);
    }

    function resetLine() {
        if (!lineEditing || editedLineIndex === null) return;
        const line = detection.page.lines[editedLineIndex];
        const original = detection.originalOutlines.get(line.id);
        if (!original) return;
        // A reset is a change like any other: Ctrl+Z brings the adjustment back.
        recordLineChange(editedLineIndex, original, false);
        applyLineState(line.id, { polygon: original, adjusted: false });
    }

    el('lineShrinkBtn').addEventListener('click', () => growLine(-LINE_GROW_STEP));
    el('lineGrowBtn').addEventListener('click', () => growLine(LINE_GROW_STEP));
    el('lineResetBtn').addEventListener('click', resetLine);
    el('lineUndoBtn').addEventListener('click', () => (alignPreview.isDrawing() ? alignPreview.undoDrawStep() : undoLineChange()));
    el('lineRedoBtn').addEventListener('click', redoLineChange);

    /**
     * PUT a line's new outline. When the outline sits in another cell than the
     * line's own, the server saves nothing and says where; the reviewer then
     * decides whether this field really belongs there (another person's row,
     * or another column). Resolves to { response, payload }.
     */
    async function putLineOutline(url, body) {
        const send = async (extra) => {
            const response = await fetch(url, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': config.csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ ...body, ...extra }),
            });
            const payload = (response.headers.get('content-type') || '').includes('application/json')
                ? await response.json()
                : null;
            return { response, payload };
        };

        const first = await send({});
        const move = first.payload?.move;
        if (first.response.status !== 409 || !move) return first;

        const confirmed = window.confirm(
            `This outline covers writing in ${move.to.column}, row ${move.to.row}.\n`
            + `The field you are adjusting is ${move.from.column}, row ${move.from.row}.\n\n`
            + `OK: move this field to ${move.to.column}, row ${move.to.row}.\n`
            + 'Cancel: keep it where it is (nothing is saved).',
        );
        if (!confirmed) {
            throw new Error(`Not saved: the outline is over ${move.to.column}, row ${move.to.row}. `
                + `Draw it over ${move.from.column}, row ${move.from.row}, or pick the right field first.`);
        }
        return send({ allow_move: true });
    }

    /** Re-crop one line on the server from its new outline. Saves run one at a time. */
    function saveLine(index, polygon, { reset = false } = {}) {
        const page = detection?.page;
        const line = page?.lines[index];
        if (!line) return Promise.resolve();
        const status = el('lineAdjustStatus');
        status.textContent = 'Saving…';
        status.classList.remove('is-error');

        lineSaveQueue = lineSaveQueue.then(async () => {
            if (detection?.page !== page) return;
            const { response, payload } = await putLineOutline(config.lineUpdateUrl
                .replace('__PAGE__', String(page.id))
                .replace('__LINE__', String(line.id)), { polygon, reset });
            if (!response.ok || !payload?.line) {
                throw new Error(payload?.message || `The outline could not be saved (HTTP ${response.status}).`);
            }
            if (detection?.page !== page) return;

            // One line moved can make or clear a shared cell elsewhere.
            page.lines = page.lines.map((existing) => {
                if (existing.id === payload.line.id) return payload.line;
                const flags = payload.flags?.[existing.id];
                return Array.isArray(flags) ? { ...existing, flags } : existing;
            });
            // Unless it was changed again meanwhile, what is shown is now the
            // saved outline (the server rounds it to a tenth of a pixel).
            const shownNow = lineShown.get(line.id);
            if (!shownNow || JSON.stringify(shownNow.polygon) === JSON.stringify(polygon)) {
                lineShown.set(line.id, { polygon: payload.line.polygon, adjusted: Boolean(payload.line.adjusted) });
            }
            const items = lineItems();
            items.forEach((item, i) => alignPreview.updateLine(i, item));
            showDetectionSummary();
            if (editedLineIndex === index) {
                const unsaved = isLineUnsaved(page.lines[index]);
                el('lineSaveBtn').disabled = !unsaved;
                el('lineDiscardBtn').disabled = !unsaved;
                el('lineResetBtn').disabled = !shownLine(index).adjusted;
                const note = flagExplanation(items[index].flags);
                el('lineAdjustNote').textContent = note;
                el('lineAdjustNote').classList.toggle('d-none', note === '');
                status.textContent = unsaved
                    ? 'Saved an earlier version. Press Save crop again for the latest.'
                    : reset ? 'Saved: back to the detected outline.' : 'Saved. Scan with OCR reads this crop.';
            }
        }).catch((error) => {
            console.error('Saving the line outline failed:', error);
            if (editedLineIndex === index) {
                status.textContent = error.message || 'The outline could not be saved.';
                status.classList.add('is-error');
            }
        });
        return lineSaveQueue;
    }

    /** Line editing keys, handled before the marker shortcuts. Returns true when used. */
    function handleLineEditKey(event) {
        if (!lineEditing) return false;
        const items = detection?.page.lines ?? [];
        // Tab steps through lines while working on the page; in the side
        // panel it keeps moving focus as usual.
        const onPage = document.activeElement === document.body
            || el('docViewport').contains(document.activeElement);
        if (event.key === 'Tab' && items.length > 0 && onPage) {
            event.preventDefault();
            const step = event.shiftKey ? -1 : 1;
            const from = editedLineIndex ?? (step > 0 ? -1 : 0);
            selectLine((from + step + items.length) % items.length);
            return true;
        }
        if (['+', '=', 'Add'].includes(event.key) || event.code === 'NumpadAdd') {
            event.preventDefault();
            growLine(event.shiftKey && event.key !== '+' ? LINE_GROW_STEP * 3 : LINE_GROW_STEP);
            return true;
        }
        if (['-', '_', 'Subtract'].includes(event.key) || event.code === 'NumpadSubtract') {
            event.preventDefault();
            growLine(event.shiftKey ? -LINE_GROW_STEP * 3 : -LINE_GROW_STEP);
            return true;
        }
        if (event.key === 'Enter' && alignPreview.isDrawing()) {
            event.preventDefault();
            useDrawing();
            return true;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            // Esc first leaves a drawing or point editing, then the line, then editing.
            if (addingLine) cancelAddingLine();
            else if (editedLineIndex !== null && alignPreview.editing && alignPreview.editing.mode !== 'box') alignPreview.setEditMode('box');
            else if (editedLineIndex !== null) selectLine(null);
            else setLineEditing(false);
            return true;
        }
        const command = event.ctrlKey || event.metaKey;
        const key = event.key.toLowerCase();
        if (command && key === 's') {
            event.preventDefault();
            saveSelectedLine();
            return true;
        }
        // Ctrl+Z: the last point while drawing, else the last outline change.
        if (command && !event.shiftKey && key === 'z') {
            event.preventDefault();
            if (!alignPreview.undoDrawStep()) undoLineChange();
            return true;
        }
        if (command && (key === 'y' || (event.shiftKey && key === 'z'))) {
            event.preventDefault();
            redoLineChange();
            return true;
        }
        if (['Delete', 'Backspace'].includes(event.key)) {
            event.preventDefault();
            // A corner of the outline first (Points mode), then the line itself.
            if (!alignPreview.deleteActivePoint()) deleteSelectedLine();
            return true;
        }
        // The markers are locked while lines are edited: no marker shortcuts.
        return ['BracketLeft', 'BracketRight'].includes(event.code) || (command && ['c', 'v'].includes(key));
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
        try {
            setGrid(previous.boxes, previous.rows, {
                estimated: previous.estimated,
                byHand: previous.byHand,
                tilts: previous.tilts,
            });
        } finally {
            restoringFieldHistory = false;
        }
    }

    function copySelectedFields() {
        const boxes = marker.toJSON();
        markerClipboard = marker.selectedIndexes().map((index) => ({ ...boxes[index] }));
        pasteSequence = 0;

        const count = markerClipboard.length;
        if (count > 0) flashFieldCount(`${count} copied`);
    }

    function nextCopyName(name, takenNames) {
        const base = name.trim() || 'Field';
        let suffix = 1;
        let tail = ' copy';
        let candidate = `${base.slice(0, config.maxFieldNameLength - tail.length)}${tail}`;

        while (takenNames.has(candidate.toLocaleLowerCase())) {
            suffix += 1;
            tail = ` copy ${suffix}`;
            candidate = `${base.slice(0, config.maxFieldNameLength - tail.length)}${tail}`;
        }

        takenNames.add(candidate.toLocaleLowerCase());
        return candidate;
    }

    function pasteCopiedFields() {
        if (markerClipboard.length === 0) return;

        const existing = marker.toJSON();
        if (existing.length + markerClipboard.length > config.maxFields) {
            showOcrError(`You can use at most ${config.maxFields} fields. Delete some markers before pasting this selection.`);
            return;
        }

        const minX = Math.min(...markerClipboard.map((box) => box.x));
        const minY = Math.min(...markerClipboard.map((box) => box.y));
        const maxX = Math.max(...markerClipboard.map((box) => box.x + box.w));
        const maxY = Math.max(...markerClipboard.map((box) => box.y + box.h));
        const distance = Math.min(0.1, 0.018 * (pasteSequence + 1));
        const dx = maxX + distance <= 1 ? distance : (minX - distance >= 0 ? -distance : 0);
        const dy = maxY + distance <= 1 ? distance : (minY - distance >= 0 ? -distance : 0);
        const takenNames = new Set(existing.map((box) => box.name.toLocaleLowerCase()));
        const copies = markerClipboard.map((box) => {
            // A pasted marker is a new, ad-hoc field. It must not silently join
            // the original template person's validation group, nor become a
            // second copy of a ledger column.
            const { personGroup, personFieldOrder, kind, columnIndex, ...ungroupedBox } = box;

            return {
                ...ungroupedBox,
                name: nextCopyName(box.name, takenNames),
                x: box.x + dx,
                y: box.y + dy,
            };
        });
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
        el('layout-navbar').classList.remove('d-none');
        el('documentFlowSteps').classList.remove('d-none');
        document.querySelector('.content-footer')?.classList.toggle('d-none', focusedWorkspace);
        // On the Document step the type list shows the type, and there is
        // nothing to cancel yet; once a scan is open the top bar names both.
        el('workspaceContextDetail').classList.toggle('d-none', !focusedWorkspace);
        el('workspaceCancel').classList.toggle('d-none', !focusedWorkspace);

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
    // How many pages the opened file has: only the first is read.
    let scanPageCount = 1;

    /**
     * What Staff should know about the scan itself. Any paper size is fine
     * (markers are page fractions), but a tall scan of wide pages, or the
     * other way round, is likely sideways; and of a PDF only page 1 is read.
     */
    function updateScanNotices() {
        // Clearly one way or the other: a near-square page is neither.
        const shape = (ratio) => (ratio > 1.05 ? 'wide' : ratio < 0.95 ? 'tall' : null);
        const scan = shape(marker.canvas.width / marker.canvas.height);
        const layout = Number(config.pageRatio) > 0 ? shape(Number(config.pageRatio)) : null;
        const sideways = scan !== null && layout !== null && scan !== layout;
        el('scanSidewaysMessage').textContent = sideways
            ? `This scan is ${scan}, but pages for this layout are ${layout}. It may be sideways: turn it upright and upload it again.`
            : '';
        el('scanSidewaysNotice').classList.toggle('d-none', !sideways);

        el('scanPagesMessage').textContent = scanPageCount > 1
            ? `This PDF has ${scanPageCount} pages. Only page 1 is read; save each page as its own file to read the others.`
            : '';
        el('scanPagesNotice').classList.toggle('d-none', scanPageCount <= 1);
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
            clearDetection();
            const measurement = await marker.load(file);
            scanPageCount = Number(measurement?.pageCount) || 1;
            updateScanNotices();
            el('workspaceContextDetail').textContent = `${config.typeLabel} · ${file.name}`;
            el('workspaceContextDetail').title = el('workspaceContextDetail').textContent;
            showStep('mark');
            guardBackButton();
            resetFieldHistory();
            rowsSource = null;
            setGrid(templateBoxes, templateRows);
            fetchSnapLines();

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

    // Once a scan is open, leaving would lose the markers and the checked
    // values, so Staff are asked first. Not while the record is being
    // submitted, or after, when it opens the record.
    let leaveTo = null;
    let leaving = false;
    const workInProgress = () => scanFile !== null && !recordSubmitting && !leaving;

    // A link in the page (Cancel, the menu) asks with the page's own dialog.
    document.addEventListener('click', (event) => {
        if (!workInProgress() || event.defaultPrevented || event.button !== 0) return;
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!(link instanceof HTMLAnchorElement) || link.target === '_blank' || link.hasAttribute('download')) return;
        if (link.hasAttribute('data-bs-toggle') || !/^https?:$/.test(link.protocol)) return;
        // A jump within this page leaves nothing.
        if (link.hash && link.pathname === location.pathname && link.search === location.search) return;

        event.preventDefault();
        askToLeave(link.href);
    });

    // The browser's Back button: an open scan adds one history entry of its
    // own, at the same address, so Back first returns to this page's entry
    // and the page asks instead of leaving.
    const BACK = 'back';
    let backGuarded = false;
    let backFallback = null;

    function guardBackButton() {
        if (backGuarded) return;
        history.pushState({ scanGuard: true }, '');
        backGuarded = true;
    }

    window.addEventListener('popstate', () => {
        if (!backGuarded) return;
        backGuarded = false;
        if (workInProgress()) askToLeave(BACK);
    });

    function askToLeave(target) {
        leaveTo = target;
        window.bootstrap.Modal.getOrCreateInstance(el('leaveScanModal')).show();
    }

    // The safe choice has the focus, so Enter never leaves by accident.
    el('leaveScanModal').addEventListener('shown.bs.modal', () => el('leaveScanStayBtn').focus());

    // Staying after Back: put the entry back, so the next Back asks again.
    el('leaveScanModal').addEventListener('hidden.bs.modal', () => {
        if (workInProgress()) guardBackButton();
    });

    el('confirmLeaveScanBtn').addEventListener('click', () => {
        if (!leaveTo) return;
        leaving = true;
        if (leaveTo !== BACK) {
            window.location.assign(leaveTo);
            return;
        }

        history.back();
        // Opened in a new tab, there is nothing to go back to: start again.
        backFallback = window.setTimeout(() => window.location.assign(el('workspaceCancel').href), 500);
    });
    window.addEventListener('pagehide', () => window.clearTimeout(backFallback));

    // Closing the tab or reloading: only the browser's own dialog can ask.
    window.addEventListener('beforeunload', (event) => {
        if (!workInProgress()) return;
        event.preventDefault();
        event.returnValue = '';
    });

    // Back to this page from the browser's cache: it is not leaving any more.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) leaving = false;
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
            li.className = 'field-list-item';
            li.dataset.fieldIndex = String(index);
            li.innerHTML = `
                <span class="badge bg-label-primary field-list-item__number">${index + 1}</span>
                <span class="field-list-item__name"></span>
                <button type="button" class="btn btn-sm btn-icon btn-outline-danger rounded-pill field-list-item__remove" title="Remove this field">
                    <i class="icon-base bx bx-x icon-sm" aria-hidden="true"></i>
                </button>`;
            const name = li.querySelector('.field-list-item__name');
            name.textContent = box.name;
            // A long name is cut short with "…": the whole name shows on hover.
            name.title = box.name;
            li.querySelector('button').setAttribute('aria-label', `Remove ${box.name}`);
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

        showFieldCount(boxes);
        selectAllFieldsInput.disabled = boxes.length === 0;
        const constraintMessage = markerSetValidationMessage(boxes);
        el('scanNowBtn').disabled = boxes.length === 0 || constraintMessage !== null;
        el('detectBtn').disabled = el('scanNowBtn').disabled || scanInProgress;
        el('addFieldBtn').disabled = boxes.length >= config.maxFields;
        el('newFieldName').disabled = boxes.length >= config.maxFields;
        if (constraintMessage) {
            markerConstraintMessage = constraintMessage;
            showOcrError(constraintMessage);
        } else if (markerConstraintMessage !== null
            && el('ocrActionMessage').textContent === markerConstraintMessage) {
            markerConstraintMessage = null;
            clearOcrError();
        }
        updateSelectionUI(marker.selectedIndexes());
    }

    /** "11 columns", "11 columns · 2 fields" or "3 fields" in the Fields header. */
    function fieldCountText(boxes = marker.toJSON()) {
        const plural = (count, noun) => `${count} ${noun}${count === 1 ? '' : 's'}`;
        const columns = ledgerColumns(boxes).length;
        const fields = boxes.length - columns;
        if (columns === 0) return plural(fields, 'field');
        return fields > 0 ? `${plural(columns, 'column')} · ${plural(fields, 'field')}` : plural(columns, 'column');
    }

    let fieldCountFlash = null;
    function showFieldCount(boxes = marker.toJSON()) {
        window.clearTimeout(fieldCountFlash);
        el('fieldCount').textContent = fieldCountText(boxes);
    }

    /** A short note in the Fields header ("3 copied"), then the count again. */
    function flashFieldCount(text) {
        window.clearTimeout(fieldCountFlash);
        el('fieldCount').textContent = text;
        fieldCountFlash = window.setTimeout(() => showFieldCount(), 1600);
    }

    function markerSetValidationMessage(boxes) {
        if (boxes.length > config.maxFields) {
            return `This document has ${boxes.length} fields. OCR supports at most ${config.maxFields}; delete extra markers or reset the layout.`;
        }

        const invalidNameIndex = boxes.findIndex((box) => (
            typeof box.name !== 'string'
            || box.name.trim() === ''
            || box.name.length > config.maxFieldNameLength
        ));
        if (invalidNameIndex >= 0) {
            return `Field ${invalidNameIndex + 1} has an invalid name. Field names must contain 1 to ${config.maxFieldNameLength} characters.`;
        }

        const names = new Set();
        const duplicateNameIndex = boxes.findIndex((box) => {
            const key = box.name.trim().toLocaleLowerCase();
            if (names.has(key)) return true;
            names.add(key);
            return false;
        });

        return duplicateNameIndex >= 0
            ? `Field ${duplicateNameIndex + 1} has a duplicate name. Give every field a unique name.`
            : null;
    }

    function centerFieldListRow(index) {
        const list = el('fieldList');
        const row = list.querySelector(`[data-field-index="${index}"]`);
        if (!(row instanceof HTMLElement)) return;

        const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const listRect = list.getBoundingClientRect();
        const rowRect = row.getBoundingClientRect();
        const listTarget = list.scrollTop
            + rowRect.top - listRect.top
            - (list.clientHeight - rowRect.height) / 2;
        const clampedListTarget = Math.min(
            Math.max(0, list.scrollHeight - list.clientHeight),
            Math.max(0, listTarget),
        );
        list.scrollTop = clampedListTarget;

        const panel = list.closest('.document-side-panel');
        if (!(panel instanceof HTMLElement) || panel.scrollHeight <= panel.clientHeight) return;

        const panelRect = panel.getBoundingClientRect();
        const centeredRowRect = row.getBoundingClientRect();
        const panelTarget = panel.scrollTop
            + centeredRowRect.top - panelRect.top
            - (panel.clientHeight - centeredRowRect.height) / 2;
        panel.scrollTo({
            top: Math.min(
                Math.max(0, panel.scrollHeight - panel.clientHeight),
                Math.max(0, panelTarget),
            ),
            behavior: smooth ? 'smooth' : 'auto',
        });
    }

    function updateSelectionUI(indexes, context = {}) {
        // A marker and a row line are never selected together.
        if (indexes.length > 0 && selectedRow !== null) selectRow(null);
        const selected = new Set(indexes);
        document.querySelectorAll('#fieldList .field-list-item').forEach((item) => {
            item.classList.toggle('is-selected', selected.has(Number(item.dataset.fieldIndex)));
        });

        const count = indexes.length;
        const total = marker.toJSON().length;
        selectAllFieldsInput.checked = total > 0 && count === total;
        selectAllFieldsInput.indeterminate = count > 0 && count < total;
        el('deleteFieldsBtn').disabled = count === 0;

        if (context.source === 'marker' && Number.isInteger(context.activeIndex)) {
            centerFieldListRow(context.activeIndex);
        }
    }

    function updateZoomUI(zoom) {
        el('zoomResetBtn').textContent = `${Math.round(zoom * 100)}%`;
        updateResetUI();
        // Line edit handles are sized in page pixels; keep them a constant size on screen.
        alignPreview?.refresh();
    }

    function updateValidationZoomUI(zoom) {
        el('validationZoomResetBtn').textContent = `${Math.round(zoom * 100)}%`;
        // Edit handles are sized in page pixels; keep them a constant size on screen.
        lineOverlay?.refresh();
    }

    el('zoomOutBtn').addEventListener('click', () => marker.zoomBy(-0.1));
    el('zoomInBtn').addEventListener('click', () => marker.zoomBy(0.1));
    el('zoomResetBtn').addEventListener('click', () => marker.resetZoom());
    el('validationZoomOutBtn').addEventListener('click', () => validationMarker.zoomBy(-0.1));
    el('validationZoomInBtn').addEventListener('click', () => validationMarker.zoomBy(0.1));
    el('validationZoomResetBtn').addEventListener('click', () => validationMarker.resetZoom());

    function restoreTemplateFields() {
        rowsSource = null;
        setGrid(templateBoxes, templateRows);
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

        if (handleLineEditKey(event)) return;

        const commandPressed = event.ctrlKey || event.metaKey;
        const key = event.key.toLowerCase();

        // A selected row line: the arrow keys move it (Shift: 10 px), Delete
        // removes it, Esc lets go of it.
        if (selectedRow !== null && !commandPressed && !event.altKey) {
            if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
                event.preventDefault();
                nudgeRow((event.key === 'ArrowUp' ? -1 : 1) * (event.shiftKey ? 10 : 1));
                return;
            }
            if (event.key === 'Delete' || event.key === 'Backspace') {
                event.preventDefault();
                removeRow(selectedRow);
                return;
            }
            if (event.key === 'Escape') {
                selectRow(null);
                return;
            }
        }

        if (commandPressed && !event.shiftKey && key === 'a' && marker.toJSON().length > 0) {
            event.preventDefault();
            marker.selectAll();
            return;
        }

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

        // [ and ] tilt the selection half a degree; with Shift, five degrees.
        if (!commandPressed && ['BracketLeft', 'BracketRight'].includes(event.code) && marker.selectedIndexes().length > 0) {
            event.preventDefault();
            marker.rotateSelected((event.code === 'BracketLeft' ? -1 : 1) * (event.shiftKey ? 5 : 0.5));
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
        if (!name) {
            showGridMessage('Type the new field’s name in the box first.');
            input.focus();
            return;
        }

        const boxes = marker.toJSON();
        if (boxes.length >= config.maxFields) {
            showGridMessage(`You can use at most ${config.maxFields} fields. Delete a marker before adding another one.`);
            return;
        }
        if (name.length > config.maxFieldNameLength) {
            showGridMessage(`Field names must not exceed ${config.maxFieldNameLength} characters.`);
            return;
        }
        if (boxes.some((box) => box.name.trim().toLocaleLowerCase() === name.toLocaleLowerCase())) {
            showGridMessage('Every field and column needs its own name. Choose a different name.');
            return;
        }

        showGridMessage(null);
        marker.addBox(name);
        input.value = '';
        input.focus();
    });
    // Enter adds a field; on a ledger page, a column is added with its button.
    el('newFieldName').addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            el('addFieldBtn').click();
        }
    });
    el('newFieldName').addEventListener('input', () => showGridMessage(null));

    if (isLedger) {
        el('addRowBtn').addEventListener('click', () => addRow());
        el('removeRowBtn').addEventListener('click', () => removeRow());
        el('gridRowCount').addEventListener('change', (event) => setRowCount(Number.parseInt(event.target.value, 10)));
        el('gridRowCount').addEventListener('keydown', (event) => {
            event.stopPropagation();
            if (event.key === 'Enter') event.target.blur();
        });
        el('gridRowCount').addEventListener('blur', () => updateGridCard());
        el('tiltLeftBtn').addEventListener('click', (event) => setTilt(shownTilt() - (event.shiftKey ? 1 : 0.1)));
        el('tiltRightBtn').addEventListener('click', (event) => setTilt(shownTilt() + (event.shiftKey ? 1 : 0.1)));
        el('gridTiltValue').addEventListener('change', (event) => {
            const value = parseTilt(event.target.value);
            if (value === null) updateGridCard();
            else setTilt(value);
        });
        el('gridTiltValue').addEventListener('keydown', (event) => {
            event.stopPropagation();
            if (event.key === 'Enter') event.target.blur();
        });
        el('gridTiltValue').addEventListener('blur', () => updateGridCard());
        el('addColumnBtn').addEventListener('click', () => addLedgerColumn());
        // A click on the page around the markers lets go of the row line.
        markerOverlay.addEventListener('pointerdown', (event) => {
            if (event.target === markerOverlay && selectedRow !== null) selectRow(null);
        });
    }

    el('backToMark').addEventListener('click', () => {
        stopAddingMissedField();
        cancelOutlineEdit();
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

    // Each phase of a run has a share of the ring: from where it starts to its
    // ceiling, which the ring nears but never reaches. Each tick it closes part
    // of the gap left, so it moves quickly as a phase opens and slows towards
    // the ceiling, but never stands still: a ring stopped at a ceiling while
    // Kraken still works (20-50 s) looked stuck. The page's real status opens
    // the next phase, and the ring jumps ahead to its start.
    const OCR_PROGRESS_TICK = 200;
    let ocrProgressCeiling = 15;
    // Seconds in which the ring covers about two thirds of the phase's gap:
    // roughly how long the phase takes.
    let ocrProgressPace = 2;

    function setOcrPhase(start, ceiling, seconds, title = null, note = null) {
        ocrProgressCeiling = ceiling;
        ocrProgressPace = seconds;
        setOcrProgress(Math.max(ocrProgress, start), title, note);
    }

    function beginOcrProgress(markerCount) {
        if (ocrProgressTimer !== null) window.clearInterval(ocrProgressTimer);

        el('ocrProgressFields').textContent = `${markerCount} marker${markerCount === 1 ? '' : 's'} aligned`;
        ocrProgress = 0;
        setOcrPhase(4, 15, 2, 'Preparing document', 'Saving the aligned page for line detection.');

        ocrProgressTimer = window.setInterval(() => {
            const gap = ocrProgressCeiling - ocrProgress;
            if (gap <= 0) return;
            setOcrProgress(ocrProgress + gap * (1 - Math.exp(-OCR_PROGRESS_TICK / 1000 / ocrProgressPace)));
        }, OCR_PROGRESS_TICK);
    }

    /**
     * Show the progress window. `shown` settles once it has finished opening:
     * Bootstrap ignores a hide() while the window is still fading in, which
     * would leave it open over a run that ended at once.
     */
    function openProgressModal() {
        const Modal = window.bootstrap?.Modal;
        if (!Modal) {
            throw new Error('The progress window did not finish loading. Refresh the page and try again.');
        }
        const element = el('scanningModal');
        const modal = Modal.getOrCreateInstance(element);
        const shown = new Promise((resolve) => {
            element.addEventListener('shown.bs.modal', resolve, { once: true });
            window.setTimeout(resolve, 1000);
        });
        modal.show();

        return { modal, shown };
    }

    async function closeProgressModal(progress) {
        if (!progress) return;
        await progress.shown;
        progress.modal.hide();
    }

    function showPageStatus(page, waitedMs, mode = 'scan') {
        if (page.status === 'detecting' && mode === 'detect') {
            setOcrPhase(18, 95, 20, 'Detecting fields and ink',
                'Straightening the page, fitting the columns and rows to its table, and outlining every handwritten line.');
            return;
        }
        if (page.status === 'queued') {
            setOcrPhase(
                15, 18, 3,
                'Waiting for the line detector',
                waitedMs > 20000
                    ? 'No background worker has picked this page up. Run php artisan queue:work (serve.ps1 does it for you).'
                    : 'The page is queued for line detection.',
            );
        } else if (page.status === 'detecting') {
            // Most of a scan's time (20-50 s), so most of the ring.
            setOcrPhase(18, 72, 20, 'Finding handwritten lines',
                'Outlining every handwritten line, including capitals and tails that cross the ruled lines.');
        } else if (page.status === 'reading') {
            setOcrPhase(72, 95, 6, 'Reading handwriting',
                'The selected TrOCR model is reading each outlined line.');
        }
    }

    function canvasBlob(canvas) {
        return new Promise((resolve, reject) => {
            canvas.toBlob(
                (blob) => (blob ? resolve(blob) : reject(new Error('The aligned page could not be prepared. Please try again.'))),
                'image/png',
            );
        });
    }

    function wait(ms, signal) {
        return new Promise((resolve, reject) => {
            const timer = window.setTimeout(resolve, ms);
            signal?.addEventListener('abort', () => {
                window.clearTimeout(timer);
                reject(new DOMException('Aborted', 'AbortError'));
            }, { once: true });
        });
    }

    /**
     * Poll the page until line detection and reading are finished. The results
     * are stored server-side, so this only ever loads them.
     */
    async function waitForPage(initial, signal, done = 'ready', mode = 'scan') {
        const started = Date.now();
        let page = initial;

        for (;;) {
            showPageStatus(page, Date.now() - started, mode);
            if (page.status === done) return page;
            if (page.status === 'failed') {
                throw new Error(page.error || 'The page could not be processed. Please scan again.');
            }

            // Often enough that a finished phase shows at once; the status is a
            // small read, and the results come only with the last one.
            await wait(700, signal);
            const response = await fetch(page.statusUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal,
            });
            const next = (response.headers.get('content-type') || '').includes('application/json')
                ? await response.json()
                : null;
            if (!response.ok || !next) {
                throw new Error(responseErrorMessage(response, next));
            }
            page = next;
        }
    }

    function stopOcrProgress() {
        if (ocrProgressTimer === null) return;
        window.clearInterval(ocrProgressTimer);
        ocrProgressTimer = null;
    }

    async function completeOcrProgress(title = 'Scan complete', note = 'Opening the validation results.') {
        stopOcrProgress();
        setOcrProgress(100, title, note);
        // Long enough to see the ring fill, no longer.
        await new Promise((resolve) => window.setTimeout(resolve, 350));
    }

    /**
     * The markers and row lines as they stand, as the geometry the server
     * works with. Rows Staff set by hand are marked, so Detect keeps them.
     */
    function currentGeometry() {
        return alignedGeometry(marker.toJSON(), gridRows, { keepRows: rowsByHand });
    }

    // ------------------------------------------------------- snap to table
    // The page's printed table lines, found in well under a second: "Snap to
    // table" fits every column and row marker onto them, and while a marker
    // is dragged its edges snap to them (Alt places it freely).

    let snapToken = 0;

    async function requestSnap(signal = null) {
        const form = new FormData();
        form.set('geometry_json', JSON.stringify(currentGeometry()));
        form.set('page', await canvasBlob(marker.canvas), 'page.png');
        const response = await fetch(config.snapUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': config.csrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
            body: form,
        });
        const payload = (response.headers.get('content-type') || '').includes('application/json')
            ? await response.json()
            : null;
        if (!response.ok || !payload?.lines) throw new Error(responseErrorMessage(response, payload));
        return payload;
    }

    /** In the background, so a dragged marker's edges snap from the start. */
    async function fetchSnapLines() {
        const token = ++snapToken;
        marker.setSnapLines(null);
        try {
            const payload = await requestSnap();
            if (token === snapToken) marker.setSnapLines(payload.lines);
        } catch (error) {
            console.warn('Printed lines for snapping could not be found:', error);
        }
    }

    // While it works, the button shows a spinner in place of its icon; its
    // size is held so nothing around it shifts. Detect and Scan wait meanwhile.
    function setSnapBusy(busy) {
        const button = el('snapTableBtn');
        const { width, height } = button.getBoundingClientRect();
        button.style.inlineSize = busy ? `${width}px` : '';
        button.style.blockSize = busy ? `${height}px` : '';
        button.classList.toggle('is-loading', busy);
        button.setAttribute('aria-busy', busy ? 'true' : 'false');
        button.disabled = busy;
    }

    el('snapTableBtn').addEventListener('click', async () => {
        if (scanInProgress) return;
        const token = ++snapToken;
        const controller = new AbortController();
        const timeoutId = window.setTimeout(() => controller.abort(), 60 * 1000);

        scanInProgress = true;
        clearOcrError();
        setSnapBusy(true);
        el('detectBtn').disabled = true;
        el('scanNowBtn').disabled = true;

        try {
            const payload = await requestSnap(controller.signal);
            if (token !== snapToken) return;
            marker.setSnapLines(payload.lines);
            if (!payload.fitted) {
                showOcrError(payload.reason || 'No printed table matching these markers was found on this page.');
                return;
            }
            // The columns fitted to the table and the page's own row lines, as
            // one undoable change (Ctrl+Z) like any marker edit.
            const rows = payload.geometry?.ruled_ys ?? gridRows;
            rowsSource = payload.rows ?? null;
            setGrid(geometryMarkers(payload.geometry, null), rows, {
                estimated: (payload.rows?.estimated ?? []).filter((index) => rows[index] !== undefined)
                    .map((index) => rowKey(rows[index])),
            });
        } catch (error) {
            showOcrError(error.name === 'AbortError'
                ? 'Snap to table timed out. Check that the web app is running, then try again.'
                : (error.message || 'The page could not be snapped to its table.'));
        } finally {
            window.clearTimeout(timeoutId);
            scanInProgress = false;
            setSnapBusy(false);
            const boxes = marker.toJSON();
            el('scanNowBtn').disabled = boxes.length === 0 || markerSetValidationMessage(boxes) !== null;
            el('detectBtn').disabled = el('scanNowBtn').disabled;
        }
    });

    /**
     * Send the page exactly as rendered here, with the markers as they stand,
     * so every outline that comes back lines up with this canvas pixel for pixel.
     */
    async function uploadPage({ detect }, signal) {
        const form = new FormData();
        form.set('document_template_id', String(config.templateId));
        form.set('geometry_json', JSON.stringify(currentGeometry()));
        form.set('page', await canvasBlob(marker.canvas), 'page.png');
        // Absent unless Staff choice is enabled; the server falls back to the
        // promoted model and re-checks that the key is one it allows.
        if (modelSelect instanceof HTMLSelectElement) form.set('model', modelSelect.value);
        if (detect) form.set('detect', '1');

        const response = await fetch(config.pagesUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': config.csrf,
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            signal,
            body: form,
        });
        const payload = (response.headers.get('content-type') || '').includes('application/json')
            ? await response.json()
            : null;
        if (!response.ok || !payload?.statusUrl) {
            throw new Error(responseErrorMessage(response, payload));
        }
        return payload;
    }

    /**
     * Markers moved after Detect: outline that same page again from where they
     * are now, and read it. The page is already on the server, straightened,
     * so only the markers are sent - and the server reuses the lines Detect
     * found instead of detecting the page again, which is nearly all of a
     * scan's work.
     */
    async function reoutlineDetectedPage(signal) {
        const response = await fetch(config.pageGeometryUrl.replace('__PAGE__', String(detection.page.id)), {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': config.csrf,
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            signal,
            body: JSON.stringify({
                geometry_json: JSON.stringify(currentGeometry()),
                model: modelSelect instanceof HTMLSelectElement ? modelSelect.value : null,
            }),
        });
        const payload = (response.headers.get('content-type') || '').includes('application/json')
            ? await response.json()
            : null;
        if (!response.ok || !payload?.statusUrl) {
            throw new Error(responseErrorMessage(response, payload));
        }
        return payload;
    }

    /**
     * Whether that page can be outlined again instead of sent afresh: Detect
     * ran, its page is still usable, and Staff have only moved the markers on
     * it (the page shown is the one the server holds).
     */
    function canReoutline() {
        return detection !== null && !detection.stale && !detectionIsCurrent();
    }

    /** Scan with OCR after Detect: read the crops Detect already made. */
    async function readDetectedPage(signal) {
        const response = await fetch(detection.page.readUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': config.csrf,
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            signal,
            body: JSON.stringify({ model: modelSelect instanceof HTMLSelectElement ? modelSelect.value : null }),
        });
        const payload = (response.headers.get('content-type') || '').includes('application/json')
            ? await response.json()
            : null;
        if (!response.ok || !payload?.statusUrl) {
            throw new Error(responseErrorMessage(response, payload));
        }
        return payload;
    }

    function responseErrorMessage(response, payload) {
        if (response.status === 419) {
            return 'Your session expired. Refresh the page, reopen the document, and try again.';
        }
        if (response.status === 413) {
            return 'The aligned page is too large to send. Use a smaller scan and try again.';
        }
        if (response.status === 422) {
            const validationMessage = payload?.errors
                ? Object.values(payload.errors).flat()[0]
                : null;
            return validationMessage || payload?.message || 'One or more marked fields are invalid.';
        }

        return payload?.message || payload?.error || `OCR failed with HTTP ${response.status}.`;
    }

    // The run behind the progress window, so its × can stop it: the browser
    // stops waiting at once, and the server is told to drop the page (or,
    // for the read of a Detect result, to keep that result).
    let activeRun = null;
    const CANCEL_LABELS = { detect: 'Cancel detection', scan: 'Cancel scan', read: 'Cancel reading' };

    function startRun(controller, kind, pageId = null) {
        activeRun = { controller, kind, pageId, cancelled: false };
        showRunKind(kind);
        el('ocrProgressCancel').disabled = false;
        return activeRun;
    }

    /** Name the cancel button after what is running. */
    function showRunKind(kind) {
        if (activeRun) activeRun.kind = kind;
        el('ocrProgressCancel').textContent = CANCEL_LABELS[kind] ?? 'Cancel';
    }

    function cancelActiveRun() {
        const run = activeRun;
        if (!run || run.cancelled) return;
        run.cancelled = true;
        el('ocrProgressCancel').disabled = true;
        el('ocrProgressCancel').textContent = 'Cancelling…';
        setOcrProgress(ocrProgress, 'Cancelling', 'Stopping this run.');
        run.controller.abort();
        if (run.pageId !== null) {
            fetch(config.pageCancelUrl.replace('__PAGE__', String(run.pageId)), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': config.csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                keepalive: true,
                body: JSON.stringify({ keep_detection: run.kind === 'read' }),
            }).catch((error) => console.warn('Cancelling the page on the server failed:', error));
        }
    }

    el('ocrProgressCancel').addEventListener('click', cancelActiveRun);

    el('scanNowBtn').addEventListener('click', async () => {
        if (scanInProgress) return;

        // Outline changes are only read once saved: offer to save them first.
        applyOpenDrawing();
        const unsavedLines = detectionIsCurrent() ? unsavedLineCount() : 0;
        if (unsavedLines > 0) {
            const plural = unsavedLines === 1 ? 'line has an outline change' : 'lines have outline changes';
            if (!window.confirm(`${unsavedLines} ${plural} that ${unsavedLines === 1 ? 'is' : 'are'} not saved.\n\nOK: save and scan.\nCancel: go back without scanning.`)) {
                return;
            }
            await saveAllUnsavedLines();
            if (unsavedLineCount() > 0) {
                showOcrError('Some outline changes could not be saved. Check the lines marked not saved and try again.');
                return;
            }
        }

        // Outlining the page again works every line out afresh from the moved
        // markers, so outlines adjusted against Detect's result cannot be kept.
        if (canReoutline()) {
            const adjusted = detection.page.lines.filter((line) => line.adjusted || isLineUnsaved(line)).length;
            const plural = adjusted === 1 ? 'adjustment' : 'adjustments';
            if (adjusted > 0 && !window.confirm(
                `You moved a marker after Detect, so every line is worked out again `
                + `and your ${adjusted} outline ${plural} will be lost.\n\n`
                + `OK: scan from the markers as they are.\n`
                + `Cancel: go back (Ctrl+Z brings the Detect result back).`,
            )) {
                return;
            }
        }

        const markerValidationMessage = markerSetValidationMessage(marker.toJSON());
        if (markerValidationMessage) {
            showOcrError(markerValidationMessage);
            return;
        }

        const button = el('scanNowBtn');
        const originalButtonContent = button.innerHTML;
        const controller = new AbortController();
        const run = startRun(controller, 'scan');
        let progress = null;
        let timeoutId = null;

        scanInProgress = true;
        clearOcrError();
        button.disabled = true;
        button.innerHTML = '<i class="icon-base bx bx-scan icon-sm me-1" aria-hidden="true"></i> OCR in progress...';
        el('detectBtn').disabled = true;

        try {
            // Cropping and modal creation used to happen outside the try block. A
            // browser-side failure there made the button appear to do nothing.
            const aligned = marker.toJSON();
            progress = openProgressModal();
            beginOcrProgress(aligned.length);

            await new Promise((resolve) => window.requestAnimationFrame(resolve));

            if (!aligned.length) {
                throw new Error('Add at least one field before reading.');
            }

            timeoutId = window.setTimeout(() => controller.abort(), 15 * 60 * 1000);
            let payload;
            const readingDetectedPage = detectionIsCurrent();
            if (readingDetectedPage) {
                showRunKind('read');
                run.pageId = detection.page.id;
                // Saves still on their way are part of what is read.
                await lineSaveQueue;
                setLineEditing(false);
                // The outlines Staff just checked are the ones read.
                setOcrPhase(72, 95, 6, 'Reading handwriting', 'The selected TrOCR model is reading each detected line.');
                payload = await readDetectedPage(controller.signal);
            } else if (canReoutline()) {
                // The markers moved after Detect. That page is already here and
                // already straightened, so only the markers go over, and its
                // lines are reused rather than found again.
                run.pageId = detection.page.id;
                setLineEditing(false);
                setOcrPhase(30, 72, 4, 'Outlining from your markers',
                    'Reusing the lines Detect found on this page.');
                payload = await reoutlineDetectedPage(controller.signal);
            } else {
                // Finishing Align hands the page to the background line detector.
                setOcrProgress(Math.max(ocrProgress, 8), 'Sending the aligned page', 'Uploading the page securely for line detection.');
                payload = await uploadPage({ detect: false }, controller.signal);
                run.pageId = payload.id;
            }

            const page = await waitForPage(payload, controller.signal);
            clearDetection();

            // A tilted ledger grid makes the server straighten the page before
            // outlining it; show that page so the outlines sit on their writing.
            if (!readingDetectedPage && Math.abs(Number(page.deskew) || 0) >= 0.05) {
                await marker.loadFromUrl(page.imageUrl);
                setGrid(geometryMarkers(page.geometry, page), page.geometry?.ruled_ys ?? gridRows, { byHand: rowsByHand });
            }

            if (!Array.isArray(page.lines) || page.lines.length === 0) {
                throw new Error('No handwriting was found inside the markers. Check the alignment and scan again.');
            }

            if (typeof page.modelKey !== 'string' || page.modelKey.trim() === '') {
                throw new Error('The OCR service did not identify the model used. No data was saved; please scan again.');
            }

            processedPage = page;
            cropped = verificationItems(page.lines, page);
            readings = cropped.map((item) => item.reading);
            requiredInput(document, '#ocrModelKey').value = page.modelKey.trim();
            requiredInput(document, '#documentPageId').value = String(page.id);
            el('summaryModel').textContent = page.model || '—';

            setOcrProgress(96, 'Preparing validation', 'The handwriting results are ready for your review.');
            renderVerifyRows();
            showGridNotes(page);
            await completeOcrProgress();
            showStep('verify');
            try {
                prepareValidationComparison();
            } catch (error) {
                showStep('mark');
                throw error;
            }
        } catch (error) {
            if (run.cancelled) {
                // Cancelled from the progress window: back to Align. A Detect
                // result that was being read is kept, ready to scan again.
                if (run.kind !== 'read') retireDetection();
            } else {
                const message = error.name === 'AbortError'
                    ? 'Line detection and reading timed out. Check the OCR service and the background worker, then try again.'
                    : (error.message || 'The document could not be scanned.');
                console.error('Document OCR failed:', error);
                // A failed read leaves the detected page unusable; the next scan
                // outlines the page afresh from the markers.
                retireDetection();
                showOcrError(message);
            }
        } finally {
            if (activeRun === run) activeRun = null;
            if (timeoutId !== null) window.clearTimeout(timeoutId);
            stopOcrProgress();
            await closeProgressModal(progress);
            scanInProgress = false;
            button.innerHTML = originalButtonContent;
            button.disabled = marker.toJSON().length === 0;
            el('detectBtn').disabled = button.disabled;
        }
    });

    // Detect: straighten this page, fit the template's columns and rows to its
    // own table, and outline every handwritten line - before anything is read.
    el('detectBtn').addEventListener('click', async () => {
        if (scanInProgress) return;

        const markerValidationMessage = markerSetValidationMessage(marker.toJSON());
        if (markerValidationMessage) {
            showOcrError(markerValidationMessage);
            return;
        }

        const button = el('detectBtn');
        const originalButtonContent = button.innerHTML;
        const controller = new AbortController();
        const run = startRun(controller, 'detect');
        const timeoutId = window.setTimeout(() => controller.abort(), 15 * 60 * 1000);
        let progress = null;

        scanInProgress = true;
        clearOcrError();
        button.disabled = true;
        el('scanNowBtn').disabled = true;
        button.innerHTML = '<i class="icon-base bx bx-radar icon-sm me-1" aria-hidden="true"></i> Detecting...';

        try {
            progress = openProgressModal();
            beginOcrProgress(marker.toJSON().length);
            await new Promise((resolve) => window.requestAnimationFrame(resolve));

            setOcrProgress(Math.max(ocrProgress, 8), 'Sending the page', 'Uploading the page securely for detection.');
            const payload = await uploadPage({ detect: true }, controller.signal);
            run.pageId = payload.id;
            const page = await waitForPage(payload, controller.signal, 'detected', 'detect');

            if (!Array.isArray(page.lines) || page.lines.length === 0) {
                throw new Error('No handwriting was found on this page. Check that the right template and file were chosen.');
            }

            await applyDetection(page);
            await completeOcrProgress('Detection complete', 'Check the outlines, then Scan with OCR.');
        } catch (error) {
            if (!run.cancelled) {
                const message = error.name === 'AbortError'
                    ? 'Detection timed out. Check the background worker, then try again.'
                    : (error.message || 'The page could not be detected.');
                console.error('Detection failed:', error);
                showOcrError(message);
            }
        } finally {
            if (activeRun === run) activeRun = null;
            window.clearTimeout(timeoutId);
            stopOcrProgress();
            await closeProgressModal(progress);
            scanInProgress = false;
            button.innerHTML = originalButtonContent;
            button.disabled = marker.toJSON().length === 0;
            el('scanNowBtn').disabled = button.disabled;
        }
    });

    // ---------------------------------------------------------------- step 3
    function normaliseConfidence(reading) {
        const confidence = Number(reading?.confidence ?? 0);
        return Number.isFinite(confidence) ? Math.max(0, Math.min(100, confidence)) : 0;
    }

    function median(values) {
        if (values.length === 0) return 0;
        const sorted = [...values].sort((a, b) => a - b);
        const middle = Math.floor(sorted.length / 2);
        return sorted.length % 2 === 0
            ? (sorted[middle - 1] + sorted[middle]) / 2
            : sorted[middle];
    }

    function buildCustomValidationGroups() {
        const personGroups = new Map();
        const detailIndexes = [];
        const unplacedIndexes = [];

        cropped.forEach((box, index) => {
            const personGroup = Number(box.personGroup);
            const personFieldOrder = Number(box.personFieldOrder);
            const grouped = Number.isInteger(personGroup) && personGroup > 0
                && Number.isInteger(personFieldOrder) && personFieldOrder >= 0;

            // A ledger line that could not be placed in a row belongs to no
            // person until a reviewer decides.
            if (!grouped && Array.isArray(box.flags) && box.flags.includes(FLAG_NO_ROW)) {
                unplacedIndexes.push(index);
                return;
            }

            if (!grouped) {
                detailIndexes.push(index);
                return;
            }

            if (!personGroups.has(personGroup)) personGroups.set(personGroup, []);
            // A field added under no column goes before the column to its right,
            // whose order it shares.
            const betweenColumns = box.gridColumn === null && box.gridRow !== null;
            personGroups.get(personGroup).push({ index, order: personFieldOrder - (betweenColumns ? 0.5 : 0) });
        });

        const groups = [];
        if (detailIndexes.length > 0) {
            groups.push({
                id: 'document-details',
                kind: 'details',
                mode: 'custom',
                label: 'Document details',
                indexes: detailIndexes,
            });
        }

        [...personGroups.entries()]
            .sort(([groupA], [groupB]) => groupA - groupB)
            .forEach(([personGroup, members]) => {
                members.sort((memberA, memberB) => (
                    memberA.order - memberB.order || memberA.index - memberB.index
                ));
                groups.push({
                    id: `person-${personGroup}`,
                    kind: 'person',
                    mode: 'custom',
                    label: `Person ${String(personGroup).padStart(2, '0')}`,
                    indexes: members.map((member) => member.index),
                });
            });

        if (unplacedIndexes.length > 0) {
            groups.push({
                id: 'needs-review',
                kind: 'details',
                mode: 'custom',
                label: 'Needs review',
                indexes: unplacedIndexes,
            });
        }

        return groups;
    }

    function buildAutomaticValidationGroups() {
        const items = cropped.map((box, index) => ({
            index,
            x: Number(box.x ?? 0),
            y: Number(box.y ?? 0),
            w: Number(box.w ?? 0),
            h: Math.max(0.00001, Number(box.h ?? 0)),
            centerY: Number(box.y ?? 0) + Math.max(0.00001, Number(box.h ?? 0)) / 2,
        })).sort((a, b) => a.centerY - b.centerY || a.x - b.x);

        if (items.length === 0) return [];

        const typicalHeight = median(items.map((item) => item.h));
        const centerTolerance = Math.max(0.004, typicalHeight * 0.58);
        const geometricRows = [];

        items.forEach((item) => {
            let closest = null;
            let closestDistance = Number.POSITIVE_INFINITY;
            geometricRows.forEach((row) => {
                const distance = Math.abs(item.centerY - row.centerY);
                if (distance <= centerTolerance && distance < closestDistance) {
                    closest = row;
                    closestDistance = distance;
                }
            });

            if (!closest) {
                geometricRows.push({ centerY: item.centerY, items: [item] });
                return;
            }

            closest.items.push(item);
            closest.centerY = closest.items.reduce((sum, current) => sum + current.centerY, 0)
                / closest.items.length;
        });

        geometricRows.sort((a, b) => a.centerY - b.centerY);
        geometricRows.forEach((row) => row.items.sort((a, b) => a.x - b.x));

        const repeatedRows = geometricRows.filter((row) => row.items.length >= 3);
        if (repeatedRows.length < 2) {
            return [{
                id: 'record-details',
                kind: 'details',
                mode: 'auto',
                label: 'Record details',
                indexes: items.map((item) => item.index),
            }];
        }

        // A registry row is not merely a dense horizontal band. It repeats the
        // same column geometry several times. Requiring that X signature keeps
        // ordinary certificates and headings from becoming fake people.
        const columnFrequency = new Map();
        repeatedRows.forEach((row) => {
            columnFrequency.set(row.items.length, (columnFrequency.get(row.items.length) ?? 0) + 1);
        });
        const dominantColumns = [...columnFrequency.entries()]
            .sort(([columnsA, frequencyA], [columnsB, frequencyB]) => {
                const scoreDifference = (columnsB * frequencyB) - (columnsA * frequencyA);
                return scoreDifference || frequencyB - frequencyA || columnsB - columnsA;
            })[0]?.[0] ?? 0;
        const prototypeRows = repeatedRows.filter((row) => row.items.length === dominantColumns);

        if (prototypeRows.length === 0) {
            return [{
                id: 'record-details',
                kind: 'details',
                mode: 'auto',
                label: 'Record details',
                indexes: items.map((item) => item.index),
            }];
        }

        const centersFor = (row) => row.items.map((item) => item.x + item.w / 2);
        const prototype = prototypeRows
            .map((row) => {
                const centers = centersFor(row);
                const typicalWidth = median(row.items.map((item) => item.w));
                const spacing = centers.slice(1).map((center, index) => center - centers[index]);
                const tolerance = Math.max(
                    0.006,
                    Math.min(typicalWidth * 0.48, (median(spacing) || typicalWidth) * 0.36),
                );
                const alignedPeers = prototypeRows.filter((candidate) => {
                    const candidateCenters = centersFor(candidate);
                    return candidateCenters.every((center, index) => Math.abs(center - centers[index]) <= tolerance);
                }).length;
                return { row, centers, tolerance, alignedPeers };
            })
            .sort((a, b) => b.alignedPeers - a.alignedPeers)[0];

        const minimumColumns = Math.max(3, Math.ceil(dominantColumns * 0.6));
        const alignedRows = geometricRows.filter((row) => {
            if (row.items.length < minimumColumns || row.items.length > dominantColumns + 2) return false;
            const available = new Set(prototype.centers.map((_, index) => index));
            let matches = 0;
            centersFor(row).forEach((center) => {
                let nearestIndex = null;
                let nearestDistance = Number.POSITIVE_INFINITY;
                available.forEach((prototypeIndex) => {
                    const distance = Math.abs(center - prototype.centers[prototypeIndex]);
                    if (distance < nearestDistance) {
                        nearestDistance = distance;
                        nearestIndex = prototypeIndex;
                    }
                });
                if (nearestIndex !== null && nearestDistance <= prototype.tolerance) {
                    matches += 1;
                    available.delete(nearestIndex);
                }
            });
            return matches >= Math.max(3, Math.ceil(Math.min(row.items.length, dominantColumns) * 0.75));
        });

        const minimumRepeatedRows = dominantColumns >= 6 ? 2 : 3;
        if (alignedRows.length < minimumRepeatedRows) {
            return [{
                id: 'record-details',
                kind: 'details',
                mode: 'auto',
                label: 'Record details',
                indexes: items.map((item) => item.index),
            }];
        }

        const rowGaps = alignedRows.slice(1).map((row, index) => row.centerY - alignedRows[index].centerY);
        const typicalGap = median(rowGaps.filter((gap) => gap > 0));
        const rowRuns = [[]];
        alignedRows.forEach((row, index) => {
            if (index > 0 && typicalGap > 0 && row.centerY - alignedRows[index - 1].centerY > typicalGap * 2.2) {
                rowRuns.push([]);
            }
            rowRuns[rowRuns.length - 1].push(row);
        });
        const personRows = rowRuns
            .filter((run) => run.length >= minimumRepeatedRows)
            .sort((a, b) => b.length - a.length)[0] ?? [];

        if (personRows.length === 0) {
            return [{
                id: 'record-details',
                kind: 'details',
                mode: 'auto',
                label: 'Record details',
                indexes: items.map((item) => item.index),
            }];
        }

        const personRowSet = new Set(personRows);
        const detailIndexes = geometricRows
            .filter((row) => !personRowSet.has(row))
            .flatMap((row) => row.items.map((item) => item.index));
        const groups = [];

        if (detailIndexes.length > 0) {
            groups.push({
                id: 'document-details',
                kind: 'details',
                mode: 'auto',
                label: 'Document details',
                indexes: detailIndexes,
            });
        }

        personRows.forEach((row, personIndex) => {
            groups.push({
                id: `person-${personIndex + 1}`,
                kind: 'person',
                mode: 'auto',
                label: `Person ${String(personIndex + 1).padStart(2, '0')}`,
                indexes: row.items.map((item) => item.index),
            });
        });

        return groups;
    }

    function buildValidationGroups() {
        // A ledger's rows are its people, so its lines always group by row.
        return config.groupingMode === 'custom' || templateColumns.length > 0
            ? buildCustomValidationGroups()
            : buildAutomaticValidationGroups();
    }

    function validationGroupForField(index) {
        return validationGroupByField.get(index) ?? null;
    }

    function validationRow(index) {
        return validationRowByIndex.get(index) ?? null;
    }

    function groupIdentity(group) {
        if (group.kind !== 'person') return `${group.indexes.length} document field${group.indexes.length === 1 ? '' : 's'}`;

        // The field the layout says holds the person's name.
        const named = group.indexes.find((index) => (
            settingsFor(index)?.role === 'name' && String(readings[index]?.text ?? '').trim() !== ''
        ));
        if (named !== undefined) return String(readings[named].text).trim();

        const candidates = group.indexes
            .map((index) => ({
                index,
                width: Number(cropped[index]?.w ?? 0),
                text: String(readings[index]?.text ?? '').trim(),
            }))
            .filter((candidate) => candidate.text.length >= 3 && /[a-z]/i.test(candidate.text))
            .sort((a, b) => b.width - a.width);

        return candidates[0]?.text || `${group.indexes.length} fields`;
    }

    // A line is labelled with its template field's or column's own name.
    function validationFieldLabel(group, columnIndex, fallback) {
        return String(fallback || `Field ${columnIndex + 1}`);
    }

    /**
     * The template's ledger columns on this page's grid. A column Staff removed
     * in Align is not expected in any row (the removal goes to the audit log).
     */
    function columnsOnThisPage() {
        const pageColumns = new Set((processedPage?.geometry?.columns ?? []).map((column) => nameKey(column.name)));
        return config.boxes.filter((box) => box.kind === 'column'
            && (pageColumns.size === 0 || pageColumns.has(nameKey(box.name))));
    }

    /**
     * Required fields of this person that nothing was read for: the ledger
     * columns missing from a row, or a person's template fields.
     */
    function missingRequired(group) {
        if (group.kind !== 'person' || group.indexes.length === 0) return [];

        const present = new Set(group.indexes.map((index) => nameKey(cropped[index]?.label)));
        const ledgerRow = group.indexes.some((index) => settingsFor(index)?.kind === 'column');
        const personGroup = Number(cropped[group.indexes[0]]?.personGroup);
        const expected = ledgerRow
            ? columnsOnThisPage()
            : config.boxes.filter((box) => box.kind !== 'column' && Number(box.personGroup) === personGroup);

        return expected
            .filter((box) => box.required !== false && !present.has(nameKey(box.name)))
            .map((box) => box.name);
    }

    function prepareValidationComparison() {
        const source = el('pageCanvas');
        const target = el('validationPageCanvas');
        const context = target.getContext('2d');

        if (!context || source.width === 0 || source.height === 0) {
            throw new Error('The original document could not be prepared for comparison. Please scan again.');
        }

        activeValidationIndex = null;
        stopAddingMissedField();
        target.width = source.width;
        target.height = source.height;
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, target.width, target.height);
        context.drawImage(source, 0, 0);

        cancelOutlineEdit();
        validationMarker.setBoxes([]);
        lineOverlay.setLines(processedPage, cropped);
        validationMarker.resetZoom();
        el('validationDocViewport').scrollTo({ top: 0, left: 0 });
        el('validationFileName').textContent = scanFile?.name || 'Document';

        window.requestAnimationFrame(() => {
            validationMarker.layout();
            updateValidationMarkerStates();
            const initialGroup = validationGroups.find((group) => group.kind === 'person')
                ?? validationGroups[0];
            if (initialGroup) activateValidationGroup(initialGroup.id, 'initial');
        });
    }

    function handleValidationMarkerSelection(indexes, context = {}) {
        if (syncingValidationSelection) return;
        if (indexes.length === 0) {
            activeValidationIndex = null;
            activeValidationGroupId = null;
            el('verifyRows').querySelectorAll('.validation-field').forEach((row) => {
                row.classList.remove('is-active');
                row.removeAttribute('aria-current');
            });
            el('verifyRows').querySelectorAll('.validation-record-group').forEach((group) => {
                group.classList.remove('is-active');
            });
            updateValidationMarkerStates();
            return;
        }
        const selectedIndex = Number.isInteger(context.activeIndex)
            ? context.activeIndex
            : indexes[indexes.length - 1];
        activateValidationField(selectedIndex, 'marker');
    }

    function revealValidationRow(index) {
        const list = el('verifyRows');
        const row = validationRow(index);
        if (!(row instanceof HTMLElement)) return;

        const listRect = list.getBoundingClientRect();
        const rowRect = row.getBoundingClientRect();
        const target = list.scrollTop
            + rowRect.top - listRect.top
            - (list.clientHeight - rowRect.height) / 2;
        const clampedTarget = Math.min(
            Math.max(0, list.scrollHeight - list.clientHeight),
            Math.max(0, target),
        );
        const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        list.scrollTo({ top: clampedTarget, behavior: smooth ? 'smooth' : 'auto' });
    }

    function revealValidationLines(indexes) {
        const bounds = lineOverlay.displayBounds(indexes);
        if (!bounds) return;

        const viewport = el('validationDocViewport');
        viewport.scrollTo({
            left: Math.max(0, (bounds.left + bounds.right) / 2 - viewport.clientWidth / 2),
            top: Math.max(0, (bounds.top + bounds.bottom) / 2 - viewport.clientHeight / 2),
            behavior: 'smooth',
        });
    }

    function revealValidationMarker(index) {
        revealValidationLines([index]);
    }

    function revealValidationGroup(group) {
        revealValidationLines(group.indexes);
    }

    // Resolves when the opening and closing animations are done, so a scroll
    // can be aimed at the list's final layout rather than a half-open one.
    function setExpandedValidationGroup(groupId) {
        const animations = [];
        el('verifyRows').querySelectorAll('.validation-record-group').forEach((section) => {
            const expanded = section.dataset.groupId === groupId;
            section.classList.toggle('is-expanded', expanded);
            const button = section.querySelector('.validation-record-group__toggle');
            const body = section.querySelector('.validation-record-group__body');
            button?.setAttribute('aria-expanded', String(expanded));
            button?.classList.toggle('collapsed', !expanded);
            if (body instanceof HTMLElement) animations.push(setDisclosureExpanded(body, expanded));
        });
        return Promise.all(animations);
    }

    function selectValidationGroupMarkers(group) {
        lineOverlay.setSelection(group.indexes, activeValidationIndex);
    }

    function activateValidationGroup(groupId, source = 'group') {
        const group = validationGroups.find((candidate) => candidate.id === groupId);
        if (!group) return;

        activeValidationGroupId = group.id;
        activeValidationIndex = null;
        const expansion = setExpandedValidationGroup(group.id);
        selectValidationGroupMarkers(group);

        el('verifyRows').querySelectorAll('.validation-record-group').forEach((section) => {
            section.classList.toggle('is-active', section.dataset.groupId === group.id);
        });
        el('verifyRows').querySelectorAll('.validation-field').forEach((row) => {
            row.classList.remove('is-active');
            row.removeAttribute('aria-current');
        });
        updateValidationMarkerStates();

        if (source === 'group') revealValidationGroup(group);
        const section = el('verifyRows').querySelector(`[data-group-id="${group.id}"]`);
        expansion.then(() => {
            if (activeValidationGroupId === group.id) {
                section?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        });
    }

    function activateValidationField(index, source = 'row') {
        if (!Number.isInteger(index) || index < 0 || index >= readings.length) return;
        const group = validationGroupForField(index);
        if (!group) return;

        activeValidationIndex = index;
        activeValidationGroupId = group.id;
        const expansion = setExpandedValidationGroup(group.id);

        selectValidationGroupMarkers(group);

        el('verifyRows').querySelectorAll('.validation-field').forEach((row) => {
            const active = Number(row.dataset.fieldIndex) === index;
            row.classList.toggle('is-active', active);
            if (active) row.setAttribute('aria-current', 'true');
            else row.removeAttribute('aria-current');
        });
        el('verifyRows').querySelectorAll('.validation-record-group').forEach((section) => {
            section.classList.toggle('is-active', section.dataset.groupId === group.id);
        });
        updateValidationMarkerStates();

        // Wait for the person's rows to finish opening (and the previous person's
        // to finish closing): measured earlier, the row's position is wrong and
        // the list scrolls past it. Skip if another field was picked meanwhile.
        if (source === 'marker') {
            expansion.then(() => {
                if (activeValidationIndex === index) revealValidationRow(index);
            });
        }
        if (source === 'row') revealValidationMarker(index);
    }

    function updateValidationMarkerStates() {
        cropped.forEach((_, index) => {
            const checkbox = validationRow(index)?.querySelector('.validation-verified');
            lineOverlay.setVerified(index, checkbox instanceof HTMLInputElement && checkbox.checked);
        });
        const group = validationGroups.find((candidate) => candidate.id === activeValidationGroupId);
        lineOverlay.setSelection(group?.indexes ?? [], activeValidationIndex);
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
        updateValidationGroupSummaries();
        updateValidationMarkerStates();
    }

    function updateValidationGroupSummaries() {
        validationGroups.forEach((group) => {
            const section = el('verifyRows').querySelector(`[data-group-id="${group.id}"]`);
            if (!(section instanceof HTMLElement)) return;

            const checkedStates = group.indexes.map((index) => {
                const checkbox = validationRow(index)?.querySelector('.validation-verified');
                return checkbox instanceof HTMLInputElement && checkbox.checked;
            });
            const state = verificationGroupState(checkedStates);
            const status = section.querySelector('.validation-record-group__status');
            if (status) status.textContent = `${state.verified}/${state.total} verified`;
            section.classList.toggle('is-complete', state.checked);

            const groupCheckbox = section.querySelector('.validation-group-verified');
            if (groupCheckbox instanceof HTMLInputElement) {
                groupCheckbox.checked = state.checked;
                groupCheckbox.indeterminate = state.indeterminate;
                groupCheckbox.setAttribute(
                    'aria-checked',
                    state.indeterminate ? 'mixed' : String(state.checked),
                );
            }
        });
    }

    function setGroupVerified(group, checked) {
        let firstBlankIndex = null;

        group.indexes.forEach((index) => {
            const row = validationRow(index);
            if (!(row instanceof HTMLElement)) return;

            const input = requiredInput(row, '.verified');
            const checkbox = requiredInput(row, '.validation-verified');

            if (checked && !canVerifyValue(input.value)) {
                checkbox.checked = false;
                row.classList.remove('is-verified');
                setValidationFieldError(index, 'Enter or confirm a value before marking this field as verified.');
                firstBlankIndex ??= index;
                return;
            }

            checkbox.checked = checked;
            row.classList.toggle('is-verified', checked);
            clearValidationFieldError(index);
        });

        updateVerificationSummary();

        if (firstBlankIndex !== null) {
            showValidationSubmitError(`${group.label} has blank fields that could not be verified.`);
            activateValidationField(firstBlankIndex, 'marker');
            const row = validationRow(firstBlankIndex);
            if (row) requiredInput(row, '.verified').focus();
            return;
        }

        clearValidationSubmitError();
    }

    function createValidationFieldRow(reading, index, group, columnIndex) {
        const confidence = normaliseConfidence(reading);
        const flagged = confidence < config.threshold;
        const inputId = `verifiedField${index}`;
        const checkboxId = `verifyField${index}`;
        const row = document.createElement('article');

        row.className = 'validation-field';
        row.dataset.fieldIndex = String(index);
        row.tabIndex = 0;
        validationRowByIndex.set(index, row);
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
                <div class="validation-field__entry">
                    <img class="validation-field__crop" alt="" loading="lazy" decoding="async">
                    <input type="text" id="${inputId}" class="form-control verified"
                           maxlength="2000" autocomplete="off">
                </div>
                <div class="validation-field__reading">
                    <span class="validation-field__reading-text">TrOCR read: <span></span></span>
                    <button type="button" class="btn btn-link btn-sm validation-field__adjust">
                        <i class="icon-base bx bx-shape-polygon" aria-hidden="true"></i>Adjust outline
                    </button>
                </div>
                <div class="validation-field__hint d-none"></div>
                <div class="validation-field__type d-none"></div>
                <div class="validation-field__flag d-none"></div>
                <div class="validation-field__ocr-error d-none">
                    TrOCR could not read this marker. Enter the value manually.
                </div>
                <div class="validation-field__error d-none" role="alert"></div>
            </div>
            <div class="validation-field__check">
                <label class="validation-verified-control" for="${checkboxId}" title="Mark as verified">
                    <input class="form-check-input validation-verified" type="checkbox"
                           id="${checkboxId}">
                    <span class="visually-hidden">Verified</span>
                </label>
            </div>`;

        const displayNumber = group.kind === 'person' ? columnIndex + 1 : index + 1;
        requiredPart(row, '.validation-field__number').textContent = String(displayNumber).padStart(2, '0');
        const item = cropped[index] ?? {};
        const displayName = validationFieldLabel(group, columnIndex, item.heading || item.label || reading.name);
        requiredPart(row, '.validation-field__name').textContent = displayName;
        requiredPart(row, `label[for="${inputId}"]`).textContent = `Verified value for ${displayName}`;

        const badge = requiredPart(row, '.validation-field__confidence');
        badge.textContent = `${confidence.toFixed(1)}%`;
        badge.classList.add(flagged ? 'is-low' : 'is-ready');

        // The exact masked crop TrOCR read, beside the value it produced.
        const thumbnail = row.querySelector('.validation-field__crop');
        if (thumbnail instanceof HTMLImageElement && item.cropUrl) {
            thumbnail.src = item.cropUrl;
            thumbnail.alt = `Crop TrOCR read for ${displayName}`;
            thumbnail.title = 'The exact image TrOCR read';
        } else {
            thumbnail?.remove();
        }

        const adjust = requiredPart(row, '.validation-field__adjust');
        adjust.setAttribute('aria-label', `Adjust the outline of ${displayName}`);
        adjust.addEventListener('click', (event) => {
            event.stopPropagation();
            // A second click on the same field closes its tools.
            if (editingLineIndex === index) cancelOutlineEdit();
            else startOutlineEdit(index);
        });

        // A field the reviewer added, or one between two rows: its person, column, Remove.
        const placement = fieldPlacementControls(index);
        if (placement) requiredPart(row, '.validation-field__reading').after(placement);

        // A field with Person and Column says it there (an empty Person is
        // outlined red), not in a red note.
        const explanation = placement ? '' : flagExplanation(item.flags ?? []);
        if (explanation) {
            row.classList.add('has-line-flag');
            const flag = requiredPart(row, '.validation-field__flag');
            flag.textContent = explanation;
            flag.classList.remove('d-none');
        }

        const readingText = String(reading.text ?? '');
        requiredPart(row, '.validation-field__reading-text span').textContent = readingText || '(nothing read)';

        const input = requiredInput(row, '.verified');
        const checkbox = requiredInput(row, '.validation-verified');
        input.value = readingText;
        if (flagged) row.classList.add('needs-review');

        // The template's hint, and whether the value fits its field (a date,
        // a number, one of a list). Pointed out, never blocking.
        const settings = settingsFor(index);
        const hint = requiredPart(row, '.validation-field__hint');
        if (settings?.hint) {
            hint.textContent = settings.hint;
            hint.classList.remove('d-none');
        }
        const typeNote = requiredPart(row, '.validation-field__type');
        const checkValue = () => {
            const problem = valueProblem(input.value, settings ?? {});
            typeNote.textContent = problem ?? '';
            typeNote.classList.toggle('d-none', !problem);
            row.classList.toggle('has-value-problem', Boolean(problem));
        };
        checkValue();
        input.addEventListener('input', checkValue);
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

        return row;
    }

    function createValidationGroup(group) {
        const section = document.createElement('section');
        const bodyId = `validationGroupBody-${group.id}`;
        section.className = 'validation-record-group';
        section.dataset.groupId = group.id;
        section.innerHTML = `
            <button type="button" class="accordion-button collapsed validation-record-group__toggle"
                    aria-expanded="false" aria-controls="${bodyId}">
                <span class="validation-record-group__number"></span>
                <span class="validation-record-group__copy">
                    <strong></strong>
                    <small></small>
                </span>
                <span class="validation-record-group__meta">
                    <span class="validation-record-group__review"></span>
                    <span class="validation-record-group__status"></span>
                </span>
                <i class="icon-base bx bx-chevron-down" aria-hidden="true"></i>
            </button>
            <div class="validation-record-group__body" id="${bodyId}" hidden></div>`;

        const number = requiredPart(section, '.validation-record-group__number');
        number.textContent = group.kind === 'person'
            ? group.label.replace('Person ', '')
            : 'i';
        requiredPart(section, '.validation-record-group__copy strong').textContent = group.label;
        requiredPart(section, '.validation-record-group__copy small').textContent = groupIdentity(group);

        const reviewCount = group.indexes.filter((index) => (
            normaliseConfidence(readings[index]) < config.threshold || cropped[index]?.needsReview
        )).length;
        const missing = missingRequired(group);
        const review = requiredPart(section, '.validation-record-group__review');
        review.textContent = [
            reviewCount > 0 ? `${reviewCount} to review` : null,
            missing.length > 0 ? `${missing.length} missing` : null,
        ].filter(Boolean).join(' · ') || 'Ready to review';
        review.classList.toggle('has-review', reviewCount > 0 || missing.length > 0);

        const body = requiredPart(section, '.validation-record-group__body');
        if (missing.length > 0) {
            const note = document.createElement('div');
            note.className = 'validation-record-group__missing';
            note.textContent = `Nothing was read for ${missing.join(', ')}. If the page has ${missing.length === 1 ? 'it' : 'them'}, `
                + 'use Add missed field above the original document and drag a box over it; if the register leaves it blank, carry on.';
            body.appendChild(note);
        }
        if (group.kind === 'person') {
            const bulk = document.createElement('div');
            const checkboxId = `verifyGroup-${group.id}`;
            bulk.className = 'validation-record-group__bulk';
            bulk.innerHTML = `
                <div class="validation-record-group__bulk-copy">
                    <strong>All fields</strong>
                    <small></small>
                </div>
                <div class="validation-record-group__bulk-action">
                    <label class="validation-verified-control validation-group-verified-control"
                           for="${checkboxId}">
                        <input class="form-check-input validation-group-verified" type="checkbox"
                               id="${checkboxId}">
                        <span>Verify all</span>
                    </label>
                </div>`;
            requiredPart(bulk, 'small').textContent = `Mark every populated field in ${group.label} as verified.`;

            const groupCheckbox = requiredInput(bulk, '.validation-group-verified');
            groupCheckbox.setAttribute('aria-label', `Verify all fields for ${group.label}`);
            groupCheckbox.addEventListener('change', () => {
                setGroupVerified(group, groupCheckbox.checked);
            });
            body.appendChild(bulk);
        }

        group.indexes.forEach((index, columnIndex) => {
            body.appendChild(createValidationFieldRow(readings[index], index, group, columnIndex));
        });

        const toggle = requiredPart(section, '.validation-record-group__toggle');
        toggle.addEventListener('click', () => {
            if (section.classList.contains('is-expanded')) {
                section.classList.remove('is-expanded');
                toggle.setAttribute('aria-expanded', 'false');
                toggle.classList.add('collapsed');
                setDisclosureExpanded(body, false);
                activeValidationGroupId = group.id;
                activeValidationIndex = null;
                selectValidationGroupMarkers(group);
                section.classList.add('is-active');
                el('verifyRows').querySelectorAll('.validation-field').forEach((row) => {
                    row.classList.remove('is-active');
                    row.removeAttribute('aria-current');
                });
                updateValidationMarkerStates();
                revealValidationGroup(group);
                return;
            }
            activateValidationGroup(group.id, 'group');
        });

        return section;
    }

    function renderVerifyRows() {
        const container = el('verifyRows');
        container.innerHTML = '';
        activeValidationIndex = null;
        activeValidationGroupId = null;
        validationGroupByField = new Map();
        validationRowByIndex = new Map();
        clearValidationSubmitError();
        validationGroups = buildValidationGroups();
        validationGroups.forEach((group) => {
            group.indexes.forEach((index) => validationGroupByField.set(index, group));
        });

        validationGroups.forEach((group) => container.appendChild(createValidationGroup(group)));

        const confidences = readings.map(normaliseConfidence);
        const average = confidences.length
            ? confidences.reduce((a, b) => a + b, 0) / confidences.length
            : 0;
        const flaggedCount = confidences.filter((confidence, index) => (
            confidence < config.threshold || cropped[index]?.needsReview
        )).length;

        el('summaryConfidence').textContent = `${average.toFixed(1)}%`;
        el('summaryReview').textContent = `${flaggedCount}/${confidences.length}`;
        updateVerificationSummary();
    }

    // ---------------------------------------------------------- outline fixes
    /**
     * Open or close one of the tool bars above the page (Adjust outline, Add
     * missed field): it slides down when shown and up when closed, and the
     * page below eases with it. Opening while it is already open does nothing;
     * opening while it is closing turns it round.
     */
    function slideToolbar(toolbar, open) {
        toolbar.getAnimations().forEach((animation) => animation.cancel());
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const hidden = toolbar.classList.contains('d-none');

        if (open) {
            if (!hidden) return;
            toolbar.classList.remove('d-none');
            const height = toolbar.offsetHeight;
            if (reduceMotion || height === 0) return;
            toolbar.animate([
                { maxBlockSize: '0px', paddingBlock: '0px', opacity: 0, transform: 'translateY(-.5rem)' },
                { maxBlockSize: `${height}px`, opacity: 1, transform: 'translateY(0)' },
            ], { duration: 280, easing: 'ease-out' });
            return;
        }

        if (hidden) return;
        const height = toolbar.offsetHeight;
        if (reduceMotion || height === 0) {
            toolbar.classList.add('d-none');
            return;
        }
        const closing = toolbar.animate([
            { maxBlockSize: `${height}px`, opacity: 1, transform: 'translateY(0)' },
            { maxBlockSize: '0px', paddingBlock: '0px', opacity: 0, transform: 'translateY(-.5rem)' },
        ], { duration: 220, easing: 'ease-in', fill: 'forwards' });
        closing.finished.then(() => {
            toolbar.classList.add('d-none');
            closing.cancel();
        }).catch(() => {}); // turned round by a new open
    }

    function lineUpdateUrl(lineId) {
        return config.lineUpdateUrl
            .replace('__PAGE__', String(processedPage?.id ?? ''))
            .replace('__LINE__', String(lineId));
    }

    function showLineEditStatus(message, isError = false) {
        const status = el('lineEditStatus');
        status.textContent = message;
        status.title = message;
        // Never hidden: its height is reserved so the toolbar does not resize (see the SCSS).
        status.classList.toggle('is-error', isError);
    }

    function startOutlineEdit(index) {
        if (!processedPage || !cropped[index]) return;
        stopAddingMissedField();
        // Moving to another field keeps the tools open, so they do not close and reopen.
        cancelOutlineEdit({ hide: false });

        editingLineIndex = index;
        activateValidationField(index, 'row');
        el('lineEditName').textContent = cropped[index].name;
        // The tools slide down above the page, in the document pane; the field list does not move.
        slideToolbar(el('lineEditToolbar'), true);
        showLineEditStatus('');
        const mode = document.querySelector('input[name="verifyLineMode"]:checked')?.value ?? 'box';
        lineOverlay.beginEdit(index, mode);
        showVerifyLineMode(mode);
    }

    const VERIFY_MODE_HINTS = {
        box: 'Drag the handles to stretch the outline.',
        points: 'Drag a corner to move it; drag a dot between corners to add one; Delete removes one.',
        draw: 'Click round the writing or hold and trace. Enter closes it; Ctrl+Z undoes a point; Esc cancels.',
        rectangle: 'Drag a box over the writing.',
    };

    /** Keep the Verify tool buttons and hint in step with the outline editor. */
    function showVerifyLineMode(mode) {
        const input = document.querySelector(`input[name="verifyLineMode"][value="${mode}"]`);
        if (input) input.checked = true;
        if (editingLineIndex !== null) showLineEditStatus(VERIFY_MODE_HINTS[mode] ?? '');
    }

    function showVerifyDraft(points) {
        if (editingLineIndex === null || lineOverlay.editing?.mode !== 'draw') return;
        showLineEditStatus(points === 0
            ? VERIFY_MODE_HINTS.draw
            : points < 3
                ? `${points} point${points === 1 ? '' : 's'}. At least 3 to make an outline.`
                : `${points} points. Enter or double-click closes it; Save and re-read uses it.`);
    }

    /** Close the outline tools (they slide up), unless `hide` is false: the caller opens them again at once. */
    function cancelOutlineEdit({ hide = true } = {}) {
        if (editingLineIndex === null) return;
        lineOverlay.cancelEdit();
        editingLineIndex = null;
        if (hide) slideToolbar(el('lineEditToolbar'), false);
        el('lineEditSave').disabled = false;
    }

    /**
     * Save the redrawn outline: the server crops along it with crop_line() and
     * TrOCR reads that one line again. Every other field keeps what the
     * reviewer typed and checked.
     */
    async function saveOutlineEdit() {
        const index = editingLineIndex;
        if (index === null) return;
        if (lineOverlay.isDrawing() && !lineOverlay.finishDrawing()) {
            showLineEditStatus('This drawing is not an outline yet: it needs at least 3 points, with some height and width.', true);
            return;
        }
        const polygon = lineOverlay.editedPolygon();
        if (!polygon) return;

        const item = cropped[index];
        const button = el('lineEditSave');
        button.disabled = true;
        showLineEditStatus('Cropping along the new outline and reading it again…');

        try {
            const { response, payload } = await putLineOutline(lineUpdateUrl(item.lineId), { polygon });

            if (!payload?.line) {
                throw new Error(responseErrorMessage(response, payload));
            }

            applyLineUpdate(payload.line, payload.flags ?? {});
            cancelOutlineEdit();
            if (!response.ok) {
                showOcrLineWarning(payload.line.id, payload.message || 'The outline was saved, but TrOCR could not read it.');
            }
        } catch (error) {
            button.disabled = false;
            showLineEditStatus(error.message || 'The outline could not be saved.', true);
        }
    }

    function showOcrLineWarning(lineId, message) {
        const index = cropped.findIndex((candidate) => candidate.lineId === lineId);
        if (index >= 0) setValidationFieldError(index, message);
    }

    /**
     * Fold one re-read line (and any flags that changed with it) back into the
     * Verify list, keeping every other field's typed value and check.
     */
    function applyLineUpdate(line, flagsById) {
        rebuildVerifyLines(
            processedPage.lines.map((candidate) => (candidate.id === line.id ? line : candidate)),
            flagsById,
            { focusLineId: line.id, freshLineId: line.id, focusFrom: 'row' },
        );
    }

    /**
     * Show the page's lines again after one was added, moved, re-read or
     * removed. Every field keeps what the reviewer typed and checked, except
     * `freshLineId`, which shows its new reading.
     */
    function rebuildVerifyLines(lines, flagsById = {}, { focusLineId = null, freshLineId = null, focusFrom = 'marker' } = {}) {
        const kept = new Map();
        cropped.forEach((item, index) => {
            const row = validationRow(index);
            if (!row || item.lineId === freshLineId) return;
            kept.set(item.lineId, {
                value: requiredInput(row, '.verified').value,
                verified: requiredInput(row, '.validation-verified').checked,
            });
        });

        processedPage.lines = lines.map((candidate) => {
            const flags = flagsById[candidate.id];
            return Array.isArray(flags) ? { ...candidate, flags } : candidate;
        });
        cropped = verificationItems(processedPage.lines, processedPage);
        readings = cropped.map((item) => item.reading);

        renderVerifyRows();
        cropped.forEach((item, index) => {
            const previous = kept.get(item.lineId);
            const row = validationRow(index);
            if (!previous || !row) return;
            requiredInput(row, '.verified').value = previous.value;
            requiredInput(row, '.validation-verified').checked = previous.verified;
            row.classList.toggle('is-verified', previous.verified);
        });
        lineOverlay.setLines(processedPage, cropped);
        updateVerificationSummary();

        const index = cropped.findIndex((item) => item.lineId === focusLineId);
        if (index >= 0) activateValidationField(index, focusFrom);
    }

    // ------------------------------------------------------ add missed field
    // Writing the line detector missed: the reviewer drags a box over it, the
    // server crops that box, places it in its person's row and column, and
    // TrOCR reads it alone. Nothing else on the page is read again.

    function verifyLineUrl(template, lineId) {
        return template
            .replace('__PAGE__', String(processedPage?.id ?? ''))
            .replace('__LINE__', String(lineId));
    }

    async function sendLineRequest(url, method, body = null) {
        const response = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': config.csrf,
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            ...(body ? { body: JSON.stringify(body) } : {}),
        });
        const payload = (response.headers.get('content-type') || '').includes('application/json')
            ? await response.json()
            : null;
        return { response, payload };
    }

    function showAddFieldStatus(message, isError = false) {
        const status = el('addFieldStatus');
        status.textContent = message;
        status.title = message;
        status.classList.toggle('is-error', isError);
    }

    // Boxes drawn and waiting for Read (page pixels), in the order drawn.
    let pendingBoxes = [];
    // Where each added field seems to sit, by line id: shown as "(suggested)"
    // in its Person and Column lists, never applied by itself.
    const placementSuggestions = new Map();
    let readingPendingBoxes = false;

    const ADD_MODE_HINTS = {
        rectangle: 'Drag a box over each piece of writing with no reading.',
        draw: 'Click round the writing or hold and trace; Enter or double-click closes it.',
    };

    function addFieldMode() {
        return document.querySelector('input[name="addFieldMode"]:checked')?.value ?? 'rectangle';
    }

    /** The strip's hint, the Read button's count, and the boxes on the page. */
    function showPendingBoxes(message = null) {
        const count = pendingBoxes.length;
        lineOverlay.setPending(pendingBoxes);
        const read = el('addFieldRead');
        read.disabled = count === 0 || readingPendingBoxes;
        read.querySelector('span').textContent = count === 0 ? 'Read boxes' : `Read ${count} box${count === 1 ? '' : 'es'}`;
        showAddFieldStatus(message ?? (count === 0
            ? `${ADD_MODE_HINTS[addFieldMode()]} Nothing is read until you press Read.`
            : `${count} box${count === 1 ? '' : 'es'} ready. Mark another, or press Read (Enter). Ctrl+Z takes back the last one.`));
    }

    /** Ready for the next box, in the chosen way. */
    function armAddFieldDrawing() {
        if (addingMissedField && !readingPendingBoxes) lineOverlay.beginDraw(addFieldMode());
    }

    function startAddingMissedField() {
        if (!processedPage || recordSubmitting) return;
        cancelOutlineEdit();
        addingMissedField = true;
        pendingBoxes = [];
        el('addMissedFieldBtn').classList.add('active');
        el('addMissedFieldBtn').setAttribute('aria-pressed', 'true');
        slideToolbar(el('addFieldToolbar'), true);
        showPendingBoxes();
        armAddFieldDrawing();
    }

    /** Close the tool. Boxes not read yet are dropped. */
    function stopAddingMissedField() {
        if (!addingMissedField || readingPendingBoxes) return;
        addingMissedField = false;
        pendingBoxes = [];
        lineOverlay.setPending([]);
        if (lineOverlay.isAddingNew()) lineOverlay.cancelEdit();
        el('addMissedFieldBtn').classList.remove('active');
        el('addMissedFieldBtn').setAttribute('aria-pressed', 'false');
        slideToolbar(el('addFieldToolbar'), false);
    }

    /** A box was closed on the page: it waits for Read, and the next can be drawn. */
    function addPendingBox(polygon) {
        pendingBoxes.push(polygon);
        showPendingBoxes();
        armAddFieldDrawing();
    }

    function removeLastPendingBox() {
        if (pendingBoxes.length === 0) return false;
        pendingBoxes.pop();
        showPendingBoxes();
        return true;
    }

    /**
     * Read: each box is cropped, placed in its person's row and column, and
     * read by TrOCR alone, one after another. Nothing else is read again.
     */
    async function readPendingBoxes() {
        if (pendingBoxes.length === 0 || readingPendingBoxes) return;
        // A drawing left open is a box too.
        if (lineOverlay.isDrawing() && lineOverlay.finishDrawing()) return readPendingBoxes();

        readingPendingBoxes = true;
        if (lineOverlay.isAddingNew()) lineOverlay.cancelEdit();
        el('addFieldCancel').disabled = true;
        el('addFieldToolbar').querySelectorAll('input[name="addFieldMode"]').forEach((input) => { input.disabled = true; });
        showPendingBoxes(`Reading ${pendingBoxes.length} box${pendingBoxes.length === 1 ? '' : 'es'}…`);

        const added = [];
        let flags = {};
        const failed = [];
        const warnings = [];
        for (const [index, polygon] of pendingBoxes.entries()) {
            showAddFieldStatus(`Reading box ${index + 1} of ${pendingBoxes.length}…`);
            try {
                const { response, payload } = await sendLineRequest(
                    config.lineStoreUrl.replace('__PAGE__', String(processedPage.id)), 'POST', { polygon },
                );
                if (!payload?.line) throw new Error(responseErrorMessage(response, payload));
                added.push(payload.line);
                if (payload.suggestion) placementSuggestions.set(payload.line.id, payload.suggestion);
                flags = payload.flags ?? flags;
                if (!response.ok) warnings.push(payload.line.id);
            } catch (error) {
                failed.push({ polygon, message: error.message || 'The box could not be read.' });
            }
        }

        readingPendingBoxes = false;
        el('addFieldCancel').disabled = false;
        el('addFieldToolbar').querySelectorAll('input[name="addFieldMode"]').forEach((input) => { input.disabled = false; });

        if (added.length > 0) {
            rebuildVerifyLines([...processedPage.lines, ...added], flags,
                { focusLineId: added[0].id, freshLineId: null });
            pushVerifyUndo({ kind: 'added', ids: added.map((line) => line.id) });
            warnings.forEach((id) => showOcrLineWarning(id, 'The box was added, but TrOCR could not read it. Type the value.'));
        }

        if (failed.length === 0) {
            stopAddingMissedField();
            return;
        }
        // Keep the ones that failed on the page, to read again or take back.
        pendingBoxes = failed.map((failure) => failure.polygon);
        showPendingBoxes(`${failed.length} box${failed.length === 1 ? '' : 'es'} could not be read: ${failed[0].message}`);
        el('addFieldStatus').classList.add('is-error');
        armAddFieldDrawing();
    }

    /** How a drawing for Add missed field stands, in the strip. */
    function showAddFieldDraft(points) {
        if (points === 0) {
            showPendingBoxes();
            return;
        }
        showAddFieldStatus(points < 3
            ? `${points} point${points === 1 ? '' : 's'}. At least 3 to make an outline.`
            : `${points} points. Enter or double-click closes it.`);
    }
    /**
     * Move a field to another person (row), and a column if one is chosen: one
     * on the page ({ column_index }) or one removed in Align ({ column_name }).
     * Its value and check stay.
     */
    const NEW_PERSON = 'new';

    async function placeVerifyLine(index, row, column = {}) {
        const item = cropped[index];
        if (!item) return;
        // Where it was, for Ctrl+Z: its person, and its column if it had one.
        const before = item.gridRow === null ? null : {
            id: item.lineId,
            row: item.gridRow,
            column: item.gridColumn !== null
                ? { column_index: item.gridColumn }
                : (item.label === 'Drawn line' ? {} : { column_name: item.label }),
        };
        try {
            const { response, payload } = await sendLineRequest(
                verifyLineUrl(config.linePlaceUrl, item.lineId), 'PATCH',
                row === NEW_PERSON ? { new_person: true, ...column } : { row, ...column },
            );
            if (!response.ok || !payload?.line) throw new Error(responseErrorMessage(response, payload));
            rebuildVerifyLines(
                processedPage.lines.map((candidate) => (candidate.id === payload.line.id ? payload.line : candidate)),
                payload.flags ?? {},
                { focusLineId: payload.line.id },
            );
            if (before) pushVerifyUndo({ kind: 'moved', ...before });
        } catch (error) {
            setValidationFieldError(index, error.message || 'The field could not be moved.');
        }
    }

    /** Take out a field the reviewer added, or one that fell between two rows. */
    async function removeVerifyLine(index) {
        const item = cropped[index];
        if (!item) return;
        try {
            const { response, payload } = await sendLineRequest(verifyLineUrl(config.lineDeleteUrl, item.lineId), 'DELETE');
            if (!response.ok) throw new Error(responseErrorMessage(response, payload));
            rebuildVerifyLines(processedPage.lines.filter((line) => line.id !== item.lineId), payload?.flags ?? {});
            pushVerifyUndo({ kind: 'removed', id: item.lineId });
        } catch (error) {
            setValidationFieldError(index, error.message || 'The field could not be removed.');
        }
    }

    // Ctrl+Z in Verify takes back the last change to the added fields: the
    // fields just read, a removal, or a move to another person or column.
    const verifyUndo = [];
    let undoingVerifyChange = false;

    function pushVerifyUndo(entry) {
        verifyUndo.push({ ...entry, pageId: processedPage?.id });
        if (verifyUndo.length > 30) verifyUndo.shift();
    }

    async function undoVerifyChange() {
        if (undoingVerifyChange || !processedPage) return;
        while (verifyUndo.length > 0 && verifyUndo[verifyUndo.length - 1].pageId !== processedPage.id) verifyUndo.pop();
        const entry = verifyUndo.pop();
        if (!entry) return;

        undoingVerifyChange = true;
        try {
            if (entry.kind === 'added') {
                let flags = {};
                for (const id of entry.ids) {
                    const { response, payload } = await sendLineRequest(verifyLineUrl(config.lineDeleteUrl, id), 'DELETE');
                    if (response.ok) flags = payload?.flags ?? flags;
                }
                rebuildVerifyLines(processedPage.lines.filter((line) => !entry.ids.includes(line.id)), flags);
            } else if (entry.kind === 'removed') {
                const { response, payload } = await sendLineRequest(verifyLineUrl(config.lineRestoreUrl, entry.id), 'POST');
                if (!response.ok || !payload?.line) throw new Error(responseErrorMessage(response, payload));
                rebuildVerifyLines([...processedPage.lines, payload.line].sort((a, b) => a.position - b.position),
                    payload.flags ?? {}, { focusLineId: payload.line.id });
            } else if (entry.kind === 'moved') {
                const { response, payload } = await sendLineRequest(
                    verifyLineUrl(config.linePlaceUrl, entry.id), 'PATCH', { row: entry.row, ...entry.column },
                );
                if (!response.ok || !payload?.line) throw new Error(responseErrorMessage(response, payload));
                rebuildVerifyLines(
                    processedPage.lines.map((candidate) => (candidate.id === payload.line.id ? payload.line : candidate)),
                    payload.flags ?? {}, { focusLineId: payload.line.id },
                );
            }
        } catch (error) {
            // The change can no longer be taken back; the field stays as it is.
        } finally {
            undoingVerifyChange = false;
        }
    }

    document.addEventListener('keydown', (event) => {
        if (addingMissedField || !processedPage || el('step-verify').classList.contains('d-none')) return;
        if (!(event.ctrlKey || event.metaKey) || event.shiftKey || event.altKey || event.key.toLowerCase() !== 'z') return;
        const target = event.target;
        // Typing keeps the browser's own undo.
        if (target instanceof HTMLElement && (target.matches('input:not([type="checkbox"]):not([type="radio"]), textarea, select') || target.isContentEditable)) return;
        if (lineOverlay.editing || verifyUndo.length === 0) return;
        event.preventDefault();
        undoVerifyChange();
    });

    /**
     * Person, Column and Remove under a field the reviewer added, or one that
     * fell between two rows. The others are moved with Adjust outline.
     */
    function fieldPlacementControls(index) {
        const item = cropped[index];
        const added = item?.source === SOURCE_MANUAL;
        if (!item || !(added || item.flags.includes(FLAG_NO_ROW))) return null;

        const columns = processedPage?.geometry?.columns ?? [];
        const rows = Math.max(0, (processedPage?.geometry?.ruled_ys?.length ?? 0) - 1);
        const ledger = columns.length > 0 && rows > 0 && !['template', 'field'].includes(item.source);

        const controls = document.createElement('div');
        controls.className = 'validation-field__place';

        // Same two columns as the picture and value box above: Person under
        // the picture, Column and Remove under the value box.
        const pick = (label, options, selected, parent) => {
            const select = document.createElement('select');
            select.className = 'form-select form-select-sm';
            select.setAttribute('aria-label', `${label} of ${item.heading || item.label}`);
            select.title = label;
            select.append(new Option(label, ''), ...options);
            select.value = selected;
            select.addEventListener('click', (event) => event.stopPropagation());
            parent.append(select);
            return select;
        };

        const end = document.createElement('div');
        end.className = 'validation-field__place-end';

        if (ledger) {
            // "05 · Pedro P. Villanueva": the person's name as their group shows it.
            const personNames = new Map(validationGroups
                .filter((group) => group.kind === 'person')
                .map((group) => [Number(cropped[group.indexes[0]]?.personGroup), groupIdentity(group)]));
            const suggestion = placementSuggestions.get(item.lineId) ?? {};
            const suggested = (text, isSuggested) => (isSuggested ? `${text} (suggested)` : text);
            // People started by hand sit past the table's last row; they stay in the list.
            const lastRow = Math.max(rows, ...cropped.map((other) => other.gridRow ?? 0));
            const person = pick('Person', [
                new Option('+ New person', NEW_PERSON),
                ...Array.from({ length: lastRow }, (_, offset) => {
                    const row = offset + 1;
                    const name = personNames.get(row);
                    const number = String(row).padStart(2, '0');
                    const text = name && !/^\d+ fields?$/.test(name) ? `${number} · ${name}` : number;
                    return new Option(suggested(text, suggestion.row === row), String(row));
                }),
            ], item.gridRow === null ? '' : String(item.gridRow), controls);
            // A person is needed; outlined red until one is chosen.
            person.classList.toggle('is-invalid', person.value === '');

            // The page's columns, then the template's columns removed in Align:
            // a field can still be filed under one of those.
            const onPage = new Set(columns.map((candidate) => nameKey(candidate.name)));
            const removed = config.boxes
                .filter((box) => box.kind === 'column' && !onPage.has(nameKey(box.name)))
                .map((box) => String(box.name));
            const columnOptions = columns.map((candidate, columnIndex) => new Option(
                suggested(candidate.name, suggestion.column_index === columnIndex), `i:${columnIndex}`,
            ));
            if (removed.length > 0) {
                const group = document.createElement('optgroup');
                group.label = 'Removed in Align';
                group.append(...removed.map((name) => new Option(name, `n:${name}`)));
                columnOptions.push(group);
            }
            const selectedColumn = item.gridColumn !== null
                ? `i:${item.gridColumn}`
                : (removed.some((name) => nameKey(name) === nameKey(item.label)) ? `n:${item.label}` : '');
            const column = pick('Column', columnOptions, selectedColumn, end);
            column.title = 'Column (optional)';

            const place = () => {
                person.classList.toggle('is-invalid', person.value === '');
                if (person.value === '') {
                    setValidationFieldError(index, 'Choose the Person first; the Column can wait.');
                    return;
                }
                clearValidationFieldError(index);
                const [kind, value] = [column.value.slice(0, 2), column.value.slice(2)];
                placeVerifyLine(index, person.value === NEW_PERSON ? NEW_PERSON : Number(person.value),
                    kind === 'i:' ? { column_index: Number(value) } : kind === 'n:' ? { column_name: value } : {});
            };
            person.addEventListener('change', place);
            column.addEventListener('change', place);
        }
        controls.append(end);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-sm btn-outline-danger validation-field__remove';
        remove.innerHTML = '<i class="icon-base bx bx-trash icon-sm me-1" aria-hidden="true"></i>Remove';
        remove.setAttribute('aria-label', `Remove ${item.heading || item.label}`);
        remove.addEventListener('click', (event) => {
            event.stopPropagation();
            remove.disabled = true;
            removeVerifyLine(index);
        });
        end.append(remove);
        return controls;
    }

    el('addMissedFieldBtn').addEventListener('click', () => (addingMissedField ? stopAddingMissedField() : startAddingMissedField()));
    el('addFieldCancel').addEventListener('click', stopAddingMissedField);
    el('addFieldRead').addEventListener('click', readPendingBoxes);
    document.querySelectorAll('input[name="addFieldMode"]').forEach((input) => {
        input.addEventListener('change', () => {
            if (!(input instanceof HTMLInputElement) || !input.checked) return;
            // A drawing of three points or more is kept as a box, not lost.
            if (lineOverlay.isDrawing()) lineOverlay.finishDrawing();
            armAddFieldDrawing();
            showPendingBoxes();
            // Off the button, so Enter and Esc reach the drawing.
            input.blur();
        });
    });
    document.addEventListener('keydown', (event) => {
        if (!addingMissedField || readingPendingBoxes) return;
        const target = event.target;
        if (target instanceof HTMLElement && (target.matches('input:not([type="radio"]), textarea, select') || target.isContentEditable)) return;
        const command = event.ctrlKey || event.metaKey;
        if (event.key === 'Escape') {
            event.preventDefault();
            // Esc first drops a drawing, then closes the tool.
            if (lineOverlay.isDrawing()) armAddFieldDrawing();
            else stopAddingMissedField();
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (lineOverlay.isDrawing()) {
                if (!lineOverlay.finishDrawing()) {
                    showAddFieldStatus('This drawing is not an outline yet: it needs at least 3 points, with some height and width.', true);
                }
            } else {
                readPendingBoxes();
            }
        } else if (command && !event.shiftKey && event.key.toLowerCase() === 'z') {
            event.preventDefault();
            if (!(lineOverlay.isDrawing() && lineOverlay.undoDrawStep())) removeLastPendingBox();
        }
    });

    document.querySelectorAll('input[name="verifyLineMode"]').forEach((input) => {
        input.addEventListener('change', () => {
            if (!(input instanceof HTMLInputElement) || !input.checked) return;
            // Leaving Draw keeps a drawing of three points or more.
            if (input.value !== 'draw' && lineOverlay.isDrawing() && lineOverlay.finishDrawing()) {
                if (input.value !== 'box') lineOverlay.setEditMode(input.value);
            } else {
                lineOverlay.setEditMode(input.value);
            }
            // Off the button, so Enter and Esc reach the drawing.
            input.blur();
        });
    });
    el('lineEditCancel').addEventListener('click', cancelOutlineEdit);
    el('lineEditSave').addEventListener('click', saveOutlineEdit);
    document.addEventListener('keydown', (event) => {
        if (editingLineIndex === null) return;
        const target = event.target;
        if (target instanceof HTMLElement && (target.matches('input:not([type="radio"]), textarea, select') || target.isContentEditable)) return;
        const command = event.ctrlKey || event.metaKey;
        if (event.key === 'Escape') {
            event.preventDefault();
            // Esc first drops a drawing, then the whole edit.
            if (lineOverlay.editing?.mode === 'draw' && lineOverlay.isDrawing()) lineOverlay.setEditMode('draw');
            else cancelOutlineEdit();
        } else if (event.key === 'Enter' && lineOverlay.isDrawing()) {
            event.preventDefault();
            if (!lineOverlay.finishDrawing()) {
                showLineEditStatus('This drawing is not an outline yet: it needs at least 3 points, with some height and width.', true);
            }
        } else if (command && !event.shiftKey && event.key.toLowerCase() === 'z' && lineOverlay.isDrawing()) {
            event.preventDefault();
            lineOverlay.undoDrawStep();
        } else if (['Delete', 'Backspace'].includes(event.key) && lineOverlay.editing?.mode === 'points') {
            event.preventDefault();
            lineOverlay.deleteActivePoint();
        }
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
        const invalidIndexes = [];
        Object.entries(errors ?? {}).forEach(([key, messages]) => {
            const match = key.match(/^fields\.(\d+)\./);
            if (!match) return;
            const originalIndex = submittedIndexes[Number(match[1])];
            if (originalIndex === undefined) return;
            setValidationFieldError(originalIndex, Array.isArray(messages) ? messages[0] : String(messages));
            invalidIndexes.push(originalIndex);
        });

        if (invalidIndexes.length === 0) return;
        activateValidationField(invalidIndexes[0], 'marker');
        const firstInvalidRow = validationRow(invalidIndexes[0]);
        if (firstInvalidRow) requiredInput(firstInvalidRow, '.verified').focus();
    }

    /**
     * What this submission leaves out that the layout requires: its own fields
     * that no checked line was read under; in a register, the required columns
     * missing from each row with something checked, then the rows with nothing
     * checked (the page is removed after submitting, so they would have to be
     * scanned again). The server makes the same list.
     */
    /**
     * The rows (1-based) each ledger column shortened in Align reaches, by
     * name key; a column reaching every row is left out. A row counts when its
     * middle lies inside the marker, give or take a quarter row, as the
     * server reads it (DocumentPage::shortenedColumnRows).
     */
    function shortenedColumnRows(geometry) {
        const ruled = (geometry?.ruled_ys ?? []).map(Number);
        const count = ruled.length - 1;
        const shortened = new Map();
        if (count < 1) return shortened;
        const reach = 0.25 * median(ruled.slice(1).map((y, row) => y - ruled[row]));
        (geometry?.columns ?? []).forEach((column) => {
            const [, top, , height] = (column.box ?? []).map(Number);
            const rows = new Set();
            for (let row = 1; row <= count; row += 1) {
                const middle = (ruled[row - 1] + ruled[row]) / 2;
                if (middle >= top - reach && middle <= top + height + reach) rows.add(row);
            }
            if (rows.size < count) shortened.set(nameKey(column.name), rows);
        });
        return shortened;
    }

    function requiredNotSubmitted(verifiedIndexes) {
        const checkedIndexes = new Set(verifiedIndexes);
        const checkedNames = new Set(verifiedIndexes.map((index) => nameKey(cropped[index]?.label)));
        const missing = config.boxes
            .filter((box) => box.kind !== 'column' && box.required !== false && !checkedNames.has(nameKey(box.name)))
            .map((box) => box.name);

        const requiredColumns = columnsOnThisPage().filter((box) => box.required !== false);
        const untouchedRows = [];
        const tableRows = Math.max(0, (processedPage?.geometry?.ruled_ys?.length ?? 0) - 1);
        const shortened = shortenedColumnRows(processedPage?.geometry);
        validationGroups.forEach((group) => {
            // A person started by hand (past the last row) has no register row to complete.
            const ledgerRow = group.kind === 'person'
                && Number(cropped[group.indexes[0]]?.personGroup) <= tableRows
                && group.indexes.some((index) => settingsFor(index)?.kind === 'column');
            if (!ledgerRow) return;

            const checkedHere = group.indexes.filter((index) => checkedIndexes.has(index));
            if (checkedHere.length === 0) {
                untouchedRows.push(Number(cropped[group.indexes[0]]?.personGroup));
                return;
            }

            const columnsHere = new Set(checkedHere.map((index) => nameKey(cropped[index]?.label)));
            const row = Number(cropped[group.indexes[0]]?.personGroup);
            // A column Staff shortened in Align is not asked for in the rows it leaves out.
            const left = requiredColumns
                .filter((box) => !columnsHere.has(nameKey(box.name)) && (shortened.get(nameKey(box.name))?.has(row) ?? true))
                .map((box) => box.name);
            if (left.length > 0) missing.push(`${group.label}: ${left.join(', ')}`);
        });

        if (untouchedRows.length > 0) {
            missing.push(`Nothing ticked in ${rowRanges(untouchedRows)} `
                + `(${untouchedRows.length === 1 ? 'this row is' : 'these rows are'} not saved)`);
        }

        return missing;
    }

    // "Person 02 – Person 05, Person 07" for rows 2 to 5 and 7, in order.
    function rowRanges(rows) {
        const label = (row) => `Person ${String(row).padStart(2, '0')}`;
        const runs = [];
        rows.forEach((row) => {
            const run = runs[runs.length - 1];
            if (run && run[1] === row - 1) run[1] = row;
            else runs.push([row, row]);
        });

        return runs
            .map(([first, last]) => (first === last ? label(first) : `${label(first)} – ${label(last)}`))
            .join(', ');
    }

    function askToSubmitWithoutRequired(entries) {
        el('missingRequiredList').replaceChildren(...missingRequiredItems(entries));
        window.bootstrap.Modal.getOrCreateInstance(el('missingRequiredModal')).show();
    }

    /**
     * The list's own wording (requiredNotSubmitted() here, the same list from
     * the server) laid out as cards: the layout's fields together, then each
     * person missing something, then the rows with nothing ticked.
     */
    function missingRequiredItems(entries) {
        const fields = [];
        const people = [];
        let untouched = null;
        entries.forEach((entry) => {
            const rows = entry.match(/^Nothing ticked in (.+) \((this row is|these rows are) not saved\)$/);
            const person = entry.match(/^Person (\d+): (.+)$/);
            if (rows) {
                untouched = missingRequiredItem({
                    icon: 'bx-user-x',
                    // One part per range, so "Person 02 – Person 05" never breaks.
                    title: rows[1].split(', '),
                    detail: `Nothing ticked, so ${rows[2]} not saved`,
                    muted: true,
                });
            } else if (person) {
                // The name Verify shows for this person, when one was read.
                const group = validationGroups.find((candidate) => candidate.label === `Person ${person[1]}`);
                const identity = group ? groupIdentity(group) : '';
                people.push(missingRequiredItem({
                    badge: person[1],
                    title: `Person ${person[1]}`,
                    subtitle: group && identity !== `${group.indexes.length} fields` ? identity : null,
                    detail: `Missing: ${person[2]}`,
                }));
            } else {
                fields.push(entry);
            }
        });

        return [
            ...(fields.length > 0 ? [missingRequiredItem({
                icon: 'bx-file',
                title: `${fields.length} required field${fields.length === 1 ? '' : 's'} not ticked`,
                names: fields,
            })] : []),
            ...people,
            ...(untouched ? [untouched] : []),
        ];
    }

    function missingRequiredItem({ badge = '', icon = null, title, subtitle = null, detail = null, names = [], muted = false }) {
        const item = document.createElement('li');
        item.className = muted ? 'missing-required-item is-muted' : 'missing-required-item';

        const marker = document.createElement('span');
        marker.className = 'missing-required-item__badge';
        marker.setAttribute('aria-hidden', 'true');
        if (icon) {
            const glyph = document.createElement('i');
            glyph.className = `icon-base bx ${icon}`;
            marker.append(glyph);
        } else {
            marker.textContent = badge;
        }

        const copy = document.createElement('div');
        copy.className = 'missing-required-item__copy';
        const heading = document.createElement('strong');
        if (Array.isArray(title)) {
            title.forEach((part, index) => {
                const span = document.createElement('span');
                span.className = 'text-nowrap';
                span.textContent = part;
                heading.append(...(index > 0 ? [', ', span] : [span]));
            });
        } else {
            heading.textContent = title;
        }
        if (subtitle) {
            const who = document.createElement('span');
            who.className = 'missing-required-item__who';
            who.textContent = ` · ${subtitle}`;
            heading.append(who);
        }
        copy.append(heading);
        if (detail) {
            const text = document.createElement('small');
            text.textContent = detail;
            copy.append(text);
        }
        if (names.length > 0) {
            const list = document.createElement('ul');
            list.className = 'missing-required-item__names';
            list.append(...names.map((name) => {
                const chip = document.createElement('li');
                chip.textContent = name;
                return chip;
            }));
            copy.append(list);
        }

        item.append(marker, copy);
        return item;
    }

    // The safe choice has the focus, so Enter never submits by accident.
    el('missingRequiredModal').addEventListener('shown.bs.modal', () => el('missingRequiredBackBtn').focus());

    el('confirmMissingRequiredBtn').addEventListener('click', () => {
        window.bootstrap.Modal.getInstance(el('missingRequiredModal'))?.hide();
        submitRecord({ allowMissing: true });
    });

    el('submitForm').addEventListener('submit', (event) => {
        event.preventDefault();
        submitRecord();
    });

    // Only "Submit anyway" passes allowMissing, after the same checks again.
    async function submitRecord({ allowMissing = false } = {}) {
        if (recordSubmitting) return;

        clearValidationSubmitError();
        const rows = [...el('verifyRows').querySelectorAll('.validation-field')];
        rows.forEach((row) => clearValidationFieldError(Number(row.dataset.fieldIndex)));

        const verifiedIndexes = rows
            .filter((row) => requiredInput(row, '.validation-verified').checked)
            .map((row) => Number(row.dataset.fieldIndex));

        if (verifiedIndexes.length === 0) {
            showValidationSubmitError('Check Verified on at least one field before submitting.');
            if (rows[0]) {
                const firstIndex = Number(rows[0].dataset.fieldIndex);
                activateValidationField(firstIndex, 'marker');
                requiredInput(rows[0], '.validation-verified').focus();
            }
            return;
        }

        const invalidIndexes = verifiedIndexes.filter((index) => {
            const row = validationRow(index);
            if (!row) return true;
            const input = requiredInput(row, '.verified');
            if (input.value.trim()) return false;
            setValidationFieldError(index, 'A verified field must contain a value.');
            return true;
        });
        if (invalidIndexes.length > 0) {
            showValidationSubmitError('Some checked fields still need a value.');
            activateValidationField(invalidIndexes[0], 'marker');
            const invalidRow = validationRow(invalidIndexes[0]);
            if (invalidRow) requiredInput(invalidRow, '.verified').focus();
            return;
        }

        if (!scanFile) {
            showValidationSubmitError('The original scan is no longer available. Return to marking and choose the file again.');
            return;
        }

        if (!allowMissing) {
            const missing = requiredNotSubmitted(verifiedIndexes);
            if (missing.length > 0) {
                askToSubmitWithoutRequired(missing);
                return;
            }
        }

        const form = el('submitForm');
        const data = new FormData(form);
        data.set('scan', scanFile, scanFile.name);
        if (allowMissing) data.set('allow_missing', '1');

        const personGroups = validationGroups.filter((candidate) => candidate.kind === 'person');
        const submittedFields = verifiedIndexes.map((sourceIndex) => {
            const reading = readings[sourceIndex];
            const crop = cropped[sourceIndex];
            const group = validationGroupForField(sourceIndex);
            const personGroup = group?.kind === 'person'
                ? personGroups.findIndex((candidate) => candidate.id === group.id) + 1
                : null;
            const personFieldOrder = group?.kind === 'person'
                ? group.indexes.indexOf(sourceIndex)
                : null;
            const row = validationRow(sourceIndex);
            const value = row instanceof HTMLElement
                ? requiredInput(row, '.verified').value.trim()
                : '';
            return {
                verified: '1',
                name: String(reading.name ?? ''),
                ocr_text: String(reading.text ?? ''),
                ocr_confidence: normaliseConfidence(reading).toFixed(1),
                verified_value: value,
                person_group: personGroup,
                person_field_order: personFieldOrder,
                x: Number(crop.x ?? 0).toFixed(5),
                y: Number(crop.y ?? 0).toFixed(5),
                width: Number(crop.w ?? 0).toFixed(5),
                height: Number(crop.h ?? 0).toFixed(5),
                // The record keeps this line's outline and the exact crop TrOCR read.
                line_id: crop.lineId ?? null,
            };
        });
        data.set('fields_json', JSON.stringify(submittedFields));

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
                // The server found required fields missing that this page did
                // not: nothing was saved, so ask the same question.
                if (response.status === 422 && Array.isArray(payload?.errors?.missing_required)) {
                    askToSubmitWithoutRequired(payload.errors.missing_required);
                    return;
                }
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
    }
</script>
@endpush
