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
                    {{-- Batch Queue banner: sits inside the document card above the toolbar --}}
                    <div id="batchQueueBanner" class="batch-queue-banner d-none" aria-live="polite" data-phase="queuing">
                        <div class="batch-queue-banner__body">
                            <div class="batch-queue-banner__top">
                                <div class="batch-queue-banner__info">
                                    <span class="batch-queue-banner__pill">
                                        <i class="icon-base bx bx-copy" aria-hidden="true"></i>
                                        <span>Batch Queue</span>
                                    </span>
                                    <div class="batch-queue-banner__titles">
                                        <strong class="batch-queue-banner__heading" id="batchQueuePhaseTitle">Queuing
                                            documents...</strong>
                                        <span class="batch-queue-banner__sub" id="batchQueueCounter">0 / 0</span>
                                    </div>
                                </div>
                                <div class="batch-queue-chips" id="batchQueueChips" role="list"
                                    aria-label="Queued documents"></div>
                            </div>
                        </div>
                    </div>

                    <div class="marker-toolbar">
                        {{-- The file's name is in the top bar, beside the document type. --}}
                        <div class="marker-toolbar__primary">
                            <div class="btn-group marker-zoom-controls" role="group" aria-label="Document zoom controls">
                                <button type="button" class="btn btn-sm btn-icon btn-outline-secondary marker-tool-button"
                                    id="zoomOutBtn" aria-label="Zoom out">
                                    <i class="icon-base bx bx-minus icon-sm" aria-hidden="true"></i>
                                </button>
                                <button type="button"
                                    class="btn btn-sm btn-outline-secondary marker-tool-button marker-zoom-value"
                                    id="zoomResetBtn" title="Fit document to the workspace">100%</button>
                                <button type="button" class="btn btn-sm btn-icon btn-outline-secondary marker-tool-button"
                                    id="zoomInBtn" aria-label="Zoom in">
                                    <i class="icon-base bx bx-plus icon-sm" aria-hidden="true"></i>
                                </button>
                            </div>

                            {{-- A PDF of many pages: one page is aligned, read and saved at a time. ✓: saved already. --}}
                            <div class="input-group input-group-sm marker-page-controls d-none" role="group" id="scanPageBar"
                                 aria-label="Pages of this PDF">
                                <button type="button" class="btn btn-sm btn-icon btn-outline-secondary marker-tool-button" id="scanPagePrevBtn"
                                    aria-label="Previous page" title="Previous page">
                                    <i class="icon-base bx bx-chevron-left icon-sm" aria-hidden="true"></i>
                                </button>
                                <div class="dropdown marker-page-dropdown">
                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle marker-tool-button marker-page-select"
                                        id="scanPageSelect" data-bs-toggle="dropdown" aria-expanded="false"
                                        aria-label="Page to read"></button>
                                    <div class="dropdown-menu marker-page-menu" id="scanPageMenu"></div>
                                </div>
                                <button type="button" class="btn btn-sm btn-icon btn-outline-secondary marker-tool-button" id="scanPageNextBtn"
                                    aria-label="Next page" title="Next page">
                                    <i class="icon-base bx bx-chevron-right icon-sm" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <div class="marker-toolbar__actions">
                            {{-- Hides the row lines and every field box to look at the page itself; on at every visit. --}}
                            <div class="form-check form-switch marker-grid-toggle"
                                 title="Show or hide the row lines and field boxes over the page">
                                <input class="form-check-input" type="checkbox" role="switch" id="showGridToggle" checked>
                                <label class="form-check-label" for="showGridToggle">Grid lines</label>
                            </div>

                            <div class="dropdown">
                                <button type="button"
                                    class="btn btn-sm btn-outline-secondary dropdown-toggle marker-help-button"
                                    data-bs-toggle="dropdown" aria-expanded="false" aria-label="Show editor shortcuts">
                                    <i class="icon-base bx bx-terminal icon-sm me-1" aria-hidden="true"></i>
                                    <span>Shortcuts</span>
                                </button>
                                <div class="dropdown-menu dropdown-menu-end marker-shortcuts-menu">
                                    <div class="marker-shortcuts-menu__title">Page and fields</div>
                                    {{-- Mac keys beside the Windows ones: ⌘ Command for Ctrl, ⌥ Option for Alt. --}}
                                    <p class="marker-shortcuts-menu__note">On a Mac, <kbd>⌘</kbd> is Command, <kbd>⌥</kbd>
                                        is Option, and Backspace is the delete key.</p>
                                    <div><span><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + scroll</span><small>Zoom document (or pinch
                                            the trackpad)</small></div>
                                    <div><span><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + drag</span><small>Move document</small>
                                    </div>
                                    <div><span><kbd>Shift</kbd> + click</span><small>Select multiple</small></div>
                                    <div><span><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>A</kbd></span><small>Select all
                                            fields</small></div>
                                    <div><span>Drag empty document area</span><small>Select fields in a rectangle</small>
                                    </div>
                                    <div><span><kbd>Shift</kbd> + drag</span><small>Add fields to selection</small></div>
                                    <div><span>Drag selection</span><small>Move selected fields</small></div>
                                    <div><span>Drag resize handle</span><small>Resize selected fields</small></div>
                                    <div><span><kbd>Alt</kbd> / <kbd>⌥</kbd> + drag</span><small>Place without snapping to
                                            printed lines</small></div>
                                    <div><span>Drag the rotate knob below a field</span><small>Tilt that field
                                            (<kbd>Shift</kbd>: 5° steps)</small></div>
                                    <div><span><kbd>[</kbd> or <kbd>]</kbd></span><small>Tilt selected 0.5°
                                            (<kbd>Shift</kbd>: 5°)</small></div>
                                    <div><span>Double-click the knob</span><small>Straighten</small></div>
                                    <div><span><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>C</kbd> / <kbd>V</kbd></span><small>Copy / paste selected</small></div>
                                    <div><span><kbd>Del</kbd> or <kbd>Backspace</kbd></span><small>Delete selected</small>
                                    </div>
                                    <div><span><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>Z</kbd></span><small>Undo last
                                            change</small></div>

                                    @if (count($ruledYs) >= 2)
                                        <div class="marker-shortcuts-menu__title">Row lines</div>
                                        <div><span>Drag the dot at a line's end</span><small>Move that line (<kbd>Shift</kbd>:
                                                all lines)</small></div>
                                        <div><span>Click a dot, then <kbd>↑</kbd> or <kbd>↓</kbd></span><small>Nudge it
                                                (<kbd>Shift</kbd>: 10 px)</small></div>
                                        <div><span><kbd>Del</kbd> or double-click a dot</span><small>Remove that line</small>
                                        </div>
                                        <div><span><kbd>Esc</kbd></span><small>Let go of the line</small></div>
                                    @endif

                                    <div class="marker-shortcuts-menu__title">Line crops, after Detect</div>
                                    <div><span><kbd>Tab</kbd> / <kbd>Shift</kbd> + <kbd>Tab</kbd></span><small>Next /
                                            previous line</small></div>
                                    <div><span><kbd>+</kbd> or <kbd>−</kbd></span><small>Grow / shrink the outline</small>
                                    </div>
                                    <div><span><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>S</kbd></span><small>Save the
                                            crop</small></div>
                                    <div><span><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>Z</kbd> /
                                            <kbd>Y</kbd></span><small>Undo / redo</small></div>
                                    <div><span><kbd>Del</kbd> or <kbd>Backspace</kbd></span><small>Remove the line (in
                                            Points: a corner)</small></div>
                                    <div><span><kbd>Enter</kbd></span><small>Finish a drawn outline</small></div>
                                    <div><span><kbd>Esc</kbd></span><small>Step back</small></div>
                                </div>
                            </div>

                            <button type="button" class="btn btn-sm btn-outline-primary marker-snap-button"
                                id="snapTableBtn"
                                title="Fit the column and row markers onto the page's printed table lines">
                                <i class="icon-base bx bx-grid-alt icon-sm me-1" aria-hidden="true"></i>
                                <span>Snap to table</span>
                                <span class="spinner-border marker-snap-spinner" aria-hidden="true"></span>
                            </button>

                            <button type="button" class="btn btn-sm btn-outline-secondary marker-reset-button"
                                id="resetFieldsBtn" title="Restore the original template fields and document view" disabled>
                                <i class="icon-base bx bx-refresh icon-sm me-1" aria-hidden="true"></i>
                                <span>Reset</span>
                            </button>
                            {{-- Selecting and deleting fields: Select all and Delete in the Fields card. --}}
                        </div>
                    </div>

                    {{-- A PDF of two or more pages opens as a spread: the pair's other page sits
                         beside the one being aligned. Click it to work on it instead. --}}
                    {{-- Both pages sit in the one scroll container, so one set of scrollbars moves them together. --}}
                    <div class="doc-viewport" id="docViewport">
                        <div class="doc-spread" id="docSpread">
                            <div class="doc-spread__page doc-spread__page--main" id="docMainPage">
                                <div class="doc-spread__label d-none" id="docMainLabel"></div>
                        <div class="doc-stage" id="docStage">
                            <canvas id="pageCanvas"></canvas>
                            <div class="field-overlay" id="fieldOverlay">
                                <div class="field-selection-marquee" id="scanFieldSelectionMarquee" aria-hidden="true">
                                </div>
                            </div>
                        </div>
                    </div>

                            <div class="doc-spread__page doc-spread__page--companion d-none" id="docCompanionPage"
                                title="Work on this page instead">
                                <div class="doc-spread__label" id="docCompanionLabel"></div>
                                <div class="doc-stage" id="docCompanionStage">
                                    <canvas id="companionCanvas"></canvas>
                                    <div class="field-overlay" id="companionOverlay"></div>
                                    <button type="button" class="doc-spread__switch" id="docCompanionSwitch"
                                        aria-label="Work on this page instead">
                                        <i class="icon-base bx bx-edit-alt me-1" aria-hidden="true"></i>
                                        <span>Align this page</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </x-card>
            </div>

            <div class="document-side-panel field-marker-controls-pane">
                {{--
                What to know about the scan itself, shown only when
                it applies: it may be sideways; this page (or file) may be
                saved already; or other pages of this PDF are.
                --}}
                <div class="document-scan-notice d-none" id="scanSidewaysNotice" role="alert">
                    <i class="icon-base bx bx-error" aria-hidden="true"></i>
                    <span id="scanSidewaysMessage"></span>
                </div>
                <div class="document-scan-notice d-none" id="scanPagesNotice" role="status">
                    <i class="icon-base bx bx-check-circle" aria-hidden="true"></i>
                    <span>
                        <span id="scanPagesMessage"></span>
                        <a href="#" target="_blank" rel="noopener" class="document-scan-notice__link d-none"
                            id="scanPagesRecordLink">Open it</a>
                    </span>
                </div>

                {{--
                The fields to read, in order. On a ledger page the table's
                rows are set here too: they start as the template's, Snap
                to table takes them from the page, and the user can add, move
                or remove rows and columns. The template is not changed.
                The tips are behind the ?.
                --}}
                <div class="card mb-4 document-fields-card">
                    <div class="card-header d-flex align-items-center gap-1 position-relative">
                        <h5 class="card-title mb-0">Fields</h5>
                        <div class="card-help">
                            <button type="button" class="btn btn-link card-help__toggle" aria-label="Fields tips"
                                aria-describedby="fieldTips">
                                <i class="icon-base bx bx-help-circle" aria-hidden="true"></i>
                            </button>
                            <div class="card-help__tips" id="fieldTips" role="tooltip">
                                <strong>Tips</strong>
                                <ul>
                                    <li>Keep each box tight around the handwriting. Loose boxes pick up neighbouring text
                                        and read badly.</li>
                                    <li>Click a name to select its box on the page. <kbd>Ctrl</kbd> / <kbd>⌘</kbd> +
                                        <kbd>A</kbd> selects them all.</li>
                                    @if (count($ruledYs) >= 2)
                                        <li>+ Field is read once (e.g. Page No.). + Column is read on every row (e.g. Remarks).
                                        </li>
                                        <li>Stretch a new field down two or more rows, between columns, to read it as a column.
                                        </li>
                                        <li>Page scanned a little turned? Snap to table turns the table with it; Tilt fine-tunes
                                            it.</li>
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
                                        <input type="number" class="document-grid-card__count" id="gridRowCount" min="1"
                                            max="40" step="1" value="0" inputmode="numeric" aria-label="Number of rows"
                                            title="Type how many rows the table has">
                                        <button type="button" class="btn btn-outline-secondary" id="addRowBtn"
                                            title="Add a row line under the selected one, or a row at the bottom"
                                            aria-label="Add a row">
                                            <i class="icon-base bx bx-plus icon-sm" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                                {{-- A page scanned a little turned: the columns and row lines turn with it. --}}
                                <div class="document-grid-card__line" id="gridTiltLine">
                                    {{-- "Tilt" for the table; "Line 3" while row line 3 is selected: Tilt then turns that line
                                    alone. --}}
                                    <span class="document-grid-card__label" id="gridTiltLabel">Tilt</span>
                                    <div class="btn-group btn-group-sm" role="group" id="gridTiltGroup"
                                        aria-label="Tilt of the table">
                                        <button type="button" class="btn btn-outline-secondary" id="tiltLeftBtn"
                                            title="Turn the table anticlockwise by 0.1° (Shift: 1°)"
                                            aria-label="Turn the table anticlockwise">
                                            <i class="icon-base bx bx-rotate-left icon-sm" aria-hidden="true"></i>
                                        </button>
                                        <input type="text" class="document-grid-card__count" id="gridTiltValue" value="0.0°"
                                            inputmode="decimal" autocomplete="off"
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
                            <button type="button" class="btn btn-sm btn-outline-danger marker-field-delete"
                                id="deleteFieldsBtn" disabled>
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
                                    placeholder="{{ count($ruledYs) >= 2 ? 'New name' : 'e.g. Page No.' }}" maxlength="500">
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
                                title="Click a line on the page to fix its outline before it is read">Adjust line
                                crops</label>
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
                    <div class="btn-group btn-group-sm line-adjust-card__modes" role="radiogroup"
                        aria-label="How to change the outline" id="lineModeGroup">
                        <input type="radio" class="btn-check" name="alignLineMode" id="lineModeBox" value="box"
                            autocomplete="off" checked>
                        <label class="btn btn-outline-primary" for="lineModeBox"
                            title="Stretch the outline by its handles">Stretch</label>
                        <input type="radio" class="btn-check" name="alignLineMode" id="lineModePoints" value="points"
                            autocomplete="off">
                        <label class="btn btn-outline-primary" for="lineModePoints"
                            title="Drag the outline's own corners">Points</label>
                        <input type="radio" class="btn-check" name="alignLineMode" id="lineModeDraw" value="draw"
                            autocomplete="off">
                        <label class="btn btn-outline-primary" for="lineModeDraw"
                            title="Draw a new outline round the writing">Draw</label>
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
                            <button type="button" class="btn btn-outline-secondary" id="lineShrinkBtn"
                                title="Shrink the outline (−)">
                                <i class="icon-base bx bx-minus icon-sm" aria-hidden="true"></i><span
                                    class="visually-hidden">Shrink</span>
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="lineGrowBtn"
                                title="Grow the outline (+)">
                                <i class="icon-base bx bx-plus icon-sm" aria-hidden="true"></i><span
                                    class="visually-hidden">Grow</span>
                            </button>
                        </div>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Undo outline changes">
                            <button type="button" class="btn btn-outline-secondary" id="lineUndoBtn" title="Undo (Ctrl+Z)"
                                disabled>
                                <i class="icon-base bx bx-undo icon-sm" aria-hidden="true"></i><span
                                    class="visually-hidden">Undo</span>
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="lineRedoBtn" title="Redo (Ctrl+Y)"
                                disabled>
                                <i class="icon-base bx bx-redo icon-sm" aria-hidden="true"></i><span
                                    class="visually-hidden">Redo</span>
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
                        <i class="icon-base bx bx-radar icon-sm me-1" aria-hidden="true"></i> Smoke test scan
                    </button>
                    <button class="btn btn-primary btn-lg d-none" type="button" id="saveQueueNextBtn">
                        <i class="icon-base bx bx-save icon-sm me-1"></i> <span id="saveQueueNextLabel">Save &amp; Queue
                            Document</span>
                    </button>
                    <button class="btn btn-primary btn-lg" type="button" id="scanNowBtn">
                        <i class="icon-base bx bx-scan icon-sm me-1"></i> <span id="scanNowBtnLabel">Scan with OCR</span>
                    </button>
                </div>
            </div>
        </div>
    </section>

    {{-- Step 3: verify and submit --}}
    <section id="step-verify" class="document-step-panel d-none">
        <form method="POST" action="{{ route('documents.store') }}" id="submitForm" enctype="multipart/form-data"
            novalidate>
            @csrf
            <input type="hidden" name="doc_type" value="{{ $docType->value }}">
            <input type="hidden" name="document_template_id" value="{{ $template->getKey() }}">
            <input type="hidden" name="ocr_model_key" id="ocrModelKey">
            <input type="hidden" name="document_page_id" id="documentPageId">

            <div class="validation-workspace">
                <div class="validation-submit-error d-none" id="validationExistingBanner" role="alert"
                    aria-live="assertive">
                    <i class="icon-base bx bx-x-circle" aria-hidden="true"></i>
                    <div class="flex-grow-1">
                        <strong>Data Extracted Rejected — Record Already Exists</strong>
                        <div id="validationExistingMessage">This document's extracted data already exists in the database.
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <a href="#" target="_blank" class="btn btn-sm btn-outline-danger d-none"
                            id="validationExistingViewLink">
                            View existing
                        </a>
                        <button type="button" class="btn btn-sm btn-danger" id="validationExistingRejectBtn">
                            Reject extracted data
                        </button>
                    </div>
                </div>

                <div class="validation-submit-error d-none" id="validationSubmitError" role="alert" aria-live="assertive">
                    <i class="icon-base bx bx-error" aria-hidden="true"></i>
                    <div>
                        <strong>Unable to submit this record</strong>
                        <div id="validationSubmitMessage"></div>
                    </div>
                </div>

                <div class="validation-comparison-grid">
                    <section class="card validation-pane validation-document-pane" aria-labelledby="originalDocumentTitle">
                        <header class="validation-pane__header">
                            <div class="min-w-0">
                                <div class="d-flex align-items-center flex-wrap column-gap-2">
                                    <span class="validation-pane__eyebrow">Source</span>
                                    {{-- A document of several pages: which page is in view and how many are checked. --}}
                                    <span class="validation-page-pill d-none" id="validationPagePill" role="status"></span>
                                </div>
                                <h3 class="h5 mb-0" id="originalDocumentTitle">Original document</h3>
                            </div>

                            <div class="validation-pane__tools">
                                {{-- Writing the line detector missed: TrOCR reads a box drawn over it. --}}
                                <button type="button" class="btn btn-sm btn-outline-primary validation-add-field"
                                    id="addMissedFieldBtn" aria-pressed="false"
                                    title="Drag a box over writing that has no reading. TrOCR reads that box alone.">
                                    <i class="icon-base bx bx-plus icon-sm me-1" aria-hidden="true"></i><span>Add missed
                                        field</span>
                                </button>
                                {{-- Pick several fields and make them one new person. --}}
                                <button type="button" class="btn btn-sm btn-outline-primary validation-add-field"
                                        id="groupFieldsBtn" aria-pressed="false"
                                        title="Pick fields on the page or in the list, then group them as a new person">
                                    <i class="icon-base bx bx-group icon-sm me-1" aria-hidden="true"></i><span>Group fields</span>
                                </button>
                                {{-- A two-page register: which page fills the view, or both side by side.
                                     A click on a page does the same. --}}
                                <div class="btn-group verify-page-view d-none" role="group" id="verifyPageView"
                                    aria-label="Pages in view">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-verify-view="0"
                                        aria-pressed="false" title="Zoom in on the left page">Page 1</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary active" data-verify-view="both"
                                        aria-pressed="true" title="Both pages side by side">Both</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-verify-view="1"
                                        aria-pressed="false" title="Zoom in on the right page">Page 2</button>
                                </div>
                                <div class="btn-group marker-zoom-controls" role="group"
                                    aria-label="Original document zoom controls">
                                    <button type="button"
                                        class="btn btn-sm btn-icon btn-outline-secondary marker-tool-button"
                                        id="validationZoomOutBtn" aria-label="Zoom out">
                                        <i class="icon-base bx bx-minus icon-sm" aria-hidden="true"></i>
                                    </button>
                                    <button type="button"
                                        class="btn btn-sm btn-outline-secondary marker-tool-button marker-zoom-value"
                                        id="validationZoomResetBtn" title="Fit document to panel">100%</button>
                                    <button type="button"
                                        class="btn btn-sm btn-icon btn-outline-secondary marker-tool-button"
                                        id="validationZoomInBtn" aria-label="Zoom in">
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
                                <input type="radio" class="btn-check" name="addFieldMode" id="addFieldDraw" value="draw">
                                <label class="btn btn-outline-secondary" for="addFieldDraw"
                                    title="Click round the writing or hold and trace; Enter or double-click closes it">Draw</label>
                                <input type="radio" class="btn-check" name="addFieldMode" id="addFieldRectangle"
                                    value="rectangle" checked>
                                <label class="btn btn-outline-secondary" for="addFieldRectangle"
                                    title="Drag a box over the writing">Rectangle</label>
                            </div>
                            <div class="line-edit-toolbar__actions">
                                <button type="button" class="btn btn-sm btn-text-secondary"
                                    id="addFieldCancel">Cancel</button>
                                <button type="button" class="btn btn-sm btn-primary" id="addFieldRead" disabled>
                                    <i class="icon-base bx bx-scan icon-sm me-1" aria-hidden="true"></i>
                                    <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                                    <span class="add-field-read__label">Read boxes</span>
                                </button>
                            </div>
                            <div class="line-edit-toolbar__status" id="addFieldStatus" role="status" aria-live="polite">
                            </div>
                        </div>

                        {{-- Shown while fields are picked to become one new person. --}}
                        <div class="line-edit-toolbar add-field-toolbar group-fields-toolbar d-none" id="groupFieldsToolbar" role="toolbar"
                             aria-label="Group fields as a new person">
                            <div class="line-edit-toolbar__copy">
                                <span class="line-edit-toolbar__eyebrow">New person</span>
                                <strong id="groupFieldsCount">No fields picked</strong>
                            </div>
                            <label class="visually-hidden" for="groupFieldsName">Name of the new person</label>
                            <input type="text" class="form-control form-control-sm group-fields-toolbar__name" id="groupFieldsName"
                                   maxlength="120" autocomplete="off" placeholder="Name (optional)">
                            <div class="line-edit-toolbar__actions">
                                <button type="button" class="btn btn-sm btn-text-secondary" id="groupFieldsCancel">Cancel</button>
                                <button type="button" class="btn btn-sm btn-primary" id="groupFieldsApply" disabled>
                                    <i class="icon-base bx bx-group icon-sm me-1" aria-hidden="true"></i>
                                    <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                                    <span>Group as person</span>
                                </button>
                            </div>
                            <div class="line-edit-toolbar__status" id="groupFieldsStatus" role="status" aria-live="polite"></div>
                        </div>

                        {{-- Shown while a reviewer redraws one line's outline. --}}
                        <div class="line-edit-toolbar d-none" id="lineEditToolbar" role="toolbar"
                            aria-label="Adjust outline">
                            <div class="line-edit-toolbar__copy">
                                <span class="line-edit-toolbar__eyebrow">Adjust outline</span>
                                <strong id="lineEditName"></strong>
                            </div>
                            <div class="btn-group btn-group-sm" role="group" aria-label="Outline tool">
                                <input type="radio" class="btn-check" name="verifyLineMode" id="lineEditDraw" value="draw">
                                <label class="btn btn-outline-secondary" for="lineEditDraw"
                                    title="Draw a new outline: click points or hold and trace; Enter or double-click closes it">Draw</label>
                                <input type="radio" class="btn-check" name="verifyLineMode" id="lineEditRectangle"
                                       value="rectangle" checked>
                                <label class="btn btn-outline-secondary" for="lineEditRectangle" title="Drag a box over the writing">Rectangle</label>
                            </div>
                            <div class="line-edit-toolbar__actions">
                                <button type="button" class="btn btn-sm btn-text-secondary"
                                    id="lineEditCancel">Cancel</button>
                                <button type="button" class="btn btn-sm btn-primary" id="lineEditSave">
                                    <i class="icon-base bx bx-check icon-sm me-1" aria-hidden="true"></i>
                                    <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                                    <span>Save and re-read</span>
                                </button>
                            </div>
                            <div class="line-edit-toolbar__status" id="lineEditStatus" role="status" aria-live="polite">
                            </div>
                        </div>

                        {{-- Shown while a field whose crop was deleted is drawn again: Save and re-read reads the box. --}}
                        <div class="line-edit-toolbar redraw-field-toolbar d-none" id="redrawFieldToolbar" role="toolbar"
                             aria-label="Draw the field again">
                            <div class="line-edit-toolbar__copy">
                                <span class="line-edit-toolbar__eyebrow">Draw again</span>
                                <strong id="redrawFieldName"></strong>
                            </div>
                            <div class="btn-group btn-group-sm" role="radiogroup" aria-label="How to mark the writing">
                                <input type="radio" class="btn-check" name="redrawFieldMode" id="redrawFieldDraw" value="draw">
                                <label class="btn btn-outline-secondary" for="redrawFieldDraw"
                                       title="Click round the writing or hold and trace; Enter or double-click closes it">Draw</label>
                                <input type="radio" class="btn-check" name="redrawFieldMode" id="redrawFieldRectangle"
                                       value="rectangle" checked>
                                <label class="btn btn-outline-secondary" for="redrawFieldRectangle" title="Drag a box over the writing">Rectangle</label>
                            </div>
                            <div class="line-edit-toolbar__actions">
                                <button type="button" class="btn btn-sm btn-text-secondary" id="redrawFieldCancel">Later</button>
                                <button type="button" class="btn btn-sm btn-primary" id="redrawFieldSave" disabled>
                                    <i class="icon-base bx bx-check icon-sm me-1" aria-hidden="true"></i>
                                    <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                                    <span>Save and re-read</span>
                                </button>
                            </div>
                            <div class="line-edit-toolbar__status" id="redrawFieldStatus" role="status" aria-live="polite"></div>
                        </div>

                        {{-- A document of two pages (a register's left and right) is one document: both
                             pages sit side by side in the one scroll container, so one set of scrollbars
                             and one zoom move them together. Click the other page to verify it instead. --}}
                        <div class="doc-viewport validation-doc-viewport" id="validationDocViewport">
                            <div class="doc-spread" id="validationSpread">
                                <div class="doc-spread__page doc-spread__page--main" id="validationMainPage">
                                    <div class="doc-spread__label d-none" id="validationMainLabel"></div>
                                    <div class="doc-stage" id="validationDocStage">
                                        <canvas id="validationPageCanvas"></canvas>
                                        <div class="field-overlay validation-field-overlay" id="validationFieldOverlay"></div>
                                    </div>
                                </div>

                                <div class="doc-spread__page doc-spread__page--companion validation-companion d-none"
                                     id="validationCompanionPage" title="Verify this page instead">
                                    <div class="doc-spread__label" id="validationCompanionLabel"></div>
                                    <div class="doc-stage" id="validationCompanionStage">
                                        <canvas id="validationCompanionCanvas"></canvas>
                                        <div class="field-overlay validation-field-overlay" id="validationCompanionOverlay"></div>
                                        <button type="button" class="btn btn-primary doc-spread__switch" id="validationCompanionSwitch"
                                                aria-label="Verify this page instead">
                                            <i class="icon-base bx bx-edit-alt me-1" aria-hidden="true"></i>
                                            <span>Verify this page</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        {{-- The person's row goes on, out of view, on the other page: one click slides there. --}}
                        <button type="button" class="btn btn-sm btn-primary verify-row-hint" id="verifyRowHint"
                            tabindex="-1" aria-hidden="true">
                            <i class="icon-base bx bx-chevron-left verify-row-hint__before" aria-hidden="true"></i>
                            <span></span>
                            <i class="icon-base bx bx-chevron-right verify-row-hint__after" aria-hidden="true"></i>
                        </button>

                        <footer class="validation-pane__hint">
                            <i class="icon-base bx bx-scan" aria-hidden="true"></i>
                            Orange shows the complete person row; green identifies the exact field;
                            red outlines need review.
                            <span class="d-none" id="verifyZoomTip">Click a page to zoom in on it.</span>
                            Hold <kbd>Ctrl</kbd> (<kbd>⌘</kbd> on a Mac) and scroll to zoom or drag to move.
                        </footer>
                    </section>

                    <section class="card validation-pane validation-output-pane" aria-labelledby="digitalDocumentTitle">
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
                                        aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="verifiedProgress">
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

                        {{-- A document of several pages: what Submit does on this page. --}}
                        <div class="validation-pages-note d-none" id="validationPagesNote" role="status">
                            <i class="icon-base bx bx-info-circle" aria-hidden="true"></i>
                            <span id="validationPagesNoteText"></span>
                        </div>

                        <footer class="validation-submit-bar">
                            <div class="validation-lock-copy">
                                <i class="icon-base bx bx-lock-alt" aria-hidden="true"></i>
                                <span>Submitted fields are locked. Change them later with Correct record on the record page.</span>
                            </div>

                            <div class="validation-submit-actions">
                                <button class="btn btn-outline-secondary" type="button" id="backToMark">
                                    <i class="icon-base bx bx-chevron-left icon-sm me-1"></i> Back to marking
                                </button>
                                <button class="btn btn-primary" type="submit" id="submitBtn" disabled>
                                    <i class="icon-base bx bx-check-shield icon-sm me-1"></i>
                                    {{-- One inline span: .btn is a flex container, which drops the
                                    spaces between separate text and element children. --}}
                                    <span><span id="submitLabelHead">Submit</span> <span id="submitVerifiedCount">0</span> verified</span>
                                </button>
                            </div>
                        </footer>
                    </section>
                </div>
            </div>
        </form>
    </section>

    {{-- Confirm restoring the original template layout --}}
    <div class="modal fade" id="resetFieldsModal" tabindex="-1" aria-labelledby="resetFieldsModalTitle"
        aria-describedby="resetFieldsModalDescription" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered reset-confirm-dialog">
            <div class="modal-content reset-confirm-modal">
                <div class="modal-header">
                    <h5 class="modal-title" id="resetFieldsModalTitle">Reset field layout?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <p class="mb-2" id="resetFieldsModalDescription">
                        Restore all field markers and row lines to the positions and sizes configured in the original
                        template?
                    </p>
                    <p class="small mb-2 d-none" id="resetFieldsModalPage"></p>
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
    <div class="modal fade" id="missingRequiredModal" tabindex="-1" aria-labelledby="missingRequiredModalTitle"
        aria-describedby="missingRequiredModalDescription" aria-hidden="true">
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
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <ul class="missing-required-list" id="missingRequiredList"></ul>
                    <p class="missing-required-modal__note">
                        <i class="icon-base bx bx-history" aria-hidden="true"></i>
                        <span>Submit anyway only if the page really leaves them blank. The audit log records what was left
                            out.</span>
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

    {{-- This page of this file is saved already: the user decides whether to save it again. --}}
    <div class="modal fade" id="duplicatePageModal" tabindex="-1" aria-labelledby="duplicatePageModalTitle"
        aria-describedby="duplicatePageModalDescription" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered leave-scan-dialog">
            <div class="modal-content leave-scan-modal">
                <div class="modal-body">
                    <span class="leave-scan-modal__icon bg-label-warning" aria-hidden="true">
                        <i class="icon-base bx bx-copy"></i>
                    </span>
                    <h5 class="modal-title" id="duplicatePageModalTitle">Already saved</h5>
                    <p class="leave-scan-modal__text mb-2" id="duplicatePageModalDescription">
                        This page is already saved.
                    </p>
                    <p class="leave-scan-modal__text">
                        <a href="#" target="_blank" rel="noopener" id="duplicatePageRecordLink">Open the saved record</a>
                        · Saving again makes a second record of it.
                    </p>

                    <div class="leave-scan-modal__actions">
                        <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"
                            id="duplicatePageBackBtn">
                            Go back
                        </button>
                        <button type="button" class="btn btn-warning" id="confirmDuplicatePageBtn">
                            Save again
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- A page of a PDF was saved: go on with the next page, or open the record. --}}
    <div class="modal fade" id="nextPageModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false"
        aria-labelledby="nextPageModalTitle" aria-describedby="nextPageModalDescription" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered leave-scan-dialog">
            <div class="modal-content leave-scan-modal">
                <div class="modal-body">
                    <span class="leave-scan-modal__icon bg-label-success" aria-hidden="true">
                        <i class="icon-base bx bx-check"></i>
                    </span>
                    <h5 class="modal-title" id="nextPageModalTitle">Page saved</h5>
                    <p class="leave-scan-modal__text mb-2" id="nextPageModalDescription">
                        Continue with the next page?
                    </p>
                    <p class="leave-scan-modal__text" id="nextPageModalProgress"></p>

                    <div class="leave-scan-modal__actions">
                        <a href="#" class="btn btn-label-secondary" id="nextPageOpenRecordBtn">Open record</a>
                        <button type="button" class="btn btn-primary" id="nextPageContinueBtn">
                            Next page
                            <i class="icon-base bx bx-right-arrow-alt icon-sm ms-1" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Confirm deleting a field's crop with Delete or Backspace (Verify) --}}
    <div class="modal fade" id="deleteCropModal" tabindex="-1"
         aria-labelledby="deleteCropModalTitle" aria-describedby="deleteCropModalDescription"
         aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered leave-scan-dialog">
            <div class="modal-content leave-scan-modal">
                <div class="modal-body">
                    <span class="leave-scan-modal__icon bg-label-danger" aria-hidden="true">
                        <i class="icon-base bx bx-trash"></i>
                    </span>
                    <h5 class="modal-title" id="deleteCropModalTitle">Delete this crop?</h5>
                    <p class="leave-scan-modal__text" id="deleteCropModalDescription"></p>

                    <div class="leave-scan-modal__actions">
                        <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"
                                id="deleteCropCancelBtn">
                            Keep it
                        </button>
                        <button type="button" class="btn btn-danger" id="deleteCropConfirmBtn">
                            Delete crop
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Confirm leaving a scan that is not submitted (Cancel, or a link out) --}}
    <div class="modal fade" id="leaveScanModal" tabindex="-1" aria-labelledby="leaveScanModalTitle"
        aria-describedby="leaveScanModalDescription" aria-hidden="true">
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
                        <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal" id="leaveScanStayBtn">
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

    {{-- Queued batch document options (Delete / Open) --}}
    <div class="modal fade" id="batchDocumentModal" tabindex="-1" aria-labelledby="batchDocumentModalTitle"
        aria-describedby="batchDocumentModalDescription" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered leave-scan-dialog" style="max-inline-size: min(25rem, calc(100% - 2rem));">
            <div class="modal-content leave-scan-modal">
                <div class="modal-body">
                    <span class="leave-scan-modal__icon bg-label-danger" aria-hidden="true">
                        <i class="icon-base bx bx-trash"></i>
                    </span>
                    <h5 class="modal-title" id="batchDocumentModalTitle">Remove from batch queue?</h5>
                    <p class="leave-scan-modal__text" id="batchDocumentModalDescription">
                        This document will be taken out of the batch.
                    </p>

                    <div class="batch-delete-file">
                        <i class="icon-base bx bx-file" aria-hidden="true"></i>
                        <div class="min-w-0">
                            <div class="batch-delete-file__name" id="batchDocumentName"></div>
                            <div class="batch-delete-file__meta" id="batchDocumentMeta"></div>
                        </div>
                    </div>
                    <ul class="batch-delete-notes" id="batchDocumentNotes"></ul>

                    <div class="leave-scan-modal__actions">
                        <button type="button" class="btn btn-secondary text-nowrap" id="batchDocCancelBtn" data-bs-dismiss="modal">
                            Keep it
                        </button>
                        <button type="button" class="btn btn-danger text-nowrap" id="confirmDeleteBatchDocBtn">
                            <i class="icon-base bx bx-trash icon-sm me-1" aria-hidden="true"></i>Remove from queue
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Flowchart: "Is it existing? -> Yes -> Data Extracted Rejected" --}}
    <div class="modal fade" id="existingRecordModal" tabindex="-1" aria-labelledby="existingRecordModalTitle"
        aria-describedby="existingRecordModalDescription" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered leave-scan-dialog">
            <div class="modal-content leave-scan-modal">
                <div class="modal-body">
                    <span class="leave-scan-modal__icon bg-label-danger" aria-hidden="true">
                        <i class="icon-base bx bx-x-circle"></i>
                    </span>
                    <h5 class="modal-title" id="existingRecordModalTitle">Data Extracted Rejected</h5>
                    <p class="leave-scan-modal__text" id="existingRecordModalDescription">
                        This document's extracted data already exists in the database.
                    </p>

                    <div class="leave-scan-modal__actions">
                        <a href="#" target="_blank" class="btn btn-outline-secondary d-none" id="existingRecordViewBtn">
                            View Existing Record
                        </a>
                        <button type="button" class="btn btn-danger" id="confirmRejectExistingBtn">
                            <i class="icon-base bx bx-x-circle icon-sm me-1" aria-hidden="true"></i>
                            <span id="confirmRejectExistingLabel">Reject &amp; End</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- OCR progress while the model runs --}}
    <div class="modal fade" id="scanningModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false"
        aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content ocr-progress-modal text-center">
                <div class="modal-body p-4 p-sm-5 pb-sm-4">
                    <div class="ocr-progress-ring mx-auto mb-4" id="ocrProgressRing" role="progressbar"
                        aria-label="OCR progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
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
        FLAG_REDRAW,
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
        import {
            fileFingerprint,
            firstUnsavedPage,
            isPdfFile,
            MAX_PDF_BYTES,
            MAX_SCAN_BYTES,
            nextUnsavedPage,
            pageRanges,
            pdfPageFile,
        } from '{{ Vite::asset('resources/js/scan-source.js') }}';
        import {
            pageSeconds,
            phaseProgress,
            scanTimings,
            timeLeftText,
        } from '{{ Vite::asset('resources/js/scan-progress.js') }}';

        const config = {
            typeLabel: @json($docType->label()),
            boxes: @json($boxes),
            ruledYs: @json($ruledYs),
            templateId: @json($template->getKey()),
            // A spread's page 2 laid out differently: its own template, boxes and row lines (or null).
            pageTwo: @json($pageTwo ?? null),
            groupingMode: @json(
                $template->grouping_mode instanceof \BackedEnum
                ? $template->grouping_mode->value
                : ($template->grouping_mode ?? 'auto')
            ),
            threshold: @json($threshold),
            // How long a scan's steps usually take on this machine (seconds).
            scanSeconds: @json($scanSeconds ?? null),
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
            pageRowsUrl: @json(route('documents.pages.rows', ['page' => '__PAGE__'])),
        lineRedrawUrl: @json(route('documents.pages.lines.redraw', ['page' => '__PAGE__', 'line' => '__LINE__'])),
            pageCancelUrl: @json(route('documents.pages.cancel', ['page' => '__PAGE__'])),
            // The pages of one file already saved; 64 zeros stand in for its fingerprint.
            sourcePagesUrl: @json(route('documents.sources.pages', ['hash' => str_repeat('0', 64)])),
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
        const scanFileInput = requiredInput(document, '#scanFile');
        const selectAllFieldsInput = requiredInput(document, '#selectAllFields');

        let scanFile = null;
        // One entry per outlined line of the processed page (see verificationItems).
        let cropped = [];
        let readings = [];
        let processedPage = null;
        // A two-page register read as one document: its left and right page,
        // each read with its own layout, verified side by side and saved as one
        // record. Row 5 of the left page and row 5 of the right are one person.
        // { pages: [{ number, page, layout }], offsets } or null. While it is
        // set, `cropped` holds both pages' fields (each with its pageIndex) and
        // processedPage is the page of the field being worked on.
        let verifyDoc = null;
        // Of a two-page document in Verify: the page (0, 1) zoomed in on, or null
        // (both side by side), and the zoom it was fitted at (zoomed by hand since,
        // it is no longer "in focus").
        let verifyFocus = null;
        let verifyFocusZoom = null;
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
            marquee: el('scanFieldSelectionMarquee'),
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

        // A document's other page, beside the one being verified: read-only, fitted
        // to half the width and zoomed with it; a click verifies it instead.
        const validationCompanionMarker = new FieldMarker({
            canvas: el('validationCompanionCanvas'),
            overlay: el('validationCompanionOverlay'),
            fitViewport: el('validationDocViewport'),
            fitShare: 0.5,
            readOnly: true,
        });
        validationMarker.linked = [validationCompanionMarker];
        const companionLines = new LineOverlay({
            container: el('validationCompanionOverlay'),
            preview: true,
        });

        /** A click on a field's outline, on either page (`index` is its place in `cropped`). */
        function selectVerifyLine(index, event) {
            // Picking fields for a new person: a click adds or takes one away.
            if (groupingFields || (event && (event.ctrlKey || event.metaKey || event.shiftKey))) {
                togglePickedField(index);
                return;
            }
            activateValidationField(index, 'marker');
        }

        // The outlines of the page being verified, and (a two-page register read
        // as one document) of its second page beside it: each page has its own
        // layer, which only knows its own lines.
        const verifyLayer = (side) => new LineOverlay({
            container: el(side === 0 ? 'validationFieldOverlay' : 'validationCompanionOverlay'),
            onSelect: (index, event) => selectVerifyLine(verifyIndexOf(side, index), event),
            onModeChange: (mode) => showVerifyLineMode(mode),
            // Verify has no Stretch tool: a closed drawing waits as a box to save.
            drawnMode: 'rectangle',
            onDraftChange: (points) => (addingMissedField
                ? showAddFieldDraft(points)
                : redrawingLineId !== null ? showRedrawDraft(points) : showVerifyDraft(points)),
            // Only a new box (Add missed field) arrives here finished and with no
            // line being edited; a redrawn outline is saved by Save and re-read.
            onEdit: (polygon, final) => {
                if (!final || verifyOverlays[side].editing) return;
                if (addingMissedField) addPendingBox(polygon, side);
                else if (redrawingLineId !== null) holdRedrawBox(polygon);
            },
        });
        const verifyOverlays = [verifyLayer(0), verifyLayer(1)];

        /** The page (0: the first, 1: the second) a field of `cropped` is on, and its place on that page. */
        function verifySideOf(index) {
            if (!verifyDoc) return { side: 0, local: index };
            const side = cropped[index]?.pageIndex ?? 0;
            return { side, local: index - (verifyDoc.offsets[side] ?? 0) };
        }

        /** A field's place in `cropped` from its page and its place there. */
        function verifyIndexOf(side, local) {
            return verifyDoc ? (verifyDoc.offsets[side] ?? 0) + local : local;
        }

        /** Fields of `cropped`, as each page's own places: [first page's, second page's]. */
        function splitBySide(indexes) {
            const parts = [[], []];
            indexes.forEach((index) => {
                const { side, local } = verifySideOf(index);
                parts[side]?.push(local);
            });
            return parts;
        }

        // What Verify asks of the outlines, said once for all the fields: each
        // call goes to the layer of the page the fields are on.
        const lineOverlay = {
            get editing() {
                return verifyOverlays[0].editing ?? verifyOverlays[1].editing;
            },
            setLines(page, items) {
                if (!verifyDoc) {
                    verifyOverlays[0].setLines(page, items);
                    verifyOverlays[1].clear();
                    return;
                }
                verifyDoc.pages.forEach((entry, side) => {
                    verifyOverlays[side].setLines(entry.page, items.filter((item) => (item.pageIndex ?? 0) === side));
                });
            },
            setSelection(indexes, current = null) {
                const parts = splitBySide(indexes);
                const at = current === null ? null : verifySideOf(current);
                verifyOverlays.forEach((overlay, side) => overlay.setSelection(parts[side], at?.side === side ? at.local : null));
            },
            setVerified(index, verified) {
                const { side, local } = verifySideOf(index);
                verifyOverlays[side].setVerified(local, verified);
            },
            setPicked(indexes) {
                const parts = splitBySide(indexes);
                verifyOverlays.forEach((overlay, side) => overlay.setPicked(parts[side]));
            },
            beginEdit(index, mode) {
                const { side, local } = verifySideOf(index);
                verifyOverlays.forEach((overlay, other) => other !== side && overlay.cancelEdit());
                verifyOverlays[side].beginEdit(local, mode);
            },
            // A new outline, on one page, or (null) on whichever page it is drawn.
            beginDraw(mode, side = null) {
                verifyOverlays.forEach((overlay, other) => {
                    const armed = side === null ? (other === 0 || verifyDoc !== null) : other === side;
                    if (armed) overlay.beginDraw(mode);
                    else overlay.cancelEdit();
                });
            },
            isDrawing() {
                return verifyOverlays.some((overlay) => overlay.isDrawing());
            },
            finishDrawing() {
                const drawing = verifyOverlays.find((overlay) => overlay.isDrawing())
                    ?? verifyOverlays.find((overlay) => overlay.editing?.mode === 'draw')
                    ?? verifyOverlays[0];
                return drawing.finishDrawing();
            },
            undoDrawStep() {
                return verifyOverlays.find((overlay) => overlay.isDrawing())?.undoDrawStep() ?? false;
            },
            cancelEdit() {
                verifyOverlays.forEach((overlay) => overlay.cancelEdit());
            },
            isAddingNew() {
                return verifyOverlays.some((overlay) => overlay.isAddingNew());
            },
            setEditMode(mode) {
                verifyOverlays.forEach((overlay) => overlay.editing && overlay.setEditMode(mode));
            },
            editedPolygon() {
                return (verifyOverlays.find((overlay) => overlay.editing && overlay.editing.index !== null)
                    ?? verifyOverlays[0]).editedPolygon();
            },
            // Boxes drawn and waiting, each on its page: [{ polygon, side }].
            setPending(boxes, options) {
                verifyOverlays.forEach((overlay, side) => overlay.setPending(
                    boxes.filter((box) => (box.side ?? 0) === side).map((box) => box.polygon),
                    options,
                ));
            },
            // After a zoom: handles keep their size on screen, on both pages.
            refresh() {
                verifyOverlays.forEach((overlay) => overlay.refresh());
            },
        };

        // On the second page each person's fields come after the first page's.
        const SECOND_PAGE_ORDER = 1000;

        /**
         * The fields Verify lists, from the page's lines (or both pages' lines,
         * the first page's first). A ledger line's person is its row, so row 5
         * of both pages falls into one person.
         */
        function verifyItems() {
            if (!verifyDoc) return verificationItems(processedPage.lines, processedPage);
            const items = verifyDoc.pages.flatMap((entry, side) => verificationItems(entry.page.lines, entry.page)
                .map((item) => ({
                    ...item,
                    pageIndex: side,
                    pageNumber: entry.number,
                    ...(side > 0 && Number.isInteger(item.personFieldOrder)
                        ? { personFieldOrder: item.personFieldOrder + SECOND_PAGE_ORDER }
                        : {}),
                })));
            verifyDoc.offsets = [0, items.filter((item) => item.pageIndex === 0).length];
            return items;
        }

        /** The pages being verified, as { number, page, layout }: one, or both of a register's. */
        function docEntries() {
            return verifyDoc?.pages ?? [{
                number: scanPageNumber,
                page: processedPage,
                layout: { templateId: config.templateId, boxes: config.boxes },
            }];
        }

        /** The page a field of `cropped` was read from. */
        function pageOfItem(index) {
            return verifyDoc ? verifyDoc.pages[cropped[index]?.pageIndex ?? 0]?.page ?? processedPage : processedPage;
        }

        /** The layout a field of `cropped` was read with. */
        function layoutOfItem(index) {
            return docEntries()[verifyDoc ? cropped[index]?.pageIndex ?? 0 : 0].layout;
        }

        /** Work on the page a field is on: its line requests go to that page. */
        function usePageOf(index) {
            if (verifyDoc) processedPage = pageOfItem(index);
            return processedPage;
        }

        function usePageOfLine(lineId) {
            const index = cropped.findIndex((item) => item.lineId === lineId);
            if (index >= 0) usePageOf(index);
            return processedPage;
        }

        /** How many table rows a page's grid has. */
        const tableRowsOf = (page) => Math.max(0, (page?.geometry?.ruled_ys?.length ?? 0) - 1);

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
        // once a marker is moved, the page is outlined again from the markers.
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
        const boxesOfLayout = (boxes) => boxes.map((box) => ({
            name: box.name,
            x: +box.x,
            y: +box.y,
            w: +box.w,
            h: +box.h,
            ...markerPersonMetadata(box),
            ...markerColumnMetadata(box),
            ...markerAngleMetadata(box),
        }));
        let templateBoxes = boxesOfLayout(config.boxes);
        let templateColumns = templateBoxes.filter((box) => box.kind === 'column');

        // What each template field and ledger column holds and how its value is
        // checked (Template Builder settings), by name: a line is read under its
        // field's or column's name.
        const nameKey = (name) => String(name ?? '').trim().toLowerCase();
        let templateSettings = new Map(config.boxes.map((box) => [nameKey(box.name), box]));
        // A layout's field and column settings by name, kept per layout.
        const layoutSettingsCache = new WeakMap();
        function layoutSettings(layout) {
            if (!layoutSettingsCache.has(layout)) {
                layoutSettingsCache.set(layout, new Map(layout.boxes.map((box) => [nameKey(box.name), box])));
            }
            return layoutSettingsCache.get(layout);
        }
        // A field's settings come from the layout its page was read with.
        const settingsFor = (index) => (verifyDoc
            ? layoutSettings(verifyDoc.pages[cropped[index]?.pageIndex ?? 0]?.layout ?? mainLayout)
            : templateSettings).get(nameKey(cropped[index]?.label)) ?? null;
        // The template's own rectangle fields: never turned into columns.
        let templateFieldNames = new Set(config.boxes.filter((box) => box.kind !== 'column').map((box) => nameKey(box.name)));

        // ------------------------------------------------------------ ledger rows
        // The ledger's row lines on this page (page fractions, top to bottom). They
        // start as the template's and go with the columns when the table is moved
        // or stretched; Snap to table takes them from the page; the user can move,
        // add or remove them. Nothing here changes the template.
        // As the Rows and Columns card is shown: the template has columns and row lines.
        let isLedger = config.ruledYs.length >= 2;
        let templateRows = config.ruledYs.map(Number);
        let gridRows = [...templateRows];

        // A spread whose pages are laid out differently: page 1 uses the layout
        // the workspace opened with, page 2 (and every even page) its own.
        const mainLayout = {
            templateId: config.templateId,
            boxes: config.boxes,
            ruledYs: config.ruledYs,
            groupingMode: config.groupingMode,
        };
        const layoutFor = (pageNumber) => (pageNumber % 2 === 0 && config.pageTwo ? config.pageTwo : mainLayout);

        /** Align and read with this layout from here on: its markers, row lines, field settings and id. */
        function useLayoutForPage(pageNumber) {
            const layout = layoutFor(pageNumber);
            config.templateId = layout.templateId;
            config.boxes = layout.boxes;
            config.ruledYs = layout.ruledYs;
            config.groupingMode = layout.groupingMode;
            templateBoxes = boxesOfLayout(config.boxes);
            templateColumns = templateBoxes.filter((box) => box.kind === 'column');
            templateSettings = new Map(config.boxes.map((box) => [nameKey(box.name), box]));
            templateFieldNames = new Set(config.boxes.filter((box) => box.kind !== 'column').map((box) => nameKey(box.name)));
            isLedger = config.ruledYs.length >= 2;
            templateRows = config.ruledYs.map(Number);
        }
        // How many degrees each row line is turned beyond the table's own tilt
        // (one per line, in step with gridRows): paper is curved, and no two
        // printed rules run quite alike. One is set by selecting its line and
        // using Tilt; Snap to table, Detect and Reset start every line at 0.
        let rowTilts = templateRows.map(() => 0);
        // Row lines with no printed line under them (rowKey), drawn amber.
        let estimatedRows = new Set();
        // A row line was moved, added or removed by hand: Detect keeps those.
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
        // Told after the page's row lines are drawn (set once the spread exists): the
        // page beside this one may be showing a copy of this grid.
        let onGridRendered = () => {};

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
                onGridRendered();
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
            onGridRendered();
        }

        /**
         * Markers and row lines set together, as one change: on a new page, by
         * Snap to table and Detect, by Reset, and by undo.
         */
        // Where each rectangle field was last placed by the template, Snap or Detect
        // (name -> box). A field that differs from it was moved by hand, and Snap
        // to table leaves such a field where it was put.
        let autoFields = new Map();

        const sameBox = (a, b) => ['x', 'y', 'w', 'h'].every((key) => Math.abs((a[key] ?? 0) - (b[key] ?? 0)) < 0.0005)
            && Math.abs((Number(a.angle) || 0) - (Number(b.angle) || 0)) < 0.05;

        function rememberAutoFields(boxes, except = new Set()) {
            boxes.filter((box) => box.kind !== 'column').forEach((box) => {
                const key = nameKey(box.name);
                if (!except.has(key)) autoFields.set(key, { x: box.x, y: box.y, w: box.w, h: box.h, angle: box.angle });
            });
        }

        /** The names of the fields as they stand now that were placed (or added) by hand. */
        function handPlacedFields(boxes = marker.toJSON()) {
            return new Set(boxes
                .filter((box) => box.kind !== 'column')
                .filter((box) => {
                    const placed = autoFields.get(nameKey(box.name));
                    return !placed || !sameBox(box, placed);
                })
                .map((box) => nameKey(box.name)));
        }

        function setGrid(boxes, rows, { estimated = [], byHand = false, tilts = null, selected = null, remember = false } = {}) {
            if (remember) {
                autoFields = new Map();
                rememberAutoFields(boxes);
            }
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
         * A column added for this page: it splits the selected column, or goes
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
         * at the left of the template's table and a typical column wide. The user
         * then drags it into place and adds its rows.
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

            const message = !rowsByHand && rowsSource
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
            // A field stretched down two or more rows of the table is read row
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
        async function applyDetection(page, { imageShown = false, keepView = false } = {}) {
            // The same pixels the outlines were found on, and that go on to TrOCR
            // (already on the canvas when a page tested beside this one is opened).
            if (!imageShown) await marker.loadFromUrl(page.imageUrl);
            updateScanNotices();
            // A page opened with its result asks for its printed lines itself.
            if (!imageShown) fetchSnapLines();
            // The markers and row lines Detect fitted (the page's own rows, unless
            // rows set by hand stay), as one change Ctrl+Z can take back.
            const rows = page.geometry?.ruled_ys ?? gridRows;
            rowsSource = null;
            setGrid(geometryMarkers(page.geometry, page), rows, {
                estimated: (page.geometry?.estimated_rows ?? []).filter((index) => rows[index] !== undefined)
                    .map((index) => rowKey(rows[index])),
                byHand: rowsByHand,
                remember: true,
            });
            if (!keepView) window.requestAnimationFrame(() => marker.resetZoom());

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
            // Of a two-page document, both pages' notes, each saying its page.
            const notes = verifyDoc
                ? verifyDoc.pages.flatMap((entry) => gridNotes(entry.page?.notes).map((note) => `Page ${entry.number}: ${note}`))
                : gridNotes(page?.notes);
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

            const batchBanner = document.getElementById('batchQueueBanner');
            if (batchBanner) {
                if (name === 'mark') {
                    const toolbar = document.querySelector('#step-mark .marker-toolbar');
                    toolbar?.parentNode?.insertBefore(batchBanner, toolbar);
                } else if (name === 'verify') {
                    const verifyHeader = document.querySelector('#step-verify .validation-document-pane .validation-pane__header');
                    verifyHeader?.parentNode?.insertBefore(batchBanner, verifyHeader);
                }
            }

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
        // The opened file. A PDF of many pages is read one page at a time, each
        // saved as its own record: the page being read (from 1), the file's
        // fingerprint, and which of its pages are saved already (page number ->
        // {recordId, title, url}), so the page bar ticks them.
        let scanPageCount = 1;
        let scanPageNumber = 1;
        let scanFingerprint = null;
        let savedPages = new Map();
        let pageTurning = false;

        const multiPage = () => scanPageCount > 1;

        /** "BIRTHS-1995.pdf · page 3 of 12", or the name alone for a file of one page. */
        function scanLabel() {
            const name = scanFile?.name || 'Document';
            return multiPage() ? `${name} · page ${scanPageNumber} of ${scanPageCount}` : name;
        }

        function showScanContext() {
            el('workspaceContextDetail').textContent = `${config.typeLabel} · ${scanLabel()}`;
            el('workspaceContextDetail').title = el('workspaceContextDetail').textContent;
        }

        /**
         * What to know about the scan itself. Any paper size is fine
         * (markers are page fractions), but a tall scan of wide pages, or the
         * other way round, is likely sideways. And this page (or this file) may
         * be saved already, or other pages of this PDF may be.
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

            const here = savedPages.get(scanPageNumber) ?? null;
            const others = [...savedPages.keys()].filter((page) => page !== scanPageNumber && page <= scanPageCount);
            let message = '';
            if (here) {
                message = multiPage()
                    ? `Page ${scanPageNumber} is already saved as record #${here.recordId}.`
                    : `This file is already saved as record #${here.recordId}.`;
            } else if (multiPage() && others.length > 0) {
                message = `Saved already: page${others.length === 1 ? '' : 's'} ${pageRanges(others)} of ${scanPageCount}.`;
            }
            el('scanPagesMessage').textContent = message;
            const link = el('scanPagesRecordLink');
            link.classList.toggle('d-none', !here);
            if (here) link.href = here.url;
            // Other pages saved is good to know; this one saved is a warning.
            el('scanPagesNotice').classList.toggle('is-info', !here);
            el('scanPagesNotice').classList.toggle('d-none', message === '');

            updatePageBar();
        }

        /** ‹ Page 3 of 12 › in the Align toolbar, for a PDF of many pages; ✓: saved already. */
        function updatePageBar() {
            el('scanPageBar').classList.toggle('d-none', !multiPage());
            if (!multiPage()) return;

            // A real dropdown, so a saved page can show a green check icon.
            const menu = el('scanPageMenu');
            if (menu.children.length !== scanPageCount) {
                menu.replaceChildren(...Array.from({ length: scanPageCount }, (_, index) => {
                    const item = document.createElement('button');
                    item.type = 'button';
                    item.className = 'dropdown-item';
                    item.dataset.page = String(index + 1);
                    item.addEventListener('click', () => turnToPage(index + 1));
                    return item;
                }));
            }
            const label = (page) => {
                const span = document.createElement('span');
                span.className = 'marker-page-label';
                span.append(`Page ${page} of ${scanPageCount}`);
                if (savedPages.has(page)) {
                    const check = document.createElement('i');
                    check.className = 'icon-base bx bx-check marker-page-saved';
                    check.setAttribute('aria-label', 'saved');
                    check.title = 'Saved already';
                    span.append(check);
                }
                return span;
            };
            Array.from(menu.children).forEach((item) => {
                const page = Number(item.dataset.page);
                item.replaceChildren(label(page));
                item.classList.toggle('active', page === scanPageNumber);
            });

            const select = el('scanPageSelect');
            select.replaceChildren(label(scanPageNumber));
            select.disabled = pageTurning;
            el('scanPagePrevBtn').disabled = pageTurning || scanPageNumber <= 1;
            el('scanPageNextBtn').disabled = pageTurning || scanPageNumber >= scanPageCount;
        }

        /**
         * The file's fingerprint, and which of its pages are saved already. A
         * lookup that fails ticks nothing: the server still asks before a page
         * is saved twice.
         */
        async function lookUpSavedPages(file) {
            try {
                const fingerprint = await fileFingerprint(file);
                if (scanFile !== file) return;
                scanFingerprint = fingerprint;

                const response = await fetch(config.sourcePagesUrl.replace('0'.repeat(64), fingerprint), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: AbortSignal.timeout?.(10000),
                });
                if (!response.ok) throw new Error(`The server answered ${response.status}.`);
                const payload = await response.json();
                if (scanFile !== file) return;
                savedPages = new Map((payload.pages ?? []).map((entry) => [Number(entry.page), entry]));
            } catch (error) {
                console.warn('The saved pages of this file could not be looked up:', error);
            }
        }

        async function openDocument(file) {
            if (!file) return;

            // Of a PDF only the page being read is sent, so it may be large.
            const pdf = isPdfFile(file);
            if (file.size > (pdf ? MAX_PDF_BYTES : MAX_SCAN_BYTES)) {
                alert(pdf ? 'Choose a PDF smaller than 200 MB.' : 'Choose an image smaller than 20 MB.');
                scanFileInput.value = '';
                return;
            }

            dropzone.classList.add('is-loading');
            dropzone.setAttribute('aria-busy', 'true');

            try {
                scanFile = file;
                scanFingerprint = null;
                savedPages = new Map();
                pageLayouts.clear();
                clearDetection();
                verifyDoc = null;
                // It opens at its first page not saved yet.
                const [pageCount] = await Promise.all([pdf ? marker.pdfPageCount(file) : 1, lookUpSavedPages(file)]);
                if (pageCount <= 1 && file.size > MAX_SCAN_BYTES) {
                    throw new Error('Choose a document smaller than 20 MB. A PDF of many pages may be larger: only the page being read is sent.');
                }
                scanPageCount = pageCount;
                scanPageNumber = firstUnsavedPage(pageCount, savedPages);
                useLayoutForPage(scanPageNumber);
                await marker.load(file, { page: scanPageNumber });
                updateScanNotices();
                showScanContext();
                showStep('mark');
                guardBackButton();
                resetFieldHistory();
                rowsSource = null;
                setGrid(templateBoxes, templateRows, { remember: true });
                fetchSnapLines();
                resetSpread();

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

        // ---------------------------------------------------------------- two pages at a time
        // A PDF of two or more pages opens as a spread, like an open book: pages
        // 1-2, then 3-4. The page being aligned has the live markers; the other
        // page of its pair sits beside it, read-only, showing the alignment it was
        // given. Click it (or "Align this page") to work on it instead. Each page
        // keeps its own markers and row lines, and is still saved as its own record.
        const companionMarker = new FieldMarker({
            canvas: el('companionCanvas'),
            overlay: el('companionOverlay'),
            // It shares the page's scroll container: fitted to half its width,
            // zoomed with the page, and scrolled by the same scrollbars.
            fitViewport: el('docViewport'),
            fitShare: 0.5,
            readOnly: true,
        });
        marker.linked = [companionMarker];
        // Its row lines too, for looking at only: no grips.
        const companionRows = new RowLineLayer({ overlay: el('companionOverlay') });
        // And the outlines Smoke test found on it, to look at only.
        const companionPreview = new LineOverlay({ container: el('companionOverlay'), preview: true });
        // What Smoke test found on a page of the PDF that is not being aligned now
        // (page number -> the detected page). A pair is tested together, so the
        // other page comes already detected when it is opened. A queued batch page
        // keeps its own on the queue item (item.detected).
        const pageDetections = new Map();
        let companionPage = null;
        let companionFile = null;
        let companionLoadToken = 0;
        let companionGridKey = '';
        let companionGridFrame = 0;

        /** A layout's own markers and row lines, as a page opened on it starts. */
        function layoutStart(page) {
            const layout = layoutFor(page);
            return { boxes: boxesOfLayout(layout.boxes), rows: layout.ruledYs.map(Number), tilts: null };
        }

        /**
         * The grid to show on the page beside the one being aligned. A page
         * already worked on shows its own. One not opened yet shows what it
         * would start as when opened: a copy of this page's grid (the layouts
         * match) or its layout's own, so every page shows its grid at once.
         */
        function companionGridFor(other, mate) {
            if (mate) {
                return mate.item.boxes && mate.item.rows
                    ? { boxes: mate.item.boxes, rows: mate.item.rows, tilts: mate.item.tilts }
                    : layoutStart(other);
            }
            const saved = pageLayouts.get(other)?.grid;
            if (saved) return saved;
            return layoutFor(other) === layoutFor(scanPageNumber)
                ? { boxes: marker.toJSON(), rows: gridRows, tilts: rowTilts }
                : layoutStart(other);
        }

        function renderCompanionGrid(grid) {
            const key = JSON.stringify(grid);
            if (key === companionGridKey && companionMarker.boxes.length === grid.boxes.length) return;
            companionGridKey = key;

            companionMarker.setBoxes(cloneBoxes(grid.boxes));
            const columns = ledgerColumns(grid.boxes);
            const { width, height } = companionMarker.canvas;
            if (columns.length === 0 || grid.rows.length < 2) {
                companionRows.render({ rows: [] });
                return;
            }
            companionRows.render({
                rows: grid.rows,
                left: Math.min(...columns.map((column) => column.x)),
                right: Math.max(...columns.map((column) => column.x + column.w)),
                locked: true,
                tilt: gridTilt(columns),
                tilts: grid.tilts ?? grid.rows.map(() => 0),
                aspect: width > 0 && height > 0 ? width / height : 1,
            });
        }

        // A page not opened yet follows this one's grid as it changes, as it starts
        // from a copy of it: redrawn once per frame at most.
        onGridRendered = () => {
            if (companionPage === null || companionGridFrame) return;
            companionGridFrame = window.requestAnimationFrame(() => {
                companionGridFrame = 0;
                const mate = batchPartner();
                if (companionPage === null || mate || pageLayouts.has(companionPage)) return;
                renderCompanionGrid(companionGridFor(companionPage, null));
            });
        };

        // The zoom a page was last focused at (null: not focused). While the view
        // still sits at it, a change in the room it has (the side bar opening or
        // closing, the window resized) fits the page to the new room.
        let focusedZoom = null;

        /**
         * Zoom in on the page being aligned and centre it: as large as the room allows,
         * whole, with one easing motion. Called when a page of the spread is clicked.
         */
        function focusMainPage({ animate = true } = {}) {
            focusedZoom = marker.focusOn(el('docMainPage'), { duration: animate ? 420 : 0 });
        }

        let focusRoom = null;
        let focusRefitTimer = 0;
        new ResizeObserver(() => {
            const viewport = el('docViewport');
            const room = { width: viewport.clientWidth, height: viewport.clientHeight };
            const before = focusRoom;
            focusRoom = room;
            if (!before || focusedZoom === null || !viewport.classList.contains('is-spread')) return;
            if (Math.abs(room.width - before.width) < 4 && Math.abs(room.height - before.height) < 4) return;
            // The side bar slides over a moment: fit once it has settled.
            window.clearTimeout(focusRefitTimer);
            focusRefitTimer = window.setTimeout(() => {
                if (focusedZoom === null) return;
                if (Math.abs(marker.zoom - focusedZoom) > 0.011) {
                    focusedZoom = null; // zoomed by hand since: leave it
                    return;
                }
                focusMainPage({ animate: false });
            }, 150);
        }).observe(el('docViewport'));

        // Before a page of the spread is zoomed on, its first click only does that:
        // the markers under it are for working once it is in view.
        el('docMainPage').addEventListener('pointerdown', (event) => {
            const viewport = el('docViewport');
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey) return;
            if (!viewport.classList.contains('is-spread') || Math.abs(marker.zoom - 1) > 0.01) return;
            if (detection || pageTurning || scanInProgress || event.target.closest('.doc-spread__label')) return;

            event.preventDefault();
            event.stopPropagation();
            const swallowClick = (clickEvent) => {
                clickEvent.stopPropagation();
                clickEvent.preventDefault();
            };
            el('docMainPage').addEventListener('click', swallowClick, { capture: true, once: true });
            window.setTimeout(() => el('docMainPage').removeEventListener('click', swallowClick, true), 400);
            focusMainPage();
        }, true);

        /** The other page of this page's pair, or null: a lone last page has no partner. */
        function companionPageFor(page) {
            if (!multiPage()) return null;
            const first = page % 2 === 1 ? page : page - 1;
            const other = page === first ? first + 1 : first;
            return other >= 1 && other <= scanPageCount ? other : null;
        }

        /** The queued page paired with the one being aligned (pages 1-2, 3-4), or null. */
        function batchPartner() {
            if (!batchState.active || batchState.phase !== 'queuing') return null;
            const current = batchState.items[batchState.currentIndex];
            if (!current?.group || !(current.pageTotal > 1)) return null;
            const first = current.pageNo % 2 === 1 ? current.pageNo : current.pageNo - 1;
            const wanted = current.pageNo === first ? first + 1 : first;
            const index = batchState.items.findIndex((it) => it.group === current.group && it.pageNo === wanted);
            return index === -1 ? null : { index, item: batchState.items[index] };
        }

        function resetSpread() {
            pageLayouts.clear();
            pageDetections.clear();
            companionPage = null;
            companionFile = null;
            companionLoadToken += 1;
            updateSpread();
        }

        /** Show the page beside the one being aligned, with the alignment it has so far. */
        async function updateSpread() {
            // In a batch the partner is the neighbouring page of the same document.
            const mate = batchPartner();
            const other = mate ? mate.item.pageNo : companionPageFor(scanPageNumber);
            const pane = el('docCompanionPage');
            const mainNumber = mate ? batchState.items[batchState.currentIndex].pageNo : scanPageNumber;

            const wasSpread = el('docViewport').classList.contains('is-spread');
            el('docViewport').classList.toggle('is-spread', other !== null);
            pane.classList.toggle('d-none', other === null);
            // One page fills the width; two share it.
            marker.fitShare = other === null ? 1 : 0.5;
            if (wasSpread !== (other !== null)) marker.resetZoom();
            el('docMainLabel').classList.toggle('d-none', !multiPage() && !mate);
            el('docMainLabel').textContent = `Page ${mainNumber} · aligning`;

            if (other === null) {
                companionPage = null;
                companionFile = null;
                companionLoadToken += 1;
                companionPreview.clear();
                updateScanButtonLabel();
                return;
            }

            // Pages stay where they are in the book: the lower page on the left.
            const companionLeft = other < mainNumber;
            pane.style.order = companionLeft ? '-1' : '1';
            const saved = mate ? mate.item.status === 'saved' : savedPages.has(other);
            const testedMark = storedDetection(other, mate) ? ' · tested' : '';
            el('docCompanionLabel').textContent = `Page ${other}${saved ? ' · saved' : ''}${testedMark} — click to align`;
            el('docCompanionSwitch').classList.toggle('d-none', pageTurning || scanInProgress);

            const token = ++companionLoadToken;
            // A page Smoke test has been over shows as the detector left it:
            // straightened, which is what its outlines and markers fit.
            const tested = storedDetection(other, mate);
            const companionSource = companionSourceFor(other, mate);
            if (companionPage !== other || companionFile !== companionSource) {
                companionPage = other;
                companionFile = companionSource;
                companionPreview.clear();
                try {
                    if (tested) await companionMarker.loadFromUrl(tested.imageUrl);
                    else await companionMarker.load(companionSource, { page: mate ? 1 : other });
                } catch (error) {
                    console.warn(`Page ${other} could not be shown beside the page:`, error);
                    companionPage = null;
                    return;
                }
                if (token !== companionLoadToken) return;
            }

            // The page's grid shows from the start, opened or not. A page just loaded
            // is sized to the zoom the pair already has, so it stays level.
            companionGridKey = '';
            marker.refit();
            renderCompanionGrid(companionGridFor(other, mate));
            if (tested) {
                companionPreview.setLines(tested, verificationItems(tested.lines, tested));
                companionPreview.setGuides([]);
                companionPreview.setVisible(true);
            } else {
                companionPreview.clear();
            }
            updateScanButtonLabel();
        }

        /**
         * The page Scan with OCR reads together with this one, as one document:
         * the other page of a two-page register (its layout has a page 2) shown
         * beside it, outside a batch. Null when this page is read alone. It goes
         * with the markers it shows, or what Smoke test made of it.
         *
         * @returns {{number: number, grid: object, tested: object|null}|null}
         */
        function spreadPartnerForScan() {
            if (batchState.active || !config.pageTwo || companionPage === null || !(companionMarker.canvas.width > 0)) return null;
            const grid = companionGridFor(companionPage, null);
            if (grid.boxes.length === 0 || markerSetValidationMessage(grid.boxes) !== null) return null;
            return { number: companionPage, grid, tested: storedDetection(companionPage) };
        }

        /** "Scan both pages" while the page beside this one is read with it. */
        function updateScanButtonLabel() {
            // Not while a scan has the button showing its progress.
            const label = document.getElementById('scanNowBtnLabel');
            if (batchState.active || !label) return;
            label.textContent = spreadPartnerForScan() ? 'Scan both pages' : 'Scan with OCR';
        }

        /** Smoke test's result kept for a page not being aligned now, or null. */
        function storedDetection(page, mate = null) {
            return mate ? (mate.item.detected ?? null) : (pageDetections.get(page) ?? null);
        }

        /** What the page beside this one is drawn from: its tested page, or the file. */
        function companionSourceFor(page, mate = null) {
            return storedDetection(page, mate)?.imageUrl ?? (mate ? mate.item.file : scanFile);
        }

        /**
         * Leaving a page (a PDF page number, or a queued batch page): its Smoke
         * test result is kept while it still fits its markers, so coming back,
         * or going on to it after a save, needs no second run.
         */
        function keepDetection(target) {
            const kept = detectionIsCurrent() ? detection.page : null;
            if (typeof target === 'object' && target !== null) target.detected = kept;
            else if (kept) pageDetections.set(target, kept);
            else pageDetections.delete(target);
        }

        /**
         * Smoke test's result for the other page of the pair: its markers and rows
         * become that page's own (as Smoke test fitted them), and it shows beside
         * this one with its outlines.
         */
        async function keepCompanionDetection(page, mate, tested) {
            const boxes = cloneBoxes(geometryMarkers(tested.geometry, tested));
            const rows = (tested.geometry?.ruled_ys ?? companionGridFor(page, mate).rows).map(Number);
            const estimated = (tested.geometry?.estimated_rows ?? [])
                .filter((index) => rows[index] !== undefined)
                .map((index) => rowKey(rows[index]));
            if (mate) {
                mate.item.detected = tested;
                mate.item.boxes = boxes;
                mate.item.rows = rows;
                mate.item.tilts = rows.map(() => 0);
            } else {
                pageDetections.set(page, tested);
                pageLayouts.set(page, {
                    grid: { boxes, rows, tilts: rows.map(() => 0), estimated, byHand: false },
                    autoFields: new Map(),
                    rowsSource: null,
                    history: [],
                });
            }
            await updateSpread();
        }

        // Each page of a PDF has its own layout: markers, row lines, tilt and
        // Ctrl+Z history (page number -> state). Going back to a page finds it as
        // it was left, and the page shown beside it in a spread shows it too. A
        // page never opened starts as a copy of the page just left: pages of one
        // book sit much alike, and Snap to table finds a page whose printed lines
        // are faint or creased far better from there than from the template. It
        // is a copy, not a link: each page changes on its own. A page laid out
        // differently (page 2 of a two-page template) starts from its own layout.
        const pageLayouts = new Map();

        function savePageLayout(page) {
            pageLayouts.set(page, {
                grid: gridState(),
                autoFields: new Map(autoFields),
                rowsSource,
                history: [...fieldHistory],
            });
        }

        /** Call after useLayoutForPage(page), so the template's markers are this page's. */
        function showPageLayout(page, { copyFrom = null } = {}) {
            const saved = pageLayouts.get(page);
            fieldHistory = [];
            currentFieldSnapshot = null;
            if (!saved) {
                // The page left behind's markers and rows, as this page's own
                // starting point: what Snap and Detect may move, as from the
                // template. Its amber marks belonged to the other page.
                const sameLayout = copyFrom !== null && layoutFor(copyFrom) === layoutFor(page);
                const start = sameLayout ? pageLayouts.get(copyFrom)?.grid : null;
                rowsSource = null;
                if (start) setGrid(start.boxes, start.rows, { tilts: start.tilts, remember: true });
                else setGrid(templateBoxes, templateRows, { remember: true });
                return;
            }
            setGrid(saved.grid.boxes, saved.grid.rows, {
                estimated: saved.grid.estimated,
                byHand: saved.grid.byHand,
                tilts: saved.grid.tilts,
            });
            autoFields = new Map(saved.autoFields);
            rowsSource = saved.rowsSource;
            fieldHistory = [...saved.history];
            currentFieldSnapshot = gridState();
            updateGridCard();
        }

        /**
         * Another page of the PDF, in Align. Each page keeps its own markers, row
         * lines and tilt, as scans are never turned alike: the page being left is
         * remembered, and the one shown comes with its own layout. What Detect
         * found belongs to the page before, so it is let go.
         */
        async function turnToPage(pageNumber) {
            const page = Math.min(scanPageCount, Math.max(1, Math.trunc(Number(pageNumber)) || 1));
            if (!scanFile || page === scanPageNumber || pageTurning || scanInProgress) {
                updatePageBar();
                return;
            }

            pageTurning = true;
            updatePageBar();
            try {
                const left = scanPageNumber;
                keepDetection(left);
                clearDetection();
                savePageLayout(left);
                // Smoke test may have been over the page being opened already.
                const tested = storedDetection(page);
                pageDetections.delete(page);
                // The page beside this one is already drawn: the two trade places
                // on the spot, with nothing to load, instead of drawing it again.
                const swap = page === companionPage && companionFile === (tested?.imageUrl ?? scanFile)
                    && companionMarker.canvas.width > 0 && !batchPartner();
                if (swap) {
                    marker.swapPageWith(companionMarker);
                    companionPage = left;
                    companionFile = companionSourceFor(left);
                } else if (tested) {
                    await marker.loadFromUrl(tested.imageUrl);
                } else {
                    await marker.load(scanFile, { page });
                }
                scanPageNumber = page;
                useLayoutForPage(page);
                showPageLayout(page, { copyFrom: left });
                if (tested) await applyDetection(tested, { imageShown: true, keepView: true });
                clearOcrError();
                updateScanNotices();
                showScanContext();
                fetchSnapLines();
                // The new page is sized to the zoom there is, then the view zooms on it
                // and centres it.
                if (!swap) marker.refit();
                await updateSpread();
                focusMainPage();
            } catch (error) {
                showOcrError(error.message || `Page ${page} could not be opened.`);
            } finally {
                pageTurning = false;
                updatePageBar();
                el('docCompanionSwitch').classList.toggle('d-none', companionPageFor(scanPageNumber) === null);
            }
        }

        el('scanPagePrevBtn').addEventListener('click', () => turnToPage(scanPageNumber - 1));
        el('scanPageNextBtn').addEventListener('click', () => turnToPage(scanPageNumber + 1));
        el('docCompanionPage').addEventListener('click', () => {
            const mate = batchPartner();
            if (mate) {
                if (!pageTurning && !scanInProgress) openBatchDocument(mate.index);
            } else if (companionPage !== null) {
                turnToPage(companionPage);
            }
        });

        // ---------------------------------------------------------------- Upload Mode & Batch Queue System
        let uploadMode = 'single';
        let batchState = {
            active: false,
            key: null,
            phase: 'idle', // 'queuing' | 'scanning' | 'verifying' | 'done'
            items: [],     // { file, name, status, pageId, page, workspaceScanPath, recordUrl, error, boxes, rows, tilts }
            currentIndex: 0,
        };

        const uploadModeRadios = document.querySelectorAll('input[name="uploadMode"]');
        const uploadModeCards = document.querySelectorAll('[data-mode-card]');
        const uploadModeHintEl = document.getElementById('uploadModeHint');
        const dropzoneTitleEl = document.getElementById('dropzoneTitle');
        const dropzoneButtonEl = document.getElementById('dropzoneButton');
        const dropzoneFolderBtnEl = document.getElementById('dropzoneFolderButton');
        const scanFolderInput = document.getElementById('scanFolder');

        uploadModeRadios.forEach((radio) => {
            radio.addEventListener('change', (e) => {
                uploadMode = e.target.value;
                uploadModeCards.forEach((card) => {
                    card.classList.toggle('is-active', card.dataset.modeCard === uploadMode);
                });
                if (uploadMode === 'batch') {
                    scanFileInput.setAttribute('multiple', 'multiple');
                    if (dropzoneTitleEl) dropzoneTitleEl.textContent = 'Drop batch scans or a folder here';
                    if (dropzoneButtonEl) dropzoneButtonEl.textContent = 'Choose files';
                    if (dropzoneFolderBtnEl) dropzoneFolderBtnEl.classList.remove('d-none');
                    if (uploadModeHintEl) uploadModeHintEl.textContent = 'Upload files or a folder to /documents/workspace, scan all, then validate';
                } else {
                    scanFileInput.removeAttribute('multiple');
                    if (dropzoneTitleEl) dropzoneTitleEl.textContent = 'Drop the scan here';
                    if (dropzoneButtonEl) dropzoneButtonEl.textContent = 'Choose file';
                    if (dropzoneFolderBtnEl) dropzoneFolderBtnEl.classList.add('d-none');
                    if (uploadModeHintEl) uploadModeHintEl.textContent = 'Upload and process one document at a time';
                }
            });
        });

        dropzoneFolderBtnEl?.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (scanFolderInput instanceof HTMLInputElement) {
                scanFolderInput.value = '';
                scanFolderInput.click();
            }
        });

        scanFolderInput?.addEventListener('change', (event) => {
            if (!(event.currentTarget instanceof HTMLInputElement)) return;
            startBatchQueue(event.currentTarget.files);
        });

        /** The queue's documents: a PDF of many pages is one document, its pages kept side by side. */
        function batchGroups() {
            const groups = [];
            const byKey = new Map();
            batchState.items.forEach((item, index) => {
                const key = item.group ?? `solo-${index}`;
                let group = byKey.get(key);
                if (!group) {
                    group = { key, indices: [], items: [] };
                    byKey.set(key, group);
                    groups.push(group);
                }
                group.indices.push(index);
                group.items.push(item);
            });
            return groups;
        }

        /** One status for a document: what still needs attention wins. */
        function groupStatus(group) {
            const order = ['failed', 'saving', 'scanning', 'verifying', 'checked', 'pending', 'saved', 'ready', 'rejected', 'imported'];
            return order.find((status) => group.items.some((it) => it.status === status)) ?? 'pending';
        }

        function renderBatchQueueBanner() {
            const banner = document.getElementById('batchQueueBanner');
            if (!banner) return;

            if (!batchState.active || batchState.items.length === 0) {
                banner.classList.add('d-none');
                return;
            }
            banner.classList.remove('d-none');
            banner.dataset.phase = batchState.phase;

            const total = batchState.items.length;
            const phaseTitle = document.getElementById('batchQueuePhaseTitle');
            const counter = document.getElementById('batchQueueCounter');
            const progressTrack = document.getElementById('batchQueueProgressTrack');
            const progressBar = document.getElementById('batchQueueProgressBar');
            const chipsContainer = document.getElementById('batchQueueChips');
            const addMoreBtn = document.getElementById('batchAddMoreBtn');
            const saveQueueBtn = document.getElementById('saveQueueNextBtn');
            const saveQueueLabel = document.getElementById('saveQueueNextLabel');
            const scanNowBtn = document.getElementById('scanNowBtn');
            const scanNowLabel = document.getElementById('scanNowBtnLabel');

            const savedCount = batchState.items.filter((it) => ['saved', 'scanning', 'ready', 'verifying', 'imported', 'rejected'].includes(it.status)).length;
            const readyCount = batchState.items.filter((it) => ['ready', 'verifying', 'imported', 'rejected'].includes(it.status)).length;
            // Pages are what is aligned and scanned one by one; documents are what is imported.
            const unit = batchGroups().length === total ? 'document' : 'page';

            const stageOrder = ['queuing', 'scanning', 'verifying'];
            const activeStageIdx = stageOrder.indexOf(batchState.phase);
            banner.querySelectorAll('[data-batch-stage]').forEach((stageNode) => {
                const idx = stageOrder.indexOf(stageNode.dataset.batchStage);
                stageNode.classList.toggle('is-active', idx === activeStageIdx);
                stageNode.classList.toggle('is-complete', idx < activeStageIdx);
            });

            const updateProgress = (pct) => {
                if (progressBar) progressBar.style.width = `${pct}%`;
                if (progressTrack) progressTrack.setAttribute('aria-valuenow', String(pct));
            };

            if (batchState.phase === 'queuing') {
                const allSaved = savedCount === total;
                if (phaseTitle) {
                    phaseTitle.textContent = allSaved
                        ? 'All documents saved in workspace queue — ready to scan all'
                        : `Align & save each ${unit} to queue (${savedCount} of ${total} saved)`;
                }
                if (counter) counter.textContent = `${savedCount} / ${total} saved`;
                updateProgress(Math.round((savedCount / total) * 100));
                if (addMoreBtn) addMoreBtn.classList.remove('d-none');
                if (saveQueueBtn) {
                    saveQueueBtn.classList.toggle('d-none', allSaved);
                    if (saveQueueLabel) {
                        const currentItem = batchState.items[batchState.currentIndex];
                        const hasMoreUnsaved = batchState.items.some((it, idx) => idx !== batchState.currentIndex && it.status === 'pending');
                        saveQueueLabel.textContent = currentItem?.status === 'saved'
                            ? (hasMoreUnsaved ? 'Update & Next Document' : 'Update Queued Document')
                            : (hasMoreUnsaved ? `Save & Next (${savedCount + 1}/${total})` : `Save Final Document (${total}/${total})`);
                    }
                }
                if (scanNowBtn) {
                    scanNowBtn.classList.toggle('d-none', !allSaved);
                    if (scanNowLabel) scanNowLabel.textContent = `Scan All Queued Documents (${total})`;
                }
            } else if (batchState.phase === 'scanning') {
                if (phaseTitle) phaseTitle.textContent = `Scanning all queued documents with OCR (${readyCount} of ${total} ${unit}s scanned)...`;
                if (counter) counter.textContent = `${readyCount} / ${total} ${unit}s scanned`;
                updateProgress(Math.round((readyCount / total) * 100));
                if (addMoreBtn) addMoreBtn.classList.add('d-none');
                if (saveQueueBtn) saveQueueBtn.classList.add('d-none');
                if (scanNowBtn) scanNowBtn.classList.remove('d-none');
            } else if (batchState.phase === 'verifying') {
                // A PDF of many pages is one document: it is imported once, all its pages together.
                const documents = batchGroups();
                const importedDocuments = documents.filter((group) => (
                    group.items.every((it) => ['imported', 'rejected'].includes(it.status))
                )).length;
                if (phaseTitle) phaseTitle.textContent = `Validate & import each document (${importedDocuments} of ${documents.length} imported)`;
                if (counter) counter.textContent = `${importedDocuments} / ${documents.length} imported`;
                updateProgress(Math.round((importedDocuments / documents.length) * 100));
                if (addMoreBtn) addMoreBtn.classList.add('d-none');
                if (saveQueueBtn) saveQueueBtn.classList.add('d-none');
            }

            if (chipsContainer) {
                chipsContainer.innerHTML = '';
                const groups = batchGroups();
                groups.forEach((group, index) => {
                    const first = group.items[0];
                    const item = {
                        ...first,
                        status: groupStatus(group),
                        name: group.items.length > 1 ? `${first.sourceName ?? first.name} (${group.items.length} pages)` : first.name,
                    };
                    const badgeMap = {
                        pending: { cls: 'is-pending', icon: 'bx-time-five', text: 'Pending' },
                        saving: { cls: 'is-working', icon: 'bx-refresh', text: 'Saving' },
                        saved: { cls: 'is-queued', icon: 'bx-check-circle', text: 'Queued' },
                        scanning: { cls: 'is-working', icon: 'bx-radar', text: 'Scanning' },
                        ready: { cls: 'is-ready', icon: 'bx-scan', text: 'Scanned' },
                        verifying: { cls: 'is-verifying', icon: 'bx-edit-alt', text: 'Validating' },
                        checked: { cls: 'is-verifying', icon: 'bx-check', text: 'Page checked' },
                        imported: { cls: 'is-imported', icon: 'bx-check-shield', text: 'Imported' },
                        rejected: { cls: 'is-failed', icon: 'bx-x-circle', text: 'Rejected (Existing)' },
                        failed: { cls: 'is-failed', icon: 'bx-error-circle', text: 'Failed' },
                    };
                    const badgeInfo = { ...(badgeMap[item.status] || badgeMap.pending) };
                    // A document of several pages says how many of them are checked.
                    if (group.items.length > 1 && batchState.phase === 'verifying' && ['verifying', 'checked'].includes(item.status)) {
                        const checkedPages = group.items.filter((it) => it.staged || it.status === 'imported').length;
                        badgeInfo.text = `${checkedPages}/${group.items.length} pages checked`;
                    }
                    const isCurrent = group.indices.includes(batchState.currentIndex);
                    const canInteract = batchState.phase !== 'scanning' && !(batchState.phase === 'verifying' && group.items.every((it) => ['imported', 'rejected'].includes(it.status)));

                    // The chip opens its document on click; the small × beside it removes it.
                    const chip = document.createElement('div');
                    chip.className = `batch-queue-chip ${badgeInfo.cls}${isCurrent ? ' is-current' : ''}${canInteract ? '' : ' is-locked'}`;
                    chip.setAttribute('role', 'listitem');
                    chip.draggable = canInteract && groups.length > 1;

                    const openBtn = document.createElement('button');
                    openBtn.type = 'button';
                    openBtn.className = 'batch-queue-chip__open';
                    openBtn.disabled = !canInteract;
                    openBtn.title = canInteract ? `${item.name} — Click to open, drag to change priority` : item.name;
                    openBtn.innerHTML = `
                        <span class="batch-queue-chip__index">${index + 1}</span>
                        <span class="batch-queue-chip__name"></span>
                        <span class="batch-queue-chip__status">
                            <i class="icon-base bx ${badgeInfo.icon}" aria-hidden="true"></i>
                            <span>${badgeInfo.text}</span>
                        </span>
                    `;
                    openBtn.querySelector('.batch-queue-chip__name').textContent = item.name;
                    chip.appendChild(openBtn);

                    if (canInteract) {
                        const removeBtn = document.createElement('button');
                        removeBtn.type = 'button';
                        removeBtn.className = 'batch-queue-chip__remove';
                        removeBtn.setAttribute('aria-label', `Remove ${item.name} from the queue`);
                        removeBtn.title = 'Remove from queue';
                        removeBtn.innerHTML = '<i class="icon-base bx bx-x" aria-hidden="true"></i>';
                        removeBtn.addEventListener('click', (event) => {
                            event.stopPropagation();
                            openBatchDocumentModal(group.indices[0]);
                        });
                        chip.appendChild(removeBtn);
                    }

                    chip.addEventListener('dragstart', (event) => {
                        if (!chip.draggable) return;
                        draggedBatchIndex = index;
                        batchChipDidDrag = true;
                        chip.classList.add('is-dragging');
                        if (event.dataTransfer) {
                            event.dataTransfer.effectAllowed = 'move';
                            event.dataTransfer.setData('text/plain', String(index));
                        }
                    });

                    chip.addEventListener('dragover', (event) => {
                        if (draggedBatchIndex === null || draggedBatchIndex === index) return;
                        event.preventDefault();
                        if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
                        chip.classList.add('is-drag-over');
                    });

                    chip.addEventListener('dragleave', () => {
                        chip.classList.remove('is-drag-over');
                    });

                    chip.addEventListener('drop', (event) => {
                        event.preventDefault();
                        chip.classList.remove('is-drag-over');
                        if (draggedBatchIndex === null || draggedBatchIndex === index) return;

                        const activeItem = batchState.items[batchState.currentIndex];
                        if (batchState.phase === 'queuing' && activeItem && !activeItem.boxes) {
                            activeItem.boxes = cloneBoxes(marker.toJSON());
                            activeItem.rows = [...gridRows];
                            activeItem.tilts = [...rowTilts];
                        }

                        // Whole documents move: a PDF's pages stay together.
                        const reordered = batchGroups();
                        const [movedGroup] = reordered.splice(draggedBatchIndex, 1);
                        reordered.splice(index, 0, movedGroup);
                        batchState.items = reordered.flatMap((g) => g.items);
                        batchState.currentIndex = Math.max(0, batchState.items.indexOf(activeItem));
                        draggedBatchIndex = null;

                        if (activeItem) {
                            el('workspaceContextDetail').textContent = `${config.typeLabel} · Batch (${batchState.currentIndex + 1}/${batchState.items.length}) · ${activeItem.name}`;
                            el('workspaceContextDetail').title = el('workspaceContextDetail').textContent;
                        }
                        renderBatchQueueBanner();
                    });

                    chip.addEventListener('dragend', () => {
                        chip.classList.remove('is-dragging');
                        chipsContainer.querySelectorAll('.batch-queue-chip').forEach((c) => c.classList.remove('is-drag-over'));
                        draggedBatchIndex = null;
                        window.setTimeout(() => { batchChipDidDrag = false; }, 60);
                    });

                    openBtn.addEventListener('click', () => {
                        if (batchChipDidDrag || !canInteract) return;
                        if (isCurrent) return;
                        // The page still to do, else the first.
                        const done = batchState.phase === 'verifying' ? ['imported', 'rejected', 'checked'] : ['saved'];
                        const target = group.items.findIndex((it) => !done.includes(it.status));
                        openBatchDocument(group.indices[target === -1 ? 0 : target]);
                    });

                    chipsContainer.appendChild(chip);
                });
                const activeChip = chipsContainer.querySelector('.batch-queue-chip.is-current');
                activeChip?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
            }
        }

        let draggedBatchIndex = null;
        let batchChipDidDrag = false;
        let selectedBatchModalIndex = null;

        function openBatchDocumentModal(index) {
            const item = batchState.items[index];
            if (!item) return;

            selectedBatchModalIndex = index;
            const modalEl = document.getElementById('batchDocumentModal');
            const total = batchState.items.length;
            const statusText = {
                pending: 'Not aligned yet',
                saving: 'Saving',
                saved: 'Aligned and queued',
                scanning: 'Being scanned',
                ready: 'Scanned, waiting for validation',
                verifying: 'Being validated',
                failed: 'Failed',
            }[item.status] || 'Queued';

            const setText = (id, text) => {
                const node = document.getElementById(id);
                if (node) node.textContent = text;
            };
            const pagesInDoc = item.group ? batchState.items.filter((it) => it.group === item.group).length : 1;
            setText('batchDocumentName', pagesInDoc > 1 ? `${item.sourceName ?? item.name} (${pagesInDoc} pages)` : item.name);
            setText('batchDocumentMeta', `Priority ${index + 1} of ${total} · ${statusText}`);

            const notes = [];
            if (index === batchState.currentIndex) notes.push('This is the document you are working on now. The next one in the queue opens in its place.');
            if (['saved', 'ready', 'scanning', 'verifying'].includes(item.status) || item.pageId) {
                notes.push('Its alignment and any scan results will be discarded.');
            }
            notes.push('The file on your computer is not changed. You can add it again later.');
            const notesEl = document.getElementById('batchDocumentNotes');
            if (notesEl) {
                notesEl.innerHTML = '';
                notes.forEach((text) => {
                    const li = document.createElement('li');
                    li.textContent = text;
                    notesEl.appendChild(li);
                });
            }

            if (modalEl) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        }

        document.getElementById('batchDocumentModal')?.addEventListener('shown.bs.modal', () => {
            // Cancel is the safe default, so Enter never deletes by accident.
            document.getElementById('batchDocCancelBtn')?.focus();
        });

        // Open a queued document straight from its chip.
        async function openBatchDocument(idx) {
            if (idx === batchState.currentIndex) return;
            if (batchState.phase === 'queuing') {
                const currentItem = batchState.items[batchState.currentIndex];
                if (currentItem) {
                    currentItem.boxes = cloneBoxes(marker.toJSON());
                    currentItem.rows = [...gridRows];
                    currentItem.tilts = [...rowTilts];
                }
                await openBatchQueueItem(idx);
            } else if (batchState.phase === 'verifying') {
                await openBatchVerifyItem(idx);
            }
        }

        document.getElementById('confirmDeleteBatchDocBtn')?.addEventListener('click', async () => {
            const modalEl = document.getElementById('batchDocumentModal');
            if (modalEl) window.bootstrap.Modal.getInstance(modalEl)?.hide();
            if (selectedBatchModalIndex === null) return;

            const deleteIdx = selectedBatchModalIndex;
            selectedBatchModalIndex = null;
            const targetItem = batchState.items[deleteIdx];
            if (!targetItem) return;
            const removing = batchState.items.filter((it) => it === targetItem || (targetItem.group && it.group === targetItem.group));

            const wasCurrent = removing.includes(batchState.items[batchState.currentIndex]);
            const currentItem = batchState.items[batchState.currentIndex];
            if (!wasCurrent && batchState.phase === 'queuing' && currentItem) {
                currentItem.boxes = cloneBoxes(marker.toJSON());
                currentItem.rows = [...gridRows];
                currentItem.tilts = [...rowTilts];
            }

            removing.forEach((gone) => {
                if (!gone.pageId) return;
                fetch(config.pageCancelUrl.replace('__PAGE__', String(gone.pageId)), {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': config.csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                    },
                    credentials: 'same-origin',
                }).catch(() => {});
            });

            batchState.items = batchState.items.filter((it) => !removing.includes(it));

            if (batchState.items.length === 0) {
                batchState.active = false;
                batchState.phase = 'idle';
                batchState.currentIndex = 0;
                scanFile = null;
                scanFileInput.value = '';
                renderBatchQueueBanner();
                showStep('upload');
                return;
            }

            if (wasCurrent) {
                const nextIndex = Math.min(deleteIdx, batchState.items.length - 1);
                if (batchState.phase === 'verifying') {
                    await openBatchVerifyItem(nextIndex);
                } else {
                    await openBatchQueueItem(nextIndex);
                }
            } else {
                batchState.currentIndex = Math.max(0, batchState.items.indexOf(currentItem));
                if (currentItem) {
                    el('workspaceContextDetail').textContent = `${config.typeLabel} · Batch (${batchState.currentIndex + 1}/${batchState.items.length}) · ${currentItem.name}`;
                    el('workspaceContextDetail').title = el('workspaceContextDetail').textContent;
                }
                renderBatchQueueBanner();
            }
        });

        const SUPPORTED_SCAN_EXT = /\.(pdf|png|jpe?g|webp|bmp)$/i;

        async function extractDroppedScanFiles(dataTransfer) {
            if (!dataTransfer) return [];
            const items = dataTransfer.items ? Array.from(dataTransfer.items) : [];
            const entries = items
                .map((item) => (typeof item.webkitGetAsEntry === 'function' ? item.webkitGetAsEntry() : null))
                .filter(Boolean);

            if (entries.length === 0) {
                return Array.from(dataTransfer.files || []);
            }

            const collected = [];
            const readEntry = (entry) => new Promise((resolve) => {
                if (entry.isFile) {
                    entry.file((file) => {
                        if (SUPPORTED_SCAN_EXT.test(file.name)) collected.push(file);
                        resolve();
                    }, () => resolve());
                } else if (entry.isDirectory) {
                    const reader = entry.createReader();
                    const readBatch = () => {
                        reader.readEntries(async (batch) => {
                            if (!batch || batch.length === 0) {
                                resolve();
                                return;
                            }
                            for (const child of batch) {
                                await readEntry(child);
                            }
                            readBatch();
                        }, () => resolve());
                    };
                    readBatch();
                } else {
                    resolve();
                }
            });

            for (const entry of entries) {
                await readEntry(entry);
            }

            return collected.length > 0 ? collected : Array.from(dataTransfer.files || []);
        }

        async function startBatchQueue(fileList) {
            // A PDF may be large (only one page of it is ever sent); an image may not.
            const picked = Array.from(fileList || []).filter((file) => {
                if (!SUPPORTED_SCAN_EXT.test(file.name)) {
                    return false;
                }
                const limit = isPdfFile(file) ? MAX_PDF_BYTES : MAX_SCAN_BYTES;
                if (file.size > limit) {
                    alert(`${file.name} is larger than ${limit / 1024 / 1024} MB and was skipped.`);
                    return false;
                }
                return true;
            });

            // Each page of a PDF is its own document in the queue, cut out with
            // its original quality, so it is aligned, scanned and saved on its own.
            const validFiles = [];
            const pageInfo = new Map(); // file -> { group, pageNo, pageTotal, sourceName }
            let groupSeq = 0;
            const groupStamp = Date.now().toString(36);
            if (picked.some(isPdfFile)) {
                dropzone.classList.add('is-loading');
                dropzone.setAttribute('aria-busy', 'true');
            }
            try {
                for (const file of picked) {
                    const group = `${groupStamp}-${groupSeq += 1}`;
                    if (!isPdfFile(file)) {
                        pageInfo.set(file, { group, pageNo: 1, pageTotal: 1, sourceName: file.name });
                        validFiles.push(file);
                        continue;
                    }
                    try {
                        const pages = await marker.pdfPageCount(file);
                        if (pages <= 1) {
                            pageInfo.set(file, { group, pageNo: 1, pageTotal: 1, sourceName: file.name });
                            validFiles.push(file);
                            continue;
                        }
                        for (let page = 1; page <= pages; page += 1) {
                            const pageFile = await pdfPageFile(file, page);
                            pageInfo.set(pageFile, { group, pageNo: page, pageTotal: pages, sourceName: file.name });
                            validFiles.push(pageFile);
                        }
                    } catch (error) {
                        console.warn(`${file.name} could not be split into pages:`, error);
                        if (file.size > MAX_SCAN_BYTES) {
                            alert(`${file.name} could not be opened page by page, and is too large to queue whole. It was skipped.`);
                        } else {
                            pageInfo.set(file, { group, pageNo: 1, pageTotal: 1, sourceName: file.name });
                            validFiles.push(file);
                        }
                    }
                }
            } finally {
                dropzone.classList.remove('is-loading');
                dropzone.removeAttribute('aria-busy');
            }

            if (validFiles.length === 0) {
                scanFileInput.value = '';
                return;
            }

            if (!batchState.active) {
                batchState.active = true;
                batchState.key = 'batch_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8);
                batchState.phase = 'queuing';
                batchState.items = [];
                batchState.currentIndex = 0;
            }

            validFiles.forEach((file) => {
                const info = pageInfo.get(file) ?? { group: `${groupStamp}-${groupSeq += 1}`, pageNo: 1, pageTotal: 1, sourceName: file.name };
                batchState.items.push({
                    file,
                    name: file.name,
                    group: info.group,
                    pageNo: info.pageNo,
                    pageTotal: info.pageTotal,
                    sourceName: info.sourceName,
                    status: 'pending',
                    pageId: null,
                    page: null,
                    workspaceScanPath: null,
                    recordUrl: null,
                    error: null,
                    boxes: null,
                    rows: null,
                    tilts: null,
                });
            });

            scanFileInput.value = '';
            await openBatchQueueItem(batchState.currentIndex);
        }

        async function openBatchQueueItem(index) {
            const item = batchState.items[index];
            if (!item) return;

            // The page beside the one being aligned is already drawn: the two trade
            // places on the spot, and the view slides to the page now being aligned.
            const left = batchState.items[batchState.currentIndex];
            // Smoke test's result goes with the page left, and comes with this one.
            if (left && left !== item) keepDetection(left);
            const tested = left !== item ? item.detected ?? null : null;
            if (left !== item) item.detected = null;
            const swap = batchPartner()?.index === index && companionMarker.canvas.width > 0
                && companionFile === (tested?.imageUrl ?? item.file);
            batchState.currentIndex = index;
            renderBatchQueueBanner();

            dropzone.classList.add('is-loading');
            dropzone.setAttribute('aria-busy', 'true');

            try {
                scanFile = item.file;
                scanFingerprint = null;
                savedPages = new Map();
                clearDetection();
                useLayoutForPage(item.pageNo ?? 1);
                if (swap) {
                    marker.swapPageWith(companionMarker);
                } else if (tested) {
                    await marker.loadFromUrl(tested.imageUrl);
                } else {
                    await marker.load(item.file, { page: 1 });
                }
                scanPageCount = 1;
                scanPageNumber = 1;
                if (swap && left) {
                    // What sits beside the page now is the one just left.
                    companionPage = left.pageNo;
                    companionFile = left.detected?.imageUrl ?? left.file;
                    updateSpread();
                } else {
                    resetSpread();
                }
                updateScanNotices();
                el('workspaceContextDetail').textContent = `${config.typeLabel} · Batch (${index + 1}/${batchState.items.length}) · ${item.name}`;
                el('workspaceContextDetail').title = el('workspaceContextDetail').textContent;
                showStep('mark');
                guardBackButton();
                resetFieldHistory();
                rowsSource = null;

                if (item.boxes && item.rows) {
                    setGrid(item.boxes, item.rows, { tilts: item.tilts });
                } else {
                    setGrid(templateBoxes, templateRows);
                }
                if (tested) await applyDetection(tested, { imageShown: true, keepView: swap });
                fetchSnapLines();
                renderBatchQueueBanner();
                // The other page of the pair is zoomed on and centred; a different
                // document is fitted afresh.
                if (swap) focusMainPage();
                else window.requestAnimationFrame(() => marker.resetZoom());
            } catch (error) {
                item.status = 'failed';
                item.error = error.message || 'Could not open file';
                renderBatchQueueBanner();
                alert(error.message || 'That document could not be opened.');
            } finally {
                dropzone.classList.remove('is-loading');
                dropzone.removeAttribute('aria-busy');
            }
        }

        document.getElementById('batchAddMoreBtn')?.addEventListener('click', () => {
            scanFileInput.setAttribute('multiple', 'multiple');
            scanFileInput.click();
        });

        document.getElementById('saveQueueNextBtn')?.addEventListener('click', async () => {
            if (scanInProgress || !batchState.active) return;

            const markerValidationMessage = markerSetValidationMessage(marker.toJSON());
            if (markerValidationMessage) {
                showOcrError(markerValidationMessage);
                return;
            }

            const item = batchState.items[batchState.currentIndex];
            if (!item) return;

            const btn = el('saveQueueNextBtn');
            btn.disabled = true;
            clearOcrError();
            item.status = 'saving';
            renderBatchQueueBanner();

            try {
                item.boxes = cloneBoxes(marker.toJSON());
                item.rows = [...gridRows];
                item.tilts = [...rowTilts];

                const form = new FormData();
                form.set('document_template_id', String(config.templateId));
                form.set('geometry_json', JSON.stringify(currentGeometry()));
                form.set('page', await canvasBlob(marker.canvas), 'page.png');
                form.set('scan', item.file, item.file.name);
                form.set('batch_key', batchState.key);
                form.set('defer_scan', '1');

                const response = await fetch(config.pagesUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': config.csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                    },
                    credentials: 'same-origin',
                    body: form,
                });
                const payload = (response.headers.get('content-type') || '').includes('application/json')
                    ? await response.json()
                    : null;
                if (!response.ok || !payload?.id) {
                    throw new Error(responseErrorMessage(response, payload));
                }

                item.pageId = payload.id;
                item.page = payload;
                item.workspaceScanPath = payload.workspaceScanPath || null;
                item.status = 'saved';
                renderBatchQueueBanner();

                // Move to next unsaved document if any
                const nextPendingIndex = batchState.items.findIndex((it, idx) => idx > batchState.currentIndex && it.status === 'pending');
                const firstPendingIndex = batchState.items.findIndex((it) => it.status === 'pending');
                const targetIndex = nextPendingIndex !== -1 ? nextPendingIndex : firstPendingIndex;

                if (targetIndex !== -1) {
                    await openBatchQueueItem(targetIndex);
                } else {
                    renderBatchQueueBanner();
                }
            } catch (error) {
                item.status = 'failed';
                item.error = error.message;
                renderBatchQueueBanner();
                showOcrError(error.message || 'Could not save document to queue.');
            } finally {
                btn.disabled = false;
            }
        });

        async function runBatchScanAll() {
            if (scanInProgress || !batchState.active) return;

            const itemsToScan = batchState.items.filter((it) => it.pageId);
            const total = itemsToScan.length;
            if (total === 0) return;

            batchState.phase = 'scanning';
            itemsToScan.forEach((it) => {
                it.status = 'scanning';
            });
            renderBatchQueueBanner();

            const button = el('scanNowBtn');
            const originalButtonContent = button.innerHTML;
            const controller = new AbortController();
            const run = startRun(controller, 'scan');
            run.batchPageIds = itemsToScan.map((it) => it.pageId);
            let progress = null;
            let timeoutId = null;

            scanInProgress = true;
            clearOcrError();
            button.disabled = true;
            el('detectBtn').disabled = true;

            try {
                progress = openProgressModal();
                stopOcrProgress();
                ocrProgress = 0;
                el('ocrProgressFields').textContent = `0 of ${total} documents scanned`;
                setOcrProgress(
                    4,
                    `Scanning batch (0 of ${total} complete)`,
                    `Queueing all ${total} documents in the batch for OCR processing...`,
                );
                timeoutId = window.setTimeout(() => controller.abort(), 30 * 60 * 1000);

                // Trigger ProcessDocumentPage for all documents in the batch upfront
                // so the entire batch is queued and tracked as one unified loading job.
                const trackList = [];
                for (let i = 0; i < itemsToScan.length; i++) {
                    const item = itemsToScan[i];
                    const triggerRes = await fetch(config.pageGeometryUrl.replace('__PAGE__', String(item.pageId)), {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': config.csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                            Accept: 'application/json',
                        },
                        credentials: 'same-origin',
                        signal: controller.signal,
                        body: JSON.stringify({
                            geometry_json: JSON.stringify(item.page?.geometry || currentGeometry()),
                        }),
                    });
                    const triggeredPayload = (triggerRes.headers.get('content-type') || '').includes('application/json')
                        ? await triggerRes.json()
                        : null;
                    if (!triggerRes.ok || !triggeredPayload?.statusUrl) {
                        throw new Error(responseErrorMessage(triggerRes, triggeredPayload));
                    }
                    item.page = triggeredPayload;
                    trackList.push({
                        item,
                        page: triggeredPayload,
                        statusUrl: triggeredPayload.statusUrl,
                        phaseStartedAt: Date.now(),
                        lastStatus: triggeredPayload.status || 'queued',
                        done: triggeredPayload.status === 'ready',
                    });
                }

                // Poll all queued documents together and compute one combined 0-100% progress across the entire batch
                for (;;) {
                    let sumFraction = 0;
                    let readyCount = 0;
                    let activeItemName = null;
                    let activeStageLabel = 'Finding handwritten lines & reading handwriting';
                    const now = Date.now();

                    for (const entry of trackList) {
                        const st = entry.page.status;
                        if (st !== entry.lastStatus) {
                            entry.lastStatus = st;
                            entry.phaseStartedAt = now;
                        }
                        const elapsedSec = Math.max(0, (now - entry.phaseStartedAt) / 1000);

                        if (st === 'ready') {
                            sumFraction += 1;
                            readyCount += 1;
                        } else if (st === 'reading') {
                            const creep = 1 - Math.exp(-elapsedSec / 6);
                            sumFraction += 0.72 + creep * 0.24;
                            if (!activeItemName) {
                                activeItemName = entry.item.name;
                                activeStageLabel = 'Reading handwriting with TrOCR';
                            }
                        } else if (st === 'detecting') {
                            const creep = 1 - Math.exp(-elapsedSec / 20);
                            sumFraction += 0.16 + creep * 0.54;
                            if (!activeItemName) {
                                activeItemName = entry.item.name;
                                activeStageLabel = 'Finding handwritten lines';
                            }
                        } else {
                            const creep = 1 - Math.exp(-elapsedSec / 8);
                            sumFraction += 0.04 + creep * 0.10;
                            if (!activeItemName) {
                                activeItemName = entry.item.name;
                            }
                        }
                    }

                    const overallPct = 4 + (sumFraction / total) * 92;
                    el('ocrProgressFields').textContent = `${readyCount} of ${total} documents scanned`;
                    setOcrProgress(
                        Math.max(ocrProgress, overallPct),
                        `Scanning batch (${readyCount} of ${total} complete)`,
                        activeItemName
                            ? `${activeStageLabel} across ${total} batch documents (currently processing ${activeItemName}).`
                            : `Processing all ${total} documents in the batch...`,
                    );

                    if (readyCount === total) {
                        break;
                    }

                    await wait(700, controller.signal);

                    for (const entry of trackList) {
                        if (entry.done) continue;
                        const response = await fetch(entry.statusUrl, {
                            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                            signal: controller.signal,
                        });
                        const next = (response.headers.get('content-type') || '').includes('application/json')
                            ? await response.json()
                            : null;
                        if (!response.ok || !next) {
                            throw new Error(responseErrorMessage(response, next));
                        }
                        entry.page = next;
                        entry.item.page = next;

                        if (next.status === 'failed') {
                            entry.item.status = 'failed';
                            renderBatchQueueBanner();
                            throw new Error(next.error || `The document "${entry.item.name}" could not be processed.`);
                        }
                        if (next.status === 'ready') {
                            entry.done = true;
                            entry.item.status = 'ready';
                            const nextActiveIdx = batchState.items.findIndex((it) => it.status === 'scanning');
                            if (nextActiveIdx !== -1) {
                                batchState.currentIndex = nextActiveIdx;
                            }
                            renderBatchQueueBanner();
                        }
                    }
                }

                el('ocrProgressFields').textContent = `${total} of ${total} documents scanned`;
                await completeOcrProgress('Batch scan complete', 'Opening the first document for validation.');
                batchState.phase = 'verifying';
                await openBatchVerifyItem(0);
            } catch (error) {
                if (!run.cancelled) {
                    showOcrError(error.message || 'Batch scan failed.');
                }
                batchState.items.forEach((it) => {
                    if (it.status === 'scanning') it.status = 'saved';
                });
                batchState.phase = 'queuing';
                renderBatchQueueBanner();
            } finally {
                if (activeRun === run) activeRun = null;
                if (timeoutId !== null) window.clearTimeout(timeoutId);
                stopOcrProgress();
                await closeProgressModal(progress);
                scanInProgress = false;
                button.innerHTML = originalButtonContent;
                button.disabled = false;
                el('detectBtn').disabled = false;
                renderBatchQueueBanner();
            }
        }

        async function openBatchVerifyItem(index) {
            const item = batchState.items[index];
            if (!item || !item.page) return;
            verifyDoc = null;

            // Leaving a page keeps what was typed and ticked on it, and a page only
            // looked at goes back to "Scanned" instead of staying "Validating".
            const leaving = batchState.items[batchState.currentIndex];
            if (leaving && leaving !== item) {
                keepVerifyDraft(leaving);
                if (leaving.status === 'verifying' && !leaving.staged) leaving.status = 'ready';
            }

            batchState.currentIndex = index;
            useLayoutForPage(item.pageNo ?? 1);
            // A page opened again is checked again: it may have changed since.
            item.staged = null;
            if (item.status !== 'imported') {
                item.status = 'verifying';
            }
            renderBatchQueueBanner();

            scanFile = item.file;
            const page = item.page;

            await marker.loadFromUrl(page.imageUrl);
            if (item.boxes && item.rows) {
                setGrid(geometryMarkers(page.geometry, page), page.geometry?.ruled_ys ?? item.rows);
            }

            processedPage = page;
            cropped = verificationItems(page.lines, page);
            readings = cropped.map((entry) => entry.reading);
            requiredInput(document, '#ocrModelKey').value = (page.modelKey || '').trim();
            requiredInput(document, '#documentPageId').value = String(page.id);
            el('summaryModel').textContent = page.model || '—';
            el('workspaceContextDetail').textContent = `${config.typeLabel} · Verify (${index + 1}/${batchState.items.length}) · ${item.name}`;
            el('workspaceContextDetail').title = el('workspaceContextDetail').textContent;

            clearValidationSubmitError();
            renderVerifyRows();
            restoreVerifyDraft(item);
            showGridNotes(page);
            showStep('verify');
            prepareValidationComparison();
            checkAndShowExistingRecord(page.existingRecord || null);
        }

        function checkAndShowExistingRecord(existing) {
            const banner = document.getElementById('validationExistingBanner');
            const msgEl = document.getElementById('validationExistingMessage');
            const viewLink = document.getElementById('validationExistingViewLink');
            const modalEl = document.getElementById('existingRecordModal');
            const modalDesc = document.getElementById('existingRecordModalDescription');
            const modalViewBtn = document.getElementById('existingRecordViewBtn');
            const rejectLabel = document.getElementById('confirmRejectExistingLabel');

            if (!existing) {
                banner?.classList.add('d-none');
                return;
            }

            const recordTitle = existing.title || 'Existing Record';
            const description = `The extracted data matches an existing record ("${recordTitle}") in the database. Duplicate extracted data is rejected.`;

            if (msgEl) msgEl.textContent = description;
            if (viewLink) {
                if (existing.url) {
                    viewLink.href = existing.url;
                    viewLink.classList.remove('d-none');
                } else {
                    viewLink.classList.add('d-none');
                }
            }
            banner?.classList.remove('d-none');

            if (batchState.active && batchState.items[batchState.currentIndex]) {
                const rejected = batchState.items[batchState.currentIndex];
                batchState.items
                    .filter((it) => it === rejected || (rejected.group && it.group === rejected.group))
                    .forEach((it) => { it.status = 'rejected'; it.staged = null; });
                renderBatchQueueBanner();
            }

            if (modalDesc) modalDesc.textContent = description;
            if (modalViewBtn) {
                if (existing.url) {
                    modalViewBtn.href = existing.url;
                    modalViewBtn.classList.remove('d-none');
                } else {
                    modalViewBtn.classList.add('d-none');
                }
            }
            if (rejectLabel) {
                if (batchState.active) {
                    const hasMore = batchState.items.some((it, idx) => idx !== batchState.currentIndex && !['imported', 'rejected'].includes(it.status));
                    rejectLabel.textContent = hasMore ? 'Reject & Next File' : 'Reject & Complete Batch';
                } else {
                    rejectLabel.textContent = 'Reject & End';
                }
            }
            if (modalEl) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        }

        async function rejectCurrentExistingDocument() {
            const modalEl = document.getElementById('existingRecordModal');
            if (modalEl) window.bootstrap.Modal.getInstance(modalEl)?.hide();
            document.getElementById('validationExistingBanner')?.classList.add('d-none');

            // Every page that was read for it (both pages of a two-page document).
            docEntries().map((entry) => entry.page?.id).filter(Boolean).forEach((pageId) => {
                fetch(config.pageCancelUrl.replace('__PAGE__', String(pageId)), {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': config.csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                    },
                    credentials: 'same-origin',
                }).catch(() => {});
            });
            verifyDoc = null;

            if (batchState.active) {
                const currentItem = batchState.items[batchState.currentIndex];
                if (currentItem) currentItem.status = 'rejected';
                renderBatchQueueBanner();

                const nextVerifyIndex = batchState.items.findIndex((it, idx) => idx > batchState.currentIndex && !['imported', 'rejected'].includes(it.status));
                const firstRemainingIndex = batchState.items.findIndex((it) => !['imported', 'rejected'].includes(it.status));
                const targetVerifyIndex = nextVerifyIndex !== -1 ? nextVerifyIndex : firstRemainingIndex;

                if (targetVerifyIndex !== -1) {
                    await openBatchVerifyItem(targetVerifyIndex);
                    return;
                }

                const lastImported = [...batchState.items].reverse().find((it) => it.recordUrl);
                if (lastImported?.recordUrl) {
                    window.location.assign(lastImported.recordUrl);
                    return;
                }

                batchState.active = false;
                batchState.phase = 'idle';
                batchState.items = [];
                batchState.currentIndex = 0;
                renderBatchQueueBanner();
            }

            scanFile = null;
            processedPage = null;
            scanFileInput.value = '';
            showStep('upload');
        }

        document.getElementById('confirmRejectExistingBtn')?.addEventListener('click', rejectCurrentExistingDocument);
        document.getElementById('validationExistingRejectBtn')?.addEventListener('click', rejectCurrentExistingDocument);

        scanFileInput.addEventListener('change', (event) => {
            if (!(event.currentTarget instanceof HTMLInputElement)) return;
            if (uploadMode === 'batch' || batchState.active) {
                startBatchQueue(event.currentTarget.files);
            } else {
                openDocument(event.currentTarget.files?.[0]);
            }
        });

        // Once a scan is open, leaving would lose the markers and the checked
        // values, so the user is asked first. Not while the record is being
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
        dropzone.addEventListener('drop', async (event) => {
            if (uploadMode === 'batch' || batchState.active) {
                const files = await extractDroppedScanFiles(event.dataTransfer);
                startBatchQueue(files);
            } else {
                openDocument(event.dataTransfer?.files?.[0]);
            }
        });
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
            // Zoomed by hand (the buttons, Ctrl + wheel): no page is "in focus" any more.
            if (verifyFocus !== null && !validationMarker.gliding && Math.abs(zoom - verifyFocusZoom) > 0.011) {
                verifyFocus = null;
                verifyFocusZoom = null;
            }
            if (verifyDoc) updateVerifyPageView();
        }

        el('zoomOutBtn').addEventListener('click', () => marker.zoomBy(-0.1));
        el('zoomInBtn').addEventListener('click', () => marker.zoomBy(0.1));
        el('zoomResetBtn').addEventListener('click', () => marker.resetZoom());
        el('validationZoomOutBtn').addEventListener('click', () => validationMarker.zoomBy(-0.1));
        el('validationZoomInBtn').addEventListener('click', () => validationMarker.zoomBy(0.1));
        // 100%: both pages of a two-page document, easing back; one page, at once.
        el('validationZoomResetBtn').addEventListener('click', () => (verifyDoc ? showVerifyBoth() : validationMarker.resetZoom()));

        function restoreTemplateFields() {
            rowsSource = null;
            setGrid(templateBoxes, templateRows, { remember: true });
            marker.resetZoom();
            el('docViewport').scrollTo({ top: 0, left: 0 });
            clearOcrError();
        }

    // Grid lines off: the row lines and every field box are hidden (not
    // removed), so the page can be seen as scanned.
    el('showGridToggle').addEventListener('change', (event) => {
        el('fieldOverlay').classList.toggle('is-grid-hidden', !event.target.checked);
    });

        el('resetFieldsBtn').addEventListener('click', () => {
            const layoutChanged = !fieldsMatchTemplate();
            const zoomChanged = Math.abs(marker.zoom - 1) > 0.001;
            if (!layoutChanged && !zoomChanged) return;

            if (!layoutChanged) {
                restoreTemplateFields();
                return;
            }

        // Each page has its own layout: only this one is reset.
        const pageNote = el('resetFieldsModalPage');
        pageNote.textContent = `Only page ${scanPageNumber} is reset. The other pages keep their own layouts.`;
        pageNote.classList.toggle('d-none', !multiPage());
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
        // ceiling. The ring crosses most of that share at a steady pace over the
        // time the phase usually takes on this machine (scanSeconds, from the
        // last scans), so it arrives as the phase ends; past that time it still
        // creeps on, never standing still. It used to rush ahead as a phase
        // opened and then crawl, which looked stuck while Kraken worked. The
        // page's real status opens the next phase, and the ring jumps ahead to
        // its start. See scan-progress.js.
        const OCR_PROGRESS_TICK = 200;
        const scanSeconds = scanTimings(config.scanSeconds);
        // The phase the ring is in: where it started, its ceiling, how long it
        // usually takes, and the seconds of the phases after it (null: not
        // known, so no time left is shown).
        let ocrPhase = null;
        // Whether this run shows its time left in the pill (a batch scan counts
        // its documents there instead).
        let ocrTimed = false;

        function phaseElapsed() {
            return ocrPhase ? (performance.now() - ocrPhase.startedAt) / 1000 : 0;
        }

        /** The time left, in the pill under the status. */
        function showTimeLeft() {
            if (!ocrPhase || ocrPhase.later === null) return;
            el('ocrProgressFields').textContent = timeLeftText(phaseElapsed(), ocrPhase.expected, ocrPhase.later);
        }

        function setOcrPhase(start, ceiling, seconds, title = null, note = null, later = null) {
            // Asked for again with every status check; its clock starts once.
            const key = `${start}:${ceiling}`;
            if (ocrPhase?.key !== key) {
                // A phase the ring is already past (a page read without being
                // outlined again still waits its turn first) holds the ring.
                const from = Math.max(ocrProgress, start);
                ocrPhase = { key, from, ceiling: Math.max(ceiling, from), startedAt: performance.now() };
            }
            ocrPhase.expected = seconds;
            ocrPhase.later = later;
            setOcrProgress(Math.max(ocrProgress, start), title, note);
            showTimeLeft();
        }

        /** The run's phases are over (or stopped): the ring is set by hand from here. */
        function endOcrPhases(pill = null) {
            ocrPhase = null;
            // Only a run that showed its time left has that pill to update.
            if (ocrTimed && pill !== null) el('ocrProgressFields').textContent = pill;
        }

        /** `later`: the seconds the run usually takes after the page is sent. */
        function beginOcrProgress(markerCount, later = null) {
            if (ocrProgressTimer !== null) window.clearInterval(ocrProgressTimer);

            el('ocrProgressFields').textContent = `${markerCount} marker${markerCount === 1 ? '' : 's'} aligned`;
            ocrProgress = 0;
            ocrPhase = null;
            ocrTimed = later !== null;
            setOcrPhase(4, 15, 2, 'Preparing document', 'Saving the aligned page for line detection.', later);

            ocrProgressTimer = window.setInterval(() => {
                if (!ocrPhase) return;
                const next = phaseProgress(ocrPhase.from, ocrPhase.ceiling, phaseElapsed(), ocrPhase.expected);
                if (next > ocrProgress) setOcrProgress(next);
                showTimeLeft();
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

        // While Smoke test runs over both pages of a pair: which one is being
        // worked on, and its share of the ring.
        let detectPhase = null;
        // While Scan with OCR reads both pages of a register: the share of the
        // ring this page fills ({ from, to } out of 100), its name, and the
        // seconds the page after it usually takes (`after`).
        let readSpan = null;
        // What the page being waited on still needs: 'full' (find its lines,
        // then read), 'reoutline' (outline again from lines already found, then
        // read) or 'read' (only read). Sets the pace and the time left.
        let scanKind = 'full';

        /** setOcrPhase within the share of the page being read, when two are. */
        function readPhase(start, ceiling, seconds, title, note, later = 0) {
            const rest = later + (readSpan?.after ?? 0);
            if (!readSpan) {
                setOcrPhase(start, ceiling, seconds, title, note, rest);
                return;
            }
            const scale = (value) => readSpan.from + (readSpan.to - readSpan.from) * value / 100;
            setOcrPhase(scale(start), scale(ceiling), seconds, `${title} · ${readSpan.label}`, note, rest);
        }

        function showPageStatus(page, waitedMs, mode = 'scan') {
            if (page.status === 'detecting' && mode === 'detect') {
                setOcrPhase(detectPhase?.from ?? 18, detectPhase?.to ?? 95, scanSeconds.detect,
                    detectPhase?.title ?? 'Smoke test scan',
                    'Straightening the page, fitting the columns and rows to its table, and outlining every handwritten line.',
                    detectPhase?.after ?? 0);
                return;
            }
            if (page.status === 'queued') {
                readPhase(
                    15, 18, scanSeconds.wait,
                    'Waiting for the line detector',
                    waitedMs > 20000
                        ? 'No background worker has picked this page up. Run php artisan queue:work (serve.ps1 does it for you).'
                        : 'The page is queued for line detection.',
                    mode === 'detect'
                        ? pageSeconds('detect', scanSeconds) + (detectPhase?.after ?? 0)
                        : pageSeconds(scanKind, scanSeconds),
                );
            } else if (page.status === 'detecting') {
                // Most of a scan's time, so most of the ring.
                readPhase(18, 72, scanKind === 'reoutline' ? scanSeconds.reoutline : scanSeconds.find,
                    'Finding handwritten lines',
                    'Outlining every handwritten line, including capitals and tails that cross the ruled lines.',
                    scanSeconds.read);
            } else if (page.status === 'reading') {
                readPhase(72, 95, scanSeconds.read, 'Reading handwriting',
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

            for (; ;) {
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
            ocrPhase = null;
            ocrTimed = false;
            if (ocrProgressTimer === null) return;
            window.clearInterval(ocrProgressTimer);
            ocrProgressTimer = null;
        }

        async function completeOcrProgress(title = 'Scan complete', note = 'Opening the validation results.') {
            endOcrPhases('Done');
            stopOcrProgress();
            setOcrProgress(100, title, note);
            // Long enough to see the ring fill, no longer.
            await new Promise((resolve) => window.setTimeout(resolve, 350));
        }

        /**
         * The markers and row lines as they stand, as the geometry the server
         * works with. Rows set by hand are marked, so Detect keeps them.
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
                // Fields moved (or added) by hand stay where they were put: Snap only
                // fits the table and the fields not touched.
                const before = marker.toJSON();
                const kept = handPlacedFields(before);
                const keptBoxes = new Map(before.filter((box) => kept.has(nameKey(box.name)) && box.kind !== 'column')
                    .map((box) => [nameKey(box.name), box]));
                const snapped = geometryMarkers(payload.geometry, null)
                    .map((box) => (box.kind !== 'column' && keptBoxes.has(nameKey(box.name)) ? keptBoxes.get(nameKey(box.name)) : box));
                setGrid(snapped, rows, {
                    estimated: (payload.rows?.estimated ?? []).filter((index) => rows[index] !== undefined)
                        .map((index) => rowKey(rows[index])),
                });
                // The fields Snap placed are the new starting point; the ones kept stay as placed by hand.
                rememberAutoFields(snapped, kept);
                if (keptBoxes.size > 0) {
                    showGridMessage(`Snap left ${keptBoxes.size} field${keptBoxes.size === 1 ? '' : 's'} you moved where ${keptBoxes.size === 1 ? 'it is' : 'they are'}.`);
                }
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
        async function uploadPage({
            detect, canvas = marker.canvas, geometry = currentGeometry(), templateId = config.templateId,
        }, signal) {
            const form = new FormData();
            form.set('document_template_id', String(templateId));
            form.set('geometry_json', JSON.stringify(geometry));
            form.set('page', await canvasBlob(canvas), 'page.png');
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
         * ran, its page is still usable, and only the markers have moved on
         * it (the page shown is the one the server holds).
         */
        function canReoutline() {
            return detection !== null && !detection.stale && !detectionIsCurrent();
        }

        /**
         * Scan with OCR after Detect: read the crops Detect already made, of
         * this page, or of the page beside it that Smoke test went over with it.
         */
        async function readDetectedPage(signal, tested = detection.page) {
            const response = await fetch(tested.readUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': config.csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                signal,
                body: JSON.stringify({}),
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
            // The ring stops where it is.
            endOcrPhases('Stopping');
            setOcrProgress(ocrProgress, 'Cancelling', 'Stopping this run.');
            run.controller.abort();
            const idsToCancel = Array.isArray(run.batchPageIds) && run.batchPageIds.length > 0
                ? run.batchPageIds
                : (run.pageId !== null ? [run.pageId] : []);
            idsToCancel.forEach((id) => {
                fetch(config.pageCancelUrl.replace('__PAGE__', String(id)), {
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
            });
        }

        el('ocrProgressCancel').addEventListener('click', cancelActiveRun);

        el('scanNowBtn').addEventListener('click', async () => {
            if (scanInProgress) return;
            if (batchState.active && batchState.phase === 'queuing') {
                await runBatchScanAll();
                return;
            }

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
            // A two-page register: the page beside this one is read in the same run,
            // and both are verified and saved together as one document.
            const partner = spreadPartnerForScan();

            scanInProgress = true;
            clearOcrError();
            button.disabled = true;
            button.innerHTML = `<i class="icon-base bx bx-scan icon-sm me-1" aria-hidden="true"></i> ${partner ? 'Reading both pages...' : 'OCR in progress...'}`;
            el('detectBtn').disabled = true;

            try {
                // Cropping and modal creation used to happen outside the try block. A
                // browser-side failure there made the button appear to do nothing.
                const aligned = marker.toJSON();
                progress = openProgressModal();
                // What this page needs (see scanKind), and so how long the run
                // usually takes: this page, then the page beside it.
                scanKind = detectionIsCurrent() ? 'read' : canReoutline() ? 'reoutline' : 'full';
                const partnerKind = partner?.tested ? 'read' : 'full';
                const partnerSeconds = partner ? scanSeconds.wait + pageSeconds(partnerKind, scanSeconds) : 0;
                beginOcrProgress(aligned.length, scanSeconds.wait + pageSeconds(scanKind, scanSeconds) + partnerSeconds);

                await new Promise((resolve) => window.requestAnimationFrame(resolve));

                if (!aligned.length) {
                    throw new Error('Add at least one field before reading.');
                }

                timeoutId = window.setTimeout(() => controller.abort(), 15 * 60 * 1000);
                // Of two pages, each fills its half of the ring, and says which it is.
                readSpan = partner ? { from: 0, to: 50, label: `page ${scanPageNumber}`, after: partnerSeconds } : null;
                let payload;
                const readingDetectedPage = detectionIsCurrent();
                if (readingDetectedPage) {
                    showRunKind('read');
                    run.pageId = detection.page.id;
                    // Saves still on their way are part of what is read.
                    await lineSaveQueue;
                    setLineEditing(false);
                    // The outlines just checked are the ones read.
                    readPhase(72, 95, scanSeconds.read, 'Reading handwriting', 'The selected TrOCR model is reading each detected line.');
                    payload = await readDetectedPage(controller.signal);
                } else if (canReoutline()) {
                    // The markers moved after Detect. That page is already here and
                    // already straightened, so only the markers go over, and its
                    // lines are reused rather than found again.
                    run.pageId = detection.page.id;
                    setLineEditing(false);
                    readPhase(30, 72, scanSeconds.reoutline, 'Outlining from your markers',
                        'Reusing the lines Detect found on this page.', scanSeconds.read);
                    payload = await reoutlineDetectedPage(controller.signal);
                } else {
                    // Finishing Align hands the page to the background line detector.
                    setOcrProgress(Math.max(ocrProgress, 4), partner ? 'Sending both pages' : 'Sending the aligned page',
                        'Uploading for line detection.');
                    payload = await uploadPage({ detect: false }, controller.signal);
                    run.pageId = payload.id;
                }

                // The other page goes straight after, so the detector moves on to it:
                // read as Smoke test outlined it, or sent with the markers it shows.
                let partnerPayload = null;
                if (partner) {
                    partnerPayload = partner.tested
                        ? await readDetectedPage(controller.signal, partner.tested)
                        : await uploadPage({
                            detect: false,
                            canvas: companionMarker.canvas,
                            geometry: alignedGeometry(partner.grid.boxes, partner.grid.rows, { keepRows: Boolean(partner.grid.byHand) }),
                            templateId: layoutFor(partner.number).templateId,
                        }, controller.signal);
                    // The progress window's × stops both.
                    run.batchPageIds = [run.pageId, partnerPayload.id].filter((id) => id !== null && id !== undefined);
                }

                const page = await waitForPage(payload, controller.signal);
                let partnerPage = null;
                if (partner) {
                    readSpan = { from: 50, to: 98, label: `page ${partner.number}` };
                    scanKind = partnerKind;
                    partnerPage = await waitForPage(partnerPayload, controller.signal);
                }
                readSpan = null;
                scanKind = 'full';
                clearDetection();
                // Read now: what Smoke test made of the other page is used up.
                if (partner) pageDetections.delete(partner.number);

                // A tilted ledger grid makes the server straighten the page before
                // outlining it; show that page so the outlines sit on their writing.
                if (!readingDetectedPage && Math.abs(Number(page.deskew) || 0) >= 0.05) {
                    await marker.loadFromUrl(page.imageUrl);
                    setGrid(geometryMarkers(page.geometry, page), page.geometry?.ruled_ys ?? gridRows, { byHand: rowsByHand });
                }

                [[page, scanPageNumber], [partnerPage, partner?.number]].forEach(([read, number]) => {
                    if (!read) return;
                    if (!Array.isArray(read.lines) || read.lines.length === 0) {
                        throw new Error(partner
                            ? `No handwriting was found inside the markers of page ${number}. Check its alignment and scan again.`
                            : 'No handwriting was found inside the markers. Check the alignment and scan again.');
                    }
                    if (typeof read.modelKey !== 'string' || read.modelKey.trim() === '') {
                        throw new Error('The OCR service did not identify the model used. No data was saved; please scan again.');
                    }
                });

                if (partner) {
                    // The lower page first: it is the record's page, the other its page 2.
                    const entries = [
                        { number: scanPageNumber, page, layout: layoutFor(scanPageNumber) },
                        { number: partner.number, page: partnerPage, layout: layoutFor(partner.number) },
                    ].sort((a, b) => a.number - b.number);
                    verifyDoc = { pages: entries, offsets: [0, 0] };
                    processedPage = entries[0].page;
                } else {
                    verifyDoc = null;
                    processedPage = page;
                }
                cropped = verifyItems();
                readings = cropped.map((item) => item.reading);
                requiredInput(document, '#ocrModelKey').value = processedPage.modelKey.trim();
                requiredInput(document, '#documentPageId').value = String(processedPage.id);
                el('summaryModel').textContent = processedPage.model || '—';

                endOcrPhases('Almost done');
                setOcrProgress(96, 'Preparing validation', partner
                    ? 'Putting both pages side by side, one person to a row.'
                    : 'The handwriting results are ready for your review.');
                renderVerifyRows();
                showGridNotes(processedPage);
                if (verifyDoc) await loadVerifySpread();
                await completeOcrProgress(partner ? 'Both pages read' : 'Scan complete');
                showStep('verify');
                try {
                    if (verifyDoc) prepareSpreadComparison();
                    else prepareValidationComparison();
                    checkAndShowExistingRecord(page.existingRecord || partnerPage?.existingRecord || null);
                } catch (error) {
                    showStep('mark');
                    throw error;
                }
            } catch (error) {
                readSpan = null;
                scanKind = 'full';
                verifyDoc = null;
                // What Smoke test made of the other page is kept only when the read
                // was cancelled (the server keeps it then); otherwise it is sent afresh.
                if (partner && !(run.cancelled && run.kind === 'read')) {
                    pageDetections.delete(partner.number);
                    updateSpread();
                }
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
            button.innerHTML = '<i class="icon-base bx bx-radar icon-sm me-1" aria-hidden="true"></i> Test scanning...';

            // A PDF page (or queued batch page) shown with its pair: the two are one
            // document cut in two by the scanner, so both are tested in one run.
            // The other page goes with the markers it shows; one already tested,
            // or with markers that would be refused, is left as it is.
            const mate = batchPartner();
            const otherPage = mate ? mate.item.pageNo : companionPage;
            const mainNumber = mate ? batchState.items[batchState.currentIndex].pageNo : scanPageNumber;
            const otherGrid = otherPage !== null && companionMarker.canvas.width > 0
                && !storedDetection(otherPage, mate)
                ? companionGridFor(otherPage, mate)
                : null;
            const pair = otherGrid !== null && otherGrid.boxes.length > 0
                && markerSetValidationMessage(otherGrid.boxes) === null;
            let otherProblem = null;

            try {
                progress = openProgressModal();
                // One page's Smoke test, and the same again for the page beside it.
                const testSeconds = scanSeconds.wait + pageSeconds('detect', scanSeconds);
                beginOcrProgress(marker.toJSON().length, testSeconds * (pair ? 2 : 1));
                await new Promise((resolve) => window.requestAnimationFrame(resolve));

                setOcrProgress(Math.max(ocrProgress, 8), pair ? 'Sending both pages' : 'Sending the page',
                    'Uploading for detection.');
                // Both go to the detector at once, so it moves straight on to the second.
                const otherUpload = pair
                    ? uploadPage({
                        detect: true,
                        canvas: companionMarker.canvas,
                        geometry: alignedGeometry(otherGrid.boxes, otherGrid.rows, { keepRows: Boolean(otherGrid.byHand) }),
                        templateId: layoutFor(otherPage).templateId,
                    }, controller.signal)
                    : null;
                // Handled below; this only keeps a failure there from going unheard
                // when this page's own upload fails first.
                otherUpload?.catch(() => {});
                const payload = await uploadPage({ detect: true }, controller.signal);
                run.pageId = payload.id;
                const otherPayload = otherUpload ? await otherUpload.catch((error) => {
                    if (error.name === 'AbortError') throw error;
                    otherProblem = error;
                    return null;
                }) : null;
                if (otherPayload) run.batchPageIds = [payload.id, otherPayload.id];

                detectPhase = otherPayload
                    ? { from: 18, to: 56, title: `Smoke test scan · page ${mainNumber} of 2`, after: testSeconds }
                    : null;
                const page = await waitForPage(payload, controller.signal, 'detected', 'detect');

                if (!Array.isArray(page.lines) || page.lines.length === 0) {
                    throw new Error(`No handwriting was found on ${pair ? `page ${mainNumber}` : 'this page'}. Check that the right template and file were chosen.`);
                }

                await applyDetection(page);

                if (otherPayload) {
                    detectPhase = { from: 56, to: 95, title: `Smoke test scan · page ${otherPage} of 2` };
                    try {
                        const otherTested = await waitForPage(otherPayload, controller.signal, 'detected', 'detect');
                        if (!Array.isArray(otherTested.lines) || otherTested.lines.length === 0) {
                            throw new Error('no handwriting was found on it');
                        }
                        await keepCompanionDetection(otherPage, mate, otherTested);
                    } catch (error) {
                        if (error.name === 'AbortError') throw error;
                        otherProblem = error;
                    }
                }

                await completeOcrProgress(
                    otherPayload && !otherProblem ? 'Both pages tested' : 'Detection complete',
                    'Check the outlines, then Scan with OCR.',
                );
                if (otherProblem) {
                    showOcrError(`Page ${mainNumber} was tested, but page ${otherPage} could not be: ${otherProblem.message}. `
                        + 'Open it and run Smoke test scan there.');
                }
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
                detectPhase = null;
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

    // Names given to people in Verify, by page and person ("12:person-21").
    // Shown in Verify only: the record keeps each field's value as before.
    const personNameOverrides = new Map();
    // A two-page document's people are named once, for both pages.
    const personNameKey = (group) => `${verifyDoc
        ? verifyDoc.pages.map((entry) => entry.page.id).join('+')
        : processedPage?.id ?? ''}:${group.id}`;

        function groupIdentity(group) {
            if (group.kind !== 'person') return `${group.indexes.length} document field${group.indexes.length === 1 ? '' : 's'}`;
        const given = personNameOverrides.get(personNameKey(group));
        if (given) return given;
        return automaticIdentity(group);
    }

    /** The person's name as read from the page, before any name typed in Verify. */
    function automaticIdentity(group) {

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
         * A layout's ledger columns on its page's grid ({ page, layout }). A column
         * removed in Align is not expected in any row (the removal goes to the audit log).
         */
        function columnsOnPage(entry) {
            const pageColumns = new Set((entry.page?.geometry?.columns ?? []).map((column) => nameKey(column.name)));
            return entry.layout.boxes.filter((box) => box.kind === 'column'
                && (pageColumns.size === 0 || pageColumns.has(nameKey(box.name))));
        }

        /** A field's name, and which page it is on when two pages are verified together. */
        const pageNamed = (entry, name) => (verifyDoc ? `${name} (page ${entry.number})` : name);

        /**
         * Required fields of this person that nothing was read for: the ledger
         * columns missing from a row (on each page that has that row), or a
         * person's template fields.
         */
        function missingRequired(group) {
            if (group.kind !== 'person' || group.indexes.length === 0) return [];

            const present = new Set(group.indexes.map((index) => `${cropped[index]?.pageIndex ?? 0}|${nameKey(cropped[index]?.label)}`));
            const ledgerRow = group.indexes.some((index) => settingsFor(index)?.kind === 'column');
            const personGroup = Number(cropped[group.indexes[0]]?.personGroup);

            return docEntries().flatMap((entry, side) => {
                let expected;
                if (ledgerRow) {
                    // A two-page document: only a page whose table has this row.
                    expected = !verifyDoc || personGroup <= tableRowsOf(entry.page) ? columnsOnPage(entry) : [];
                } else {
                    expected = entry.layout.boxes.filter((box) => box.kind !== 'column' && Number(box.personGroup) === personGroup);
                }
                return expected
                    .filter((box) => box.required !== false && !present.has(`${side}|${nameKey(box.name)}`))
                    .map((box) => pageNamed(entry, box.name));
            });
        }

        // ------------------------------------------- a document of several pages
        // A PDF of many pages is one document (one record). In Verify its pages sit
        // side by side, two at a time like an open book (1-2, 3-4): the page being
        // verified has the live outlines, its partner sits beside it, read-only.
        // Click the partner to verify that page instead; each page keeps what was
        // typed and ticked on it, so going back loses nothing.

        /** The queued pages of one document, in page order. */
        function documentPagesOf(item) {
            return item?.group && item.pageTotal > 1
                ? batchState.items.filter((it) => it.group === item.group).sort((a, b) => a.pageNo - b.pageNo)
                : [];
        }

        /** The other page of this page's pair, once it has been scanned. */
        function verifyPartnerOf(item) {
            const pages = documentPagesOf(item);
            if (pages.length < 2) return null;
            const first = item.pageNo % 2 === 1 ? item.pageNo : item.pageNo - 1;
            const wanted = item.pageNo === first ? first + 1 : first;
            const mate = pages.find((it) => it.pageNo === wanted);
            return mate?.page?.imageUrl && mate.status !== 'failed' ? mate : null;
        }

        /** The queued page being verified, while a batch is in Verify. */
        function currentVerifyItem() {
            return batchState.active && batchState.phase === 'verifying'
                ? batchState.items[batchState.currentIndex] ?? null
                : null;
        }

        const pageIsChecked = (item) => Boolean(item.staged) || item.status === 'imported';

        /**
         * What the Submit button and the note beside it promise: on a page with
         * another still to check it moves on ("Continue to page 2"), and only the
         * last one saves the record, so nothing is saved before that.
         */
        function updateSubmitLabel(item, pages) {
            const head = el('submitLabelHead');
            const note = el('validationPagesNote');
            note.classList.toggle('d-none', pages.length < 2);
            if (pages.length < 2) {
                head.textContent = 'Submit';
                return;
            }

            const next = pages.find((it) => it !== item && !pageIsChecked(it));
            const all = pages.length === 2 ? 'Both pages are' : 'All pages are';
            if (next) {
                head.textContent = `Next: page ${next.pageNo} ·`;
                el('validationPagesNoteText').textContent = `${all} saved together as one record once the last page is checked. Nothing is saved yet.`;
            } else {
                head.textContent = 'Save record ·';
                el('validationPagesNoteText').textContent = `Saving puts ${pages.length === 2 ? 'both pages' : 'all pages'} together as one record.`;
            }
        }

        /** The page labels, the partner's place and the Submit wording; before the outlines are drawn. */
        function setVerifySpreadLayout(item) {
            const pages = documentPagesOf(item);
            const mate = verifyPartnerOf(item);
            const pill = el('validationPagePill');

            if (pages.length > 1) {
                const checked = pages.filter((it) => it !== item && pageIsChecked(it)).length;
                pill.textContent = `Page ${item.pageNo} of ${pages.length} · ${checked} of ${pages.length} checked`;
                pill.classList.remove('d-none');
            } else if (!item && multiPage()) {
                pill.textContent = `Page ${scanPageNumber} of ${scanPageCount}`;
                pill.classList.remove('d-none');
            } else {
                pill.classList.add('d-none');
            }

            const mainLabel = el('validationMainLabel');
            mainLabel.classList.toggle('d-none', pages.length < 2);
            if (pages.length > 1) mainLabel.textContent = `Page ${item.pageNo} · verifying`;

            // One page fills the width; two share it.
            validationMarker.fitShare = mate ? 0.5 : 1;
            el('validationCompanionPage').classList.toggle('d-none', !mate);
            el('validationCompanionPage').classList.remove('is-live');
            el('validationCompanionPage').title = 'Verify this page instead';
            el('validationSpread').classList.remove('is-joined', 'is-page-focused');
            // One page (or a batch's pages, verified one at a time): the usual layout.
            document.querySelector('#step-verify .validation-comparison-grid')?.classList.remove('is-two-page');
            el('verifyPageView').classList.add('d-none');
            el('verifyZoomTip').classList.add('d-none');
            verifyFocus = null;
            verifyFocusZoom = null;
            updateRowHint();
            if (mate) {
                // Pages stay where they are in the book: the lower page on the left.
                el('validationCompanionPage').style.order = mate.pageNo < item.pageNo ? '-1' : '1';
                el('validationCompanionLabel').textContent = pageIsChecked(mate)
                    ? `Page ${mate.pageNo} · checked ✓`
                    : `Page ${mate.pageNo} · not checked yet`;
            }
            updateSubmitLabel(item, pages);
        }

        let verifyCompanionToken = 0;

        /** Draw the partner page and its outlines beside the page being verified. */
        async function loadVerifyCompanion(item) {
            const token = ++verifyCompanionToken;
            const mate = verifyPartnerOf(item);
            if (!mate) {
                companionLines.clear();
                return;
            }

            try {
                await validationCompanionMarker.loadFromUrl(mate.page.imageUrl);
            } catch (error) {
                console.warn(`Page ${mate.pageNo} could not be shown beside the page:`, error);
                if (token === verifyCompanionToken) {
                    el('validationCompanionPage').classList.add('d-none');
                    validationMarker.fitShare = 1;
                    validationMarker.resetZoom();
                }
                return;
            }
            if (token !== verifyCompanionToken) return;

            const entries = verificationItems(mate.page.lines, mate.page);
            companionLines.setLines(mate.page, entries);
            // What was checked on that page shows green.
            const checked = new Set((mate.staged?.fields ?? []).map((field) => field.line_id));
            entries.forEach((entry, index) => companionLines.setVerified(index, checked.has(entry.lineId)));
            // Same zoom for both, so the pair stays level.
            window.requestAnimationFrame(() => validationMarker.resetZoom());
        }

        /** What was typed and ticked on a page, kept while another page is shown. */
        function keepVerifyDraft(item) {
            if (!item || !processedPage || processedPage !== item.page) return;
            const draft = new Map();
            cropped.forEach((entry, index) => {
                const row = validationRow(index);
                if (!row) return;
                draft.set(entry.lineId, {
                    value: requiredInput(row, '.verified').value,
                    verified: requiredInput(row, '.validation-verified').checked,
                });
            });
            item.verifyDraft = draft;
        }

        function restoreVerifyDraft(item) {
            const draft = item?.verifyDraft;
            if (!draft) return;
            cropped.forEach((entry, index) => {
                const previous = draft.get(entry.lineId);
                const row = validationRow(index);
                if (!previous || !row) return;
                requiredInput(row, '.verified').value = previous.value;
                requiredInput(row, '.validation-verified').checked = previous.verified;
                row.classList.toggle('is-verified', previous.verified);
            });
            updateVerificationSummary();
        }

        let switchingVerifyPage = false;
        el('validationCompanionPage').addEventListener('click', async () => {
            // A two-page document: both pages are verified where they are.
            if (verifyDoc) return;
            const item = currentVerifyItem();
            const mate = verifyPartnerOf(item);
            if (!mate || switchingVerifyPage || recordSubmitting || undoingVerifyChange) return;
            switchingVerifyPage = true;
            try {
                await openBatchVerifyItem(batchState.items.indexOf(mate));
            } finally {
                switchingVerifyPage = false;
            }
        });

        function prepareValidationComparison() {
            const source = el('pageCanvas');
            const target = el('validationPageCanvas');
            const context = target.getContext('2d');

            if (!context || source.width === 0 || source.height === 0) {
                throw new Error('The original document could not be prepared for comparison. Please scan again.');
            }

            const spreadItem = currentVerifyItem();
            setVerifySpreadLayout(spreadItem);
            activeValidationIndex = null;
            stopAddingMissedField();
        stopGroupingFields();
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

            window.requestAnimationFrame(() => {
                validationMarker.layout();
                updateValidationMarkerStates();
                const initialGroup = validationGroups.find((group) => group.kind === 'person')
                    ?? validationGroups[0];
                if (initialGroup) activateValidationGroup(initialGroup.id, 'initial');
            });
            loadVerifyCompanion(spreadItem);
        }

        /**
         * Both pages of a two-page document, drawn from the pages the server read
         * (straightened ones included), before Verify is shown.
         */
        async function loadVerifySpread() {
            const [first, second] = verifyDoc.pages;
            await Promise.all([
                validationMarker.loadFromUrl(first.page.imageUrl),
                validationCompanionMarker.loadFromUrl(second.page.imageUrl),
            ]);
        }

        /**
         * Verify a two-page document: its pages side by side, the first on the
         * left, each with live outlines. A person is a row across both pages, so
         * picking any of their fields lights up the whole row on both.
         */
        function prepareSpreadComparison() {
            const [first, second] = verifyDoc.pages;
            activeValidationIndex = null;
            stopAddingMissedField();
            stopGroupingFields();
            cancelOutlineEdit();

            const pill = el('validationPagePill');
            pill.textContent = `Pages ${first.number}–${second.number} of ${scanPageCount} · one record`;
            pill.classList.remove('d-none');
            el('validationMainLabel').textContent = `Page ${first.number}`;
            el('validationMainLabel').classList.remove('d-none');
            const companion = el('validationCompanionPage');
            companion.classList.remove('d-none');
            companion.classList.add('is-live');
            companion.style.order = '1';
            // Both pages are verified where they are: a click zooms in, no tooltip needed.
            companion.title = '';
            el('validationCompanionLabel').textContent = `Page ${second.number}`;
            el('validationSpread').classList.add('is-joined');
            // The pages get the larger share of the width, and start side by side.
            document.querySelector('#step-verify .validation-comparison-grid')?.classList.add('is-two-page');
            el('verifyPageView').querySelector('[data-verify-view="0"]').textContent = `Page ${first.number}`;
            el('verifyPageView').querySelector('[data-verify-view="1"]').textContent = `Page ${second.number}`;
            verifyFocus = null;
            verifyFocusZoom = null;

            el('submitLabelHead').textContent = 'Save record ·';
            el('validationPagesNote').classList.remove('d-none');
            el('validationPagesNoteText').textContent = `Pages ${first.number} and ${second.number} are saved as one record. `
                + 'Each row of the two pages is one person.';

            validationMarker.fitShare = 0.5;
            companionLines.clear();
            validationMarker.setBoxes([]);
            validationCompanionMarker.setBoxes([]);
            lineOverlay.setLines(processedPage, cropped);
            validationMarker.resetZoom();
            el('validationDocViewport').scrollTo({ top: 0, left: 0 });
            updateVerifyPageView();

            window.requestAnimationFrame(() => {
                // The wider share of the width is laid out now: fit both pages to it.
                validationMarker.resetZoom();
                validationMarker.layout();
                validationCompanionMarker.layout();
                updateValidationMarkerStates();
                const initialGroup = validationGroups.find((group) => group.kind === 'person')
                    ?? validationGroups[0];
                if (initialGroup) activateValidationGroup(initialGroup.id, 'initial');
            });
        }

        // -------------------------------------------- two pages in view (Verify)
        // A two-page document starts with both pages side by side. A click on a
        // page (or Page 1 / Page 2 above) zooms in until that page fills the view,
        // with one easing motion; a second click, Both, or 100% goes back. A
        // person's row runs across both pages: when part of it is out of view, a
        // button at that edge, level with the row, slides there.
        const verifyPaneOf =(side) => el(side === 0 ? 'validationMainPage' : 'validationCompanionPage');
        const verifyCanvasOf = (side) => (side === 0 ? validationMarker : validationCompanionMarker).canvas;

        /** A page is zoomed in on, at the zoom it was fitted at. */
        function verifyFocusIsCurrent() {
            return verifyFocus !== null && verifyFocusZoom !== null
                && (validationMarker.gliding || Math.abs(validationMarker.zoom - verifyFocusZoom) <= 0.011);
        }

        function focusVerifyPage(side, { animate = true } = {}) {
            if (!verifyDoc) return;
            verifyFocus = side;
            verifyFocusZoom = validationMarker.focusOn(verifyPaneOf(side), {
                fitCanvas: verifyCanvasOf(side),
                duration: animate ? 420 : 0,
            });
            updateVerifyPageView();
        }

        function showVerifyBoth({ animate = true } = {}) {
            verifyFocus = null;
            verifyFocusZoom = null;
            validationMarker.focusOn(el('validationSpread'), {
                zoom: 1,
                pad: { across: 0, down: 0 },
                duration: animate ? 420 : 0,
            });
            updateVerifyPageView();
        }

        /** Page 1 / Both / Page 2: which is in view, and the cursor that says what a click does. */
        function updateVerifyPageView() {
            const control = el('verifyPageView');
            control.classList.toggle('d-none', !verifyDoc);
            el('verifyZoomTip').classList.toggle('d-none', !verifyDoc);
            const focused = verifyFocusIsCurrent();
            const current = focused ? String(verifyFocus) : (Math.abs(validationMarker.zoom - 1) <= 0.011 ? 'both' : null);
            control.querySelectorAll('[data-verify-view]').forEach((button) => {
                const on = button.dataset.verifyView === current;
                button.classList.toggle('active', on);
                button.setAttribute('aria-pressed', String(on));
            });
            el('validationSpread').classList.toggle('is-page-focused', focused);
            el('validationSpread').dataset.focus = focused ? String(verifyFocus) : '';
            scheduleRowHint();
        }

        el('verifyPageView').addEventListener('click', (event) => {
            const button = event.target instanceof Element ? event.target.closest('[data-verify-view]') : null;
            if (!button || !verifyDoc) return;
            if (button.dataset.verifyView === 'both') showVerifyBoth();
            else focusVerifyPage(Number(button.dataset.verifyView));
        });

        // A click on a page, beside every outline: zoom in on it, or back out.
        el('validationSpread').addEventListener('click', (event) => {
            if (!verifyDoc || !(event.target instanceof Element)) return;
            if (addingMissedField || groupingFields || redrawingLineId !== null || editingLineIndex !== null || recordSubmitting) return;
            if (event.target.closest('.line-marker, .line-overlay.is-editing, button, a, input, select')) return;
            const pane = event.target.closest('#validationMainPage, #validationCompanionPage');
            if (!pane) return;
            const side = pane.id === 'validationMainPage' ? 0 : 1;
            if (verifyFocusIsCurrent() && verifyFocus === side) showVerifyBoth();
            else focusVerifyPage(side);
        });

        /**
         * Where some fields' outlines are, in the viewport's scrolled space, one
         * box per page they are on: [{ side, left, right, top, bottom }]. A page
         * may not start at the viewport's corner: the other page of a spread can
         * sit to its left, and a label above it.
         */
        function placedVerifyBoxes(indexes) {
            const viewport = el('validationDocViewport');
            const view = viewport.getBoundingClientRect();
            return splitBySide(indexes).flatMap((locals, side) => {
                const bounds = locals.length > 0 ? verifyOverlays[side].displayBounds(locals) : null;
                if (!bounds) return [];
                const stage = el(side === 0 ? 'validationDocStage' : 'validationCompanionStage').getBoundingClientRect();
                const left = stage.left - view.left - viewport.clientLeft + viewport.scrollLeft;
                const top = stage.top - view.top - viewport.clientTop + viewport.scrollTop;
                return [{
                    side,
                    left: left + bounds.left,
                    right: left + bounds.right,
                    top: top + bounds.top,
                    bottom: top + bounds.bottom,
                }];
            });
        }

        /** How much of a box the viewport shows, in square pixels. */
        function visibleArea(box) {
            const viewport = el('validationDocViewport');
            const width = Math.min(box.right, viewport.scrollLeft + viewport.clientWidth) - Math.max(box.left, viewport.scrollLeft);
            const height = Math.min(box.bottom, viewport.scrollTop + viewport.clientHeight) - Math.max(box.top, viewport.scrollTop);
            return Math.max(0, width) * Math.max(0, height);
        }

        /** Slide the view to a box (centred on it), keeping the zoom. */
        function slideVerifyViewTo(box) {
            const viewport = el('validationDocViewport');
            const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            viewport.scrollTo({
                left: Math.max(0, (box.left + box.right) / 2 - viewport.clientWidth / 2),
                top: Math.max(0, (box.top + box.bottom) / 2 - viewport.clientHeight / 2),
                behavior: smooth ? 'smooth' : 'auto',
            });
        }

        // The edge button: shown while the person's row goes on out of view.
        let rowHintFrame = 0;
        function scheduleRowHint() {
            if (rowHintFrame) return;
            rowHintFrame = window.requestAnimationFrame(() => {
                rowHintFrame = 0;
                updateRowHint();
            });
        }

        function updateRowHint() {
            const hint = el('verifyRowHint');
            const hide = () => {
                hint.classList.remove('is-shown');
                hint.setAttribute('aria-hidden', 'true');
                hint.tabIndex = -1;
            };
            const group = verifyDoc
                ? validationGroups.find((candidate) => candidate.id === activeValidationGroupId)
                : null;
            if (!group || group.kind !== 'person') return hide();
            // A part of the row is in view when most of it shows: a sliver of the
            // other page at the edge does not count.
            const shownShare = (box) => visibleArea(box) / Math.max(1, (box.right - box.left) * (box.bottom - box.top));
            const placed = placedVerifyBoxes(group.indexes);
            const shown = placed.find((box) => shownShare(box) >= 0.5);
            const hidden = placed.find((box) => shownShare(box) < 0.35);
            if (!shown || !hidden) return hide();

            // Level with the row, just inside the edge the rest of it is past.
            const viewport = el('validationDocViewport');
            const view = viewport.getBoundingClientRect();
            const pane = viewport.parentElement.getBoundingClientRect();
            const middle = (shown.top + shown.bottom) / 2 - viewport.scrollTop;
            const toRight = hidden.left >= shown.left;
            hint.style.top = `${view.top - pane.top + Math.min(viewport.clientHeight - 22, Math.max(22, middle))}px`;
            hint.style.left = `${(toRight ? view.right - 14 : view.left + 14) - pane.left}px`;
            hint.classList.toggle('is-right', toRight);
            hint.classList.toggle('is-left', !toRight);
            const number = verifyDoc.pages[hidden.side].number;
            hint.querySelector('span').textContent = `Page ${number}`;
            hint.title = `${group.label}'s row goes on on page ${number}`;
            hint.dataset.side = String(hidden.side);
            hint.setAttribute('aria-hidden', 'false');
            hint.tabIndex = 0;
            hint.classList.add('is-shown');
        }

        el('verifyRowHint').addEventListener('click', () => {
            const side = Number(el('verifyRowHint').dataset.side);
            if (!verifyDoc || !Number.isInteger(side)) return;
            if (verifyFocusIsCurrent()) {
                focusVerifyPage(side);
                return;
            }
            const group = validationGroups.find((candidate) => candidate.id === activeValidationGroupId);
            const box = group ? placedVerifyBoxes(group.indexes).find((candidate) => candidate.side === side) : null;
            if (box) slideVerifyViewTo(box);
        });

        el('validationDocViewport').addEventListener('scroll', scheduleRowHint, { passive: true });

        // The side bar opening or closing, or the window resized: a page in focus
        // is fitted to the new room once it has settled.
        let verifyRoom = null;
        let verifyRefitTimer = 0;
        new ResizeObserver(() => {
            const viewport = el('validationDocViewport');
            const room = { width: viewport.clientWidth, height: viewport.clientHeight };
            const before = verifyRoom;
            verifyRoom = room;
            scheduleRowHint();
            if (!before || !verifyDoc || !verifyFocusIsCurrent()) return;
            if (Math.abs(room.width - before.width) < 4 && Math.abs(room.height - before.height) < 4) return;
            window.clearTimeout(verifyRefitTimer);
            verifyRefitTimer = window.setTimeout(() => {
                if (verifyFocusIsCurrent()) focusVerifyPage(verifyFocus, { animate: false });
            }, 150);
        }).observe(el('validationDocViewport'));

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
            const placed = placedVerifyBoxes(indexes);
            if (placed.length === 0) return;

            // One page zoomed in on: it is in view whole, so only a field of the
            // other page moves the view, by bringing that page in.
            if (verifyDoc && verifyFocusIsCurrent()) {
                if (!placed.some((box) => box.side === verifyFocus)) focusVerifyPage(placed[0].side);
                return;
            }

            // A person across both pages: their whole row when it fits across the
            // view; zoomed in further, the part of it most in view (else the first).
            const viewport = el('validationDocViewport');
            const across = Math.max(...placed.map((box) => box.right)) - Math.min(...placed.map((box) => box.left));
            const shown = across <= viewport.clientWidth
                ? placed
                : [[...placed].sort((a, b) => visibleArea(b) - visibleArea(a))[0]];
            const target = {
                left: Math.min(...shown.map((box) => box.left)),
                right: Math.max(...shown.map((box) => box.right)),
                top: Math.min(...shown.map((box) => box.top)),
                bottom: Math.max(...shown.map((box) => box.bottom)),
            };

            // Already in view: the view stays still, so nothing jumps.
            const margin = 8;
            const inView = target.left >= viewport.scrollLeft + margin
                && target.right <= viewport.scrollLeft + viewport.clientWidth - margin
                && target.top >= viewport.scrollTop + margin
                && target.bottom <= viewport.scrollTop + viewport.clientHeight - margin;
            if (!inView) slideVerifyViewTo(target);
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
            // The person may now run on, out of view, on the other page.
            scheduleRowHint();
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

            if (checked && cropped[index]?.cropDeleted) {
                setValidationFieldError(index, 'Draw this field again first; its crop was deleted.');
                firstBlankIndex ??= index;
                return;
            }
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
                    <span class="validation-field__tools">
                        <button type="button" class="btn btn-link btn-sm validation-field__adjust">
                            <i class="icon-base bx bx-shape-polygon" aria-hidden="true"></i>Adjust outline
                        </button>
                        <button type="button" class="btn btn-link btn-sm validation-field__adjust validation-field__delete-crop"
                                title="The crop took in the wrong writing: delete it and draw this field again">
                            <i class="icon-base bx bx-trash" aria-hidden="true"></i>Delete crop
                        </button>
                    </span>
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

        // Every table field: its person and column (and Remove where it can go).
            const placement = fieldPlacementControls(index);
            if (placement) requiredPart(row, '.validation-field__reading').after(placement);

        // Delete crop: for a crop that took in the wrong writing. A field with
        // Remove already has its own way out, so it is not offered twice.
        const deleteCrop = requiredPart(row, '.validation-field__delete-crop');
        if (isRemovableField(item) || item.cropDeleted) {
            deleteCrop.remove();
        } else {
            deleteCrop.setAttribute('aria-label', `Delete the crop of ${displayName} and draw it again`);
            deleteCrop.addEventListener('click', (event) => {
                event.stopPropagation();
                askToDeleteCrop(index);
            });
        }

        // A field the reviewer added, or one between two rows, says it in its
        // Person and Column (an empty Person is outlined red), not in a red note.
        const explanation = placement && isRemovableField(item) ? '' : flagExplanation(item.flags ?? []);
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

        if (item.cropDeleted) {
            // Nothing to check until the field is drawn again: one clean line
            // with the name, what happened, and the one thing to do.
            row.classList.add('is-crop-deleted');
            ['.validation-field__reading', '.validation-field__place', '.validation-field__hint', '.validation-field__type',
                '.validation-field__flag', '.validation-field__ocr-error'].forEach((selector) => row.querySelector(selector)?.remove());
            row.classList.remove('has-line-flag', 'has-ocr-error', 'needs-review');

            const entry = requiredPart(row, '.validation-field__entry');
            const placeholder = document.createElement('span');
            placeholder.className = 'validation-field__crop validation-field__crop-deleted';
            placeholder.innerHTML = '<i class="icon-base bx bx-trash" aria-hidden="true"></i>Crop deleted';
            const picture = row.querySelector('img.validation-field__crop');
            if (picture) picture.replaceWith(placeholder);
            else entry.prepend(placeholder);

            // The value box stays in the row (the list reads it) but is not shown.
            input.value = '';
            input.disabled = true;
            input.classList.add('d-none');
            checkbox.disabled = true;

            // What to do next, in step with the Draw again bar above the page
            // (see showRedrawState): Draw again, then draw on the page, then
            // Save and re-read.
            const actions = document.createElement('div');
            actions.className = 'validation-field__redraw';
            actions.innerHTML = `
                <button type="button" class="btn btn-sm btn-danger validation-field__draw-again">
                    <i class="icon-base bx bx-edit-alt icon-sm me-1" aria-hidden="true"></i>Draw again
                </button>
                <span class="validation-field__redraw-hint" role="status">
                    <i class="icon-base bx bx-chevron-left" aria-hidden="true"></i>Draw a box on the page
                </span>
                <button type="button" class="btn btn-sm btn-primary validation-field__redraw-save">
                    <i class="icon-base bx bx-check icon-sm me-1" aria-hidden="true"></i>
                    <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    Save and re-read
                </button>`;
            const again = requiredPart(actions, '.validation-field__draw-again');
            again.setAttribute('aria-label', `Draw ${displayName} again`);
            again.addEventListener('click', (event) => {
                event.stopPropagation();
                startRedrawField(index);
            });
            requiredPart(actions, '.validation-field__redraw-save').addEventListener('click', (event) => {
                event.stopPropagation();
                saveRedrawnField();
            });
            row.dataset.redraw = 'idle';
            entry.append(actions);
        }

        row.addEventListener('click', (event) => {
            const modifier = event.ctrlKey || event.metaKey || event.shiftKey;
            // While picking, the value box, the tick and the buttons still work as usual.
            const onControl = event.target instanceof Element
                && event.target.closest('input, select, textarea, button, label, a');
            if (modifier || (groupingFields && !onControl)) {
                event.preventDefault();
                togglePickedField(index);
                return;
            }
            activateValidationField(index, 'row');
        });
        row.classList.toggle('is-picked', pickedLineIds.has(item.lineId));
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
            // A person across both pages of a register says so.
            if (verifyDoc && new Set(group.indexes.map((index) => cropped[index]?.pageIndex ?? 0)).size > 1) {
                const both = document.createElement('span');
                both.className = 'validation-record-group__pages';
                both.textContent = `Pages ${verifyDoc.pages[0].number}–${verifyDoc.pages[1].number}`;
                requiredPart(section, '.validation-record-group__copy strong').append(' ', both);
            }

        // A deleted crop is not something to review but something to do.
        const redrawCount = group.indexes.filter((index) => cropped[index]?.cropDeleted).length;
        const reviewCount = group.indexes.filter((index) => !cropped[index]?.cropDeleted && (
                normaliseConfidence(readings[index]) < config.threshold || cropped[index]?.needsReview
            )).length;
            const missing = missingRequired(group);
            const review = requiredPart(section, '.validation-record-group__review');
            review.textContent = [
            redrawCount > 0 ? `${redrawCount} to draw again` : null,
                reviewCount > 0 ? `${reviewCount} to review` : null,
                missing.length > 0 ? `${missing.length} missing` : null,
            ].filter(Boolean).join(' · ') || 'Ready to review';
            review.classList.toggle('has-review', reviewCount > 0 || missing.length > 0);
        review.classList.toggle('has-redraw', redrawCount > 0);

            const body = requiredPart(section, '.validation-record-group__body');
            if (missing.length > 0) {
                const note = document.createElement('div');
                note.className = 'validation-record-group__missing';
                note.textContent = `Nothing was read for ${missing.join(', ')}. If the page has ${missing.length === 1 ? 'it' : 'them'}, `
                    + 'use Add missed field above the original document and drag a box over it; if the register leaves it blank, carry on.';
                body.appendChild(note);
            }
            if (group.kind === 'person') {
            body.appendChild(personNameControl(group, section));
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

            // A two-page document: each page's fields under that page's name.
            // A person's part of each page can be moved to another person there.
            const pagesInGroup = new Set(group.indexes.map((index) => cropped[index]?.pageIndex ?? 0));
            let shownPage = null;
            group.indexes.forEach((index, columnIndex) => {
                const pageIndex = cropped[index]?.pageIndex ?? 0;
                if (verifyDoc && (pagesInGroup.size > 1 || group.kind === 'person') && pageIndex !== shownPage) {
                    shownPage = pageIndex;
                    const divider = document.createElement('div');
                    divider.className = 'validation-page-divider';
                    divider.innerHTML = '<i class="icon-base bx bx-file" aria-hidden="true"></i><span></span>';
                    divider.querySelector('span').textContent = `Page ${verifyDoc.pages[pageIndex].number}`;
                    const move = group.kind === 'person' ? pagePartControl(group, pageIndex) : null;
                    if (move) divider.appendChild(move);
                    body.appendChild(divider);
                }
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

        /**
         * "Belongs to": on a two-page document, which person this page's part of
         * a row is. A row of page 2 that landed on the wrong person is moved, all
         * its columns together, to the right one; if that person already has a
         * part on this page, the two swap. Ctrl+Z takes it back.
         */
        function pagePartControl(group, side) {
            const row = group.indexes
                .filter((index) => (cropped[index]?.pageIndex ?? 0) === side)
                .map((index) => cropped[index]?.gridRow)
                .find((value) => Number.isInteger(value));
            if (!Number.isInteger(row)) return null;

            const entry = verifyDoc.pages[side];
            const onPage = cropped.filter((item) => (item.pageIndex ?? 0) === side);
            const rows = Math.max(tableRowsOf(entry.page), ...onPage.map((item) => item.gridRow ?? 0));
            const names = new Map(validationGroups
                .filter((candidate) => candidate.kind === 'person')
                .map((candidate) => [Number(cropped[candidate.indexes[0]]?.personGroup), groupIdentity(candidate)]));

            const wrap = document.createElement('label');
            wrap.className = 'validation-page-divider__move';
            wrap.innerHTML = '<span>Belongs to</span>';
            const select = document.createElement('select');
            select.className = 'form-select form-select-sm';
            select.setAttribute('aria-label', `Page ${entry.number} part of ${group.label} belongs to`);
            select.title = `Move page ${entry.number}'s part of this row to another person`;
            for (let candidate = 1; candidate <= rows; candidate += 1) {
                const number = String(candidate).padStart(2, '0');
                const name = names.get(candidate);
                select.append(new Option(name && !/^\d+ fields?$/.test(name) ? `Person ${number} · ${name}` : `Person ${number}`, String(candidate)));
            }
            select.value = String(row);
            select.addEventListener('click', (event) => event.stopPropagation());
            select.addEventListener('change', async () => {
                const moved = await movePagePart(side, row, Number(select.value));
                if (!moved) select.value = String(row);
            });
            wrap.append(select);
            return wrap;
        }

        /** Move page `side`'s row `from` to person `to` (swapping, if `to` has a part there). */
        async function movePagePart(side, from, to) {
            if (!verifyDoc || from === to || recordSubmitting) return false;
            const page = verifyDoc.pages[side].page;
            const onPage = cropped.filter((item) => (item.pageIndex ?? 0) === side);
            const movingIds = onPage.filter((item) => item.gridRow === from).map((item) => item.lineId);
            const swapped = onPage.some((item) => item.gridRow === to);
            const moves = swapped ? [{ from, to }, { from: to, to: from }] : [{ from, to }];
            try {
                const { response, payload } = await sendLineRequest(
                    config.pageRowsUrl.replace('__PAGE__', String(page.id)), 'PATCH', { moves },
                );
                if (!response.ok || !Array.isArray(payload?.lines)) throw new Error(responseErrorMessage(response, payload));
                processedPage = page;
                rebuildVerifyLines(payload.lines, payload.flags ?? {}, { focusLineId: movingIds[0] ?? null, focusFrom: 'marker' });
                pushVerifyUndo({ kind: 'rowsMoved', moves });
                flashVerifyFields(movingIds);
                return true;
            } catch (error) {
                showValidationSubmitError(error.message || 'That part of the row could not be moved.');
                return false;
            }
        }

        /** A short flash on fields that just moved, so the eye finds them. */
        function flashVerifyFields(lineIds) {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            lineIds.forEach((lineId) => {
                const row = validationRow(cropped.findIndex((item) => item.lineId === lineId));
                if (!row) return;
                row.classList.add('is-changing');
                window.setTimeout(() => row.classList.remove('is-changing'), 700);
            });
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
            }).catch(() => { }); // turned round by a new open
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
            usePageOf(index);
            stopAddingMissedField();
        stopRedrawField();
        stopGroupingFields();
            // Moving to another field keeps the tools open, so they do not close and reopen.
            cancelOutlineEdit({ hide: false });

            editingLineIndex = index;
            activateValidationField(index, 'row');
            el('lineEditName').textContent = cropped[index].name;
            // The tools slide down above the page, in the document pane; the field list does not move.
            slideToolbar(el('lineEditToolbar'), true);
            showLineEditStatus('');
        const mode = document.querySelector('input[name="verifyLineMode"]:checked')?.value ?? 'rectangle';
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
            setLineEditSaving(false);
        }

        /** Save and re-read: a spinner in place of the tick while the line is cropped and read again. */
        function setLineEditSaving(saving) {
            const button = el('lineEditSave');
            button.disabled = saving;
            button.querySelector('.bx-check')?.classList.toggle('d-none', saving);
            button.querySelector('.spinner-border')?.classList.toggle('d-none', !saving);
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
            usePageOf(index);
            setLineEditSaving(true);
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
                setLineEditSaving(false);
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
            // The page that line is on, whatever was clicked meanwhile.
            usePageOfLine(line.id);
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

            // `lines` are the page being worked on's (processedPage); of a
            // two-page document the other page's stay as they are.
            processedPage.lines = lines.map((candidate) => {
                const flags = flagsById[candidate.id];
                return Array.isArray(flags) ? { ...candidate, flags } : candidate;
            });
            cropped = verifyItems();
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
        showPickedFields();
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
        read.querySelector('.add-field-read__label').textContent = readingPendingBoxes
            ? 'Reading…'
            : count === 0 ? 'Read boxes' : `Read ${count} box${count === 1 ? '' : 'es'}`;
        // A spinner in place of the scan icon while the boxes are read.
        read.querySelector('.bx-scan')?.classList.toggle('d-none', readingPendingBoxes);
        read.querySelector('.spinner-border')?.classList.toggle('d-none', !readingPendingBoxes);
            showAddFieldStatus(message ?? (count === 0
                ? `${ADD_MODE_HINTS[addFieldMode()]}${verifyDoc ? ' Either page.' : ''} Nothing is read until you press Read.`
                : `${count} box${count === 1 ? '' : 'es'} ready. Mark another, or press Read (Enter). Ctrl+Z takes back the last one.`));
        }

        /** Ready for the next box, in the chosen way. */
        function armAddFieldDrawing() {
            if (addingMissedField && !readingPendingBoxes) lineOverlay.beginDraw(addFieldMode());
        }

        function startAddingMissedField() {
            if (!processedPage || recordSubmitting) return;
            cancelOutlineEdit();
        stopRedrawField();
        stopGroupingFields();
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

        /** A box was closed on a page: it waits for Read, and the next can be drawn (on either page). */
        function addPendingBox(polygon, side = 0) {
            pendingBoxes.push({ polygon, side });
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

            // Each box is read on the page it was drawn on.
            const pageOfSide = (side) => docEntries()[side]?.page ?? processedPage;
            const added = [[], []];
            const flags = [{}, {}];
            const failed = [];
            const warnings = [];
            for (const [index, box] of pendingBoxes.entries()) {
                showAddFieldStatus(`Reading box ${index + 1} of ${pendingBoxes.length}…`);
                try {
                    const { response, payload } = await sendLineRequest(
                        config.lineStoreUrl.replace('__PAGE__', String(pageOfSide(box.side).id)), 'POST', { polygon: box.polygon },
                    );
                    if (!payload?.line) throw new Error(responseErrorMessage(response, payload));
                    added[box.side].push(payload.line);
                    if (payload.suggestion) placementSuggestions.set(payload.line.id, payload.suggestion);
                    flags[box.side] = payload.flags ?? flags[box.side];
                    if (!response.ok) warnings.push(payload.line.id);
                } catch (error) {
                    failed.push({ box, message: error.message || 'The box could not be read.' });
                }
            }

            readingPendingBoxes = false;
        showPendingBoxes();
            el('addFieldCancel').disabled = false;
            el('addFieldToolbar').querySelectorAll('input[name="addFieldMode"]').forEach((input) => { input.disabled = false; });

            added.forEach((lines, side) => {
                if (lines.length === 0) return;
                processedPage = pageOfSide(side);
                rebuildVerifyLines([...processedPage.lines, ...lines], flags[side],
                    { focusLineId: lines[0].id, freshLineId: null });
                pushVerifyUndo({ kind: 'added', ids: lines.map((line) => line.id) });
            });
            warnings.forEach((id) => showOcrLineWarning(id, 'The box was added, but TrOCR could not read it. Type the value.'));

            if (failed.length === 0) {
                stopAddingMissedField();
                return;
            }
            // Keep the ones that failed on the page, to read again or take back.
            pendingBoxes = failed.map((failure) => failure.box);
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
            const page = usePageOf(index);
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
                processedPage = page;
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
            const page = usePageOf(index);
            try {
                const { response, payload } = await sendLineRequest(verifyLineUrl(config.lineDeleteUrl, item.lineId), 'DELETE');
                if (!response.ok) throw new Error(responseErrorMessage(response, payload));
                processedPage = page;
                rebuildVerifyLines(processedPage.lines.filter((line) => line.id !== item.lineId), payload?.flags ?? {});
                pushVerifyUndo({ kind: 'removed', id: item.lineId });
            } catch (error) {
                setValidationFieldError(index, error.message || 'The field could not be removed.');
            }
        }

    // ------------------------------------------------------ delete crop
    // A crop that took in the wrong writing (two lines overlapping, a word
    // cut off) is deleted, and the field must be drawn again: the new box is
    // cropped and read by TrOCR at once, and stays in the same person and
    // column. Submit waits until every deleted crop is drawn again.

    let redrawingLineId = null;
    // The page (0 or 1) the field being drawn again is on: the box is drawn there.
    let redrawSide = 0;
    let savingRedraw = false;

    const REDRAW_MODE_HINTS = {
        rectangle: 'Drag a box over this field’s writing only, then press Save and re-read.',
        draw: 'Click round this field’s writing or hold and trace; Enter or double-click closes it.',
    };
    const REDRAW_READY_HINT = 'Box ready. Press Save and re-read (Enter), or drag again to replace it.';
    // The box drawn for the field, waiting for Save and re-read (page pixels).
    let redrawBox = null;

    function redrawFieldMode() {
        return document.querySelector('input[name="redrawFieldMode"]:checked')?.value ?? 'rectangle';
    }

    function showRedrawStatus(message, isError = false) {
        const status = el('redrawFieldStatus');
        status.textContent = message;
        status.title = message;
        status.classList.toggle('is-error', isError);
    }

    function showRedrawDraft(points) {
        if (points === 0) {
            showRedrawStatus(REDRAW_MODE_HINTS.draw);
            return;
        }
        showRedrawStatus(points < 3
            ? `${points} point${points === 1 ? '' : 's'}. At least 3 to make an outline.`
            : `${points} points. Enter or double-click closes it.`);
    }

    function armRedrawDrawing() {
        if (redrawingLineId === null || savingRedraw) return;
        lineOverlay.beginDraw(redrawFieldMode(), redrawSide);
        showRedrawStatus(redrawBox ? REDRAW_READY_HINT : REDRAW_MODE_HINTS[redrawFieldMode()]);
    }

    /** The box for the field, shown on the page until it is saved; null clears it. */
    function setRedrawBox(polygon) {
        redrawBox = polygon;
        // One box for one field: no number on it.
        lineOverlay.setPending(polygon ? [{ polygon, side: redrawSide }] : [], { numbered: false });
        el('redrawFieldSave').disabled = !polygon || savingRedraw;
        showRedrawState();
    }

    /** Save and re-read: a spinner in place of the tick while the box is cropped and read. */
    function setRedrawSaving(saving) {
        savingRedraw = saving;
        const button = el('redrawFieldSave');
        button.disabled = saving || !redrawBox;
        button.querySelector('.bx-check')?.classList.toggle('d-none', saving);
        button.querySelector('.spinner-border')?.classList.toggle('d-none', !saving);
        el('redrawFieldCancel').disabled = saving;
        el('redrawFieldToolbar').querySelectorAll('input[name="redrawFieldMode"]').forEach((input) => { input.disabled = saving; });
        showRedrawState();
    }

    async function deleteFieldCrop(index) {
        const item = cropped[index];
        if (!item || !processedPage) return;
        usePageOf(index);
        try {
            const { response, payload } = await sendLineRequest(
                verifyLineUrl(config.lineRedrawUrl, item.lineId), 'POST', { needed: true },
            );
            if (!response.ok || !payload?.line) throw new Error(responseErrorMessage(response, payload));
            const fromHeight = validationRow(index)?.offsetHeight ?? 0;
            applyLineUpdate(payload.line, payload.flags ?? {});
            animateFieldChange(item.lineId, fromHeight);
            pushVerifyUndo({ kind: 'cropDeleted', id: item.lineId });
            const fresh = cropped.findIndex((candidate) => candidate.lineId === item.lineId);
            if (fresh >= 0) startRedrawField(fresh);
        } catch (error) {
            const button = validationRow(index)?.querySelector('.validation-field__delete-crop');
            if (button instanceof HTMLButtonElement) button.disabled = false;
            setValidationFieldError(index, error.message || 'The crop could not be deleted.');
        }
    }

    /**
     * Each deleted row's next step, in step with the Draw again bar: 'idle'
     * (Draw again), 'drawing' (draw a box on the page), 'ready' (Save and
     * re-read) or 'saving' (its spinner).
     */
    function showRedrawState() {
        el('verifyRows').querySelectorAll('.validation-field.is-crop-deleted').forEach((row) => {
            const item = cropped[Number(row.dataset.fieldIndex)];
            const current = item && item.lineId === redrawingLineId;
            const state = !current ? 'idle' : savingRedraw ? 'saving' : redrawBox ? 'ready' : 'drawing';
            row.dataset.redraw = state;
            const save = row.querySelector('.validation-field__redraw-save');
            if (save instanceof HTMLButtonElement) save.disabled = state === 'saving';
        });
    }

    /**
     * Ease a field's row from its old height to its new one and flash its new
     * state, so deleting (or putting back) a crop moves instead of jumping.
     * `fromHeight` is the row's height before the list was rebuilt.
     */
    function animateFieldChange(lineId, fromHeight) {
        const index = cropped.findIndex((candidate) => candidate.lineId === lineId);
        const row = index >= 0 ? validationRow(index) : null;
        if (!row || !fromHeight || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        row.classList.add('is-changing');
        const toHeight = row.offsetHeight;
        if (Math.abs(toHeight - fromHeight) > 1) {
            row.style.overflow = 'hidden';
            row.animate(
                [{ height: `${fromHeight}px` }, { height: `${toHeight}px` }],
                { duration: 360, easing: 'cubic-bezier(.22, .8, .24, 1)' },
            ).finished.catch(() => {}).finally(() => { row.style.overflow = ''; });
        }
        window.setTimeout(() => row.classList.remove('is-changing'), 700);
    }

    function startRedrawField(index) {
        const item = cropped[index];
        if (!processedPage || !item?.cropDeleted || recordSubmitting) return;
        stopAddingMissedField();
        stopGroupingFields();
        cancelOutlineEdit();
        usePageOf(index);
        redrawingLineId = item.lineId;
        redrawSide = verifySideOf(index).side;
        setRedrawBox(null);
        activateValidationField(index, 'marker');
        const group = validationGroupForField(index);
        el('redrawFieldName').textContent = group?.kind === 'person'
            ? `${item.heading || item.label} · ${group.label}`
            : (item.heading || item.label);
        slideToolbar(el('redrawFieldToolbar'), true);
        armRedrawDrawing();
        showRedrawState();
        // Bring its old outline into view once the bar has slid down.
        window.setTimeout(() => {
            if (redrawingLineId === item.lineId) revealValidationMarker(index);
        }, 300);
    }

    /** Close the tool; the field still waits to be drawn again. */
    function stopRedrawField() {
        if (redrawingLineId === null || savingRedraw) return;
        redrawingLineId = null;
        setRedrawBox(null);
        if (lineOverlay.isAddingNew()) lineOverlay.cancelEdit();
        slideToolbar(el('redrawFieldToolbar'), false);
        showRedrawState();
    }

    /** A box was closed on the page: it waits for Save and re-read; another drag replaces it. */
    function holdRedrawBox(polygon) {
        if (redrawingLineId === null || savingRedraw) return;
        setRedrawBox(polygon);
        armRedrawDrawing();
    }

    /** Save and re-read: the box is cropped, kept in the field's own person and column, and read. */
    async function saveRedrawnField() {
        const lineId = redrawingLineId;
        if (lineId === null || savingRedraw) return;
        // A drawing left open is the box.
        if (lineOverlay.isDrawing() && !lineOverlay.finishDrawing()) {
            showRedrawStatus('This drawing is not an outline yet: it needs at least 3 points, with some height and width.', true);
            return;
        }
        if (!redrawBox) {
            showRedrawStatus('Draw a box over the writing first.', true);
            return;
        }
        if (lineOverlay.isAddingNew()) lineOverlay.cancelEdit();
        usePageOfLine(lineId);
        setRedrawSaving(true);
        showRedrawStatus('Cropping the new box and reading it…');
        try {
            const { response, payload } = await sendLineRequest(
                verifyLineUrl(config.lineUpdateUrl, lineId), 'PUT', { polygon: redrawBox, keep_place: true },
            );
            if (!payload?.line) throw new Error(responseErrorMessage(response, payload));
            setRedrawSaving(false);
            stopRedrawField();
            const fromHeight = validationRow(cropped.findIndex((candidate) => candidate.lineId === lineId))?.offsetHeight ?? 0;
            applyLineUpdate(payload.line, payload.flags ?? {});
            animateFieldChange(lineId, fromHeight);
            if (!response.ok) {
                showOcrLineWarning(payload.line.id, payload.message || 'The box was saved, but TrOCR could not read it. Type the value.');
            }
        } catch (error) {
            setRedrawSaving(false);
            armRedrawDrawing();
            showRedrawStatus(error.message || 'The box could not be read. Draw it again.', true);
        }
    }

    // Delete crop (the button, or Delete / Backspace on the selected field)
    // asks first. The keys never act while typing in a box, so correcting a
    // typo never deletes a crop.
    let cropToDelete = null;

    function askToDeleteCrop(index) {
        const item = cropped[index];
        if (!item || item.cropDeleted || !processedPage) return;
        cropToDelete = item.lineId;
        const group = validationGroupForField(index);
        const name = item.heading || item.label;
        el('deleteCropModalDescription').textContent = `${name}${group?.kind === 'person' ? ` · ${group.label}` : ''} `
            + 'will have to be drawn again before you can submit. Ctrl+Z takes it back.';
        window.bootstrap.Modal.getOrCreateInstance(el('deleteCropModal')).show();
    }

    el('deleteCropModal').addEventListener('shown.bs.modal', () => el('deleteCropCancelBtn').focus());
    el('deleteCropModal').addEventListener('hidden.bs.modal', () => { cropToDelete = null; });
    el('deleteCropConfirmBtn').addEventListener('click', () => {
        const index = cropped.findIndex((item) => item.lineId === cropToDelete);
        window.bootstrap.Modal.getOrCreateInstance(el('deleteCropModal')).hide();
        if (index >= 0) deleteFieldCrop(index);
    });
    document.addEventListener('keydown', (event) => {
        if (!['Delete', 'Backspace'].includes(event.key) || event.ctrlKey || event.metaKey || event.altKey) return;
        if (!processedPage || el('step-verify').classList.contains('d-none')) return;
        if (addingMissedField || groupingFields || redrawingLineId !== null || editingLineIndex !== null || recordSubmitting) return;
        if (document.querySelector('.modal.show')) return;
        const target = event.target;
        if (target instanceof HTMLElement && (target.matches('input, textarea, select, button') || target.isContentEditable)) return;
        if (activeValidationIndex === null) return;
        event.preventDefault();
        askToDeleteCrop(activeValidationIndex);
    });

    el('redrawFieldCancel').addEventListener('click', stopRedrawField);
    el('redrawFieldSave').addEventListener('click', saveRedrawnField);
    document.querySelectorAll('input[name="redrawFieldMode"]').forEach((input) => {
        input.addEventListener('change', () => {
            if (!(input instanceof HTMLInputElement) || !input.checked) return;
            // A drawing of three points or more is kept as the box, not lost.
            if (lineOverlay.isDrawing() && lineOverlay.finishDrawing()) return;
            armRedrawDrawing();
            // Off the button, so Enter and Esc reach the drawing.
            input.blur();
        });
    });
    document.addEventListener('keydown', (event) => {
        if (redrawingLineId === null || savingRedraw) return;
        const target = event.target;
        if (target instanceof HTMLElement && (target.matches('input:not([type="radio"]), textarea, select') || target.isContentEditable)) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            // Esc drops a drawing, then the waiting box, then closes the tool.
            if (lineOverlay.isDrawing()) armRedrawDrawing();
            else if (redrawBox) {
                setRedrawBox(null);
                armRedrawDrawing();
            } else stopRedrawField();
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (lineOverlay.isDrawing()) {
                if (!lineOverlay.finishDrawing()) {
                    showRedrawStatus('This drawing is not an outline yet: it needs at least 3 points, with some height and width.', true);
                }
            } else if (redrawBox) {
                saveRedrawnField();
            }
        } else if ((event.ctrlKey || event.metaKey) && !event.shiftKey && event.key.toLowerCase() === 'z') {
            event.preventDefault();
            // Ctrl+Z takes back the newest thing first: a point of the drawing,
            // then the waiting box, then the deleted crop itself.
            if (lineOverlay.isDrawing() && lineOverlay.undoDrawStep()) return;
            if (redrawBox) {
                setRedrawBox(null);
                armRedrawDrawing();
                return;
            }
            undoVerifyChange();
        }
    });

    // ------------------------------------------------------ group fields
    // Pick fields (on the page or in the list) and make them one new person,
    // each keeping its column. The person can be named; every person can.

    let groupingFields = false;
    let groupingBusy = false;
    // By line id, so a pick survives the list being rebuilt.
    const pickedLineIds = new Set();

    /**
     * Any field can join a person on a page with a table: a table field, or a
     * layout box's field (Province, Year…). Without a table there are no people.
     */
    function canJoinPerson(item) {
        const page = entryOfItem(item).page;
        const columns = page?.geometry?.columns ?? [];
        return Boolean(item) && columns.length > 0 && tableRowsOf(page) > 0;
    }

    /** The page (and its layout) a field is on, as docEntries() has it. */
    function entryOfItem(item) {
        return docEntries()[verifyDoc ? item?.pageIndex ?? 0 : 0];
    }

    /** A layout box's field: it joins a person by its person alone, never as a cell of the table. */
    const isLayoutBoxField = (item) => ['template', 'field'].includes(item?.source);

    /** The column a field keeps when it moves: one on the page, one removed in Align, or none. */
    function columnPlacementOf(item) {
        if (item.gridColumn !== null) return { column_index: item.gridColumn };
        const { page, layout } = entryOfItem(item);
        const onPage = new Set((page?.geometry?.columns ?? []).map((column) => nameKey(column.name)));
        const removedInAlign = layout.boxes.some((box) => (
            box.kind === 'column' && !onPage.has(nameKey(box.name)) && nameKey(box.name) === nameKey(item.label)
        ));
        return removedInAlign ? { column_name: item.label } : {};
    }

    function showGroupFieldsStatus(message, isError = false) {
        const status = el('groupFieldsStatus');
        status.textContent = message;
        status.title = message;
        status.classList.toggle('is-error', isError);
    }

    /** The picks on the page and in the list, and the strip's count and button. */
    function showPickedFields() {
        const indexes = cropped
            .map((item, index) => (pickedLineIds.has(item.lineId) ? index : null))
            .filter((index) => index !== null);
        lineOverlay.setPicked(indexes);
        el('verifyRows').querySelectorAll('.validation-field').forEach((row) => {
            const item = cropped[Number(row.dataset.fieldIndex)];
            row.classList.toggle('is-picked', Boolean(item && pickedLineIds.has(item.lineId)));
        });
        // Each group header says how many of its fields are picked.
        el('verifyRows').querySelectorAll('.validation-record-group').forEach((section) => {
            const group = validationGroups.find((candidate) => candidate.id === section.dataset.groupId);
            const count = group ? group.indexes.filter((index) => pickedLineIds.has(cropped[index]?.lineId)).length : 0;
            section.classList.toggle('has-picked', count > 0);
            const title = section.querySelector('.validation-record-group__copy strong');
            if (title) title.dataset.picked = count > 0 ? `${count} picked` : '';
        });

        const count = indexes.length;
        el('groupFieldsCount').textContent = count === 0 ? 'No fields picked' : `${count} field${count === 1 ? '' : 's'} picked`;
        el('groupFieldsApply').disabled = count === 0 || groupingBusy;
        if (!groupingBusy) {
            showGroupFieldsStatus(count === 0
                ? 'Click the fields of one person on the page or in the list. Click again to take one away.'
                : 'Pick more, or press Group as person (Enter). Esc cancels.');
        }
    }

    function togglePickedField(index) {
        const item = cropped[index];
        if (!item || groupingBusy) return;
        if (!canJoinPerson(item)) {
            if (!groupingFields) startGroupingFields();
            showGroupFieldsStatus('This page has no table, so there are no people to group fields into.', true);
            return;
        }
        if (!groupingFields) startGroupingFields();
        // A new person is a row on one page: pick one page's fields at a time.
        // Row numbers join the pages, so the other page's fields can follow.
        const otherPage = verifyDoc && !pickedLineIds.has(item.lineId)
            && cropped.some((other) => pickedLineIds.has(other.lineId) && other.pageIndex !== item.pageIndex);
        if (otherPage) {
            showGroupFieldsStatus('Pick one page’s fields at a time: group these, then the other page’s.', true);
            return;
        }
        if (pickedLineIds.has(item.lineId)) pickedLineIds.delete(item.lineId);
        else pickedLineIds.add(item.lineId);
        showPickedFields();
    }

    function startGroupingFields() {
        if (!processedPage || recordSubmitting || groupingFields) return;
        stopAddingMissedField();
        stopRedrawField();
        cancelOutlineEdit();
        groupingFields = true;
        el('groupFieldsBtn').classList.add('active');
        el('groupFieldsBtn').setAttribute('aria-pressed', 'true');
        el('groupFieldsName').value = '';
        slideToolbar(el('groupFieldsToolbar'), true);
        el('validationFieldOverlay').classList.add('is-picking');
        el('validationCompanionOverlay').classList.toggle('is-picking', verifyDoc !== null);
        showPickedFields();
    }

    /** Close the strip; nothing picked is kept. */
    function stopGroupingFields() {
        if (!groupingFields || groupingBusy) return;
        groupingFields = false;
        pickedLineIds.clear();
        el('groupFieldsBtn').classList.remove('active');
        el('groupFieldsBtn').setAttribute('aria-pressed', 'false');
        slideToolbar(el('groupFieldsToolbar'), false);
        el('validationFieldOverlay').classList.remove('is-picking');
        el('validationCompanionOverlay').classList.remove('is-picking');
        showPickedFields();
    }

    function setGroupingBusy(busy) {
        groupingBusy = busy;
        const button = el('groupFieldsApply');
        button.disabled = busy || pickedLineIds.size === 0;
        button.querySelector('.bx-group')?.classList.toggle('d-none', busy);
        button.querySelector('.spinner-border')?.classList.toggle('d-none', !busy);
        el('groupFieldsCancel').disabled = busy;
        el('groupFieldsName').disabled = busy;
    }

    /**
     * Group as person: the first field starts a new person (a new row past the
     * table's last), the others join it. Each keeps its column; what was typed
     * and ticked stays. One Ctrl+Z puts them all back.
     */
    async function groupPickedFields() {
        if (groupingBusy || !processedPage) return;
        // Table fields first, left to right, then the layout boxes' fields.
        const items = cropped
            .filter((item) => pickedLineIds.has(item.lineId) && canJoinPerson(item))
            .sort((a, b) => Number(isLayoutBoxField(a)) - Number(isLayoutBoxField(b)) || a.x - b.x || a.y - b.y);
        if (items.length === 0) return;
        // The picks are all on one page (see togglePickedField): its row is the new person.
        const page = usePageOfLine(items[0].lineId);

        setGroupingBusy(true);
        showGroupFieldsStatus(`Grouping ${items.length} field${items.length === 1 ? '' : 's'}…`);
        const name = el('groupFieldsName').value.trim();
        const moved = [];
        const before = [];
        let flags = {};
        let newRow = null;
        let failure = null;
        for (const item of items) {
            const boxField = isLayoutBoxField(item);
            // A layout box's field keeps its name: only its person changes.
            const column = boxField ? {} : columnPlacementOf(item);
            try {
                const { response, payload } = await sendLineRequest(
                    verifyLineUrl(config.linePlaceUrl, item.lineId), 'PATCH',
                    newRow === null ? { new_person: true, ...column } : { row: newRow, ...column },
                );
                if (!response.ok || !payload?.line) throw new Error(responseErrorMessage(response, payload));
                newRow ??= boxField ? payload.line.personGroup : payload.line.row;
                moved.push(payload.line);
                flags = payload.flags ?? flags;
                if (boxField) {
                    // Ctrl+Z: back to the person it had (none: the page's details).
                    before.push({
                        id: item.lineId,
                        boxField: true,
                        person: Number.isInteger(item.personGroup) ? item.personGroup : null,
                        order: Number.isInteger(item.personFieldOrder) ? item.personFieldOrder : null,
                    });
                } else if (item.gridRow !== null) before.push({ id: item.lineId, row: item.gridRow, column });
            } catch (error) {
                failure = error.message || 'A field could not be moved.';
                break;
            }
        }

        setGroupingBusy(false);
        processedPage = page;
        if (moved.length > 0) {
            const byId = new Map(moved.map((line) => [line.id, line]));
            if (name && newRow !== null) personNameOverrides.set(personNameKey({ id: `person-${newRow}` }), name);
            groupingFields = true; // still open while the list is rebuilt
            pickedLineIds.clear();
            rebuildVerifyLines(processedPage.lines.map((line) => byId.get(line.id) ?? line), flags,
                // 'marker': the list scrolls to the new person, at its end.
                { focusLineId: moved[0].id, focusFrom: 'marker' });
            if (before.length > 0) pushVerifyUndo({ kind: 'grouped', moves: before });
        }
        if (failure) {
            showGroupFieldsStatus(moved.length > 0
                ? `${moved.length} of ${items.length} fields were grouped; then: ${failure}`
                : failure, true);
            return;
        }
        stopGroupingFields();
    }

    /** The person's name at the top of their fields: what Verify calls them. */
    function personNameControl(group, section) {
        const wrap = document.createElement('div');
        wrap.className = 'validation-record-group__name';
        const inputId = `personName-${group.id}`;
        wrap.innerHTML = `
            <label for="${inputId}">Name</label>
            <input type="text" class="form-control form-control-sm" id="${inputId}" maxlength="120" autocomplete="off">
            <small></small>`;
        const input = requiredInput(wrap, 'input');
        const note = requiredPart(wrap, 'small');
        const automatic = automaticIdentity(group);
        const key = personNameKey(group);
        input.value = personNameOverrides.get(key) ?? '';
        input.placeholder = /^\d+ fields?$/.test(automatic) ? 'Type a name' : automatic;
        const showNote = () => {
            note.textContent = input.value.trim() ? 'Named by you' : 'From the page';
        };
        showNote();

        const apply = () => {
            const value = input.value.trim();
            if (value) personNameOverrides.set(key, value);
            else personNameOverrides.delete(key);
            showNote();
            // The header and every Person list show the new name at once.
            const shown = groupIdentity(group);
            const header = section.querySelector('.validation-record-group__copy small');
            if (header) header.textContent = shown;
            const row = Number(cropped[group.indexes[0]]?.personGroup);
            const number = String(row).padStart(2, '0');
            el('verifyRows').querySelectorAll(`select[aria-label^="Person"] option[value="${row}"]`).forEach((option) => {
                option.textContent = /^\d+ fields?$/.test(shown) ? number : `${number} · ${shown}`;
            });
        };
        input.addEventListener('click', (event) => event.stopPropagation());
        input.addEventListener('change', apply);
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                input.blur();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                input.value = personNameOverrides.get(key) ?? '';
                input.blur();
            }
        });
        return wrap;
    }

    el('groupFieldsBtn').addEventListener('click', () => (groupingFields ? stopGroupingFields() : startGroupingFields()));
    el('groupFieldsCancel').addEventListener('click', stopGroupingFields);
    el('groupFieldsApply').addEventListener('click', groupPickedFields);
    el('groupFieldsName').addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            groupPickedFields();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (!groupingFields || groupingBusy) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            stopGroupingFields();
            return;
        }
        const target = event.target;
        if (target instanceof HTMLElement && target.matches('input, textarea, select')) return;
        if (event.key === 'Enter' && pickedLineIds.size > 0) {
            event.preventDefault();
            groupPickedFields();
        }
    });

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
            // Only changes to the page(s) being verified can be taken back.
            const pageIds = new Set(docEntries().map((entry) => entry.page?.id));
            while (verifyUndo.length > 0 && !pageIds.has(verifyUndo[verifyUndo.length - 1].pageId)) verifyUndo.pop();
            const entry = verifyUndo.pop();
            if (!entry) return;
            // Taken back on the page it was made on.
            processedPage = docEntries().find((candidate) => candidate.page?.id === entry.pageId)?.page ?? processedPage;

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
            } else if (entry.kind === 'grouped') {
                // Every field back to the person and column it came from.
                const lines = new Map();
                let flags = {};
                for (const move of entry.moves) {
                    let body = { row: move.row, ...move.column };
                    if (move.boxField) {
                        body = move.person === null
                            ? { page_details: true }
                            : { row: move.person, ...(move.order === null ? {} : { field_order: move.order }) };
                    }
                    const { response, payload } = await sendLineRequest(
                        verifyLineUrl(config.linePlaceUrl, move.id), 'PATCH', body,
                    );
                    if (response.ok && payload?.line) {
                        lines.set(payload.line.id, payload.line);
                        flags = payload.flags ?? flags;
                    }
                }
                rebuildVerifyLines(processedPage.lines.map((line) => lines.get(line.id) ?? line), flags,
                    { focusLineId: entry.moves[0]?.id ?? null });
            } else if (entry.kind === 'cropDeleted') {
                // The old crop and reading come back, unless it was drawn again since.
                if (!processedPage.lines.some((line) => line.id === entry.id && (line.flags ?? []).includes(FLAG_REDRAW))) return;
                if (redrawingLineId === entry.id) stopRedrawField();
                const { response, payload } = await sendLineRequest(verifyLineUrl(config.lineRedrawUrl, entry.id), 'POST', { needed: false });
                if (!response.ok || !payload?.line) throw new Error(responseErrorMessage(response, payload));
                const fromHeight = validationRow(cropped.findIndex((candidate) => candidate.lineId === entry.id))?.offsetHeight ?? 0;
                applyLineUpdate(payload.line, payload.flags ?? {});
                animateFieldChange(entry.id, fromHeight);
                } else if (entry.kind === 'rowsMoved') {
                    // A page's part of a row back to the person it was on (a swap swaps back).
                    const back = entry.moves.map(({ from, to }) => ({ from: to, to: from }));
                    const { response, payload } = await sendLineRequest(
                        config.pageRowsUrl.replace('__PAGE__', String(processedPage.id)), 'PATCH', { moves: back },
                    );
                    if (!response.ok || !Array.isArray(payload?.lines)) throw new Error(responseErrorMessage(response, payload));
                    rebuildVerifyLines(payload.lines, payload.flags ?? {});
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
        if (addingMissedField || redrawingLineId !== null || !processedPage || el('step-verify').classList.contains('d-none')) return;
            if (!(event.ctrlKey || event.metaKey) || event.shiftKey || event.altKey || event.key.toLowerCase() !== 'z') return;
            const target = event.target;
            // Typing keeps the browser's own undo.
            if (target instanceof HTMLElement && (target.matches('input:not([type="checkbox"]):not([type="radio"]), textarea, select') || target.isContentEditable)) return;
            if (lineOverlay.editing || verifyUndo.length === 0) return;
            event.preventDefault();
            undoVerifyChange();
        });

        /**
     * A field the reviewer added, or one that fell between two rows, while it
     * belongs to no person yet: it can be removed outright. Once it joins a
     * person it is like every other field there (Delete crop, no Remove).
     */
    function isRemovableField(item) {
        if (!item || item.gridRow !== null) return false;
        return item.source === SOURCE_MANUAL || Boolean(item.flags?.includes(FLAG_NO_ROW));
    }

    /**
     * Person and Column under every field of a table, to say whose it is and
     * which column it is; Remove too under a field the reviewer added or one
     * that fell between two rows. Fields of a layout box have neither.
         */
        function fieldPlacementControls(index) {
            const item = cropped[index];
        if (!item) return null;
        const removable = isRemovableField(item);

            // The field's own page: its table, and its layout's columns.
            const itemPage = pageOfItem(index);
            const itemLayout = layoutOfItem(index);
            const columns = itemPage?.geometry?.columns ?? [];
            const rows = tableRowsOf(itemPage);
            const ledger = columns.length > 0 && rows > 0 && !['template', 'field'].includes(item.source);
        if (!ledger && !removable) return null;

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
                const lastRow = Math.max(rows, ...cropped
                    .filter((other) => (other.pageIndex ?? 0) === (item.pageIndex ?? 0))
                    .map((other) => other.gridRow ?? 0));
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
                const removed = itemLayout.boxes
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

        if (!removable) return controls;

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
                lineOverlay.setEditMode(input.value);
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
            const sideOf = (index) => cropped[index]?.pageIndex ?? 0;
            // Each page's layout, table and columns (one page, or a register's two).
            const pages = docEntries().map((entry, side) => ({
                entry,
                side,
                requiredColumns: columnsOnPage(entry).filter((box) => box.required !== false),
                tableRows: tableRowsOf(entry.page),
                shortened: shortenedColumnRows(entry.page?.geometry),
            }));

            const missing = pages.flatMap(({ entry, side }) => {
                const checkedNames = new Set(verifiedIndexes
                    .filter((index) => sideOf(index) === side)
                    .map((index) => nameKey(cropped[index]?.label)));
                return entry.layout.boxes
                    .filter((box) => box.kind !== 'column' && box.required !== false && !checkedNames.has(nameKey(box.name)))
                    .map((box) => pageNamed(entry, box.name));
            });

            const untouchedRows = [];
            validationGroups.forEach((group) => {
                const row = Number(cropped[group.indexes[0]]?.personGroup);
                // A person started by hand (past the last row) has no register row to complete.
                const ledgerRow = group.kind === 'person'
                    && pages.some((page) => row <= page.tableRows)
                    && group.indexes.some((index) => settingsFor(index)?.kind === 'column');
                if (!ledgerRow) return;

                const checkedHere = group.indexes.filter((index) => checkedIndexes.has(index));
                if (checkedHere.length === 0) {
                    untouchedRows.push(row);
                    return;
                }

                const left = pages.flatMap((page) => {
                    if (row > page.tableRows) return [];
                    const columnsHere = new Set(checkedHere
                        .filter((index) => sideOf(index) === page.side)
                        .map((index) => nameKey(cropped[index]?.label)));
                    // A column shortened in Align is not asked for in the rows it leaves out.
                    return page.requiredColumns
                        .filter((box) => !columnsHere.has(nameKey(box.name)) && (page.shortened.get(nameKey(box.name))?.has(row) ?? true))
                        .map((box) => pageNamed(page.entry, box.name));
                });
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
            submitRecord({ ...lastSubmitChoices, allowMissing: true });
        });

        el('submitForm').addEventListener('submit', (event) => {
            event.preventDefault();
            submitRecord();
        });

        // ------------------------------------------------ a page saved before
        // The server says this page of this file (or this file) is saved already.
        function askToSaveAgain(payload) {
            el('duplicatePageModalDescription').textContent = payload?.message || 'This page is already saved.';
            const link = el('duplicatePageRecordLink');
            link.href = payload?.record?.url || '#';
            link.classList.toggle('d-none', !payload?.record?.url);
            if (payload?.record) {
                // The page the server found saved: of two pages, either one.
                savedPages.set(payload.record.page ?? scanPageNumber, payload.record);
                updateScanNotices();
            }
            window.bootstrap.Modal.getOrCreateInstance(el('duplicatePageModal')).show();
        }

        // The safe choice has the focus, so Enter never saves a page twice by accident.
        el('duplicatePageModal').addEventListener('shown.bs.modal', () => el('duplicatePageBackBtn').focus());

        el('confirmDuplicatePageBtn').addEventListener('click', () => {
            window.bootstrap.Modal.getInstance(el('duplicatePageModal'))?.hide();
            submitRecord({ ...lastSubmitChoices, allowDuplicate: true });
        });

        // ------------------------------------------------ the next page of a PDF
        const SUBMIT_IDLE_HTML = el('submitBtn').innerHTML;

        function askForNextPage(next, recordUrl, savedNow = [scanPageNumber]) {
            const saved = [...savedPages.keys()].filter((page) => page <= scanPageCount);
            el('nextPageModalTitle').textContent = savedNow.length > 1
                ? `Pages ${pageRanges(savedNow)} saved as one record`
                : `Page ${savedNow[0]} saved`;
            el('nextPageModalDescription').textContent = `Continue with page ${next} of ${scanPageCount}?`;
            el('nextPageModalProgress').textContent = `Saved so far: page${saved.length === 1 ? '' : 's'} ${pageRanges(saved)} of ${scanPageCount}.`;
            el('nextPageOpenRecordBtn').href = recordUrl;
            el('nextPageContinueBtn').dataset.page = String(next);
            window.bootstrap.Modal.getOrCreateInstance(el('nextPageModal')).show();
        }

        el('nextPageModal').addEventListener('shown.bs.modal', () => el('nextPageContinueBtn').focus());

        /**
         * Go on with another page of the same PDF once one is saved: Align starts
         * from a copy of the saved page's markers (or that page's own, if it was
         * opened before, or page 2's layout), and the file is not uploaded again.
         */
        async function continueWithPage(pageNumber) {
            const button = el('nextPageContinueBtn');
            button.disabled = true;
            try {
                stopAddingMissedField();
                cancelOutlineEdit();
                clearValidationSubmitError();
                clearDetection();
                processedPage = null;
                verifyDoc = null;
                cropped = [];
                readings = [];
                requiredInput(document, '#documentPageId').value = '';
                requiredInput(document, '#ocrModelKey').value = '';

                const left = scanPageNumber;
                savePageLayout(left);
                pageDetections.delete(left);
                // Smoke test may have been over this page with the one just saved.
                const tested = storedDetection(pageNumber);
                pageDetections.delete(pageNumber);
                if (tested) await marker.loadFromUrl(tested.imageUrl);
                else await marker.load(scanFile, { page: pageNumber });
                scanPageNumber = pageNumber;
                useLayoutForPage(pageNumber);
                recordSubmitting = false;
                el('submitBtn').innerHTML = SUBMIT_IDLE_HTML;
                updateScanNotices();
                showScanContext();
                showStep('mark');
                resetFieldHistory();
                showPageLayout(pageNumber, { copyFrom: left });
                if (tested) await applyDetection(tested, { imageShown: true });
                fetchSnapLines();
                updateSpread();
                window.bootstrap.Modal.getInstance(el('nextPageModal'))?.hide();
                window.requestAnimationFrame(() => marker.resetZoom());
            } catch (error) {
                console.error('The next page could not be opened:', error);
                // The page just saved is safe: show its record.
                window.location.assign(el('nextPageOpenRecordBtn').href);
            } finally {
                button.disabled = false;
            }
        }

        el('nextPageContinueBtn').addEventListener('click', () => continueWithPage(Number(el('nextPageContinueBtn').dataset.page)));

        /**
         * What the record keeps of the file: of a PDF of many pages, the page
         * being read alone, cut out with its original quality; any other file as
         * it is. A PDF that cannot be cut (one locked with a password) goes whole
         * when it is small enough, and the record still knows its page.
         */
        async function scanForRecord(pageNumber = scanPageNumber) {
            if (!multiPage() || !isPdfFile(scanFile)) return scanFile;
            try {
                return await pdfPageFile(scanFile, pageNumber);
            } catch (error) {
                console.warn('The page could not be cut out of the PDF:', error);
                if (scanFile.size <= MAX_SCAN_BYTES) return scanFile;
                throw new Error(`Page ${pageNumber} could not be taken out of this PDF. Save that page as its own file and upload it.`);
            }
        }

        // What the user last chose to go ahead with, so answering one question
        // ("Submit anyway", "Save again") keeps the answer to the other.
        let lastSubmitChoices = { allowMissing: false, allowDuplicate: false };

        // Only "Submit anyway" passes allowMissing, and only "Save again"
        // allowDuplicate, after the same checks again.
        async function submitRecord({ allowMissing = false, allowDuplicate = false } = {}) {
            if (recordSubmitting) return;
            lastSubmitChoices = { allowMissing, allowDuplicate };

            clearValidationSubmitError();
            const rows = [...el('verifyRows').querySelectorAll('.validation-field')];
            rows.forEach((row) => clearValidationFieldError(Number(row.dataset.fieldIndex)));

        // A deleted crop must be drawn again and read before anything is submitted.
        const toRedraw = cropped.map((item, index) => (item.cropDeleted ? index : null)).filter((index) => index !== null);
        if (toRedraw.length > 0) {
            showValidationSubmitError(toRedraw.length === 1
                ? 'One field has a deleted crop. Draw it again before submitting.'
                : `${toRedraw.length} fields have a deleted crop. Draw them again before submitting.`);
            toRedraw.forEach((index) => setValidationFieldError(index, 'Press Draw again and mark this field on the page.'));
            activateValidationField(toRedraw[0], 'marker');
            return;
        }

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
            // The layout this page was aligned and read with (page 2 may have its own).
            data.set('document_template_id', String(config.templateId));
            const currentBatchItem = batchState.active ? batchState.items[batchState.currentIndex] : null;
            if (currentBatchItem?.workspaceScanPath) {
                data.set('workspace_scan_path', currentBatchItem.workspaceScanPath);
            } else {
                data.set('scan', scanFile, scanFile.name);
            }
            if (allowMissing) data.set('allow_missing', '1');
            if (allowDuplicate) data.set('allow_duplicate', '1');
            // Which file, and which of its pages, this record is.
            data.set('source_name', scanFile.name);
            data.set('source_page', String(scanPageNumber));
            data.set('source_page_count', String(scanPageCount));
            if (scanFingerprint) data.set('source_hash', scanFingerprint);

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

            // A two-page register read as one document: one record, its first
            // page's fields as the record's own and the second page's as its
            // page 2. A person keeps one number on both pages.
            let combined = false;
            let spreadPages = null;
            if (verifyDoc) {
                const [first, second] = verifyDoc.pages;
                const pageFields = [[], []];
                verifiedIndexes.forEach((sourceIndex, position) => {
                    pageFields[cropped[sourceIndex]?.pageIndex ?? 0].push(submittedFields[position]);
                });
                const unchecked = verifyDoc.pages.find((_, side) => pageFields[side].length === 0);
                if (unchecked) {
                    showValidationSubmitError(`Check Verified on at least one field of page ${unchecked.number}.`);
                    return;
                }
                // Field names stay unique in the record: a name both pages have gets its page.
                const firstNames = new Set(pageFields[0].map((field) => String(field.name).toLocaleLowerCase()));
                const secondFields = pageFields[1].map((field) => (firstNames.has(String(field.name).toLocaleLowerCase())
                    ? { ...field, name: `${field.name} · page ${second.number}` }
                    : field));
                data.set('fields_json', JSON.stringify(pageFields[0]));
                data.set('document_template_id', String(first.layout.templateId));
                data.set('document_page_id', String(first.page.id));
                data.set('ocr_model_key', (first.page.modelKey || '').trim());
                data.set('source_page', String(first.number));
                data.set('extra_pages_json', JSON.stringify([{
                    document_page_id: second.page.id,
                    source_page: second.number,
                    fields: secondFields,
                }]));
                spreadPages = verifyDoc.pages.map((entry) => entry.number);
                combined = true;
            }
            const documentPages = !spreadPages && currentBatchItem?.group && currentBatchItem.pageTotal > 1
                ? batchState.items.filter((it) => it.group === currentBatchItem.group).sort((a, b) => a.pageNo - b.pageNo)
                : [];
            if (documentPages.length > 1) {
                const rowMarker = /· row (\d+)/;
                const parsedRows = submittedFields.map((field) => Number(String(field.name).match(rowMarker)?.[1] ?? 0));
                currentBatchItem.staged = {
                    fields: submittedFields,
                    pageId: processedPage.id,
                    modelKey: (processedPage.modelKey || '').trim(),
                    rowSpan: Math.max(Math.max(0, (processedPage.geometry?.ruled_ys?.length ?? 0) - 1), ...parsedRows),
                    personCount: personGroups.length,
                };

                const waiting = documentPages.find((it) => !it.staged);
                if (waiting) {
                    currentBatchItem.status = 'checked';
                    renderBatchQueueBanner();
                    await openBatchVerifyItem(batchState.items.indexOf(waiting));
                    return;
                }

                // Pages after the first carry on the row and person numbers of the page before,
                // so the record reads as one table with unique field names.
                let rowOffset = 0;
                let personOffset = 0;
                const ordered = documentPages.map((item) => {
                    const fields = item.staged.fields.map((field) => {
                        let name = String(field.name);
                        if (item.pageNo > 1) {
                            name = rowMarker.test(name)
                                ? name.replace(rowMarker, (_, row) => `· row ${Number(row) + rowOffset}`)
                                : `${name} · page ${item.pageNo}`;
                        }
                        return {
                            ...field,
                            name,
                            person_group: field.person_group == null ? null : field.person_group + personOffset,
                        };
                    });
                    rowOffset += item.staged.rowSpan;
                    personOffset += item.staged.personCount;
                    return { item, fields };
                });

                const [first, ...others] = ordered;
                data.set('fields_json', JSON.stringify(first.fields));
                // The record is page 1's layout; the server reads the other pages with its page 2 layout.
                data.set('document_template_id', String(mainLayout.templateId));
                data.set('document_page_id', String(first.item.staged.pageId));
                data.set('ocr_model_key', first.item.staged.modelKey);
                data.set('workspace_scan_path', first.item.workspaceScanPath ?? '');
                data.set('source_name', first.item.sourceName ?? scanFile.name);
                data.set('source_page', String(first.item.pageNo));
                data.set('source_page_count', String(first.item.pageTotal));
                data.set('extra_pages_json', JSON.stringify(others.map(({ item, fields }) => ({
                    document_page_id: item.staged.pageId,
                    workspace_scan_path: item.workspaceScanPath,
                    source_page: item.pageNo,
                    fields,
                }))));
                combined = true;
            }

            const button = el('submitBtn');
            const idleButton = button.innerHTML;
            const controller = new AbortController();
            const timeoutId = window.setTimeout(() => controller.abort(), 120000);
            let submissionSucceeded = false;
            recordSubmitting = true;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Submitting verified fields...';

            try {
                if (spreadPages) {
                    // Each page as its own file, cut out of the PDF.
                    const [firstScan, secondScan] = await Promise.all(spreadPages.map((page) => scanForRecord(page)));
                    data.set('scan', firstScan, firstScan.name);
                    data.set('extra_scans[0]', secondScan, secondScan.name);
                } else if (!currentBatchItem?.workspaceScanPath) {
                    const scanUpload = await scanForRecord();
                    data.set('scan', scanUpload, scanUpload.name);
                }

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
                    if (response.status === 409 && payload?.existing_record) {
                        checkAndShowExistingRecord({
                            id: payload.record_id,
                            title: payload.record_title || 'Existing Record',
                            url: payload.record_url || null,
                        });
                        return;
                    }
                    // This page of this file is saved already: nothing was saved,
                    // so ask whether to save it again.
                    if (response.status === 409 && payload?.duplicate_page) {
                        askToSaveAgain(payload);
                        return;
                    }
                    // The server found required fields missing that this page did
                    // not: nothing was saved, so ask the same question.
                    if (response.status === 422 && Array.isArray(payload?.errors?.missing_required)) {
                        askToSubmitWithoutRequired(payload.errors.missing_required);
                        return;
                    }
                    // Field numbers of a document sent as one record do not match this page's.
                    if (!combined) applyServerFieldErrors(payload?.errors, verifiedIndexes);
                    throw new Error(submissionErrorMessage(response, payload));
                }

                if (!payload?.redirect) {
                    throw new Error('The record was saved, but the archive location was not returned. Open Records Archive to confirm it.');
                }

                if (batchState.active && currentBatchItem) {
                    (combined ? documentPages : [currentBatchItem]).forEach((item) => {
                        item.status = 'imported';
                        item.recordUrl = payload.redirect;
                        item.staged = null;
                        item.verifyDraft = null;
                    });
                    renderBatchQueueBanner();

                    const nextVerifyIndex = batchState.items.findIndex((it, idx) => idx > batchState.currentIndex && !['imported', 'rejected'].includes(it.status));
                    const firstRemainingIndex = batchState.items.findIndex((it) => !['imported', 'rejected'].includes(it.status));
                    const targetVerifyIndex = nextVerifyIndex !== -1 ? nextVerifyIndex : firstRemainingIndex;

                    if (targetVerifyIndex !== -1) {
                        recordSubmitting = false;
                        button.innerHTML = idleButton;
                        await openBatchVerifyItem(targetVerifyIndex);
                        return;
                    }
                }

                const savedNow = spreadPages ?? [scanPageNumber];
                if (payload.record) savedNow.forEach((page) => savedPages.set(page, { ...payload.record, page }));
                submissionSucceeded = true;

                // A PDF with pages still to save: offer the next one. The page just
                // saved stays locked meanwhile (recordSubmitting), so it is not sent twice.
                const next = !batchState.active && multiPage()
                    ? nextUnsavedPage(Math.max(...savedNow), scanPageCount, savedPages)
                    : null;
                if (next !== null) {
                    button.innerHTML = '<i class="icon-base bx bx-check me-2" aria-hidden="true"></i>Saved';
                    askForNextPage(next, payload.redirect, savedNow);
                    return;
                }

                button.innerHTML = '<i class="icon-base bx bx-check me-2" aria-hidden="true"></i>Saved. Opening record...';
                window.location.assign(payload.redirect);
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