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
                                {{-- What to do now, and how far the batch is: "Align each page · 3 of 36 queued". --}}
                                <div class="batch-queue-banner__info">
                                    <strong class="batch-queue-banner__heading" id="batchQueuePhaseTitle">Preparing the batch</strong>
                                    <span class="batch-queue-banner__sub" id="batchQueueCounter"></span>
                                </div>
                                <div class="batch-queue-banner__actions">
                                    {{-- The queue, collapsed to where the user is; opens the panel below. --}}
                                    <button type="button" class="btn btn-sm btn-label-secondary batch-queue-toggle" id="batchQueueToggle"
                                        aria-expanded="false" aria-controls="batchQueuePanel" title="Show every file and page in this batch">
                                        <i class="icon-base bx bx-list-check icon-sm" aria-hidden="true"></i>
                                        <span class="batch-queue-toggle__num d-none" id="batchQueueToggleNum"></span>
                                        <span class="batch-queue-toggle__text" id="batchQueueToggleText">Queue</span>
                                        <span class="batch-queue-toggle__pages d-none" id="batchQueueTogglePages"></span>
                                        <i class="icon-base bx bx-chevron-down icon-sm batch-queue-toggle__chevron" aria-hidden="true"></i>
                                    </button>
                                    {{-- More files for this batch, while pages can still be aligned. --}}
                                    <div class="btn-group btn-group-sm d-none" role="group" id="batchAddMoreGroup"
                                        aria-label="Add to this batch">
                                        <button type="button" class="btn btn-sm btn-outline-primary text-nowrap" id="batchAddMoreBtn"
                                            title="Add more files to this batch: their pages go after the queue">
                                            <i class="icon-base bx bx-plus icon-sm me-1" aria-hidden="true"></i><span
                                                id="batchAddMoreLabel">Add files</span>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle dropdown-toggle-split"
                                            id="batchAddMoreMenuBtn" data-bs-toggle="dropdown" aria-expanded="false"
                                            aria-label="More ways to add"></button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <button type="button" class="dropdown-item" id="batchAddFolderBtn">
                                                    <i class="icon-base bx bx-archive icon-sm me-2" aria-hidden="true"></i>Add a folder
                                                </button>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            {{-- Pages that could not be read: the batch goes on, and each can be re-aligned or removed. --}}
                            <div class="batch-queue-banner__notice d-none" id="batchQueueNotice" role="status">
                                <i class="icon-base bx bx-error-circle" aria-hidden="true"></i>
                                <span id="batchQueueNoticeText"></span>
                                <button type="button" class="btn btn-sm btn-label-danger text-nowrap" id="batchQueueNoticeBtn">
                                    Open it
                                </button>
                            </div>
                            {{-- A resumed batch: pages whose file has to be picked again. --}}
                            <div class="batch-queue-banner__notice is-info d-none" id="batchFilesNotice" role="status">
                                <i class="icon-base bx bx-folder-open" aria-hidden="true"></i>
                                <span id="batchFilesNoticeText"></span>
                                <button type="button" class="btn btn-sm btn-label-primary text-nowrap" id="batchFilesNoticeBtn">
                                    Pick files
                                </button>
                            </div>
                            {{-- How far the batch is, as a thin line along the bottom of the bar. --}}
                            <div class="batch-queue-progress" id="batchQueueProgressTrack" role="progressbar"
                                aria-label="Batch progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                                <div class="batch-queue-progress__bar" id="batchQueueProgressBar"></div>
                                {{-- Save & finish batch: the records saved so far, over the ones that are Done. --}}
                                <div class="batch-queue-progress__saved" id="batchQueueProgressSaved"></div>
                            </div>
                            {{-- Every file of the batch; the one expanded lists its pages. It floats over
                                 the page (it does not push it down), and slides open and shut. --}}
                            <div class="batch-queue-panel" id="batchQueuePanel" inert>
                                {{-- Only the tabs that have pages in them; none at all when All is the only one. --}}
                                <div class="batch-queue-panel__head" id="batchQueueHead">
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Show pages" id="batchQueueFilters">
                                        <button type="button" class="btn btn-sm btn-outline-secondary active" data-queue-filter="all"
                                            aria-pressed="true">All<span class="batch-queue-filter__count"></span></button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-queue-filter="attention"
                                            aria-pressed="false" title="Pages that failed, or that Snap could not fit">Needs attention<span
                                                class="batch-queue-filter__count"></span></button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-queue-filter="pending"
                                            aria-pressed="false">Pending<span class="batch-queue-filter__count"></span></button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-queue-filter="saved"
                                            aria-pressed="false">Saved<span class="batch-queue-filter__count"></span></button>
                                    </div>
                                </div>
                                <ol class="batch-queue-files" id="batchQueueFiles" aria-label="Files in this batch"></ol>
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
                                    <div><span><kbd>←</kbd> <kbd>→</kbd> <kbd>↑</kbd> <kbd>↓</kbd></span><small>Nudge selected fields
                                            (<kbd>Shift</kbd>: 10 px)</small></div>
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
                                    <button type="button" class="doc-spread__switch" id="docCompanionSwitch" data-custom-control
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

            {{-- Drag to resize the page and the panel, as in Review & Export; double-click for the start. --}}
            <div class="review-splitter workspace-splitter" id="alignSplitter" role="separator" tabindex="0"
                 aria-orientation="vertical" aria-label="Resize the page and the side panel"
                 title="Drag to resize · double-click to reset"></div>

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

                {{-- Document Reference: Book Number & Page Number (Required) --}}
                <div class="card mb-3 document-reference-card" id="documentReferenceCard">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="small fw-semibold text-uppercase text-body-secondary d-flex align-items-center gap-1" style="font-size: 0.75rem; letter-spacing: 0.04em;">
                                <i class="icon-base bx bx-book-open text-primary" aria-hidden="true"></i> Document Reference
                            </span>
                            <span class="badge bg-label-danger d-none" id="docReferenceIdentifiedBadge" style="font-size: 0.7rem;">Already saved</span>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label for="bookNumberInput" class="form-label mb-1 small fw-medium">
                                    Book No. <span class="text-danger" title="Required">*</span>
                                </label>
                                <input type="text"
                                       class="form-control form-control-sm"
                                       id="bookNumberInput"
                                       name="book_no_input"
                                       inputmode="numeric"
                                       pattern="[0-9]*"
                                       maxlength="9"
                                       placeholder="e.g. 7"
                                       list="existingBooksList"
                                       autocomplete="off"
                                       required>
                                <datalist id="existingBooksList">
                                    @foreach ($existingBooks ?? [] as $b)
                                        <option value="{{ $b }}">
                                    @endforeach
                                </datalist>
                                <div class="invalid-feedback small" id="bookNumberError" style="display: none;">Book number is required.</div>
                            </div>
                            <div class="col-6">
                                <label for="pageNumberInput" class="form-label mb-1 small fw-medium">
                                    Page No. <span class="text-danger" title="Required">*</span>
                                </label>
                                <input type="text"
                                       class="form-control form-control-sm"
                                       id="pageNumberInput"
                                       aria-describedby="pageNumberError documentIdentifyText"
                                       name="page_no_input"
                                       inputmode="numeric"
                                       pattern="[0-9]*"
                                       maxlength="9"
                                       placeholder="e.g. 83"
                                       autocomplete="off"
                                       required>
                                <div class="invalid-feedback small" id="pageNumberError" style="display: none;">Page number is required.</div>
                            </div>
                        </div>
                        <div class="mt-2 p-2 rounded small d-none" id="documentIdentifyNotice" style="border: 1px solid var(--bs-border-color, #e9ecef); font-size: 0.8rem; line-height: 1.35;">
                            <div class="d-flex align-items-start gap-1">
                                <i class="icon-base bx bx-info-circle text-primary mt-1" id="documentIdentifyIcon"></i>
                                <span class="flex-grow-1" id="documentIdentifyText"></span>
                            </div>
                        </div>
                    </div>
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
                    {{-- Smoke test scan is not offered. The button stays (hidden) because the page script reads and
                         toggles it by id; the script's d-none toggling cannot show a `hidden` element. --}}
                    <button class="btn btn-outline-primary" type="button" id="detectBtn" hidden aria-hidden="true" tabindex="-1"
                        title="Straighten this page, find its table, and outline every handwritten line before reading">
                        <i class="icon-base bx bx-radar icon-sm me-1" aria-hidden="true"></i> Smoke test scan
                    </button>
                    <button class="btn btn-primary btn-lg d-none" type="button" id="saveQueueNextBtn">
                        <i class="icon-base bx bx-save icon-sm me-1" id="saveQueueNextIcon"></i>
                        <span class="spinner-border spinner-border-sm me-1 d-none" id="saveQueueNextSpinner" role="status" aria-hidden="true"></span>
                        <span id="saveQueueNextLabel">Save &amp; Queue
                            Document</span>
                    </button>
                    <button class="btn btn-primary btn-lg" type="button" id="scanNowBtn">
                        <i class="icon-base bx bx-scan icon-sm me-1"></i> <span id="scanNowBtnLabel">Scan with OCR</span>
                    </button>
                    {{-- A page sent back from Verify: keep the reading it had and go back to Verify. --}}
                    <button class="btn btn-label-secondary d-none" type="button" id="cancelRealignBtn"
                        title="Keep the reading this page had and go back to Verify">
                        <i class="icon-base bx bx-chevron-left icon-sm me-1" aria-hidden="true"></i>Cancel re-align
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
            <input type="hidden" name="book_no" id="submitBookNo">
            <input type="hidden" name="page_no" id="submitPageNo">

            <div class="validation-workspace">
                <div class="validation-submit-error d-none" id="validationExistingBanner" role="alert"
                    aria-live="assertive">
                    <i class="icon-base bx bx-copy" aria-hidden="true"></i>
                    <div class="flex-grow-1">
                        <strong>This document may already be saved</strong>
                        <div id="validationExistingMessage">What was read matches a saved record.
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <a href="#" target="_blank" class="btn btn-sm btn-outline-secondary d-none"
                            id="validationExistingViewLink">
                            View existing
                        </a>
                        <button type="button" class="btn btn-sm btn-label-danger" id="validationExistingRejectBtn">
                            Reject
                        </button>
                        <button type="button" class="btn btn-sm btn-primary" id="validationExistingContinueBtn">
                            Continue anyway
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

                <div class="validation-comparison-grid verify-grid" id="verifyGrid">
                    <section class="card validation-pane validation-document-pane" aria-labelledby="originalDocumentTitle">
                        <header class="validation-pane__header">
                            <div class="min-w-0">
                                {{-- Kept for the page script, never shown: the pages are labelled on the scan itself. --}}
                                <span class="validation-page-pill d-none" id="validationPagePill" role="status" style="display:none !important"></span>
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
                                {{-- Review & Export only: the field outlines off, to read the writing under them.
                                     The picked field and its row stay shown. --}}
                                <div class="form-check form-switch marker-grid-toggle review-grid-toggle"
                                     title="Show or hide the field outlines over the page. The picked field and its row stay shown.">
                                    <input class="form-check-input" type="checkbox" role="switch" id="reviewGridToggle" checked>
                                    <label class="form-check-label" for="reviewGridToggle">Grid</label>
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
                             and one zoom move them together. Both are verified where they are; a click zooms in on one. --}}
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
                                     id="validationCompanionPage">
                                    <div class="doc-spread__label" id="validationCompanionLabel"></div>
                                    <div class="doc-stage" id="validationCompanionStage">
                                        <canvas id="validationCompanionCanvas"></canvas>
                                        <div class="field-overlay validation-field-overlay" id="validationCompanionOverlay"></div>
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
                            Hold <kbd>Ctrl</kbd> (<kbd>⌘</kbd> on a Mac) and scroll to zoom or drag to move.
                        </footer>
                    </section>

                    {{-- Drag to resize the page and the fields, as in Review & Export; double-click for the start. --}}
                    <div class="review-splitter workspace-splitter" id="verifySplitter" role="separator" tabindex="0"
                         aria-orientation="vertical" aria-label="Resize the document and the fields"
                         title="Drag to resize · double-click to reset"></div>

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

                        <div class="validation-record-list-heading">
                            <div>
                                <i class="icon-base bx bx-list-check" aria-hidden="true"></i>
                                <span>Registry records</span>
                            </div>
                            {{-- Every person's fields at once, for a quick submission. --}}
                            <label class="validation-verified-control validation-group-verified-control" for="verifyAllFields">
                                <input class="form-check-input" type="checkbox" id="verifyAllFields"
                                       aria-label="Verify every field of every person">
                                <span>Verify all</span>
                            </label>
                        </div>
                        <div class="validation-result-list" id="verifyRows"></div>

                        {{-- A document of several pages: what Submit does on this page. --}}
                        <div class="validation-pages-note d-none" id="validationPagesNote" role="status">
                            <i class="icon-base bx bx-info-circle" aria-hidden="true"></i>
                            <span id="validationPagesNoteText"></span>
                        </div>

                        <footer class="validation-submit-bar">
                            <div class="validation-submit-actions">
                                <button class="btn btn-outline-secondary" type="button" id="backToMark">
                                    <i class="icon-base bx bx-chevron-left icon-sm me-1"></i> Back to marking
                                </button>
                                {{-- Not shown: every document, one at a time or queued, is saved from Review & Export.
                                     It stays as the form's submit button. --}}
                                <button class="btn btn-primary d-none" type="submit" id="submitBtn" disabled>
                                    <i class="icon-base bx bx-check-shield icon-sm me-1"></i>
                                    {{-- One inline span: .btn is a flex container, which drops the
                                    spaces between separate text and element children. --}}
                                    <span><span id="submitLabelHead">Submit</span> <span id="submitVerifiedCount">0</span> verified</span>
                                </button>
                                {{-- A batch only: this whole document (every page of the record) back to Align, read again once queued. --}}
                                <div class="d-none" id="verifyRealignGroup">
                                    <button class="btn btn-outline-secondary" type="button" id="verifyRealignBtn"
                                        title="Back to Align for this whole document: it is read again once queued">
                                        <i class="icon-base bx bx-chevron-left icon-sm me-1" aria-hidden="true"></i>Re-align
                                    </button>
                                </div>
                                <button class="btn btn-primary" type="button" id="reviewExportBtn">
                                    <span>Review &amp; export</span>
                                    <i class="icon-base bx bx-chevron-right icon-sm ms-1" aria-hidden="true"></i>
                                </button>
                            </div>
                        </footer>
                    </section>

                </div>
            </div>
        </form>
    </section>

    {{-- Step 4: Review & Export. Verify's own document pane (and its error notices) is
         moved in here when the step opens and back when Verify is shown again, so
         the scan, its outlines and its zoom are the same ones. The table is on the
         right, half and half; drag the bar between them to resize, double-click it for 50/50. --}}
    <section id="step-review" class="document-step-panel d-none">
        <div class="validation-workspace">
            <div id="reviewNotices"></div>
            <div class="validation-comparison-grid review-grid" id="reviewGrid">
                <div class="review-splitter" id="reviewSplitter" role="separator" tabindex="0"
                     aria-orientation="vertical" aria-controls="reviewPane" aria-label="Resize the scan and the table"
                     aria-valuemin="20" aria-valuemax="65" aria-valuenow="30"
                     title="Drag to resize · double-click for 50/50"></div>

                <section class="card validation-pane review-pane" id="reviewPane" aria-labelledby="reviewPaneTitle">
                    <header class="validation-pane__header review-pane__header">
                        <div class="min-w-0">
                            <span class="validation-pane__eyebrow">Review &amp; Export</span>
                            <h3 class="h5 mb-0" id="reviewPaneTitle">Records table</h3>
                            <p class="small text-muted mb-0" id="reviewPaneSummary"></p>
                        </div>
                        <div class="review-pane__tools">
                            @include('records.partials.review-shortcuts')
                            @if ($registry)
                                {{-- Which scanned column fills each register field (kept for the layout). --}}
                                <div class="dropdown" id="reviewFieldMap">
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="reviewFieldMapBtn"
                                            data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                                            data-bs-popper-config='{"strategy":"fixed"}'>
                                        <i class="icon-base bx bx-list-check icon-sm me-1" aria-hidden="true"></i>Columns → fields
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-end p-3 review-field-map" aria-labelledby="reviewFieldMapBtn">
                                        <p class="small text-muted mb-2">
                                            Which scanned column fills each field of <strong>{{ $registry['type'] }}_records</strong>.
                                            Kept for this layout when you save this document: the next one starts the same way.
                                            A column named like a field fills it by itself ("Name of Mother",
                                            "Mother's Name" → name_of_mother): name the columns in Template Builder.
                                        </p>
                                        {{-- What the last choice did (a column moved, typed values hidden), with Undo. --}}
                                        <div class="review-field-map__notice d-none" id="reviewFieldMapNotice" role="status" aria-live="polite">
                                            <i class="icon-base bx bx-info-circle" aria-hidden="true"></i>
                                            <span id="reviewFieldMapNoticeText"></span>
                                            <button type="button" class="btn btn-sm btn-link p-0 ms-auto" id="reviewFieldMapUndo">Undo</button>
                                        </div>
                                        <div class="review-field-map__rows" id="reviewFieldMapRows"></div>
                                        <p class="small text-muted mt-2 mb-0" id="reviewFieldMapManual"></p>
                                        {{-- How the register writes its dates: all are saved as YYYY-MM-DD. --}}
                                        <div class="review-field-map__row mt-3 pt-3 border-top">
                                            <label for="reviewDateOrder" class="form-label small mb-0">Dates are written</label>
                                            <select class="form-select form-select-sm" id="reviewDateOrder">
                                                <option value="auto">Automatic (by what the column holds)</option>
                                                <option value="month">Month first: 3-11-57 is 1957-03-11</option>
                                                <option value="day">Day first: 3-11-57 is 1957-11-03</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            @endif
                            @include('records.partials.review-add-column')
                            <div class="dropdown">
                                <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" id="reviewDownloadBtn"
                                        data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                                    <i class="icon-base bx bx-download icon-sm me-1" aria-hidden="true"></i>Download
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="reviewDownloadBtn">
                                    <li>
                                        <button type="button" class="dropdown-item" id="reviewExportCsv">
                                            <i class="icon-base bx bx-file icon-sm me-2" aria-hidden="true"></i>CSV (.csv)
                                        </button>
                                    </li>
                                    <li>
                                        <button type="button" class="dropdown-item" id="reviewExportXlsx">
                                            <i class="icon-base bx bx-spreadsheet icon-sm me-2" aria-hidden="true"></i>Excel (.xlsx)
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </header>

                    <div class="review-table-scroll" id="reviewTable"></div>

                    <footer class="validation-submit-bar">
                        <div class="validation-submit-actions">
                            <button class="btn btn-outline-secondary" type="button" id="backToVerify">
                                <i class="icon-base bx bx-chevron-left icon-sm me-1" aria-hidden="true"></i> Back to verify
                            </button>
                            {{-- Nothing ticked Verified on this record (opened from the queue on this step):
                                 in place of Back to verify and Save, one way on, back to Verify. --}}
                            <button class="btn btn-primary d-none" type="button" id="reviewVerifyFirstBtn"
                                title="Nothing on this record is verified yet: check its fields in Verify first">
                                <i class="icon-base bx bx-chevron-left icon-sm me-1" aria-hidden="true"></i> Verify first
                            </button>
                            <button class="btn btn-primary" type="button" id="reviewSaveBtn" disabled>
                                <i class="icon-base bx bx-check-shield icon-sm me-1" aria-hidden="true"></i>
                                {{-- A batch says where saving goes next ("Save & next (3/7)"). --}}
                                <span id="reviewSaveLabel">Save record</span>
                            </button>
                        </div>
                    </footer>
                </section>
            </div>

            {{-- The end of a batch, in this step's place (the scan and the table go): what
                 was saved, each record's link, and every record's table as one download. --}}
            <section class="card validation-pane batch-done d-none" id="batchSummaryPanel" aria-labelledby="batchSummaryTitle">
                <header class="validation-pane__header">
                    <div class="batch-done__heading min-w-0">
                        <span class="batch-done__icon bg-label-success" aria-hidden="true">
                            <i class="icon-base bx bx-check-shield"></i>
                        </span>
                        <div class="min-w-0">
                            <span class="validation-pane__eyebrow">Review &amp; Export</span>
                            <h3 class="h5 mb-0" id="batchSummaryTitle">Batch complete</h3>
                            <p class="small text-muted mb-0" id="batchSummaryDescription"></p>
                        </div>
                    </div>
                    <div class="review-pane__tools">
                        <div class="dropdown" id="batchSummaryDownload">
                            <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" id="batchSummaryDownloadBtn"
                                    data-bs-toggle="dropdown" aria-expanded="false">
                                <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                                <i class="icon-base bx bx-download icon-sm me-1" aria-hidden="true"></i>Download all
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="batchSummaryDownloadBtn">
                                <li>
                                    <button type="button" class="dropdown-item" id="batchSummaryCsv">
                                        <i class="icon-base bx bx-file icon-sm me-2" aria-hidden="true"></i>CSV (.csv)
                                    </button>
                                </li>
                                <li>
                                    <button type="button" class="dropdown-item" id="batchSummaryXlsx">
                                        <i class="icon-base bx bx-spreadsheet icon-sm me-2" aria-hidden="true"></i>Excel (.xlsx)
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                </header>

                <div class="batch-done__body">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="batch-done__num">#</th>
                                <th>Document</th>
                                <th>Pages</th>
                                <th class="text-end">Rows</th>
                                <th>Status</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody id="batchSummaryList"></tbody>
                    </table>
                </div>

                <footer class="validation-submit-bar">
                    <div class="validation-submit-actions">
                        <a href="#" class="btn btn-outline-secondary" id="batchSummaryRecordsBtn">
                            <i class="icon-base bx bx-folder-open icon-sm me-1" aria-hidden="true"></i>Saved records
                        </a>
                        <a href="#" class="btn btn-primary" id="batchSummaryRestartBtn">
                            <i class="icon-base bx bx-plus icon-sm me-1" aria-hidden="true"></i>Scan another batch
                        </a>
                    </div>
                </footer>
            </section>
        </div>
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
                        {{-- A batch only: leave this record out (the saved one stays) and go on. --}}
                        <button type="button" class="btn btn-label-danger d-none" id="duplicatePageSkipBtn">
                            Skip this record
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

    {{-- A field's crop, larger (Verify: click the picture beside a value) --}}
    <div class="modal fade" id="cropPreviewModal" tabindex="-1"
         aria-labelledby="cropPreviewTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg crop-preview-dialog">
            <div class="modal-content crop-preview">
                <div class="crop-preview__header">
                    <div class="min-w-0">
                        <span class="crop-preview__eyebrow">Field crop</span>
                        <h5 class="crop-preview__title" id="cropPreviewTitle"></h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="crop-preview__stage">
                    <img id="cropPreviewImage" alt="">
                </div>
            </div>
        </div>
    </div>

    {{-- Confirm leaving a scan that is not submitted (Cancel, or a link out) --}}
    <div class="modal fade" id="leaveScanModal" tabindex="-1" aria-labelledby="leaveScanModalTitle"
        aria-describedby="leaveScanModalDescription" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered leave-scan-dialog">
            <div class="modal-content leave-scan-modal">
                {{-- Closing it (x, Esc, or outside) is the same as Stay. --}}
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="modal-body">
                    <span class="leave-scan-modal__icon bg-label-warning" aria-hidden="true" id="leaveScanIcon">
                        <i class="icon-base bx bx-error" id="leaveScanIconGlyph"></i>
                    </span>
                    <h5 class="modal-title" id="leaveScanModalTitle">Leave this scan?</h5>
                    <p class="leave-scan-modal__text" id="leaveScanModalDescription">
                        Your markers and checked values are not saved yet.
                    </p>
                    {{-- A batch: where it stands, so the choice below is an informed one. --}}
                    <ul class="leave-scan-modal__summary d-none" id="leaveScanSummary"></ul>

                    <div class="leave-scan-modal__actions" id="leaveScanActions">
                        {{-- Closes the window from the script (it also steps back out of Discard's question). --}}
                        <button type="button" class="btn btn-outline-secondary" id="leaveScanStayBtn">
                            Stay
                        </button>
                        {{-- A batch kept on the server: it can be thrown away instead of kept (asked again first). --}}
                        <button type="button" class="btn btn-outline-danger d-none" id="discardBatchLeaveBtn">
                            Discard batch
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

                    <div class="leave-scan-modal__actions batch-delete-actions" id="batchDocumentActions">
                        <button type="button" class="btn btn-label-secondary text-nowrap" id="batchDocCancelBtn" data-bs-dismiss="modal">
                            Keep it
                        </button>
                        {{-- Only one page of the file: the other pages stay queued. --}}
                        <button type="button" class="btn btn-label-danger text-nowrap d-none" id="confirmDeleteBatchPageBtn">
                            <i class="icon-base bx bx-trash icon-sm me-1" aria-hidden="true"></i><span
                                id="confirmDeleteBatchPageLabel">Remove this page</span>
                        </button>
                        <button type="button" class="btn btn-danger text-nowrap" id="confirmDeleteBatchDocBtn">
                            <i class="icon-base bx bx-trash icon-sm me-1" aria-hidden="true"></i><span
                                id="confirmDeleteBatchDocLabel">Remove from queue</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- A resumed batch: its files whose pages are not queued yet are picked again, matched by fingerprint. --}}
    <div class="modal fade" id="batchFilesModal" tabindex="-1" aria-labelledby="batchFilesTitle"
        aria-describedby="batchFilesText" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered leave-scan-dialog" style="max-inline-size: min(32rem, calc(100% - 2rem));">
            <div class="modal-content leave-scan-modal">
                <div class="modal-body">
                    <span class="leave-scan-modal__icon bg-label-primary" aria-hidden="true">
                        <i class="icon-base bx bx-folder-open"></i>
                    </span>
                    <h5 class="modal-title" id="batchFilesTitle">Pick these files again</h5>
                    <p class="leave-scan-modal__text" id="batchFilesText">
                        Their pages not queued yet stay on your computer. Pick the files (or their folder) again:
                        each is matched by its content, so a renamed file still matches.
                    </p>
                    <ul class="batch-intake__list batch-files-list" id="batchFilesList"></ul>
                    <p class="batch-intake__note d-none" id="batchFilesResult" role="status"></p>
                    <div class="leave-scan-modal__actions is-three">
                        <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal" id="batchFilesLaterBtn">
                            Later
                        </button>
                        <button type="button" class="btn btn-outline-primary" id="batchFilesFolderBtn">
                            <i class="icon-base bx bx-archive icon-sm me-1" aria-hidden="true"></i>Choose a folder
                        </button>
                        <button type="button" class="btn btn-primary" id="batchFilesPickBtn">
                            <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>Choose files
                        </button>
                    </div>
                    <input type="file" id="batchRepickFiles" class="visually-hidden" multiple
                        accept="application/pdf,image/png,image/jpeg,image/webp,image/bmp">
                    <input type="file" id="batchRepickFolder" class="visually-hidden" webkitdirectory directory multiple>
                </div>
            </div>
        </div>
    </div>

    {{-- Another tab took this batch over (or it was finished or discarded): nothing here is saved now. --}}
    <div class="modal fade" id="batchTakenModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false"
        aria-labelledby="batchTakenTitle" aria-describedby="batchTakenText" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered leave-scan-dialog" style="max-inline-size: min(26rem, calc(100% - 2rem));">
            <div class="modal-content leave-scan-modal">
                <div class="modal-body">
                    <span class="leave-scan-modal__icon bg-label-warning" aria-hidden="true">
                        <i class="icon-base bx bx-error"></i>
                    </span>
                    <h5 class="modal-title" id="batchTakenTitle">This batch continued in another tab</h5>
                    <p class="leave-scan-modal__text" id="batchTakenText">
                        What you do here is no longer saved. Reload this page to pick the batch up where it stands, or close this tab.
                    </p>
                    <div class="leave-scan-modal__actions">
                        <a class="btn btn-label-secondary" id="batchTakenRecordsLink" href="#">Saved Records</a>
                        <button type="button" class="btn btn-primary" id="batchTakenReloadBtn">Reload</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Re-align: sending a read page back to Align, and what happens to what was typed. --}}
    <div class="modal fade" id="realignConfirmModal" tabindex="-1" aria-labelledby="realignConfirmTitle"
        aria-describedby="realignConfirmText" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered leave-scan-dialog" style="max-inline-size: min(23rem, calc(100% - 2rem));">
            <div class="modal-content leave-scan-modal">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="modal-body">
                    <h5 class="modal-title" id="realignConfirmTitle">Re-align this document?</h5>
                    {{-- Which document: its file and pages. --}}
                    <p class="realign-doc" id="realignConfirmDoc"></p>
                    {{-- Queuing a re-align: the fields that moved, page by page (scanned again). --}}
                    <ul class="realign-fields d-none" id="realignConfirmList"></ul>
                    <p class="leave-scan-modal__text" id="realignConfirmText"></p>
                    <p class="realign-warning d-none" id="realignConfirmWarning"></p>
                    <div class="leave-scan-modal__actions">
                        <button type="button" class="btn btn-label-secondary text-nowrap" data-bs-dismiss="modal"
                            id="realignConfirmCancel">
                            Cancel
                        </button>
                        <button type="button" class="btn btn-primary text-nowrap" id="realignConfirmBtn">
                            Re-align
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Every unfinished batch, one row each (newest first): Resume, or Discard (confirmed in the row). --}}
    <div class="modal fade" id="batchResumeModal" tabindex="-1" aria-labelledby="batchResumeModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-inline-size: min(30rem, calc(100% - 2rem));">
            <div class="modal-content batch-resume-modal">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="batchResumeModalTitle">Unfinished batches</h5>
                        <p class="batch-resume-modal__sub" id="batchResumeModalSub"></p>
                    </div>
                </div>
                <div class="modal-body">
                    <ul class="batch-resume__list" id="batchResumeList" aria-labelledby="batchResumeModalTitle"></ul>
                </div>
                <div class="modal-footer" id="batchResumeModalFooter">
                    <button type="button" class="btn btn-sm btn-danger" id="batchResumeDiscardAllBtn">Discard all</button>
                </div>
            </div>
        </div>
    </div>

    {{-- The app's own question window, in place of the browser's confirm and alert boxes (askInApp). --}}
    <div class="modal fade" id="appAskModal" tabindex="-1" aria-labelledby="appAskTitle"
        aria-describedby="appAskText" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered leave-scan-dialog" style="max-inline-size: min(25rem, calc(100% - 2rem));">
            <div class="modal-content leave-scan-modal">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="modal-body">
                    <span class="leave-scan-modal__icon bg-label-warning" aria-hidden="true" id="appAskIcon">
                        <i class="icon-base bx bx-error" id="appAskGlyph"></i>
                    </span>
                    <h5 class="modal-title" id="appAskTitle"></h5>
                    <div class="leave-scan-modal__text app-ask-text" id="appAskText"></div>
                    <div class="leave-scan-modal__actions" id="appAskActions">
                        <button type="button" class="btn btn-label-secondary text-nowrap" id="appAskCancel">Cancel</button>
                        <button type="button" class="btn btn-primary text-nowrap" id="appAskConfirm">OK</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Files just added to a batch that were left out, and a way to add the duplicates after all. --}}
    <div class="modal fade" id="batchIntakeModal" tabindex="-1" aria-labelledby="batchIntakeModalTitle"
        aria-describedby="batchIntakeSummary" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered leave-scan-dialog" style="max-inline-size: min(34rem, calc(100% - 2rem));">
            <div class="modal-content leave-scan-modal">
                {{-- Closing it (x, Esc, or outside) is the same as Leave them out. --}}
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="modal-body">
                    <span class="leave-scan-modal__icon bg-label-warning" aria-hidden="true">
                        <i class="icon-base bx bx-copy" id="batchIntakeIconGlyph"></i>
                    </span>
                    <h5 class="modal-title" id="batchIntakeModalTitle">Some files were left out</h5>
                    <p class="leave-scan-modal__text" id="batchIntakeSummary"></p>

                    <div class="batch-intake" id="batchIntakeGroups"></div>
                    <p class="batch-intake__note d-none" id="batchIntakeAddNote">
                        Pages added anyway are checked again when saved: you choose to save again or skip.
                    </p>

                    <div class="leave-scan-modal__actions">
                        <button type="button" class="btn btn-outline-secondary text-nowrap" id="batchIntakeAddBtn">
                            Add them anyway
                        </button>
                        {{-- Closes the window from the script, so it can stay the main button. --}}
                        <button type="button" class="btn btn-primary text-nowrap" id="batchIntakeOkBtn">
                            Leave them out
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
                    <span class="leave-scan-modal__icon bg-label-warning" aria-hidden="true">
                        <i class="icon-base bx bx-copy"></i>
                    </span>
                    <h5 class="modal-title" id="existingRecordModalTitle">Already saved?</h5>
                    <p class="leave-scan-modal__text" id="existingRecordModalDescription">
                        This document's extracted data already exists in the database.
                    </p>

                    <p class="leave-scan-modal__text">
                        <a href="#" target="_blank" rel="noopener" class="d-none" id="existingRecordViewBtn">Open the saved record</a>
                    </p>

                    <div class="leave-scan-modal__actions">
                        <button type="button" class="btn btn-label-danger" id="confirmRejectExistingBtn">
                            <span id="confirmRejectExistingLabel">Reject &amp; End</span>
                        </button>
                        <button type="button" class="btn btn-primary" id="continueExistingBtn">
                            Continue anyway
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
            LINE_TWO,
            NOT_A_FIELD,
            applyRegistry,
            attachAddColumn,
            attachSplit,
            buildReviewModel,
            combineTables,
            downloadCsv,
            downloadRecordTables,
            downloadXlsx,
            exportFileName,
            fetchRecordTables,
            ReviewTable,
        } from '{{ Vite::asset('resources/js/review-table.js') }}';
        import {
            appendToQueue,
            batchSummaryPhase,
            compareQueuePaths,
            fileFingerprint,
            firstUnsavedPage,
            forgetBatchFiles,
            isPdfFile,
            matchFilesToBatch,
            MAX_PDF_BYTES,
            MAX_SCAN_BYTES,
            MAX_SENT_PDF_BYTES,
            nextUnsavedPage,
            pageCanAlign,
            pageDone,
            pageRanges,
            pageFileName,
            pageKey,
            pageNeedsFile,
            pdfPageFile,
            QUEUE_FILTERS,
            queueCounts,
            rebuildBatchState,
            recallBatchFiles,
            recordLeadPage,
            recordState,
            rememberBatchFile,
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
            // How far each row line is turned beyond the table (degrees), from the layout.
            ruledTilts: @json($ruledTilts ?? []),
            templateId: @json($template->getKey()),
            // A spread's page 2 laid out differently: its own template, boxes and row lines (or null).
            pageTwo: @json($pageTwo ?? null),
            // Step 4: the register's columns (birth_records, …; null for a type without
            // one), and which layout column fills which field (by layout id; a changed
            // choice is kept on the layout when the record is saved).
            registry: @json($registry ?? null),
            fieldMaps: @json((object) ($fieldMaps ?? [])),
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
            ocrStatusUrl: @json(route('documents.ocr-status')),
            snapUrl: @json(route('documents.pages.snap')),
            lineUpdateUrl: @json(route('documents.pages.lines.update', ['page' => '__PAGE__', 'line' => '__LINE__'])),
            lineDeleteUrl: @json(route('documents.pages.lines.destroy', ['page' => '__PAGE__', 'line' => '__LINE__'])),
            lineRestoreUrl: @json(route('documents.pages.lines.restore', ['page' => '__PAGE__', 'line' => '__LINE__'])),
            lineStoreUrl: @json(route('documents.pages.lines.store', ['page' => '__PAGE__'])),
            linePlaceUrl: @json(route('documents.pages.lines.place', ['page' => '__PAGE__', 'line' => '__LINE__'])),
            pageRowsUrl: @json(route('documents.pages.rows', ['page' => '__PAGE__'])),
        lineRedrawUrl: @json(route('documents.pages.lines.redraw', ['page' => '__PAGE__', 'line' => '__LINE__'])),
            pageCancelUrl: @json(route('documents.pages.cancel', ['page' => '__PAGE__'])),
            // Batch: queue a page Smoke test went over, as it is.
            pageQueueUrl: @json(route('documents.pages.queue', ['page' => '__PAGE__'])),
            // A finished batch: the archive, or this workspace again for the next one.
            recordsUrl: @json(route('records.index')),
            // Several saved records' tables at once (the batch's Download all).
            recordsExportUrl: @json(route('records.export')),
            restartUrl: @json(route('documents.workspace', ['type' => $docType->value])),
            // The pages of one file already saved; 64 zeros stand in for its fingerprint.
            sourcePagesUrl: @json(route('documents.sources.pages', ['hash' => str_repeat('0', 64)])),
            // Account settings > Uploads: check files for copies and saved pages (off unless turned on).
            duplicateFileCheck: @json((bool) auth()->user()?->duplicate_file_check),
            identifyReferenceUrl: @json(route('documents.identify-reference')),
            // Batch File Upload kept on the server (resume after a reload or a crash).
            docType: @json($docType->value),
            batchUrls: {
                store: @json(route('documents.batches.store')),
                show: @json(route('documents.batches.show', ['batch' => '__BATCH__'])),
                claim: @json(route('documents.batches.claim', ['batch' => '__BATCH__'])),
                ping: @json(route('documents.batches.ping', ['batch' => '__BATCH__'])),
                pages: @json(route('documents.batches.pages', ['batch' => '__BATCH__'])),
                draft: @json(route('documents.batches.draft', ['batch' => '__BATCH__'])),
                done: @json(route('documents.batches.done', ['batch' => '__BATCH__'])),
                abandon: @json(route('documents.batches.abandon', ['batch' => '__BATCH__'])),
                release: @json(route('documents.batches.release', ['batch' => '__BATCH__'])),
            },
            openBatches: @json($openBatches ?? []),
            batchKeepDays: @json(\App\Models\ScanBatch::keepDays()),
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
        const steps = {
            upload: el('step-upload'),
            mark: el('step-mark'),
            verify: el('step-verify'),
            review: el('step-review'),
        };
        // The step shown now (see showStep).
        let activeStep = 'upload';
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
        // Step 4, Review & Export: on while it is shown; the table (made when
        // first shown) and what it was drawn from; values typed into cells
        // where nothing was read ("person-5|1|sex" -> "M"), kept until the
        // next scan and saved with the record as fields with no outline.
        let reviewing = false;
        let reviewTable = null;
        let reviewModel = null;
        const reviewExtras = new Map();
        // ------------------------------------------------ Document Reference (Book No & Page No)
        const docRefStorageKey = 'crms_last_book_no_' + config.docType;
        let lastEnteredBookNo = (function () {
            try {
                return sessionStorage.getItem(docRefStorageKey) || '';
            } catch {
                return '';
            }
        })();
        const pageReferences = new Map();
        let identifyDebounceTimer = null;
        // The book and page last looked up, and what was found: { book, page, taken, message }.
        // A page saved already (or used by another record of this batch) cannot be saved again.
        let referenceLookup = null;

        /** Digits only: a book or page number is a whole number. */
        const digitsOnly = (value) => String(value ?? '').replace(/\D+/g, '').slice(0, 9);

        /** Another record of this batch with the same book and page, or null. */
        function batchRecordWithReference(bookNo, pageNo) {
            if (!batchState.active || !bookNo || !pageNo) return null;
            const current = batchState.items[batchState.currentIndex];
            const mine = current ? recordOf(current).members : [];
            return batchState.items.find((item) => !mine.includes(item)
                && item.status !== 'rejected'
                && String(item.bookNo ?? '') === bookNo
                && String(item.pageNoRef ?? '') === pageNo) ?? null;
        }

        /** Why this book and page cannot be saved, or '' (the last lookup, and this batch's other records). */
        function referenceTakenMessage(bookNo = getBookNumber(), pageNo = getPageNumber()) {
            const other = batchRecordWithReference(bookNo, pageNo);
            if (other) {
                return `Book ${bookNo}, page ${pageNo} is already used by ${other.sourceName ?? other.name ?? 'another record'} in this batch. Enter another page number.`;
            }
            if (referenceLookup?.taken && referenceLookup.book === bookNo && referenceLookup.page === pageNo) {
                return referenceLookup.message;
            }
            return '';
        }

        const getBookNumber = () => el('bookNumberInput')?.value?.trim() || '';
        const getPageNumber = () => el('pageNumberInput')?.value?.trim() || '';

        function validateDocumentReference() {
            const bookInput = el('bookNumberInput');
            const pageInput = el('pageNumberInput');
            const bookError = el('bookNumberError');
            const pageError = el('pageNumberError');

            // Step 1's boxes empty but the book and page typed in step 4's table:
            // those are the record's book and page.
            if (reviewing && registry && reviewTable) {
                const rows = reviewTable.fieldRows();
                const typedIn = (key) => rows.map((row) => String(row.values[key] ?? '').trim()).find((value) => value !== '') ?? '';
                if (bookInput && !bookInput.value.trim()) bookInput.value = typedIn('book_no');
                if (pageInput && !pageInput.value.trim()) pageInput.value = typedIn('page_no');
            }

            let valid = true;
            const bookVal = bookInput ? bookInput.value.trim() : '';
            const pageVal = pageInput ? pageInput.value.trim() : '';

            if (!bookVal) {
                valid = false;
                if (bookInput) bookInput.classList.add('is-invalid');
                if (bookError) {
                    bookError.textContent = 'Book number is required before saving.';
                    bookError.style.display = 'block';
                }
            } else {
                if (bookInput) bookInput.classList.remove('is-invalid');
                if (bookError) bookError.style.display = 'none';
            }

            if (!pageVal) {
                valid = false;
                if (pageInput) pageInput.classList.add('is-invalid');
                if (pageError) {
                    pageError.textContent = 'Page number is required before saving.';
                    pageError.style.display = 'block';
                }
            } else {
                if (pageInput) pageInput.classList.remove('is-invalid');
                if (pageError) pageError.style.display = 'none';
            }

            if (!valid) {
                if (!bookVal && bookInput) {
                    bookInput.focus();
                } else if (!pageVal && pageInput) {
                    pageInput.focus();
                }
                const msg = !bookVal && !pageVal
                    ? 'Book number and page number are required before saving.'
                    : (!bookVal ? 'Book number is required before saving.' : 'Page number is required before saving.');
                showOcrError(msg);
                // In Verify or Review & Export step 1's boxes are not on screen: say it where the save was pressed.
                if (activeStep === 'verify' || activeStep === 'review') {
                    showValidationSubmitError(reviewing && registry
                        ? `${msg} Type them in the book_no and page_no columns, or go back to step 1 (Document).`
                        : `${msg} Go back to step 1 (Document) to enter them.`);
                }
                return valid;
            }

            // The same register page is never saved twice.
            const taken = referenceTakenMessage(bookVal, pageVal);
            if (taken) {
                if (pageInput) {
                    pageInput.classList.add('is-invalid');
                    pageInput.focus();
                }
                if (pageError) {
                    pageError.textContent = 'This page is already saved.';
                    pageError.style.display = 'block';
                }
                showOcrError(taken);
                if (activeStep === 'verify' || activeStep === 'review') showValidationSubmitError(taken);
                return false;
            }
            return valid;
        }

        async function checkDocumentIdentification() {
            const bookNo = getBookNumber();
            const pageNo = getPageNumber();
            const noticeEl = el('documentIdentifyNotice');
            const textEl = el('documentIdentifyText');
            const iconEl = el('documentIdentifyIcon');
            const badgeEl = el('docReferenceIdentifiedBadge');

            if (!noticeEl || !textEl) return;

            if (!bookNo || !pageNo) {
                referenceLookup = null;
                noticeEl.classList.add('d-none');
                badgeEl?.classList.add('d-none');
                return;
            }

            // Used by another record of this batch: said at once, without asking the server.
            if (batchRecordWithReference(bookNo, pageNo)) {
                showReferenceNotice('taken', referenceTakenMessage(bookNo, pageNo));
                return;
            }

            if (identifyDebounceTimer) clearTimeout(identifyDebounceTimer);
            identifyDebounceTimer = setTimeout(async () => {
                try {
                    const url = new URL(config.identifyReferenceUrl, window.location.origin);
                    url.searchParams.set('doc_type', config.docType);
                    url.searchParams.set('book_no', bookNo);
                    url.searchParams.set('page_no', pageNo);

                    const res = await fetch(url.toString(), {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        }
                    });
                    if (!res.ok) return;
                    const data = await res.json();
                    // Typed on meanwhile: this answer is about another page.
                    if (getBookNumber() !== bookNo || getPageNumber() !== pageNo) return;

                    if (data.identified) {
                        const record = data.saved_record ? ` (Record #${data.saved_record.id})` : '';
                        const names = (data.entries ?? []).slice(0, 2).map((entry) => entry.label).join(', ');
                        const more = (data.count ?? 0) > 2 ? ` and ${data.count - 2} more` : '';
                        const message = `Book ${bookNo}, page ${pageNo} is already saved${record}`
                            + (names ? `: ${names}${more}` : '') + '. Enter another page number.';
                        referenceLookup = { book: bookNo, page: pageNo, taken: true, message };
                        showReferenceNotice('taken', message);
                    } else {
                        referenceLookup = { book: bookNo, page: pageNo, taken: false, message: '' };
                        showReferenceNotice('new', data.message);
                    }
                } catch {
                    // Ignore network error
                }
            }, 300);
        }

        /** The notice under Book / Page: 'taken' (red: the page is saved already) or 'new'. */
        function showReferenceNotice(kind, message) {
            const noticeEl = el('documentIdentifyNotice');
            const textEl = el('documentIdentifyText');
            const iconEl = el('documentIdentifyIcon');
            const badgeEl = el('docReferenceIdentifiedBadge');
            const pageInput = el('pageNumberInput');
            if (!noticeEl || !textEl) return;
            const taken = kind === 'taken';
            noticeEl.classList.remove('d-none');
            noticeEl.setAttribute('role', taken ? 'alert' : 'status');
            textEl.textContent = message;
            badgeEl?.classList.toggle('d-none', !taken);
            pageInput?.classList.toggle('is-invalid', taken);
            if (iconEl) {
                iconEl.className = taken
                    ? 'icon-base bx bx-error-circle text-danger mt-1'
                    : 'icon-base bx bx-info-circle text-muted mt-1';
            }
            noticeEl.style.backgroundColor = taken ? 'rgba(var(--bs-danger-rgb), .08)' : 'var(--bs-light, #f8f9fa)';
            noticeEl.style.borderColor = taken ? 'rgba(var(--bs-danger-rgb), .35)' : 'var(--bs-border-color, #e9ecef)';
            noticeEl.style.color = taken ? 'var(--bs-danger-text-emphasis)' : '';
        }

        function syncReferenceInputsForCurrentPage() {
            const bookInput = el('bookNumberInput');
            const pageInput = el('pageNumberInput');
            if (!bookInput || !pageInput) return;

            if (batchState.active) {
                const current = batchState.items[batchState.currentIndex];
                if (current) {
                    if (current.bookNo) {
                        bookInput.value = current.bookNo;
                    } else if (lastEnteredBookNo) {
                        bookInput.value = lastEnteredBookNo;
                        current.bookNo = lastEnteredBookNo;
                    } else {
                        bookInput.value = '';
                    }

                    if (current.pageNoRef !== undefined && current.pageNoRef !== null) {
                        pageInput.value = current.pageNoRef;
                    } else {
                        pageInput.value = '';
                    }
                }
            } else {
                const ref = pageReferences.get(scanPageNumber);
                if (ref && ref.bookNo) {
                    bookInput.value = ref.bookNo;
                } else if (lastEnteredBookNo) {
                    bookInput.value = lastEnteredBookNo;
                } else {
                    bookInput.value = '';
                }

                if (ref && ref.pageNo !== undefined && ref.pageNo !== null) {
                    pageInput.value = ref.pageNo;
                } else {
                    pageInput.value = '';
                }
            }

            bookInput.classList.remove('is-invalid');
            pageInput.classList.remove('is-invalid');
            if (el('bookNumberError')) el('bookNumberError').style.display = 'none';
            if (el('pageNumberError')) el('pageNumberError').style.display = 'none';
            checkDocumentIdentification();
        }

        function saveReferenceInputsForCurrentPage() {
            const bVal = getBookNumber();
            const pVal = getPageNumber();

            if (bVal) {
                lastEnteredBookNo = bVal;
                try {
                    sessionStorage.setItem(docRefStorageKey, bVal);
                } catch {}
            }

            if (batchState.active) {
                const current = batchState.items[batchState.currentIndex];
                if (current) {
                    if (bVal) current.bookNo = bVal;
                    if (pVal !== '') current.pageNoRef = pVal;
                    const mate = batchPartner()?.item;
                    if (mate && bVal && !mate.bookNo) {
                        mate.bookNo = bVal;
                    }
                    // Kept on the server with the batch, so a resume has them.
                    scheduleBatchSync();
                }
            } else {
                pageReferences.set(scanPageNumber, { bookNo: bVal, pageNo: pVal });
            }

            if (el('submitBookNo')) el('submitBookNo').value = bVal;
            if (el('submitPageNo')) el('submitPageNo').value = pVal;
        }

        el('bookNumberInput')?.addEventListener('input', () => {
            const input = el('bookNumberInput');
            // Digits only (typed or pasted): anything else is dropped as it comes.
            const digits = digitsOnly(input.value);
            if (digits !== input.value) {
                const at = Math.max(0, (input.selectionStart ?? digits.length) - (input.value.length - digits.length));
                input.value = digits;
                input.setSelectionRange(at, at);
            }
            input.classList.remove('is-invalid');
            if (el('bookNumberError')) el('bookNumberError').style.display = 'none';
            saveReferenceInputsForCurrentPage();
            checkDocumentIdentification();
        });

        el('pageNumberInput')?.addEventListener('input', () => {
            const input = el('pageNumberInput');
            // Digits only (typed or pasted): anything else is dropped as it comes.
            const digits = digitsOnly(input.value);
            if (digits !== input.value) {
                const at = Math.max(0, (input.selectionStart ?? digits.length) - (input.value.length - digits.length));
                input.value = digits;
                input.setSelectionRange(at, at);
            }
            input.classList.remove('is-invalid');
            if (el('pageNumberError')) el('pageNumberError').style.display = 'none';
            saveReferenceInputsForCurrentPage();
            checkDocumentIdentification();
        });

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
        // The picked field shows as a box (a rectangle), here and in Review & Export.
        verifyOverlays.forEach((overlay) => overlay.setBoxCurrent(true));

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
            // Review & Export: fields whose cell was emptied.
            setCleared(indexes) {
                const parts = splitBySide(indexes);
                verifyOverlays.forEach((overlay, side) => overlay.setCleared(parts[side]));
            },
            // Review & Export: fields of several cells picked together.
            setRange(indexes) {
                const parts = splitBySide(indexes);
                verifyOverlays.forEach((overlay, side) => overlay.setRange(parts[side]));
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
        // Each row line's own extra turn in the layout (degrees, one per line).
        let templateTilts = fitTilts(config.ruledTilts, templateRows.length);

        // A spread whose pages are laid out differently: page 1 uses the layout
        // the workspace opened with, page 2 (and every even page) its own.
        const mainLayout = {
            templateId: config.templateId,
            boxes: config.boxes,
            ruledYs: config.ruledYs,
            ruledTilts: config.ruledTilts,
            groupingMode: config.groupingMode,
        };
        const layoutFor = (pageNumber) => (pageNumber % 2 === 0 && config.pageTwo ? config.pageTwo : mainLayout);

        /** Align and read with this layout from here on: its markers, row lines, field settings and id. */
        function useLayoutForPage(pageNumber) {
            const layout = layoutFor(pageNumber);
            config.templateId = layout.templateId;
            config.boxes = layout.boxes;
            config.ruledYs = layout.ruledYs;
            config.ruledTilts = layout.ruledTilts;
            config.groupingMode = layout.groupingMode;
            templateBoxes = boxesOfLayout(config.boxes);
            templateColumns = templateBoxes.filter((box) => box.kind === 'column');
            templateSettings = new Map(config.boxes.map((box) => [nameKey(box.name), box]));
            templateFieldNames = new Set(config.boxes.filter((box) => box.kind !== 'column').map((box) => nameKey(box.name)));
            isLedger = config.ruledYs.length >= 2;
            templateRows = config.ruledYs.map(Number);
            templateTilts = fitTilts(config.ruledTilts, templateRows.length);
        }
        // How many degrees each row line is turned beyond the table's own tilt
        // (one per line, in step with gridRows): paper is curved, and no two
        // printed rules run quite alike. One is set by selecting its line and
        // using Tilt; Snap to table, Detect and Reset start every line at 0.
        let rowTilts = [...templateTilts];
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

        // Re-align: the markers the page open in Align was read with (null otherwise). Reset goes
        // back to them, not to the layout's template, and what moved since is said under Save.
        let realignBase = null;
        // The bar's next refresh after a marker moved (one per frame).
        let bannerRefresh = 0;

        /** What Reset goes back to: the markers the page was read with (re-align), or the layout's template. */
        const resetTarget = () => (realignBase
            ? { boxes: realignBase.boxes, rows: realignBase.rows, tilts: null }
            : { boxes: templateBoxes, rows: templateRows, tilts: templateTilts });

        function fieldsMatchTemplate() {
            const target = resetTarget();
            return JSON.stringify(marker.toJSON()) === JSON.stringify(target.boxes)
                && JSON.stringify(gridRows) === JSON.stringify(target.rows)
                && JSON.stringify(fitTilts(rowTilts, gridRows.length)) === JSON.stringify(fitTilts(target.tilts, gridRows.length));
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
            el('resetFieldsBtn').title = realignBase ? 'Back to the markers this page was read with' : '';
            // A queued page moved: the bar's Save (Update) follows, once a frame.
            if (batchState.active && !bannerRefresh) {
                bannerRefresh = window.requestAnimationFrame(() => {
                    bannerRefresh = 0;
                    renderBatchQueueBanner();
                });
            }
        }

        /** The markers a page sent back by Re-align was read with (kept on the item), or null. */
        function realignBaseline(item) {
            if (!item?.realign || !item.page?.geometry) return null;
            item.readBaseline ??= {
                boxes: cloneBoxes(geometryMarkers(item.page.geometry, item.page)),
                rows: (item.page.geometry.ruled_ys ?? []).map(Number),
            };
            return item.readBaseline;
        }

        /** Which markers (by name) and whether row lines differ from what the page was read with. */
        function realignDiff(boxes, rows, tilts, base) {
            // Start and baseline come from the same geometry: anything past float noise is a move the server re-cuts.
            const near = (a, b) => Math.abs((Number(a) || 0) - (Number(b) || 0)) <= 0.0002;
            const before = new Map(base.boxes.map((box) => [nameKey(box.name), box]));
            const now = new Set(boxes.map((box) => nameKey(box.name)));
            const moved = boxes.filter((box) => {
                const old = before.get(nameKey(box.name));
                return !old || !['x', 'y', 'w', 'h'].every((key) => near(box[key], old[key])) || !near(box.angle, old.angle);
            }).map((box) => box.name);
            const removed = base.boxes.filter((box) => !now.has(nameKey(box.name))).map((box) => `${box.name} (removed)`);
            const rowsMoved = rows.length !== base.rows.length
                || rows.some((y, index) => !near(y, base.rows[index]))
                || (tilts ?? []).some((tilt) => Math.abs(Number(tilt) || 0) > 0.001);
            return { names: [...moved, ...removed], rowsMoved };
        }

        /**
         * While re-aligning: the pages with something moved since the reading (only fields
         * there are read again). Null when not re-aligning.
         *
         * @returns {object|null} pages ({number, names, rowsMoved} each) and rowsMoved
         */
        function realignChanges() {
            if (!realignBase) return null;
            const current = batchState.items[batchState.currentIndex];
            const mate = batchPartner()?.item ?? null;
            const pages = [{ number: current?.pageNo ?? 1, ...realignDiff(marker.toJSON(), gridRows, rowTilts, realignBase) }];
            const mateBase = mate ? realignBaseline(mate) : null;
            if (mate && mateBase && mate.boxes) {
                pages.push({ number: mate.pageNo, ...realignDiff(mate.boxes, mate.rows ?? [], mate.tilts, mateBase) });
            }
            pages.sort((a, b) => a.number - b.number);
            const changed = pages.filter((page) => page.names.length > 0 || page.rowsMoved);
            return { pages: changed, rowsMoved: changed.some((page) => page.rowsMoved) };
        }

        /** Queuing a re-align with fields moved: say which are scanned again. Resolves true to go on. */
        async function confirmRealignChanges() {
            const changes = realignChanges();
            if (!changes || changes.pages.length === 0) return true;
            el('realignConfirmList').replaceChildren(...changes.pages.map((page) => {
                const li = document.createElement('li');
                const label = document.createElement('strong');
                label.textContent = `Page ${page.number}: `;
                li.append(label, [...page.names, ...(page.rowsMoved ? ['row lines'] : [])].join(', '));
                return li;
            }));
            const fields = changes.pages.reduce((sum, page) => sum + page.names.length, 0);
            el('realignConfirmWarning').textContent = changes.rowsMoved
                ? 'Row lines moved: cells typed in Review & Export for this document will be cleared.'
                : '';
            const current = batchState.items[batchState.currentIndex];
            return askRealign(
                'Scan these fields again?',
                current ? recordLabel(recordOf(current)) : '',
                fields === 1 && !changes.rowsMoved
                    ? 'Only this field is scanned again. Everything else keeps its reading and what you typed.'
                    : 'Only these are scanned again. Everything else keeps its reading and what you typed.',
                { confirm: 'Scan again', cancel: 'Keep aligning', list: true, warning: changes.rowsMoved },
            );
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

        /**
         * Verify and Review & Export fill the window exactly: with the batch's bar
         * above them, their panes give up its height, so the page never scrolls.
         */
        function syncBatchBarSpace() {
            const bar = document.getElementById('batchQueueBanner');
            ['step-verify', 'step-review'].forEach((id) => {
                const step = document.getElementById(id);
                const here = Boolean(bar) && step?.contains(bar) && !bar.classList.contains('d-none');
                step?.classList.toggle('has-batch-bar', here);
                if (here) step.style.setProperty('--batch-bar-block', `${bar.offsetHeight}px`);
            });
        }
        // A notice in the bar (a failed page) makes it taller: the panes follow.
        if (window.ResizeObserver && document.getElementById('batchQueueBanner')) {
            new ResizeObserver(() => syncBatchBarSpace()).observe(document.getElementById('batchQueueBanner'));
        }

        function showStep(name) {
            activeStep = name;
            // The re-align baseline belongs to a page open in Align.
            if (name !== 'mark') realignBase = null;
            Object.entries(steps).forEach(([key, node]) => node.classList.toggle('d-none', key !== name));
            // Verify's document pane goes with Review & Export and comes back to Verify.
            setReviewMode(name === 'review');
            if (name === 'verify') syncVerifyButtons();

            // A batch's bar: on top of the document in Align; in Verify and in Review &
            // Export, its own short row above both panes (the panes give up its height).
            const batchBanner = document.getElementById('batchQueueBanner');
            if (batchBanner) {
                if (name === 'mark') {
                    const toolbar = document.querySelector('#step-mark .marker-toolbar');
                    toolbar?.parentNode?.insertBefore(batchBanner, toolbar);
                    batchBanner.classList.remove('is-standalone');
                } else if (name === 'verify' || name === 'review') {
                    const grid = document.querySelector(`#step-${name} .validation-comparison-grid`);
                    grid?.parentNode?.insertBefore(batchBanner, grid);
                    batchBanner.classList.add('is-standalone');
                }
                // Its words follow the step (run once the page's script has set up the batch).
                window.queueMicrotask(() => {
                    if (batchState.active) renderBatchQueueBanner();
                    syncBatchBarSpace();
                });
            }

            const marking = name === 'mark';
            const focusedWorkspace = marking || name === 'verify' || name === 'review';
            el('layout-navbar').classList.remove('d-none');
            el('documentFlowSteps').classList.remove('d-none');
            document.querySelector('.content-footer')?.classList.toggle('d-none', focusedWorkspace);
            // On the Document step the type list shows the type, and there is
            // nothing to cancel yet; once a scan is open the top bar names both.
            el('workspaceContextDetail').classList.toggle('d-none', !focusedWorkspace);
            el('workspaceCancel').classList.toggle('d-none', !focusedWorkspace);

            const order = ['upload', 'mark', 'verify', 'review'];
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
            // A batch has its own: the pages of the file open, as queued.
            if (batchState.active) {
                updateBatchPageBar();
                return;
            }
            el('scanPageBar').classList.toggle('d-none', !multiPage());
            if (!multiPage()) return;

            // A real dropdown, so a saved page can show a green check icon.
            const menu = el('scanPageMenu');
            if (menu.dataset.mode === 'batch') menu.replaceChildren();
            delete menu.dataset.mode;
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
                // Always kept with the record; only asking which pages are saved is up to the account setting.
                scanFingerprint = fingerprint;
                if (!config.duplicateFileCheck) return;

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
                alert(pdf ? 'Choose a PDF smaller than 1 GB.' : 'Choose an image smaller than 20 MB.');
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
                setGrid(templateBoxes, templateRows, { remember: true, tilts: templateTilts });
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
            return { boxes: boxesOfLayout(layout.boxes), rows: layout.ruledYs.map(Number), tilts: layout.ruledTilts ?? null };
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
            if (!batchAligning()) return null;
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
                if (batchState.active) renderBatchQueueBanner();
                // A lone page: the Fields list has only its own fields again.
                renderFieldList(marker.toJSON());
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
                    // A page re-aligned from the server's copy is an address, not a file.
                    else if (typeof companionSource === 'string') await companionMarker.loadFromUrl(companionSource);
                    else await companionMarker.load(companionSource, { page: mate ? (mate.item.pageNo ?? 1) : other });
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
            // The Fields list shows the page beside too (both pages in one list).
            renderFieldList(marker.toJSON());
            if (tested) {
                companionPreview.setLines(tested, verificationItems(tested.lines, tested));
                companionPreview.setGuides([]);
                companionPreview.setVisible(true);
            } else {
                companionPreview.clear();
            }
            updateScanButtonLabel();
            // The Save button says "both pages" once the page beside this one is drawn.
            if (batchState.active) renderBatchQueueBanner();
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
            return storedDetection(page, mate)?.imageUrl ?? (mate ? batchPageSource(mate.item) : scanFile);
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
                else setGrid(templateBoxes, templateRows, { remember: true, tilts: templateTilts });
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
                saveReferenceInputsForCurrentPage();
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
                syncReferenceInputsForCurrentPage();
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

        el('scanPagePrevBtn').addEventListener('click', () => (batchState.active ? turnBatchPage(-1) : turnToPage(scanPageNumber - 1)));
        el('scanPageNextBtn').addEventListener('click', () => (batchState.active ? turnBatchPage(1) : turnToPage(scanPageNumber + 1)));

        /**
         * The page bar in a batch, in Align: "Page 3 of 12" of the file open, its
         * queued pages in the dropdown (each with its state), and ‹ › to the page
         * before or after it in that file that can be aligned.
         */
        function updateBatchPageBar() {
            const current = batchState.items[batchState.currentIndex];
            const show = batchAligning() && current?.pageTotal > 1;
            el('scanPageBar').classList.toggle('d-none', !show);
            if (!show) return;

            const siblings = batchState.items
                .map((item, index) => ({ item, index }))
                .filter(({ item }) => item.group === current.group);
            const label = (item) => {
                const span = document.createElement('span');
                span.className = 'marker-page-label';
                span.append(`Page ${item.pageNo} of ${item.pageTotal}`);
                if (item !== current) {
                    const state = document.createElement('span');
                    state.className = 'marker-page-state';
                    const badge = statusBadge(item);
                    const icon = document.createElement('i');
                    icon.className = `icon-base bx ${badge.icon} me-1`;
                    icon.setAttribute('aria-hidden', 'true');
                    state.append(icon, badge.text);
                    span.append(state);
                }
                return span;
            };
            const menu = el('scanPageMenu');
            menu.dataset.mode = 'batch';
            menu.replaceChildren(...siblings.map(({ item, index }) => {
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'dropdown-item';
                option.classList.toggle('active', item === current);
                option.disabled = item !== current && !pageAlignable(item);
                option.append(label(item));
                option.addEventListener('click', () => {
                    if (item !== current) openBatchDocument(index);
                });
                return option;
            }));
            el('scanPageSelect').replaceChildren(label(current));
            el('scanPageSelect').disabled = pageTurning || batchState.readingAll;
            const here = siblings.findIndex(({ item }) => item === current);
            const alignable = ({ item }) => pageAlignable(item);
            el('scanPagePrevBtn').disabled = pageTurning || !siblings.slice(0, here).some(alignable);
            el('scanPageNextBtn').disabled = pageTurning || !siblings.slice(here + 1).some(alignable);
        }

        /** The page before (-1) or after (1) this one in its file that can be aligned. */
        function turnBatchPage(step) {
            const current = batchState.items[batchState.currentIndex];
            if (!current || pageTurning || scanInProgress) return;
            const siblings = batchState.items
                .map((item, index) => ({ item, index }))
                .filter(({ item }) => item.group === current.group);
            const here = siblings.findIndex(({ item }) => item === current);
            const ordered = step < 0 ? siblings.slice(0, here).reverse() : siblings.slice(here + 1);
            const target = ordered.find(({ item }) => pageAlignable(item));
            if (target) openBatchDocument(target.index);
        }
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
        // An unfinished batch of this type waits (setUploadLock): nothing new is uploaded
        // until it is resumed (and so finished here) or discarded.
        let uploadLocked = false;
        // Set once the batch is done (showBatchSummary): step 4 shows the summary card
        // in place of the scan and the table, and the batch's bar (its queue) is put away.
        let batchSummaryShown = false;
        // Save & finish batch is saving the Done records, one after another (finishBatchSave).
        let batchFinishing = false;
        // There is no phase for the whole batch: what can be done with a page is
        // decided by its own status (see scan-source.js), and the phase the bar
        // shows is worked out from the pages (batchSummaryPhase).
        let batchState = {
            active: false,
            key: null,
            // Scan all is reading the queue (its progress window is open).
            readingAll: false,
            // One page each: { file, name, group (its file), record (its record), pageNo, pageTotal,
            // sourceName, sourceHash, status, pageId, page, workspaceScanPath, scanKind, geometry,
            // recordUrl, recordId, error, boxes, rows, tilts, detected, verifyDraft, reviewExtras,
            // existingAccepted, exportTable, sentToScan (sent to the worker by a Scan all that stopped partway) }
            items: [],
            currentIndex: 0,
        };

        const uploadModeRadios = document.querySelectorAll('input[name="uploadMode"]');
        const uploadModeCards = document.querySelectorAll('[data-mode-card]');
        const uploadModeHintEl = document.getElementById('uploadModeHint');
        const dropzoneTitleEl = document.getElementById('dropzoneTitle');
        const dropzoneButtonEl = document.getElementById('dropzoneButton');
        const dropzoneFolderBtnEl = document.getElementById('dropzoneFolderButton');
        const scanFolderInput = document.getElementById('scanFolder');

        // The mode chosen last, in this browser only (a convenience: none is the default).
        const UPLOAD_MODE_KEY = 'crms.scan.uploadMode';

        /** The drop area's words for the mode (also put back after files are prepared). */
        function showUploadModeText() {
            const batch = uploadMode === 'batch';
            if (dropzoneTitleEl) {
                dropzoneTitleEl.textContent = uploadLocked
                    ? 'Resume or discard the unfinished batch first'
                    : batch ? 'Drop scans or a folder here' : 'Drop the scan here';
            }
            if (dropzoneButtonEl) dropzoneButtonEl.textContent = batch ? 'Choose files' : 'Choose file';
            if (uploadModeHintEl) {
                uploadModeHintEl.textContent = batch
                    ? 'Many files or a folder: align each page, scan them all, then review and save each record'
                    : 'Upload and process one document at a time';
            }
        }

        function setUploadMode(mode, { remember = true } = {}) {
            uploadMode = mode === 'batch' ? 'batch' : 'single';
            uploadModeRadios.forEach((radio) => {
                radio.checked = radio.value === uploadMode;
            });
            uploadModeCards.forEach((card) => {
                card.classList.toggle('is-active', card.dataset.modeCard === uploadMode);
            });
            if (uploadMode === 'batch') scanFileInput.setAttribute('multiple', 'multiple');
            else scanFileInput.removeAttribute('multiple');
            dropzoneFolderBtnEl?.classList.toggle('d-none', uploadMode !== 'batch');
            showUploadModeText();
            if (!remember) return;
            try {
                window.localStorage.setItem(UPLOAD_MODE_KEY, uploadMode);
            } catch {
                // Not kept (a private window, or storage turned off): single is the default.
            }
        }

        uploadModeRadios.forEach((radio) => {
            radio.addEventListener('change', (event) => setUploadMode(event.target.value));
        });
        try {
            if (uploadModeRadios.length > 0 && window.localStorage.getItem(UPLOAD_MODE_KEY) === 'batch') {
                setUploadMode('batch', { remember: false });
            }
        } catch {
            // Storage unavailable: the page starts in single mode.
        }

        dropzoneFolderBtnEl?.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (uploadLocked) {
                explainUploadLock();
                return;
            }
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
            const order = ['failed', 'saving', 'scanning', 'verifying', 'pending', 'saved', 'ready', 'rejected', 'imported'];
            return order.find((status) => group.items.some((it) => it.status === status)) ?? 'pending';
        }

        /**
         * The queue's records, in queue order, each with its pages in page order.
         * Pages go into records as in single mode (see recordLeadPage): a page
         * alone, or a pair (1–2, 3–4) when the layout has a page 2.
         */
        function batchRecords() {
            const records = [];
            const byKey = new Map();
            batchState.items.forEach((item) => {
                const key = item.record ?? item;
                let record = byKey.get(key);
                if (!record) {
                    record = { key, members: [] };
                    byKey.set(key, record);
                    records.push(record);
                }
                record.members.push(item);
            });
            records.forEach((record) => record.members.sort((a, b) => (a.pageNo ?? 1) - (b.pageNo ?? 1)));
            return records;
        }

        function recordOf(item) {
            return batchRecords().find((record) => record.members.includes(item)) ?? { key: item, members: [item] };
        }

        const recordDone = (record) => record.members.every(pageDone);

        /**
         * Save & next was pressed on it (Done): it waits, still open to change, for
         * Save & finish batch, which saves every record Done at once. Nothing goes
         * to the Saved Records before that.
         */
        const recordApproved = (record) => !recordDone(record)
            && recordState(record.members) === 'ready'
            && Boolean(record.members[0]?.approved);

        /** Nothing more to do on it before Save & finish batch: saved, left out, or Done. */
        const recordSettled = (record) => recordDone(record) || recordApproved(record);

        /** A page that can be opened in Align here: not read yet (or failed), and its file is at hand. */
        const pageAlignable = (item) => pageCanAlign(item) && (Boolean(item?.file) || Boolean(item?.serverCopy));

        /** What a batch page is drawn from in Align: its file, or the server's copy of it (Re-align). */
        const batchPageSource = (item) => item?.file ?? (item?.serverCopy ? item.page?.imageUrl ?? null : null);

        /** The page as the server has it (its image and markers), asked for when only where it is was known. */
        async function ensureServerPage(item) {
            if (item?.page?.imageUrl && item.page.geometry) return;
            if (!item?.page?.statusUrl) throw new Error('This page is not on the server any more.');
            const response = await fetch(item.page.statusUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error(`The server answered ${response.status}.`);
            item.page = await response.json();
        }

        /** The state of the record a page is in: ready, waiting, blocked or done (see recordState). */
        const recordStateOf = (item) => recordState(recordOf(item).members);

        /**
         * A record not read yet whose pages are all queued (or read already): it
         * can be read now, on its own ("Read it now").
         */
        function recordReadable(item) {
            const { members } = recordOf(item);
            return members.some((it) => it.status === 'saved' && it.pageId)
                && members.every((it) => it.pageId && ['saved', 'ready'].includes(it.status));
        }

        /**
         * Queued pages the worker may have read without this page seeing it (a
         * Scan all cut short, a stuck worker, a reload): the server is asked, and
         * what it read (or could not read) is marked so. Answers whether anything changed.
         */
        async function refreshQueuedPages(items = batchState.items) {
            if (batchState.readingAll) return false;
            const queued = items.filter((it) => it.status === 'saved' && it.page?.statusUrl);
            let changed = false;
            await Promise.all(queued.map(async (item) => {
                try {
                    const response = await fetch(item.page.statusUrl, {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    if (!response.ok) return;
                    const page = await response.json();
                    // Scan all started meanwhile, or the page moved on: it is left as it is.
                    if (item.status !== 'saved' || batchState.readingAll) return;
                    if (page?.status === 'ready') {
                        item.page = page;
                        item.status = 'ready';
                        item.sentToScan = false;
                        changed = true;
                    } else if (page?.status === 'failed') {
                        item.page = page;
                        item.status = 'failed';
                        item.error = page.error || 'This page could not be read.';
                        item.sentToScan = false;
                        changed = true;
                    }
                } catch (error) {
                    console.warn(`The status of ${item.name} could not be checked:`, error);
                }
            }));
            if (changed) renderBatchQueueBanner();
            return changed;
        }

        /** A record queued but not read: read it now (on its own), then it opens in Verify. */
        async function readQueuedRecord(item) {
            const record = recordOf(item);
            const pages = record.members.filter((it) => it.status === 'saved' && it.pageId);
            if (pages.length === 0) return;
            const answer = await askInApp({
                title: 'This document is not read yet',
                text: `${recordLabel(record)} is queued but has not been read. Read it now? It opens in Verify when it is done.`,
                confirm: 'Read it now',
                cancel: 'Not now',
                tone: 'info',
            });
            if (answer !== 'confirm') return;
            // What was typed on the record open now is kept, to come back to.
            const leaving = batchState.items[batchState.currentIndex];
            keepCurrentAlignment();
            leaveBatchVerify();
            await runBatchScanAll({ pages, prefer: record.members[0] });
            // Not read (stopped, or it failed): back to the record that was open.
            // (Its status is still "ready", not "verifying", when nothing opened it since.)
            if (recordStateOf(record.members[0]) !== 'ready' && leaving?.status === 'ready' && recordStateOf(leaving) === 'ready') {
                await openBatchVerifyItem(batchState.items.indexOf(leaving));
            }
        }

        /**
         * Whether the batch page open now is being aligned: true in Align, and
         * for a page about to open there (pending, queued or failed). Not while
         * Scan all reads the queue, nor for a record in Verify.
         */
        function batchAligning() {
            if (!batchState.active || batchState.readingAll) return false;
            const current = batchState.items[batchState.currentIndex];
            if (!current) return false;
            return activeStep === 'mark' || pageCanAlign(current);
        }

        /** Keep the markers of the page open in Align on its queue item, before another opens. */
        function keepCurrentAlignment({ onlyIfNew = false } = {}) {
            const current = batchState.items[batchState.currentIndex];
            if (!current || !batchAligning() || (onlyIfNew && current.boxes)) return;
            current.boxes = cloneBoxes(marker.toJSON());
            current.rows = [...gridRows];
            current.tilts = [...rowTilts];
            current.autoFields = new Map(autoFields);
            current.rowsByHand = rowsByHand;
        }

        /** "BIRTHS-1995.pdf · pages 1–2", "BIRTHS-1995.pdf · page 3", or the file's name. */
        /** A page as people know it: "pair-one.pdf · page 1" (a PDF's page), else the file's name. */
        function batchPageName(item) {
            const name = item.sourceName ?? item.name;
            return (item.pageTotal ?? 1) > 1 ? `${name} · page ${item.pageNo}` : name;
        }

        function recordLabel(record) {
            const [first] = record.members;
            const name = first.sourceName ?? first.name;
            if (!(first.pageTotal > 1)) return name;
            const pages = record.members.map((it) => it.pageNo);
            return `${name} · page${pages.length > 1 ? 's' : ''} ${pageRanges(pages)}`;
        }

        /** The first record (from `after` on, then from the start) read and still to save, or null. */
        function nextOpenRecord(after = null) {
            // Done records wait for Save & finish batch: the next one to check is one not Done.
            const records = batchRecords().filter((record) => recordState(record.members) === 'ready' && !recordApproved(record));
            const start = after === null ? -1 : batchState.items.indexOf(after);
            return records.find((record) => batchState.items.indexOf(record.members[0]) > start) ?? records[0] ?? null;
        }

        /** Pages Scan all reads: queued and not read yet, or queued and failed (read again as they are). */
        function batchPagesToRead() {
            return batchState.items.filter((it) => it.pageId && ['saved', 'failed'].includes(it.status));
        }

        /** The first page to open in Align: one not queued yet or failed, else one queued. -1: none. */
        function firstAlignableIndex(from = 0) {
            const wanted = (it) => pageAlignable(it) && it.status !== 'saved';
            const after = batchState.items.findIndex((it, index) => index >= from && wanted(it));
            if (after !== -1) return after;
            const any = batchState.items.findIndex(wanted);
            return any !== -1 ? any : batchState.items.findIndex(pageAlignable);
        }

        /**
         * After a record is saved, skipped or rejected (or a page removed, or Scan
         * all finished): the next record that is read opens in Verify; else a page
         * still to align (one that failed, say) opens in Align; else the summary.
         */
        async function continueBatch(after = null, { askForFiles = true, prefer = null } = {}) {
            // Pages read without this page seeing it are not read again.
            await refreshQueuedPages();
            // A record just read again opens itself; otherwise the next one that is read.
            const preferred = prefer && recordState(recordOf(prefer).members) === 'ready' ? recordOf(prefer) : null;
            const next = preferred ?? nextOpenRecord(after);
            if (next) {
                await openBatchVerifyItem(batchState.items.indexOf(next.members[0]));
                return;
            }
            const alignIndex = firstAlignableIndex();
            if (alignIndex !== -1) {
                leaveBatchVerify();
                await openBatchQueueItem(alignIndex);
                return;
            }
            // Queued pages not read yet, and none can be opened in Align (a resumed
            // batch has not their files): Scan all reads them now.
            if (unsentBatchPages().length > 0) {
                await runBatchScanAll({ pages: unsentBatchPages(), after });
                return;
            }
            // A resumed batch: pages wait for their file to be picked again.
            if (batchState.items.some(pageNeedsFile)) {
                if (askForFiles) await askForBatchFiles({ thenContinue: true });
                else showResumeOffer();
                return;
            }
            // Every record Done, not saved yet (a resume, say): the last one opens,
            // where Save & finish batch saves them all.
            const done = batchRecords().filter(recordApproved);
            if (done.length > 0 && batchRecords().every(recordSettled)) {
                await openBatchVerifyItem(batchState.items.indexOf(done[done.length - 1].members[0]));
                return;
            }
            // The summary only once every page is saved or left out.
            if (batchState.items.every(pageDone)) showBatchSummary();
            else renderBatchQueueBanner();
        }

        /** "2 pages failed: re-align or remove", with a button to open the first one. */
        function renderBatchNotice() {
            const needFile = batchState.items.filter(pageNeedsFile);
            el('batchFilesNotice').classList.toggle('d-none', needFile.length === 0);
            if (needFile.length > 0) {
                const files = new Set(needFile.map((it) => it.sourceHash ?? it.sourceName)).size;
                el('batchFilesNoticeText').textContent = `${needFile.length} page${needFile.length === 1 ? '' : 's'} of ${files} file${files === 1 ? '' : 's'} wait${needFile.length === 1 ? 's' : ''} for ${files === 1 ? 'its file' : 'their files'} to be picked again.`;
            }
            const notice = document.getElementById('batchQueueNotice');
            if (!notice) return;
            const failed = batchState.items.filter((it) => it.status === 'failed');
            const current = batchState.items[batchState.currentIndex];
            const show = failed.length > 0 && !batchState.readingAll;
            notice.classList.toggle('d-none', !show);
            if (!show) return;
            const onFailed = current?.status === 'failed' && batchAligning();
            const text = failed.length === 1
                ? `1 page failed (${failed[0].name}): re-align or remove it.`
                : `${failed.length} pages failed: re-align or remove them.`;
            el('batchQueueNoticeText').textContent = onFailed && failed.length === 1
                ? `This page failed${current.error ? ` (${current.error})` : ''}: re-align it and queue it again, or remove it.`
                : text;
            // Open the first failed page that is not the one open now.
            const target = batchState.items.findIndex((it) => it.status === 'failed' && it !== current);
            el('batchQueueNoticeBtn').classList.toggle('d-none', target === -1);
            el('batchQueueNoticeBtn').textContent = onFailed ? 'Next failed page' : 'Open it';
            el('batchQueueNoticeBtn').dataset.index = String(target);
        }

        document.getElementById('batchQueueNoticeBtn')?.addEventListener('click', (event) => {
            const index = Number(event.currentTarget.dataset.index);
            if (Number.isInteger(index) && index >= 0) openBatchDocument(index);
        });

        function renderBatchQueueBanner() {
            const banner = document.getElementById('batchQueueBanner');
            if (!banner) return;

            if (!batchState.active || batchState.items.length === 0) {
                banner.classList.add('d-none');
                el('detectBtn').classList.remove('d-none');
                return;
            }
            // The batch is done: its summary card says it all, so the bar (and its queue) goes.
            if (batchSummaryShown) {
                banner.classList.add('d-none');
                setQueuePanelOpen(false);
                syncBatchBarSpace();
                return;
            }
            banner.classList.remove('d-none');
            // Display only: the bar's wording, worked out from the pages.
            const phase = batchSummaryPhase(batchState.items);
            banner.dataset.phase = phase;

            const total = batchState.items.length;
            const phaseTitle = document.getElementById('batchQueuePhaseTitle');
            const counter = document.getElementById('batchQueueCounter');
            const progressTrack = document.getElementById('batchQueueProgressTrack');
            const progressBar = document.getElementById('batchQueueProgressBar');
            // Add files shows in Align while pages can still be aligned.
            const addMoreBtn = document.getElementById('batchAddMoreGroup');
            const saveQueueBtn = document.getElementById('saveQueueNextBtn');
            const saveQueueLabel = document.getElementById('saveQueueNextLabel');
            const scanNowBtn = document.getElementById('scanNowBtn');
            const scanNowLabel = document.getElementById('scanNowBtnLabel');

            const savedCount = batchState.items.filter((it) => ['saved', 'scanning', 'ready', 'verifying', 'imported', 'rejected'].includes(it.status)
                || (it.status === 'failed' && it.pageId)).length;
            const readyCount = batchState.items.filter((it) => ['ready', 'verifying', 'imported', 'rejected'].includes(it.status)).length;
            // Pages are what is aligned and scanned one by one; documents are what is imported.
            const unit = batchGroups().length === total ? 'document' : 'page';

            // `pct` is how far the step is. Saving the Done records (Save & finish batch)
            // fills a second, green bar over it with `savedPct`, and says it in words.
            const progressSaved = document.getElementById('batchQueueProgressSaved');
            const updateProgress = (pct, { savedPct = null, text = '' } = {}) => {
                const saving = savedPct !== null;
                if (progressBar) progressBar.style.width = `${pct}%`;
                if (progressSaved) progressSaved.style.width = `${saving ? savedPct : 0}%`;
                if (progressTrack) {
                    progressTrack.classList.toggle('is-saving', saving);
                    progressTrack.setAttribute('aria-valuenow', String(saving ? savedPct : pct));
                    if (text) progressTrack.setAttribute('aria-valuetext', text);
                    else progressTrack.removeAttribute('aria-valuetext');
                }
            };

            // What the bar offers follows where the user is: Scan all running, the
            // batch done, a page in Align, or a record in Verify.
            const counts = queueCounts(batchState.items);
            // Re-aligning a document: what is not about fixing it is out of the way.
            const realigningNow = batchAligning() && !batchState.readingAll
                && [batchState.items[batchState.currentIndex], batchPartner()?.item].some((it) => it?.realign && it.page);
            if (addMoreBtn) addMoreBtn.classList.toggle('d-none', !batchAligning() || realigningNow);
            // Smoke test makes a fresh scan of the page: queued after it, every field would be read
            // again and what was typed lost, so it is not offered while re-aligning.
            el('detectBtn').classList.toggle('d-none', realigningNow);

            if (batchState.readingAll) {
                if (phaseTitle) phaseTitle.textContent = 'Scanning';
                if (counter) counter.textContent = `${readyCount} of ${total} ${unit}s read`;
                updateProgress(Math.round((readyCount / total) * 100));
                if (saveQueueBtn) saveQueueBtn.classList.add('d-none');
                if (scanNowBtn) scanNowBtn.classList.remove('d-none');
            } else if (batchAligning()) {
                const allSaved = savedCount === total;
                // "Queued", not "saved": a page is saved as a record only after Verify.
                const openItem = batchState.items[batchState.currentIndex];
                if (phaseTitle) {
                    phaseTitle.textContent = openItem?.realign
                        ? 'Re-aligning'
                        : allSaved ? 'Ready to scan' : `Align each ${unit}`;
                }
                if (counter) {
                    if (openItem?.realign) {
                        // Which record and pages (the file's name is on the queue button beside it).
                        const records = batchRecords();
                        const record = recordOf(openItem);
                        const pageNumbers = record.members.map((it) => it.pageNo);
                        const pagesText = (openItem.pageTotal ?? 1) > 1
                            ? `page${pageNumbers.length > 1 ? 's' : ''} ${pageRanges(pageNumbers)} · `
                            : '';
                        counter.textContent = `${pagesText}Record ${records.findIndex((r) => r.key === record.key) + 1} of ${records.length}`;
                    } else {
                        counter.textContent = `${savedCount} of ${total} queued`
                            + (counts.read > 0 ? ` · ${counts.read} read` : '');
                    }
                }
                updateProgress(Math.round((savedCount / total) * 100));
                if (saveQueueBtn) {
                    const currentItem = batchState.items[batchState.currentIndex];
                    // A failed page open in Align can be queued again, re-aligned.
                    const failedHere = currentItem?.status === 'failed';
                    // A queued page moved since it was queued (all queued, so Save was hidden): Update shows,
                    // or the change would be lost when Scan all reads the page as it was queued.
                    const editedHere = activeStep === 'mark' && currentItem?.status === 'saved'
                        && currentItem.queuedGrid != null && gridSnapshot() !== currentItem.queuedGrid;
                    saveQueueBtn.classList.toggle('d-none', allSaved && !failedHere && !editedHere);
                    if (saveQueueLabel) {
                        // A pair: this page and the one beside it are queued together.
                        const pairing = batchPairToQueue();
                        const hasMoreUnsaved = batchState.items.some((it, idx) => idx !== batchState.currentIndex
                            && it !== pairing?.mate.item && it.status === 'pending' && pageAlignable(it));
                        // The words only (how far the queue is shows in the bar above).
                        let text;
                        if (pairing) {
                            if (currentItem?.realign) text = hasMoreUnsaved ? 'Queue Both Again & Next' : 'Queue Both Again & Scan';
                            else if (failedHere) text = hasMoreUnsaved ? 'Queue Both Again & Next' : 'Queue Both Again';
                            else if (currentItem?.status === 'saved') text = hasMoreUnsaved ? 'Update & Save Both & Next' : 'Update & Save Both Pages';
                            else {
                                // " Pages" shows only where the panel is wide enough (.save-queue-opt).
                                text = hasMoreUnsaved ? 'Save Both[ Pages] & Next' : 'Save Both[ Pages]';
                            }
                        } else if (currentItem?.realign) {
                            text = hasMoreUnsaved ? 'Queue Again & Next' : 'Queue Again & Scan';
                        } else if (failedHere) {
                            text = hasMoreUnsaved ? 'Queue Again & Next' : 'Queue Again';
                        } else if (currentItem?.status === 'saved') {
                            text = hasMoreUnsaved ? 'Update & Next Document' : 'Update Queued Document';
                        } else {
                            text = hasMoreUnsaved ? 'Save & Next' : 'Save Final Document';
                        }
                        // Words in [ ] are optional: shown only where the panel has the room.
                        saveQueueLabel.replaceChildren(...text.split(/(\[[^\]]*\])/).filter(Boolean).map((part) => {
                            if (!part.startsWith('[')) return part;
                            const optional = document.createElement('span');
                            optional.className = 'save-queue-opt';
                            optional.textContent = part.slice(1, -1);
                            return optional;
                        }));
                    }
                }
                if (scanNowBtn) {
                    // Once every page is queued, Scan all reads what is queued (and failed
                    // pages again); with nothing left to read, Verify opens the records.
                    const action = batchScanAction();
                    scanNowBtn.classList.toggle('d-none', action.kind === 'none');
                    if (scanNowLabel) {
                        // How many pages in their own tag, as on Save: the label stays on one line.
                        scanNowLabel.replaceChildren(action.label);
                        if (action.count) {
                            const tag = document.createElement('span');
                            tag.className = 'save-queue-count';
                            tag.textContent = action.count;
                            scanNowLabel.append(tag);
                        }
                    }
                }
            } else {
                // Saved a record at a time, as in single mode: a page, or a pair of pages.
                const records = batchRecords();
                // Done counts as soon as Save & next is pressed (saved with the rest at the end).
                const finished = records.filter(recordSettled).length;
                // Which record is open, while it is still to save.
                const current = batchState.items[batchState.currentIndex];
                const position = current ? records.findIndex((record) => record.members.includes(current)) : -1;
                const onRecord = position !== -1 && !recordDone(records[position]);
                const allSaved = records.length > 0 && records.every(recordDone);
                if (phaseTitle) {
                    phaseTitle.textContent = phase === 'done' && !batchFinishing ? 'Batch complete'
                        : batchFinishing ? (allSaved ? 'Batch saved' : 'Saving the batch')
                            : (activeStep === 'review' ? 'Review & save' : 'Verify each record');
                }
                // Saving the Done records: which one is being saved, and how many are.
                const savedRecords = records.filter(recordDone).length;
                if (counter && batchFinishing) {
                    counter.textContent = allSaved
                        ? `All ${records.length} record${records.length === 1 ? '' : 's'} saved`
                        : `Saving record ${Math.min(savedRecords + 1, records.length)} of ${records.length}`
                            + (savedRecords > 0 ? ` · ${savedRecords} saved` : '');
                } else if (counter) {
                    counter.textContent = phase === 'done' || !onRecord
                        ? `${finished} of ${records.length} record${records.length === 1 ? '' : 's'} done`
                        : `Record ${position + 1} of ${records.length} · ${finished} done`;
                }
                const donePct = records.length > 0 ? Math.round((finished / records.length) * 100) : 100;
                if (batchFinishing && records.length > 0) {
                    // Every record is Done by now: the saved ones fill over them in green.
                    updateProgress(donePct, {
                        savedPct: Math.round((savedRecords / records.length) * 100),
                        text: `${savedRecords} of ${records.length} records saved`,
                    });
                } else {
                    updateProgress(donePct, { text: `${finished} of ${records.length} records done` });
                }
                if (saveQueueBtn) saveQueueBtn.classList.add('d-none');
            }

            // A page sent back from Verify can be taken back, while it is open in Align.
            const realigning = batchAligning() && !batchState.readingAll
                && [batchState.items[batchState.currentIndex], batchPartner()?.item].some((it) => it?.realign && it.page);
            el('cancelRealignBtn').classList.toggle('d-none', !realigning);

            renderBatchNotice();
            if (activeStep === 'mark') updateBatchPageBar();
            // While Scan all reads, the queue cannot be opened (nothing can be switched to).
            // Nor while Save & finish batch saves the records one after another.
            el('batchQueueToggle').disabled = batchState.readingAll || batchFinishing;
            if ((batchState.readingAll || batchFinishing) && queuePanel.open) {
                queuePanel.open = false;
                el('batchQueueToggle').setAttribute('aria-expanded', 'false');
            }
            syncBatchBarSpace();

            renderBatchQueuePanel();
            scheduleBatchSync();
        }

        // ------------------------------------------------ the queue panel
        // One line in the bar says where the user is; the panel lists every file
        // (its pages, their states and counts) and the pages of the file expanded,
        // each to open or remove. Only that file's pages are drawn, so a PDF of
        // hundreds of pages stays quick. Whole files are dragged to reorder.

        /** What the user sees for each page status (section 3 of the batch plan). */
        const BATCH_STATUS_BADGE = {
            pending: { cls: 'is-pending', icon: 'bx-time-five', text: 'Pending' },
            saving: { cls: 'is-working', icon: 'bx-refresh', text: 'Saving' },
            saved: { cls: 'is-queued', icon: 'bx-check-circle', text: 'Queued' },
            scanning: { cls: 'is-working', icon: 'bx-radar', text: 'Reading' },
            ready: { cls: 'is-ready', icon: 'bx-scan', text: 'Scanned' },
            verifying: { cls: 'is-verifying', icon: 'bx-edit-alt', text: 'Verifying' },
            imported: { cls: 'is-imported', icon: 'bx-check-shield', text: 'Saved' },
            rejected: { cls: 'is-failed', icon: 'bx-x-circle', text: 'Rejected' },
            failed: { cls: 'is-failed', icon: 'bx-error-circle', text: 'Failed' },
        };

        function statusBadge(item) {
            // Save & next pressed on it: Done, saved with the rest by Save & finish batch.
            if (item.status === 'ready' && item.approved) {
                return { cls: 'is-done', icon: 'bx-check', text: 'Done' };
            }
            const badge = { ...(BATCH_STATUS_BADGE[item.status] ?? BATCH_STATUS_BADGE.pending) };
            if (item.status === 'rejected' && item.skipped) badge.text = 'Skipped';
            return badge;
        }

        /** The state in the queue panel: a dot and a word, and nothing for pending (the default). */
        function stateElement(item) {
            if (item.status === 'pending') return null;
            const { cls, text } = statusBadge(item);
            const span = document.createElement('span');
            span.className = `batch-queue-state ${cls}`;
            span.textContent = text;
            return span;
        }

        function badgeElement({ cls, icon, text }, extraClass = '') {
            const span = document.createElement('span');
            span.className = `batch-queue-status ${cls}${extraClass ? ` ${extraClass}` : ''}`;
            const i = document.createElement('i');
            i.className = `icon-base bx ${icon}`;
            i.setAttribute('aria-hidden', 'true');
            span.append(i, text);
            return span;
        }

        const queuePanel = { open: false, filter: 'all', expanded: null, justExpanded: null };
        let draggedBatchIndex = null;
        let batchChipDidDrag = false;

        /** Whether a page can be opened from the queue: in Align, or (its record read) in Verify. */
        function batchPageOpenable(item) {
            if (batchState.readingAll) return false;
            // Verifying: a page of the open record shows (it slides into view); of another
            // record, only one that is read opens; the rest wait their turn.
            if (batchVerifying()) return inOpenVerifyRecord(item) || recordStateOf(item) === 'ready' || recordReadable(item);
            return pageAlignable(item) || pageNeedsFile(item) || recordStateOf(item) === 'ready' || recordReadable(item);
        }

        /** Whether a record is open in Verify or Review & Export now. */
        const batchVerifying = () => !batchAligning() && ['verify', 'review'].includes(activeStep);

        /** Why a page cannot be opened while records are verified (shown on it). */
        const VERIFY_WAIT_REASON = {
            pending: 'Not aligned yet: it comes after the records that are read',
            saving: 'Being queued',
            saved: 'Queued, not read yet',
            scanning: 'Being read',
            failed: 'Could not be read: re-align it once the records that are read are done',
            imported: 'Saved as a record',
            rejected: 'Left out of the batch',
        };

        /** "BIRTHS-1995.pdf · page 3 of 12", or the record in Verify, for the collapsed line. */
        /** Where the user is, in parts: the document's number, its file name, and its page(s). */
        function batchWhereParts() {
            const current = batchState.items[batchState.currentIndex];
            if (!current) return { name: 'Queue', pages: '', number: 0 };
            const name = current.sourceName ?? current.name;
            let pages = '';
            if (current.pageTotal > 1) {
                if (!batchAligning() && !batchState.readingAll) {
                    const numbers = recordOf(current).members.map((it) => it.pageNo);
                    pages = `page${numbers.length > 1 ? 's' : ''} ${pageRanges(numbers)}`;
                } else {
                    pages = `page ${current.pageNo} of ${current.pageTotal}`;
                }
            }
            const number = batchGroups().findIndex((group) => group.items.includes(current)) + 1;
            return { name, pages, number };
        }

        function setQueuePanelOpen(open) {
            if (queuePanel.open === open) return;
            queuePanel.open = open;
            if (open) queuePanel.expanded = batchState.items[batchState.currentIndex]?.group ?? queuePanel.expanded;
            el('batchQueueToggle').setAttribute('aria-expanded', open ? 'true' : 'false');
            renderBatchQueuePanel();
            // What shows as queued is checked with the server, so a page read meanwhile opens.
            if (open) {
                refreshQueuedPages().then((changed) => {
                    if (changed && queuePanel.open) renderBatchQueuePanel();
                });
            }
        }

        /** Whether the page asks for less motion (then nothing slides). */
        const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

        el('batchQueueToggle').addEventListener('click', () => setQueuePanelOpen(!queuePanel.open));
        // Like a dropdown: a click elsewhere, or Esc, closes it (not a click in a dialog it opened).
        // Heard on the way down (capture), as the page's markers stop their own clicks.
        document.addEventListener('pointerdown', (event) => {
            if (!queuePanel.open || !(event.target instanceof Element)) return;
            if (event.target.closest('#batchQueuePanel, #batchQueueToggle, .modal')) return;
            setQueuePanelOpen(false);
        }, true);
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || !queuePanel.open || document.querySelector('.modal.show')) return;
            setQueuePanelOpen(false);
            el('batchQueueToggle').focus();
        });
        el('batchQueueFilters').addEventListener('click', (event) => {
            const button = event.target.closest('[data-queue-filter]');
            if (!button) return;
            queuePanel.filter = button.dataset.queueFilter;
            renderBatchQueuePanel();
        });

        function renderBatchQueuePanel() {
            const groups = batchGroups();
            const counts = queueCounts(batchState.items);
            const files = groups.length;
            const where = batchWhereParts();
            // "2/4" says which document is open, so a pick in the list is easy to follow.
            const num = el('batchQueueToggleNum');
            num.textContent = files > 1 && where.number > 0 ? `${where.number}/${files}` : '';
            num.classList.toggle('d-none', num.textContent === '');
            el('batchQueueToggleText').textContent = where.name;
            const pagesTag = el('batchQueueTogglePages');
            pagesTag.textContent = where.pages;
            pagesTag.classList.toggle('d-none', where.pages === '');
            el('batchQueueToggle').title = files > 1 && where.number > 0
                ? `Document ${where.number} of ${files}: ${where.name}${where.pages ? ` · ${where.pages}` : ''}. Show every file and page in this batch`
                : 'Show every file and page in this batch';

            const panel = el('batchQueuePanel');
            // Closed: it slides away with what it showed; not focusable meanwhile.
            panel.inert = !queuePanel.open;
            if (!queuePanel.open) {
                panel.classList.remove('is-open');
                return;
            }
            if (!panel.classList.contains('is-open')) {
                // Drawn first, then shown, so it slides in from where it starts.
                window.requestAnimationFrame(() => {
                    if (queuePanel.open) panel.classList.add('is-open');
                });
            }

            // Filters and their counts. A tab with nothing in it is hidden (All stays),
            // and the open one falls back to All when it empties; with only All left,
            // the whole row goes and the list starts at the top.
            const filterCount = (name) => counts.filters[name] ?? 0;
            if (queuePanel.filter !== 'all' && filterCount(queuePanel.filter) === 0) queuePanel.filter = 'all';
            let tabsShown = 0;
            el('batchQueueFilters').querySelectorAll('[data-queue-filter]').forEach((button) => {
                const name = button.dataset.queueFilter;
                const active = name === queuePanel.filter;
                const shown = name !== 'all' && filterCount(name) > 0;
                if (shown) tabsShown += 1;
                button.classList.toggle('d-none', name !== 'all' && !shown);
                button.classList.toggle('active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
                button.querySelector('.batch-queue-filter__count').textContent = String(filterCount(name));
            });
            el('batchQueueHead').classList.toggle('d-none', tabsShown === 0);

            const matches = QUEUE_FILTERS[queuePanel.filter] ?? QUEUE_FILTERS.all;
            const list = el('batchQueueFiles');
            const scrollTop = list.scrollTop;
            const rows = [];
            groups.forEach((group, groupIndex) => {
                const shownPages = group.items.filter(matches);
                if (shownPages.length === 0) return;
                rows.push(fileRow(group, groupIndex, groups.length, shownPages));
            });
            if (rows.length === 0) {
                const empty = document.createElement('li');
                empty.className = 'batch-queue-files__empty';
                empty.textContent = 'No page matches this filter.';
                rows.push(empty);
            }
            list.replaceChildren(...rows);
            list.scrollTop = scrollTop;
            // The file just expanded slid open once; later redraws show it as it is.
            queuePanel.justExpanded = null;
        }

        /** One file: its name and pages, how they stand, remove; expanded, its pages. */
        /** What a file's state tag shows: its one page's own state, else the state that needs attention most. */
        function groupStateItem(group) {
            if (group.items.length === 1) return group.items[0];
            const status = groupStatus(group);
            return {
                status,
                // Every page still to save is Done: the file is Done.
                approved: status === 'ready' && group.items.every((it) => pageDone(it) || it.approved),
            };
        }

        function fileRow(group, groupIndex, groupCount, shownPages) {
            const first = group.items[0];
            const fileName = first.sourceName ?? first.name;
            const fileTotal = first.pageTotal ?? 1;
            const isCurrent = group.indices.includes(batchState.currentIndex);
            const expanded = queuePanel.expanded === group.key && fileTotal > 1;
            const canInteract = !batchState.readingAll;

            const li = document.createElement('li');
            li.className = `batch-queue-file${isCurrent ? ' is-current' : ''}`;
            li.dataset.group = group.key;
            const row = document.createElement('div');
            row.className = 'batch-queue-file__row';
            row.draggable = canInteract && groupCount > 1;
            // The handle says the row can be dragged (the whole row is what is dragged). Its
            // place is kept when it cannot be, so every file name starts at the same spot.
            const grip = document.createElement('span');
            grip.className = `batch-queue-file__grip${row.draggable ? '' : ' is-off'}`;
            grip.setAttribute('aria-hidden', 'true');
            if (row.draggable) grip.title = 'Drag to change the order';
            row.append(grip);

            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'batch-queue-file__toggle';
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            const index = document.createElement('span');
            index.className = 'batch-queue-file__index';
            index.textContent = String(groupIndex + 1);
            const name = document.createElement('span');
            name.className = 'batch-queue-file__name';
            name.textContent = fileName;
            name.title = fileName;
            const meta = document.createElement('span');
            meta.className = 'batch-queue-file__meta';
            // "12 pages · 4 queued · 1 failed", or "2 of 5 pages" when some were left out as saved.
            const pagesText = fileTotal > 1
                ? (group.items.length < fileTotal ? `${group.items.length} of ${fileTotal} pages` : `${fileTotal} pages`)
                : '';
            const stateText = Object.entries(queueCounts(group.items).byStatus)
                .map(([status, count]) => `${count} ${(BATCH_STATUS_BADGE[status]?.text ?? status).toLowerCase()}`);
            // The states only when they differ: one state is the badge beside it already.
            const mixed = stateText.length > 1;
            // Its pages listed right under it say how many there are: not said again
            // (unless some were left out: "2 of 5 pages" is news).
            const pagesListed = expanded && group.items.length >= fileTotal;
            meta.textContent = [pagesListed ? '' : pagesText, ...(mixed ? stateText : [])].filter(Boolean).join(' · ');
            if (fileTotal > 1) {
                const chevron = document.createElement('i');
                chevron.className = 'icon-base bx bx-chevron-right icon-sm batch-queue-file__chevron';
                chevron.setAttribute('aria-hidden', 'true');
                toggle.append(chevron);
            } else {
                // No pages to open: an empty space as wide as the arrow keeps the name in line.
                const spacer = document.createElement('span');
                spacer.className = 'batch-queue-file__chevron';
                spacer.setAttribute('aria-hidden', 'true');
                toggle.append(spacer);
            }
            toggle.append(index, name, meta);
            // A file of one page shows its state; one of many, its pages when expanded.
            // What still needs attention in the file wins (see groupStatus).
            const fileState = stateElement(groupStateItem(group));
            if (fileState) toggle.append(fileState);
            toggle.title = fileTotal > 1 ? 'Click the name to open the file, the arrow to show its pages' : 'Open it';
            toggle.addEventListener('click', (event) => {
                if (batchChipDidDrag) return;
                // The name opens the file; only the arrow shows or hides its pages.
                if (fileTotal > 1 && !event.target.closest('.batch-queue-file__chevron')) {
                    const target = group.items.findIndex(batchPageOpenable);
                    if (!isCurrent && target !== -1) {
                        setQueuePanelOpen(false);
                        openBatchDocument(group.indices[target]);
                    }
                    return;
                }
                if (fileTotal > 1) {
                    if (!expanded) {
                        queuePanel.expanded = group.key;
                        queuePanel.justExpanded = group.key;
                        renderBatchQueuePanel();
                        return;
                    }
                    // Its pages slide shut, then the list is drawn without them.
                    toggle.setAttribute('aria-expanded', 'false');
                    const wrap = li.querySelector('.batch-queue-pages-wrap');
                    let done = false;
                    const collapse = () => {
                        if (done) return;
                        done = true;
                        if (queuePanel.expanded === group.key) queuePanel.expanded = null;
                        renderBatchQueuePanel();
                    };
                    if (!wrap || reducedMotion()) {
                        collapse();
                        return;
                    }
                    wrap.addEventListener('transitionend', collapse, { once: true });
                    window.setTimeout(collapse, 320);
                    wrap.classList.remove('is-open');
                    return;
                }
                if (!isCurrent && batchPageOpenable(first)) {
                    setQueuePanelOpen(false);
                    openBatchDocument(group.indices[0]);
                }
            });

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'batch-queue-row__remove';
            remove.disabled = !canInteract || group.items.every(pageDone);
            remove.title = fileTotal > 1 ? 'Remove this file from the batch' : 'Remove from the batch';
            remove.setAttribute('aria-label', `Remove ${fileName} from the batch`);
            remove.innerHTML = '<i class="icon-base bx bx-x" aria-hidden="true"></i>';
            remove.addEventListener('click', (event) => {
                event.stopPropagation();
                const currentHere = isCurrent ? batchState.currentIndex : null;
                openBatchDocumentModal(group.indices[0], { pageIndex: currentHere });
            });
            row.append(toggle, remove);
            li.append(row);
            attachFileDrag(li, row, groupIndex);

            if (expanded) {
                // The pages sit in a wrapper whose height slides between none and theirs.
                const wrap = document.createElement('div');
                wrap.className = 'batch-queue-pages-wrap';
                const pages = document.createElement('ol');
                pages.className = 'batch-queue-pages';
                pages.setAttribute('aria-label', `Pages of ${fileName}`);
                shownPages.forEach((item) => pages.append(pageRow(item, group)));
                wrap.append(pages);
                li.append(wrap);
                if (queuePanel.justExpanded === group.key && !reducedMotion()) {
                    // Drawn shut, then opened, so it slides.
                    window.requestAnimationFrame(() => window.requestAnimationFrame(() => wrap.classList.add('is-open')));
                } else {
                    wrap.classList.add('is-open');
                }
            }
            return li;
        }

        /** One page of the file expanded: its state and flags, open on click, and remove. */
        function pageRow(item, group) {
            const index = batchState.items.indexOf(item);
            // In Verify one page is marked: the one slid to, or the first while both are in view.
            const inRecord = inOpenVerifyRecord(item);
            const isCurrent = inRecord && verifyDoc
                ? verifyDoc.pages[verifyFocus ?? 0]?.item === item
                : index === batchState.currentIndex;
            const li = document.createElement('li');
            li.className = `batch-queue-page${isCurrent ? ' is-current' : ''}`;

            const open = document.createElement('button');
            open.type = 'button';
            open.className = 'batch-queue-page__open';
            open.disabled = (index === batchState.currentIndex && !inRecord) || !batchPageOpenable(item);
            const number = document.createElement('span');
            number.className = 'batch-queue-page__number';
            number.textContent = `Page ${item.pageNo}`;
            open.append(number);
            // The file's tag already says what all its pages are: a page says its own
            // only when it is different (or failed, which needs a look).
            const sameAsFile = item.status !== 'failed'
                && statusBadge(item).text === statusBadge(groupStateItem(group)).text;
            const pageState = sameAsFile ? null : stateElement(item);
            if (pageState) open.append(pageState);
            if (item.status === 'failed' && item.error) {
                const error = document.createElement('span');
                error.className = 'batch-queue-page__error';
                error.textContent = item.error;
                open.append(error);
            }
            const waitsFor = recordOf(item).members.find((member) => member !== item && member.realign);
            open.title = inRecord ? 'Show this page'
                : isCurrent ? 'Open now'
                : (open.disabled && waitsFor ? `Waits for page ${waitsFor.pageNo} to be read again`
                : batchVerifying() && recordStateOf(item) !== 'ready' && recordReadable(item) ? 'Not read yet: click to read it now'
                : batchVerifying() && open.disabled ? (VERIFY_WAIT_REASON[item.status] ?? '')
                : recordStateOf(item) === 'ready' ? 'Open in Verify'
                    : (pageAlignable(item) ? 'Open in Align' : (pageNeedsFile(item) ? 'Pick its file again to align it' : '')));
            open.addEventListener('click', () => {
                if (open.disabled) return;
                // The page opens with the panel out of its way.
                setQueuePanelOpen(false);
                openBatchDocument(index);
            });

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'batch-queue-row__remove';
            remove.disabled = batchState.readingAll || pageDone(item);
            remove.title = 'Remove this page from the batch';
            remove.setAttribute('aria-label', `Remove page ${item.pageNo} from the batch`);
            remove.innerHTML = '<i class="icon-base bx bx-x" aria-hidden="true"></i>';
            remove.addEventListener('click', () => openBatchDocumentModal(group.indices[0], { pageIndex: index }));
            li.append(open, remove);
            return li;
        }

        /** Whole files are dragged to change their order (a PDF's pages stay together). */
        function attachFileDrag(li, row, groupIndex) {
            row.addEventListener('dragstart', (event) => {
                if (!row.draggable) return;
                draggedBatchIndex = groupIndex;
                batchChipDidDrag = true;
                li.classList.add('is-dragging');
                if (event.dataTransfer) {
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', String(groupIndex));
                }
            });
            const clearDropMarks = () => li.classList.remove('is-drag-over', 'is-drop-before', 'is-drop-after');
            li.addEventListener('dragover', (event) => {
                if (draggedBatchIndex === null || draggedBatchIndex === groupIndex) return;
                event.preventDefault();
                if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
                // The dragged file takes this one's place: a line on the side it will land.
                li.classList.add('is-drag-over');
                li.classList.toggle('is-drop-after', draggedBatchIndex < groupIndex);
                li.classList.toggle('is-drop-before', draggedBatchIndex > groupIndex);
            });
            li.addEventListener('dragleave', (event) => {
                if (!li.contains(event.relatedTarget)) clearDropMarks();
            });
            li.addEventListener('drop', (event) => {
                event.preventDefault();
                clearDropMarks();
                if (draggedBatchIndex === null || draggedBatchIndex === groupIndex) return;
                // Where every file is now, to slide each one to its new place.
                const before = new Map(Array.from(el('batchQueueFiles').children)
                    .map((node) => [node.dataset.group, node.getBoundingClientRect().top]));

                const activeItem = batchState.items[batchState.currentIndex];
                keepCurrentAlignment({ onlyIfNew: true });
                const reordered = batchGroups();
                const [movedGroup] = reordered.splice(draggedBatchIndex, 1);
                reordered.splice(groupIndex, 0, movedGroup);
                batchState.items = reordered.flatMap((g) => g.items);
                batchState.currentIndex = Math.max(0, batchState.items.indexOf(activeItem));
                draggedBatchIndex = null;
                showBatchContext();
                renderBatchQueueBanner();
                slideQueueRows(before);
            });
            row.addEventListener('dragend', () => {
                li.classList.remove('is-dragging');
                el('batchQueueFiles').querySelectorAll('.batch-queue-file').forEach((node) => node.classList.remove('is-drag-over', 'is-drop-before', 'is-drop-after'));
                draggedBatchIndex = null;
                window.setTimeout(() => { batchChipDidDrag = false; }, 60);
            });
        }

        /** After a reorder: each file slides from where it was to where it is now. */
        function slideQueueRows(before) {
            if (reducedMotion()) return;
            Array.from(el('batchQueueFiles').children).forEach((node) => {
                const was = before.get(node.dataset.group);
                if (was === undefined) return;
                const shift = was - node.getBoundingClientRect().top;
                if (Math.abs(shift) < 1) return;
                node.animate(
                    [{ transform: `translateY(${shift}px)` }, { transform: 'none' }],
                    { duration: 240, easing: 'cubic-bezier(.2, .8, .2, 1)' },
                );
            });
        }

        // A long list scrolls itself when a file is dragged near its top or bottom edge.
        el('batchQueueFiles').addEventListener('dragover', (event) => {
            if (draggedBatchIndex === null) return;
            const box = el('batchQueueFiles').getBoundingClientRect();
            const edge = 36;
            if (event.clientY < box.top + edge) el('batchQueueFiles').scrollTop -= 14;
            else if (event.clientY > box.bottom - edge) el('batchQueueFiles').scrollTop += 14;
        });

        // The file (index) and page (pageIndex, or null) the remove dialog is about.
        let selectedBatchModal = null;

        /** What each page status means, in the remove dialog. */
        const BATCH_STATUS_TEXT = {
            pending: 'Not aligned yet',
            saving: 'Saving',
            saved: 'Aligned and queued',
            scanning: 'Being read',
            ready: 'Scanned, waiting to be verified',
            verifying: 'Being verified',
            imported: 'Saved as a record',
            rejected: 'Rejected (already saved)',
            failed: 'Failed',
        };

        /** The pages of a file that can still be removed (saved and rejected ones are records). */
        function removableGroupPages(item) {
            return batchState.items.filter((it) => (it === item || (item.group && it.group === item.group)) && !pageDone(it));
        }

        /**
         * Ask before taking pages out of the batch. `pageIndex` is a page of the
         * file the user may remove alone (null: only the whole file is offered).
         */
        function openBatchDocumentModal(index, { pageIndex = null } = {}) {
            // The queue list closes first: it would show through above the dialog.
            setQueuePanelOpen(false);
            const item = batchState.items[index];
            if (!item) return;
            const filePages = removableGroupPages(item);
            if (filePages.length === 0) return;
            const page = pageIndex !== null ? batchState.items[pageIndex] : null;
            // A page alone is offered when its file has other pages to keep.
            const pageOffered = Boolean(page) && !pageDone(page) && filePages.length > 1 && filePages.includes(page);

            selectedBatchModal = { index, pageIndex: pageOffered ? pageIndex : null };
            const modalEl = document.getElementById('batchDocumentModal');

            const setText = (id, text) => {
                const node = document.getElementById(id);
                if (node) node.textContent = text;
            };
            const fileName = item.sourceName ?? item.name;
            const pagesInDoc = item.group ? batchState.items.filter((it) => it.group === item.group).length : 1;
            setText('batchDocumentModalTitle', pageOffered ? 'Remove from the batch?' : 'Remove from batch queue?');
            setText('batchDocumentName', pagesInDoc > 1 ? `${fileName} (${pagesInDoc} pages)` : item.name);
            setText('batchDocumentMeta', pageOffered
                ? `Page ${page.pageNo} of ${page.pageTotal} · ${BATCH_STATUS_TEXT[page.status] ?? 'Queued'}`
                : `Priority ${batchGroups().findIndex((group) => group.items.includes(item)) + 1} of ${batchGroups().length} · ${BATCH_STATUS_TEXT[groupStatus({ items: filePages })] ?? 'Queued'}`);

            const notes = [];
            const current = batchState.items[batchState.currentIndex];
            if (pageOffered && page === current) {
                notes.push('This is the page you are working on now. The next one opens in its place.');
            } else if (filePages.includes(current)) {
                notes.push(`You are working on ${pageOffered ? 'a page of this file' : 'this file'} now. The next page opens in its place if it is removed.`);
            }
            // Removing one page of a pair leaves the other as a record on its own.
            if (pageOffered) {
                const partner = recordOf(page).members.find((it) => it !== page && !pageDone(it));
                if (partner) {
                    notes.push(`Page ${page.pageNo} is read with page ${partner.pageNo} as one record. If only this page is removed, page ${partner.pageNo} is saved as a record on its own.`);
                }
            }
            if (filePages.some((it) => it.pageId || ['saved', 'ready', 'scanning', 'verifying'].includes(it.status))) {
                notes.push('Alignment and scan results of what is removed are discarded.');
            }
            const kept = batchState.items.filter((it) => item.group && it.group === item.group && pageDone(it)).length;
            if (kept > 0) {
                notes.push(`${kept} page${kept === 1 ? ' is' : 's are'} saved as records (or left out) already: ${kept === 1 ? 'it stays' : 'they stay'} in the batch summary.`);
            }
            notes.push('The file on your computer is not changed. You can add it again later.');
            const notesEl = document.getElementById('batchDocumentNotes');
            if (notesEl) {
                notesEl.replaceChildren(...notes.map((text) => {
                    const li = document.createElement('li');
                    li.textContent = text;
                    return li;
                }));
            }

            el('confirmDeleteBatchPageBtn').classList.toggle('d-none', !pageOffered);
            setText('confirmDeleteBatchPageLabel', pageOffered ? `Remove page ${page.pageNo} only` : 'Remove this page');
            setText('confirmDeleteBatchDocLabel', filePages.length > 1
                ? `Remove the whole file (${filePages.length} pages)`
                : 'Remove from queue');
            el('batchDocumentActions').classList.toggle('is-three', pageOffered);

            if (modalEl) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        }

        document.getElementById('batchDocumentModal')?.addEventListener('shown.bs.modal', () => {
            // Cancel is the safe default, so Enter never deletes by accident.
            document.getElementById('batchDocCancelBtn')?.focus();
        });

        /**
         * Open a queued page straight from its chip, where its own state says:
         * a page whose record is read opens in Verify; a page not read yet (or
         * one that failed) opens in Align.
         */
        /** A page of the record open now in Verify or Review & Export (not Align). */
        function inOpenVerifyRecord(item) {
            const current = batchState.items[batchState.currentIndex];
            return batchVerifying() && Boolean(current) && recordOf(current).members.includes(item);
        }

        /** The view slides to a page of the record open now (both pages are in view until one is picked). */
        function viewBatchRecordPage(item) {
            if (!verifyDoc) return;
            const side = verifyDoc.pages.findIndex((entry) => entry.item === item);
            if (side === -1 || (verifyFocusIsCurrent() && verifyFocus === side)) return;
            focusVerifyPage(side);
        }

        async function openBatchDocument(idx) {
            const item = batchState.items[idx];
            if (!item || batchState.readingAll) return;
            // A page of the record open now: it is in view already, so the view slides to it.
            if (inOpenVerifyRecord(item)) {
                viewBatchRecordPage(item);
                return;
            }
            if (idx === batchState.currentIndex) return;
            // Picked while on Review & Export: the record opens there too, not in Verify.
            const stayInReview = activeStep === 'review';
            // Shown as queued: the server may have read it already.
            if (item.status === 'saved') await refreshQueuedPages(recordOf(item).members);
            if (recordStateOf(item) === 'ready') {
                keepCurrentAlignment();
                if (stayInReview) reviewTable?.commit();
                await openBatchVerifyItem(idx);
                const opened = batchState.items[batchState.currentIndex];
                // Set up in Verify and moved on in the same moment, so Verify never shows.
                if (stayInReview && activeStep === 'verify' && recordOf(item).members.includes(opened)) {
                    openReview({ switching: true });
                }
            } else if (batchVerifying() && recordReadable(item)) {
                await readQueuedRecord(item);
            } else if (pageAlignable(item)) {
                keepCurrentAlignment();
                leaveBatchVerify();
                await openBatchQueueItem(idx);
            } else if (recordReadable(item)) {
                await readQueuedRecord(item);
            } else if (pageNeedsFile(item)) {
                await askForBatchFiles();
            }
        }

        /**
         * Leaving Verify (or Review & Export) for a page in Align: what was typed
         * and ticked on the record is kept, and it goes back to "Scanned".
         */
        function leaveBatchVerify() {
            const current = batchState.items[batchState.currentIndex];
            if (!current || batchAligning() || current.status !== 'verifying') return;
            keepVerifyDraft(current);
            recordOf(current).members.forEach((it) => {
                if (it.status === 'verifying') it.status = 'ready';
            });
        }

        document.getElementById('confirmDeleteBatchDocBtn')?.addEventListener('click', () => confirmBatchRemoval('file'));
        document.getElementById('confirmDeleteBatchPageBtn')?.addEventListener('click', () => confirmBatchRemoval('page'));

        async function confirmBatchRemoval(scope) {
            const modalEl = document.getElementById('batchDocumentModal');
            if (modalEl) window.bootstrap.Modal.getInstance(modalEl)?.hide();
            const selected = selectedBatchModal;
            selectedBatchModal = null;
            if (!selected) return;

            const targetItem = batchState.items[selected.index];
            const pageItem = selected.pageIndex !== null ? batchState.items[selected.pageIndex] : null;
            if (!targetItem) return;
            // Saved and rejected pages stay: they are records now, and the summary lists them.
            const removing = scope === 'page' && pageItem
                ? [pageItem].filter((it) => !pageDone(it))
                : removableGroupPages(targetItem);
            await removeBatchPages(removing);
        }

        /**
         * Take pages out of the batch: each is let go on the server, and what the
         * user is on carries on (the next page or record opens in its place).
         */
        async function removeBatchPages(removing) {
            if (removing.length === 0) return;
            const currentItem = batchState.items[batchState.currentIndex];
            const firstIndex = Math.min(...removing.map((it) => batchState.items.indexOf(it)));
            // Where the user is: in Align, or on a record in Verify.
            const aligning = batchAligning();
            // In Verify, the record shown changes when any of its pages goes.
            const shown = aligning ? [currentItem] : (currentItem ? recordOf(currentItem).members : []);
            const wasCurrent = removing.some((it) => shown.includes(it));
            if (!wasCurrent) keepCurrentAlignment();
            else if (!aligning) leaveBatchVerify();

            removing.forEach((gone) => {
                cancelPageOnServer(gone.pageId);
                if (gone.key) batchServer.removed.add(gone.key);
            });
            batchState.items = batchState.items.filter((it) => !removing.includes(it));

            if (batchState.items.length === 0) {
                batchState.active = false;
                batchState.currentIndex = 0;
                scanFile = null;
                scanFileInput.value = '';
                // Nothing left to resume: the batch kept on the server goes too.
                const emptied = batchServer.id;
                resetServerBatch();
                if (emptied !== null) discardServerBatch(emptied);
                renderBatchQueueBanner();
                showStep('upload');
                return;
            }

            if (wasCurrent) {
                // In Align, the next page that can be aligned (from where the removed
                // one was); in Verify, the next record that is read.
                const nextAlign = firstAlignableIndex(firstIndex);
                if (aligning && nextAlign !== -1) {
                    await openBatchQueueItem(nextAlign);
                } else {
                    batchState.currentIndex = Math.min(firstIndex, batchState.items.length - 1);
                    await continueBatch();
                }
            } else {
                batchState.currentIndex = Math.max(0, batchState.items.indexOf(currentItem));
                showBatchContext();
                // The page beside this one may be the one removed.
                if (aligning) updateSpread();
                renderBatchQueueBanner();
            }
        }

        const SUPPORTED_SCAN_EXT = /\.(pdf|png|jpe?g|webp|bmp)$/i;
        // What a folder brings that is not a scan, left out without a word: hidden
        // files (".DS_Store") and the ones Windows keeps beside pictures.
        const SYSTEM_FILE = /^(\..*|thumbs\.db|desktop\.ini)$/i;
        // Where a dropped file sat in what was dropped ("/Book 1/page-2.pdf").
        const droppedPaths = new WeakMap();
        // "page-2" before "page-10", a folder's files in its own order, and an
        // original before its copies (compareQueuePaths), so of identical files
        // the one queued is the original.
        const naturalOrder = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });
        const queuePath = (file) => droppedPaths.get(file) || file.webkitRelativePath || file.name;

        /** Whether a drop holds a folder (read while the drop event lasts). */
        function dropHasFolder(dataTransfer) {
            return Array.from(dataTransfer?.items ?? [])
                .some((item) => typeof item.webkitGetAsEntry === 'function' && item.webkitGetAsEntry()?.isDirectory);
        }

        /** Every file a drop brings, folders opened (call it while the drop event lasts). */
        async function extractDroppedScanFiles(dataTransfer) {
            if (!dataTransfer) return [];
            // The entries are taken at once: the drop's items are gone after the event.
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
                        droppedPaths.set(file, entry.fullPath || file.name);
                        collected.push(file);
                        resolve();
                    }, () => resolve());
                } else if (entry.isDirectory) {
                    const reader = entry.createReader();
                    // A folder is read in batches until one comes back empty.
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

        /**
         * Which pages of a file are saved already, by its fingerprint. A lookup
         * that fails says none: the server still asks before a page is saved twice.
         */
        async function savedPagesOfFile(fingerprint) {
            if (!fingerprint) return new Set();
            try {
                const response = await fetch(config.sourcePagesUrl.replace('0'.repeat(64), fingerprint), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: AbortSignal.timeout?.(10000),
                });
                if (!response.ok) throw new Error(`The server answered ${response.status}.`);
                const payload = await response.json();
                return new Set((payload.pages ?? []).map((entry) => Number(entry.page)));
            } catch (error) {
                console.warn('The saved pages of this file could not be looked up:', error);
                return new Set();
            }
        }

        /** Let the server drop a queued page no longer wanted (best effort). */
        function cancelPageOnServer(pageId) {
            if (!pageId) return;
            fetch(config.pageCancelUrl.replace('__PAGE__', String(pageId)), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': config.csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
            }).catch(() => {});
        }

        /**
         * The file a queued page keeps with its record: of a PDF of many pages,
         * that page alone, cut out with its original quality when it is queued
         * (not when the file is added); any other file as it is. A PDF that
         * cannot be cut goes whole when it is small enough, and the record still
         * knows its page.
         */
        async function queuedScanFile(item) {
            // A one-page PDF goes whole, unless it is too large to send: then as an image of its page.
            if (!(item.pageTotal > 1) && !(isPdfFile(item.file) && item.file.size > MAX_SENT_PDF_BYTES)) return item.file;
            try {
                return await pdfPageFile(item.file, item.pageNo);
            } catch (error) {
                console.warn(`Page ${item.pageNo} could not be cut out of ${item.sourceName}:`, error);
                // The whole PDF goes instead, when the server can take it.
                if (item.file.size <= MAX_SENT_PDF_BYTES) return item.file;
                throw new Error(`Page ${item.pageNo} could not be taken out of ${item.sourceName}. Save that page as its own file and add it to a batch.`);
            }
        }

        /**
         * Say what was left out of the files just added, grouped by why, and offer
         * to add the pages left out as saved already or already in this batch.
         * Files of pages are `{ name, pages, pageCount, of }` (`of`: the file in the
         * batch a copy repeats); `added` is `{ name, pages }` per file queued.
         * Resolves 'add' or 'ok' (at once, when there is nothing to say).
         */
        function askAboutLeftOut(notes, queuedCount, addableCount, added = []) {
            const plural = (count, noun) => `${count} ${noun}${count === 1 ? '' : 's'}`;
            const groups = [
                { title: 'Already saved as records', entries: notes.saved },
                { title: 'Copies of a file already in the batch', entries: notes.inBatch, copies: true },
                { title: 'Not a PDF or an image', entries: notes.unsupported },
                { title: 'Too large', entries: notes.tooLarge },
                { title: 'Could not be opened (empty, damaged or password-protected)', entries: notes.unreadable },
            ].filter((group) => group.entries.length > 0);
            if (groups.length === 0 && queuedCount > 0) return Promise.resolve('ok');

            // Files of pages (saved, or copies) are objects; the other reasons list names.
            const isFileEntry = (entry) => typeof entry === 'object';
            // "2 pages each" when every file lost all of its pages and they all have as many.
            const pagesEach = (entries) => {
                if (entries.length === 0 || !entries.every(isFileEntry)) return null;
                const whole = entries.every((entry) => entry.pages.length === entry.pageCount);
                return whole && new Set(entries.map((entry) => entry.pageCount)).size === 1 && entries[0].pageCount > 1
                    ? entries[0].pageCount
                    : null;
            };
            const entryLabel = (entry, each) => {
                if (!isFileEntry(entry)) return entry;
                if (each !== null || entry.pageCount <= 1) return entry.name;
                return `${entry.name}: page${entry.pages.length === 1 ? '' : 's'} ${pageRanges(entry.pages)}`;
            };

            // The first three, and the rest behind "show".
            const LIST_SHOWN = 3;
            const intakeList = (labels) => {
                const list = document.createElement('ul');
                list.className = 'batch-intake__list';
                labels.forEach((text, position) => {
                    const li = document.createElement('li');
                    li.textContent = text;
                    li.hidden = position >= LIST_SHOWN;
                    list.append(li);
                });
                if (labels.length > LIST_SHOWN) {
                    const more = document.createElement('li');
                    more.className = 'batch-intake__more';
                    const toggle = document.createElement('button');
                    toggle.type = 'button';
                    toggle.className = 'btn btn-link p-0 batch-intake__toggle';
                    let open = false;
                    const label = () => {
                        toggle.textContent = open ? 'Show fewer' : `and ${labels.length - LIST_SHOWN} more: show`;
                        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                    };
                    label();
                    toggle.addEventListener('click', () => {
                        open = !open;
                        Array.from(list.children).forEach((item, position) => {
                            if (position >= LIST_SHOWN && item !== more) item.hidden = !open;
                        });
                        label();
                    });
                    more.append(toggle);
                    list.append(more);
                }
                return list;
            };

            // No file at all (an empty folder, or only hidden system files): say what is accepted instead.
            const noFiles = groups.length === 0;
            el('batchIntakeModalTitle').textContent = noFiles ? 'No files found' : queuedCount > 0 ? 'Some files were left out' : 'Nothing was added';
            el('batchIntakeIconGlyph').className = `icon-base bx ${noFiles ? 'bx-folder-open' : 'bx-copy'}`;
            const addedPages = added.reduce((sum, entry) => sum + entry.pages, 0);
            let addedText = `${plural(added.length, 'file')} (${plural(addedPages, 'page')}) were added to the batch.`;
            if (queuedCount === 0 || added.length === 0) addedText = 'No page was added to the batch.';
            else if (added.length === 1) addedText = `${added[0].name} (${plural(addedPages, 'page')}) was added to the batch.`;
            el('batchIntakeSummary').textContent = groups.length === 0
                ? 'The folder is empty, or holds only hidden system files. Choose PDFs or images (PNG, JPG, WEBP, BMP).'
                : `${addedText} These were left out:`;
            el('batchIntakeGroups').classList.toggle('d-none', groups.length === 0);
            el('batchIntakeGroups').replaceChildren(...groups.map((group) => {
                const { entries } = group;
                const each = pagesEach(entries);
                const originals = group.copies ? [...new Set(entries.map((entry) => entry.of ?? null))] : [];
                const single = group.copies && originals.length === 1 && originals[0] !== null ? originals[0] : null;

                const section = document.createElement('section');
                section.className = 'batch-intake__group';
                const heading = document.createElement('h6');
                heading.className = 'batch-intake__title';
                let title = group.title;
                if (single !== null) title = `Copies of ${single}`;
                else if (group.copies) title = 'Copies of files already in the batch';
                heading.textContent = [
                    title,
                    plural(entries.length, 'file'),
                    ...(each !== null ? [`${each} pages each`] : []),
                ].join(' · ');
                section.append(heading);

                if (group.copies && single === null) {
                    // Copies of several files: each file's copies together, under its name.
                    originals.forEach((original) => {
                        const mine = entries.filter((entry) => (entry.of ?? null) === original);
                        const caption = document.createElement('p');
                        caption.className = 'batch-intake__of';
                        caption.append('Copies of ');
                        const strong = document.createElement('strong');
                        strong.textContent = original ?? 'a file already in the batch';
                        caption.append(strong, ':');
                        section.append(caption, intakeList(mine.map((entry) => entryLabel(entry, each))));
                    });
                } else {
                    section.append(intakeList(entries.map((entry) => entryLabel(entry, each))));
                }
                return section;
            }));

            const addButton = el('batchIntakeAddBtn');
            addButton.classList.toggle('d-none', addableCount === 0);
            addButton.textContent = `Add them anyway (${plural(addableCount, 'page')})`;
            el('batchIntakeAddNote').classList.toggle('d-none', addableCount === 0);
            // Leaving out is only a choice when something could be added after all.
            el('batchIntakeOkBtn').textContent = addableCount > 0 ? 'Leave them out' : 'OK';
            // Alone, the button takes the whole width.
            el('batchIntakeOkBtn').parentElement.classList.toggle('is-three', addableCount === 0);

            return new Promise((resolve) => {
                const modalEl = el('batchIntakeModal');
                let choice = 'ok';
                addButton.onclick = () => {
                    choice = 'add';
                    window.bootstrap.Modal.getInstance(modalEl)?.hide();
                };
                el('batchIntakeOkBtn').onclick = () => window.bootstrap.Modal.getInstance(modalEl)?.hide();
                modalEl.addEventListener('hidden.bs.modal', () => resolve(choice), { once: true });
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            });
        }

        // Files are being prepared for the queue: another drop waits until they are.
        let preparingBatch = false;

        async function startBatchQueue(fileList) {
            if (preparingBatch) {
                tellInApp('Files are still being added', 'The files added a moment ago are still being prepared. Add these once they are in the queue.');
                return;
            }

            // A folder is queued in its own order, "page-2" before "page-10".
            const notes = { saved: [], inBatch: [], unsupported: [], tooLarge: [], unreadable: [] };
            // Pages left out as saved already or already in this batch: added after all if asked.
            const leftOutPages = [];
            const picked = Array.from(fileList || [])
                .filter((file) => !SYSTEM_FILE.test(file.name))
                .sort((a, b) => compareQueuePaths(queuePath(a), queuePath(b), naturalOrder))
                .filter((file) => {
                    if (!SUPPORTED_SCAN_EXT.test(file.name)) {
                        notes.unsupported.push(file.name);
                        return false;
                    }
                    if (file.size === 0) {
                        notes.unreadable.push(file.name);
                        return false;
                    }
                    // A PDF may be large (only one page of it is ever sent); an image may not.
                    const limit = isPdfFile(file) ? MAX_PDF_BYTES : MAX_SCAN_BYTES;
                    if (file.size > limit) {
                        notes.tooLarge.push(`${file.name} (over ${limit >= 1024 ** 3 ? `${limit / 1024 ** 3} GB` : `${limit / 1024 / 1024} MB`})`);
                        return false;
                    }
                    return true;
                });

            // Each page of a PDF is queued on its own and aligned and scanned on its
            // own. It is drawn from the file itself, and cut out only when it is
            // queued, so adding a register of hundreds of pages takes a moment, not
            // a copy of every page. Pages are saved as in single mode: a page is a
            // record, or a pair (1–2, 3–4) when the layout has a page 2. Every file
            // is fingerprinted, so the record knows where it came from, and a page
            // saved before (or queued already in this batch) is left out.
            const pairs = Boolean(config.pageTwo);
            const queued = [];
            const groupStamp = Date.now().toString(36);
            let groupSeq = 0;
            const inQueue = new Set(batchState.active ? batchState.items.map(pageKey).filter(Boolean) : []);
            // The first file in the batch with each fingerprint: what a copy is a copy of.
            const keptBy = new Map();
            if (batchState.active) {
                batchState.items.forEach((item) => {
                    if (item.sourceHash && !keptBy.has(item.sourceHash)) keptBy.set(item.sourceHash, item.sourceName ?? item.name);
                });
            }
            // Which pages of a file are saved, asked once per file (its copies share the answer).
            const savedLookups = new Map();
            // Account settings > Uploads: with the check off, no file is left out as saved or as a copy.
            const checkDuplicates = Boolean(config.duplicateFileCheck);
            // Added to a batch under way (Add files): the page being worked on stays open.
            const adding = batchState.active && batchState.items.length > 0;

            preparingBatch = true;
            if (picked.length > 0) {
                dropzone.classList.add('is-loading');
                dropzone.setAttribute('aria-busy', 'true');
                setBatchAddBusy(adding ? 'Preparing…' : null);
            }
            try {
                for (const [position, file] of picked.entries()) {
                    if (dropzoneTitleEl && picked.length > 1) {
                        dropzoneTitleEl.textContent = `Preparing ${position + 1} of ${picked.length}…`;
                    }
                    if (adding && picked.length > 1) setBatchAddBusy(`Preparing ${position + 1} of ${picked.length}…`);
                    const group = `${groupStamp}-${groupSeq += 1}`;

                    // Its fingerprint and saved pages are looked up while its pages are counted.
                    const source = (async () => {
                        let fingerprint = null;
                        try {
                            fingerprint = await fileFingerprint(file);
                        } catch (error) {
                            console.warn(`${file.name} could not be fingerprinted:`, error);
                        }
                        if (fingerprint && checkDuplicates && !savedLookups.has(fingerprint)) savedLookups.set(fingerprint, savedPagesOfFile(fingerprint));
                        return { fingerprint, saved: fingerprint && checkDuplicates ? await savedLookups.get(fingerprint) : new Set() };
                    })();
                    const counting = isPdfFile(file)
                        ? marker.pdfPageCount(file).then((count) => Math.max(1, count), (error) => {
                            console.warn(`${file.name} could not be read:`, error);
                            return 0;
                        })
                        : Promise.resolve(1);
                    const [{ fingerprint, saved }, pageCount] = await Promise.all([source, counting]);
                    if (pageCount === 0) {
                        notes.unreadable.push(file.name);
                        continue;
                    }

                    // A page saved before, or one this batch has already (a copy of a
                    // file added twice), is left out, unless asked for below.
                    const savedHere = [];
                    const inBatchHere = [];
                    for (let page = 1; page <= pageCount; page += 1) {
                        const key = fingerprint !== null ? `${fingerprint}#${page}` : null;
                        const entry = {
                            file,
                            info: { order: position, group, pageNo: page, pageTotal: pageCount, sourceName: file.name, sourceHash: fingerprint },
                        };
                        if (saved.has(page)) {
                            savedHere.push(page);
                            leftOutPages.push(entry);
                        } else if (checkDuplicates && key !== null && inQueue.has(key)) {
                            inBatchHere.push(page);
                            leftOutPages.push(entry);
                        } else {
                            if (key !== null) inQueue.add(key);
                            if (fingerprint && !keptBy.has(fingerprint)) keptBy.set(fingerprint, file.name);
                            queued.push(entry);
                        }
                    }
                    if (savedHere.length > 0) notes.saved.push({ name: file.name, pages: savedHere, pageCount });
                    if (inBatchHere.length > 0) {
                        notes.inBatch.push({ name: file.name, pages: inBatchHere, pageCount, of: keptBy.get(fingerprint) ?? null });
                    }
                }
            } finally {
                preparingBatch = false;
                showUploadModeText();
                setBatchAddBusy(null);
                dropzone.classList.remove('is-loading');
                dropzone.removeAttribute('aria-busy');
            }

            // What was left out, in the page's own window (not a browser alert,
            // which froze the drop area mid-update). Pages left out as saved
            // already, or as copies of what this batch has, can be added anyway:
            // each is still checked before it is saved twice (Save again or Skip).
            const addedFiles = new Map();
            queued.forEach(({ file }) => addedFiles.set(file, (addedFiles.get(file) ?? 0) + 1));
            const addedSummary = [...addedFiles].map(([file, pages]) => ({ name: file.name, pages }));
            if (await askAboutLeftOut(notes, queued.length, leftOutPages.length, addedSummary) === 'add') {
                leftOutPages.forEach((entry) => { entry.info.addedAnyway = true; });
                queued.push(...leftOutPages);
                queued.sort((a, b) => a.info.order - b.info.order || a.info.pageNo - b.info.pageNo);
            }

            if (queued.length === 0) {
                scanFileInput.value = '';
                return;
            }

            if (!batchState.active) {
                batchState.active = true;
                batchState.key = 'batch_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8);
                batchState.readingAll = false;
                batchState.items = [];
                batchState.currentIndex = 0;
            }

            // The new pages go after the queue (appendToQueue), in their own order.
            const added = queued.map(({ file, info }) => ({
                // Its own name for the batch kept on the server.
                key: randomKey(),
                // The file itself: a PDF's page is drawn from it, and cut out when queued.
                file,
                name: info.pageTotal > 1 ? pageFileName(file.name, info.pageNo) : file.name,
                group: info.group,
                // The record this page is saved in, named by its first page.
                record: `${info.group}#${recordLeadPage(info.pageNo, info.pageTotal, pairs)}`,
                pageNo: info.pageNo,
                pageTotal: info.pageTotal,
                sourceName: info.sourceName,
                sourceHash: info.sourceHash,
                status: 'pending',
                pageId: null,
                page: null,
                workspaceScanPath: null,
                scanKind: 'full',
                geometry: null,
                recordUrl: null,
                recordId: null,
                error: null,
                boxes: null,
                rows: null,
                tilts: null,
                addedAnyway: Boolean(info.addedAnyway),
            }));
            batchState.items = appendToQueue(batchState.items, added, { allowCopies: !checkDuplicates });
            // Kept on the server from the first files, to resume after a reload or a crash.
            if (!adding) startServerBatch();
            // The files stay in this browser (not uploaded), so a resume needs no re-pick.
            new Map(added.filter((it) => it.sourceHash).map((it) => [it.sourceHash, it.file]))
                .forEach((file, hash) => { rememberBatchFile(hash, file); });

            scanFileInput.value = '';
            if (adding) {
                // The page being worked on stays open, as it is.
                showBatchContext();
                renderBatchQueueBanner();
                return;
            }
            await openBatchQueueItem(batchState.currentIndex);
        }

        /** "Preparing 2 of 5…" on Add files while added files are read, or its own label (null). */
        function setBatchAddBusy(text) {
            const label = document.getElementById('batchAddMoreLabel');
            if (label) label.textContent = text ?? 'Add files';
            document.getElementById('batchAddMoreBtn')?.toggleAttribute('disabled', text !== null);
            document.getElementById('batchAddMoreMenuBtn')?.toggleAttribute('disabled', text !== null);
        }

        /** The top bar's "Batch (3/12) · name" for the page open now. */
        function showBatchContext() {
            const item = batchState.items[batchState.currentIndex];
            if (!item || !batchAligning()) return;
            // The bar below names the file, the page and how far the batch is.
            el('workspaceContextDetail').textContent = config.typeLabel;
            el('workspaceContextDetail').title = config.typeLabel;
        }

        async function openBatchQueueItem(index) {
            const item = batchState.items[index];
            if (!item) return;

            // The page beside the one being aligned is already drawn: the two trade
            // places on the spot, and the view slides to the page now being aligned.
            const left = batchState.items[batchState.currentIndex];
            if (left) saveReferenceInputsForCurrentPage();
            // Smoke test's result goes with the page left, and comes with this one.
            if (left && left !== item) keepDetection(left);
            const tested = left !== item ? item.detected ?? null : null;
            if (left !== item) item.detected = null;
            // Only from Align (the page beside this one is shown there): coming from Verify (Re-align),
            // the document opens fitted, not zoomed in on one page.
            const swap = activeStep === 'mark' && batchPartner()?.index === index && companionMarker.canvas.width > 0
                && companionFile === (tested?.imageUrl ?? batchPageSource(item));
            batchState.currentIndex = index;
            // Which page is open is kept too (Resume opens it again).
            scheduleBatchSync();
            syncReferenceInputsForCurrentPage();
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
                } else if (!item.file && item.serverCopy) {
                    // Sent back by Re-align without its file: the page as the server has it,
                    // the other page of the document with it (drawn beside it).
                    await Promise.all(recordOf(item).members.filter((it) => it.serverCopy).map(ensureServerPage));
                    await marker.loadFromUrl(item.page.imageUrl);
                } else {
                    // A PDF's page is drawn from the file itself (kept open between its pages).
                    await marker.load(item.file, { page: item.pageNo ?? 1 });
                }
                scanPageCount = 1;
                scanPageNumber = 1;
                if (swap && left) {
                    // What sits beside the page now is the one just left.
                    companionPage = left.pageNo;
                    companionFile = left.detected?.imageUrl ?? batchPageSource(left);
                    updateSpread();
                } else {
                    resetSpread();
                }
                updateScanNotices();
                // The bar names the file, the page and how far the batch is.
                el('workspaceContextDetail').textContent = config.typeLabel;
                el('workspaceContextDetail').title = config.typeLabel;
                showStep('mark');
                guardBackButton();
                resetFieldHistory();
                rowsSource = null;

                // Re-align: what Reset goes back to, and what the note under Save compares with.
                realignBase = realignBaseline(item);
                if (item.boxes && item.rows) {
                    // A page opened before keeps its own markers, and which of them were placed by hand.
                    setGrid(item.boxes, item.rows, { tilts: item.tilts, remember: !item.autoFields });
                    if (item.autoFields) autoFields = new Map(item.autoFields);
                } else {
                    // A new page starts on its layout's template grid. Snap to table is
                    // pressed by hand, on each page.
                    setGrid(templateBoxes, templateRows, { remember: true, tilts: templateTilts });
                }
                if (tested) await applyDetection(tested, { imageShown: true, keepView: swap });
                clearOcrError();
                if (item.status === 'failed' && item.error) {
                    const why = String(item.error).replace(/[.\s]+$/, '');
                    // A page that was queued was read (and failed); one that never was queued could not be opened.
                    showOcrError(item.pageId
                        ? `This page could not be read: ${why}. Re-align it and queue it again, or remove it.`
                        : `This page could not be opened: ${why}. Try it again, or remove it.`);
                }
                // What a queued page was queued with: moved since, it can be updated (Save shows again).
                item.queuedGrid = item.status === 'saved' ? gridSnapshot() : null;
                // No Snap runs by itself in a batch: the printed lines come with Snap to table, pressed by hand.
                snapToken += 1;
                marker.setSnapLines(null);
                renderBatchQueueBanner();
                // The other page of the pair is zoomed on and centred; a different
                // document is fitted afresh.
                if (swap) focusMainPage();
                else window.requestAnimationFrame(() => marker.resetZoom());
            } catch (error) {
                item.status = 'failed';
                item.error = error.message || 'Could not open file';
                renderBatchQueueBanner();
                tellInApp('This page could not be opened', error.message || '');
            } finally {
                dropzone.classList.remove('is-loading');
                dropzone.removeAttribute('aria-busy');
            }
        }

        // Add files: more files (or a folder) for this batch, their pages after the queue.
        document.getElementById('batchAddMoreBtn')?.addEventListener('click', () => {
            if (preparingBatch) return;
            keepCurrentAlignment();
            scanFileInput.setAttribute('multiple', 'multiple');
            scanFileInput.value = '';
            scanFileInput.click();
        });
        document.getElementById('batchAddFolderBtn')?.addEventListener('click', () => {
            if (preparingBatch || !(scanFolderInput instanceof HTMLInputElement)) return;
            keepCurrentAlignment();
            scanFolderInput.value = '';
            scanFolderInput.click();
        });

        /**
         * A page sent back by Re-align, read again on the server as the same page: its new
         * markers go with keep_readings, so only the fields cut out differently are read.
         * The reading starts at once; Scan all's window then waits for it.
         */
        async function rereadBatchPage(item, geometry) {
            const rowsMoved = JSON.stringify((item.page?.geometry?.ruled_ys ?? []).map(Number))
                !== JSON.stringify((geometry?.ruled_ys ?? []).map(Number));
            const response = await fetch(config.pageGeometryUrl.replace('__PAGE__', String(item.pageId)), {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': config.csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ geometry_json: JSON.stringify(geometry), keep_readings: true }),
            });
            const payload = (response.headers.get('content-type') || '').includes('application/json')
                ? await response.json()
                : null;
            if (!response.ok || !payload?.id) throw new Error(responseErrorMessage(response, payload));

            item.page = payload;
            item.error = null;
            item.realign = false;
            item.serverCopy = false;
            item.scanKind = 'reoutline';
            item.sentToScan = true;
            item.status = 'saved';
            // Row lines moved: the people may line up differently, so cells typed in Review &
            // Export for this record no longer fit (Verify's typed values follow their lines).
            if (rowsMoved) {
                const lead = recordOf(item).members[0];
                if (lead?.reviewExtras?.size) {
                    lead.reviewExtras = null;
                    syncBatchDraft(lead);
                }
            }
            return payload;
        }

        /**
         * The other page of the pair open in Align (pages 1-2, 3-4), when Save & Next
         * queues it together with this one: it is not queued yet, its file is at hand,
         * and it is drawn beside this page with markers a page can be read on. Null:
         * this page is saved alone. Nothing about how well it is aligned is asked.
         *
         * @returns {{mate: {index: number, item: object}, grid: object}|null}
         */
        function batchPairToQueue() {
            if (!batchAligning()) return null;
            const mate = batchPartner();
            if (!mate || !(mate.item.file || mate.item.serverCopy) || !['pending', 'failed'].includes(mate.item.status)) return null;
            if (companionPage !== mate.item.pageNo || !(companionMarker.canvas.width > 0)) return null;
            const grid = companionGridFor(mate.item.pageNo, mate);
            if (grid.boxes.length === 0 || markerSetValidationMessage(grid.boxes) !== null) return null;
            return { mate, grid };
        }

        /**
         * The page as drawn now, for sendBatchPageToQueue (null when none is sent: a page
         * Smoke test went over, or one re-read on the server): a pixel-for-pixel copy, made
         * at once (the canvas shows the next page soon), turned into a PNG only when sent.
         */
        function queuedPageImage(item, canvas, tested) {
            if (tested || (item.realign && item.serverCopy && item.pageId)) return null;
            const copy = document.createElement('canvas');
            copy.width = canvas.width;
            copy.height = canvas.height;
            copy.getContext('2d').drawImage(canvas, 0, 0);
            return copy;
        }

        // Pages on their way to the queue in the background: Save & Next opens the next
        // page at once and they are sent behind it, one save after another, in order.
        let batchUploadChain = Promise.resolve();
        let batchUploadsInFlight = 0;

        /** Run `job` (an upload) after the ones already on their way; resolves or rejects with it. */
        function queueBatchUpload(job) {
            batchUploadsInFlight += 1;
            const run = batchUploadChain.then(job);
            batchUploadChain = run.catch(() => {}).finally(() => {
                batchUploadsInFlight -= 1;
            });
            return run;
        }

        /**
         * Every page still on its way, sent (or given up on): before Scan all reads the
         * queue, and before the batch is kept for later or discarded. Waits at most `limitMs`.
         */
        async function waitForBatchUploads(limitMs = 30000) {
            if (batchUploadsInFlight === 0) return;
            await Promise.race([batchUploadChain, wait(limitMs)]);
        }

        /**
         * Send one page to the queue, where it waits for Scan all: its file (of a PDF,
         * the page alone, cut out now) and, unless Smoke test went over it, the page as
         * drawn with its markers (`pageImage`, from queuedPageImage). Marks the item
         * queued; throws when it could not be sent.
         */
        async function sendBatchPageToQueue(item, {
            pageImage, geometry, templateId, tested, scanKind,
        }) {
            // Re-align: the same page on the server, read again with the markers as they are
            // now. Fields cut out as before keep their reading (and what was typed on them).
            if (!tested && item.realign && item.serverCopy && item.pageId) {
                return rereadBatchPage(item, geometry);
            }
            const form = new FormData();
            if (item.file) {
                const scan = await queuedScanFile(item);
                form.set('scan', scan, scan.name);
            } else if (item.pageId) {
                // No file at hand (Re-align after a resume): the server copies the original it keeps.
                form.set('scan_from_page', String(item.pageId));
            }
            form.set('batch_key', batchState.key);
            let url = config.pagesUrl;
            if (tested) {
                url = config.pageQueueUrl.replace('__PAGE__', String(tested.id));
            } else {
                form.set('document_template_id', String(templateId));
                form.set('geometry_json', JSON.stringify(geometry));
                form.set('page', await canvasBlob(pageImage), 'page.png');
                form.set('defer_scan', '1');
            }

            const response = await fetch(url, {
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
            // Taken out of the batch while it was on its way: the page made for it is let go.
            if (!batchState.items.includes(item)) {
                cancelPageOnServer(payload.id);
                return payload;
            }

            // A page queued before in its place (Update queued document, or a
            // page read before and queued again) is let go, its reading with it.
            if (item.pageId && item.pageId !== payload.id) cancelPageOnServer(item.pageId);
            item.pageId = payload.id;
            item.page = payload;
            item.error = null;
            item.realign = false;
            // Queued (again) means read (again): it is checked again before it is Done.
            item.approved = null;
            item.workspaceScanPath = payload.workspaceScanPath || item.workspaceScanPath || null;
            item.serverCopy = false;
            item.scanKind = scanKind;
            item.sentToScan = false;
            item.status = 'saved';
            return payload;
        }

        document.getElementById('saveQueueNextBtn')?.addEventListener('click', async () => {
            if (scanInProgress || !batchState.active) return;

            saveReferenceInputsForCurrentPage();
            if (!validateDocumentReference()) {
                return;
            }

            const markerValidationMessage = markerSetValidationMessage(marker.toJSON());
            if (markerValidationMessage) {
                showOcrError(markerValidationMessage);
                return;
            }

            const item = batchState.items[batchState.currentIndex];
            if (!item) return;

            // A page with nothing marked (page 2 of a book whose fields are all on
            // page 1) has nothing to read: it is left out, and the batch goes on.
            if (marker.toJSON().length === 0) {
                if (await askInApp({
                    title: `Page ${item.pageNo ?? ''} has no markers`.replace('  ', ' '),
                    text: 'Nothing on it is read, so it is left out of the batch. The other pages are read as usual.',
                    confirm: 'Leave it out',
                    cancel: 'Go back',
                }) !== 'confirm') {
                    return;
                }
                clearOcrError();
                await removeBatchPages([item]);
                return;
            }

            // Re-align with fields moved: say which are scanned again first.
            if (!(await confirmRealignChanges())) return;

            // Smoke test went over this page: it joins the queue as tested, with
            // the lines it found and any outline fixed by hand, so Scan all only
            // reads it (or outlines it again from markers moved since) instead of
            // finding its lines afresh. Outline changes count once saved, as Scan
            // with OCR asks in single mode.
            applyOpenDrawing();
            const tested = detection !== null && !detection.stale ? detection.page : null;
            if (tested) {
                const unsaved = unsavedLineCount();
                if (unsaved > 0) {
                    const plural = unsaved === 1 ? 'line has an outline change' : 'lines have outline changes';
                    if (await askInApp({
                        title: `${unsaved} ${plural} not saved`,
                        text: 'Save them and add this page to the queue?',
                        confirm: 'Save and queue',
                        cancel: 'Go back',
                    }) !== 'confirm') {
                        return;
                    }
                    await saveAllUnsavedLines();
                    if (unsavedLineCount() > 0) {
                        showOcrError('Some outline changes could not be saved. Check the lines marked not saved and try again.');
                        return;
                    }
                }
                if (canReoutline()) {
                    const adjusted = tested.lines.filter((line) => line.adjusted || isLineUnsaved(line)).length;
                    if (adjusted > 0 && await askInApp({
                        title: `${adjusted} outline adjustment${adjusted === 1 ? '' : 's'} will be lost`,
                        text: 'You moved a marker after Smoke test, so every line is worked out again when the batch is scanned.'
                            + '\n\nGo back and press Ctrl+Z to bring the Smoke test result back.',
                        confirm: 'Queue anyway',
                        cancel: 'Go back',
                    }) !== 'confirm') {
                        return;
                    }
                }
            }

            const btn = el('saveQueueNextBtn');
            btn.disabled = true;
            // A loading circle in place of the save icon until the next page is open.
            el('saveQueueNextIcon')?.classList.add('d-none');
            el('saveQueueNextSpinner')?.classList.remove('d-none');
            clearOcrError();
            // The other page of the pair goes to the queue with this one (null: this page alone).
            const pairing = batchPairToQueue();
            const mate = pairing?.mate.item ?? null;
            // What the pages were before, to go back to if one cannot be sent (it was never read).
            const statusBefore = item.status;
            const mateStatusBefore = mate?.status;
            let mateError = null;
            let uploaded = false;

            try {
                item.boxes = cloneBoxes(marker.toJSON());
                item.rows = [...gridRows];
                item.tilts = [...rowTilts];
                item.autoFields = new Map(autoFields);
                item.rowsByHand = rowsByHand;
                item.geometry = currentGeometry();
                item.bookNo = getBookNumber();
                item.pageNoRef = getPageNumber();
                if (mate) {
                    mate.bookNo = item.bookNo;
                    if (!mate.pageNoRef) mate.pageNoRef = item.pageNoRef;
                }
                // What it is queued with, kept now: the grid on screen soon is the next page's.
                const queuedGrid = gridSnapshot();

                // What reading this page still takes (sets Scan all's pace too): only
                // reading a Smoke test result, outlining it again from moved markers,
                // or the whole page. The page only waits in the queue: Scan all reads it.
                const scanKind = tested ? (detectionIsCurrent() ? 'read' : 'reoutline') : 'full';
                const realigned = Boolean(item.realign) || Boolean(mate?.realign);
                // Both pages as drawn now, before anything else is drawn on their canvases.
                const mainImage = queuedPageImage(item, marker.canvas, tested);
                const mateTested = mate?.detected ?? null;
                let mateImage = null;
                if (mate) {
                    // The other page as it is shown beside this one, sent at the same time.
                    const { grid } = pairing;
                    mate.boxes = cloneBoxes(grid.boxes);
                    mate.rows = [...grid.rows];
                    mate.tilts = grid.tilts ? [...grid.tilts] : grid.rows.map(() => 0);
                    mate.geometry = alignedGeometry(grid.boxes, grid.rows, { keepRows: Boolean(mate.rowsByHand) });
                    mateImage = queuedPageImage(mate, companionMarker.canvas, mateTested);
                }
                const mateTemplateId = mate ? layoutFor(mate.pageNo).templateId : null;
                item.status = 'saving';
                if (mate) mate.status = 'saving';

                // The upload itself: both pages at once; a page alone that cannot be sent
                // goes back to what it was, and the batch goes on.
                const upload = async () => {
                    const mainJob = sendBatchPageToQueue(item, {
                        pageImage: mainImage,
                        geometry: item.geometry,
                        templateId: config.templateId,
                        tested,
                        scanKind,
                    });
                    const mateJob = mate
                        ? sendBatchPageToQueue(mate, {
                            pageImage: mateImage,
                            geometry: mate.geometry,
                            templateId: mateTemplateId,
                            // A page Smoke test went over joins as it was tested (its markers still fit).
                            tested: mateTested,
                            scanKind: mateTested ? 'read' : 'full',
                        }).catch((error) => {
                            mateError = error;
                            mate.status = mateStatusBefore;
                        })
                        : Promise.resolve();
                    try {
                        await mainJob;
                    } catch (error) {
                        await mateJob;
                        item.status = statusBefore;
                        throw error;
                    }
                    item.queuedGrid = queuedGrid;
                    await mateJob;
                };
                const mateFailed = () => {
                    const why = String(mateError.message || 'the server could not be reached').replace(/[.\s]+$/, '');
                    return `${batchPageName(mate)} could not be added to the queue: ${why}. Nothing was read. Align it and save it again once that is fixed.`;
                };

                // The next page to align (one being sent is not it), if any.
                // (A resumed page whose file is not picked again yet is skipped.)
                const toAlign = (it) => it.status === 'pending' && pageAlignable(it);
                const nextPendingIndex = batchState.items.findIndex((it, idx) => idx > batchState.currentIndex && toAlign(it));
                const targetIndex = nextPendingIndex !== -1 ? nextPendingIndex : batchState.items.findIndex(toAlign);
                renderBatchQueueBanner();

                if (targetIndex !== -1 && !realigned) {
                    // Sent in the background: the next page opens now, and the queue list shows
                    // these as saving until they are in. One that cannot be sent goes back to
                    // Pending (its alignment kept) and says so. The pages are made into PNGs
                    // and sent once the next page is drawn, so they do not slow that down.
                    let nextPageShown = () => {};
                    const nextPageDrawn = new Promise((resolve) => { nextPageShown = resolve; });
                    queueBatchUpload(async () => {
                        await nextPageDrawn;
                        return upload();
                    }).then(() => {
                        renderBatchQueueBanner();
                        if (mateError) showOcrError(mateFailed());
                    }, (error) => {
                        renderBatchQueueBanner();
                        const why = String(error.message || 'the server could not be reached').replace(/[.\s]+$/, '');
                        showOcrError(`${batchPageName(item)} could not be added to the queue: ${why}. Nothing was read. Open it from the queue list and save it again once that is fixed.`);
                    });
                    uploaded = true;
                    try {
                        await openBatchQueueItem(targetIndex);
                    } finally {
                        nextPageShown();
                    }
                    return;
                }

                // The last page to queue (or one sent back by Re-align): waited for here, after
                // any still on their way, so what comes next sees every page queued.
                await queueBatchUpload(upload);
                uploaded = true;
                renderBatchQueueBanner();
                if (realigned) {
                    // A page sent back from Verify is read again now: just this record's pages
                    // (Scan all's window), and the record opens again once they are read.
                    await runBatchScanAll({
                        pages: recordOf(item).members.filter((it) => it.status === 'saved' && it.pageId),
                        after: item,
                        prefer: item,
                    });
                } else if (targetIndex !== -1) {
                    await openBatchQueueItem(targetIndex);
                }
                // The other page could not be sent: it waits to be queued again.
                if (mateError) showOcrError(mateFailed());
            } catch (error) {
                if (uploaded) {
                    // Queued (or on its way); what failed came after (opening the next page, say).
                    showOcrError(error.message || 'The page was queued, but the next step failed.');
                } else {
                    // Never sent, so never read: it went back to what it was, not to "Failed".
                    if (item.status === 'saving') item.status = statusBefore;
                    if (mate?.status === 'saving') mate.status = mateStatusBefore;
                    const why = String(error.message || 'the server could not be reached').replace(/[.\s]+$/, '');
                    showOcrError(`This page could not be added to the queue: ${why}. Nothing was read. Queue it again once that is fixed.`);
                }
                renderBatchQueueBanner();
            } finally {
                btn.disabled = false;
                el('saveQueueNextSpinner')?.classList.add('d-none');
                el('saveQueueNextIcon')?.classList.remove('d-none');
            }
        });

        /**
         * Scan all: read the queue. By default every queued page not read yet, and
         * failed ones (read again as they are); a page already read is not read
         * again. `pages` narrows it (a page sent back by Re-align), and `after`
         * is the record the batch returns to once they are read.
         */
        async function runBatchScanAll({ pages = null, after = null, prefer = null } = {}) {
            if (scanInProgress || !batchState.active) return;
            // Pages still on their way to the queue are in first.
            await waitForBatchUploads();

            const itemsToScan = pages ?? batchPagesToRead();
            const total = itemsToScan.length;
            if (total === 0) return;

            batchState.readingAll = true;
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

            scanInProgress = true;
            clearOcrError();
            button.disabled = true;
            el('detectBtn').disabled = true;

            // What each page still needs, as in single mode (see scanKind): a page
            // Smoke test went over is only read, or outlined again from its moved
            // markers; any other page has its lines found first. A page already
            // read (a run stopped partway) is not read again.
            // A page the worker still has from that run ('busy') is only waited for.
            const kindOf = (item) => {
                if (item.page?.status === 'ready') return 'done';
                if (item.sentToScan && ['queued', 'detecting', 'reading'].includes(item.page?.status)) return 'busy';
                if (item.scanKind === 'read' && item.page?.status === 'detected') return 'read';
                return item.scanKind === 'reoutline' ? 'reoutline' : 'full';
            };
            const secondsOf = (item, kind) => {
                if (kind === 'done') return 0;
                if (kind === 'busy') return pageSeconds(['read', 'reoutline'].includes(item.scanKind) ? item.scanKind : 'full', scanSeconds);
                return pageSeconds(kind, scanSeconds);
            };
            const unitWord = `page${total === 1 ? '' : 's'}`;

            try {
                // Without the OCR service no page can be read: say so now, before
                // every page spends its time finding lines only to fail. A service
                // that is off takes about 2 s to say so: the button says what it waits on.
                button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Checking the OCR service…';
                const ocrDownNow = await ocrServiceDown(controller.signal);
                button.innerHTML = originalButtonContent;
                if (ocrDownNow) throw new Error(OCR_DOWN_MESSAGE);

                progress = openProgressModal();
                stopOcrProgress();

                // A run stopped partway (cancelled, or a page failed): what the
                // server holds now for the pages it had sent, so each is taken up
                // where it is instead of from a status remembered here.
                for (const item of itemsToScan.filter((it) => it.sentToScan && it.page?.statusUrl)) {
                    try {
                        const response = await fetch(item.page.statusUrl, {
                            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                            signal: controller.signal,
                        });
                        if (response.ok) item.page = await response.json();
                    } catch (error) {
                        if (error.name === 'AbortError') throw error;
                        console.warn(`The status of ${item.name} could not be checked:`, error);
                    }
                }

                const trackList = itemsToScan.map((item) => {
                    const kind = kindOf(item);
                    return { item, kind, seconds: secondsOf(item, kind), done: kind === 'done' };
                });
                // Stopping this run keeps what Smoke test found on a page being only
                // read (the server puts it back to "detected"), as in single mode.
                run.keepDetectionIds = new Set(trackList.filter((entry) => entry.kind === 'read').map((entry) => entry.item.pageId));
                // The ring crosses the whole batch at a steady pace over the time
                // its pages usually take on this machine (one at a time, as the
                // worker takes them), and the pill says the time left.
                const expected = scanSeconds.wait + trackList.reduce((sum, entry) => sum + entry.seconds, 0);
                beginOcrProgress(total, expected);
                setOcrPhase(4, 96, expected, `Scanning batch (0 of ${total} ${unitWord})`,
                    `Queueing all ${total} ${unitWord} of the batch for OCR.`, null);
                // Pages, not markers, while they are sent.
                el('ocrProgressFields').textContent = `${trackList.filter((entry) => entry.done).length} of ${total} ${unitWord} scanned`;

                // Every page goes to the worker up front, so the batch is one run.
                for (const entry of trackList) {
                    if (entry.done || entry.kind === 'busy') {
                        entry.page = entry.item.page;
                        continue;
                    }
                    const { item } = entry;
                    const read = entry.kind === 'read';
                    const triggerRes = await fetch(read ? item.page.readUrl : config.pageGeometryUrl.replace('__PAGE__', String(item.pageId)), {
                        method: read ? 'POST' : 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': config.csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                            Accept: 'application/json',
                        },
                        credentials: 'same-origin',
                        signal: controller.signal,
                        body: JSON.stringify(read ? {} : {
                            geometry_json: JSON.stringify(item.geometry ?? item.page?.geometry ?? currentGeometry()),
                        }),
                    });
                    const triggeredPayload = (triggerRes.headers.get('content-type') || '').includes('application/json')
                        ? await triggerRes.json()
                        : null;
                    if (!triggerRes.ok || !triggeredPayload?.statusUrl) {
                        // This page could not be sent: it fails alone, and the batch goes on.
                        failBatchEntry(entry, responseErrorMessage(triggerRes, triggeredPayload));
                        continue;
                    }
                    item.page = triggeredPayload;
                    item.sentToScan = true;
                    entry.page = triggeredPayload;
                    entry.done = triggeredPayload.status === 'ready';
                }

                // The worker takes the pages in turn: the one it is on is the one
                // finding lines or reading, else the first not done.
                let lastFinishedAt = performance.now();
                let waitFirst = scanSeconds.wait;
                // Each round asks only about the page being worked on and the next
                // one; every page is asked about now and then, in case one ended
                // out of turn. Asking about all of them every round kept the
                // server busy answering for pages still waiting in line.
                let lastSweepAt = performance.now();
                // A failed check (a network blip, the server busy) is asked again;
                // only a server silent for this long ends the run.
                let lastAnswerAt = performance.now();
                let reconnecting = false;
                // When the worker last moved on: a page that stays queued long after
                // it means no worker is running.
                let lastMovedAt = performance.now();
                const ocrDown = [];
                const inProgress = (entry) => !entry.done && ['detecting', 'reading'].includes(entry.page?.status);
                for (;;) {
                    const readyCount = trackList.filter((entry) => entry.done).length;
                    const active = trackList.find(inProgress) ?? trackList.find((entry) => !entry.done) ?? null;
                    const doneSeconds = trackList.filter((entry) => entry.done).reduce((sum, entry) => sum + entry.seconds, 0);
                    // The ring never lags the pages actually done.
                    if (expected > 0) setOcrProgress(Math.max(ocrProgress, 4 + 92 * (doneSeconds / expected)));

                    if (!active) break;

                    const status = active.page?.status;
                    const stage = reconnecting ? 'Reconnecting to the server'
                        : status === 'reading' ? 'Reading handwriting with TrOCR'
                            : status === 'detecting' ? 'Finding handwritten lines'
                                : 'Waiting for the line detector';
                    const noWorker = !reconnecting && status === 'queued' && performance.now() - lastMovedAt > WORKER_HINT_AFTER_MS;
                    const later = trackList.filter((entry) => !entry.done && entry !== active).reduce((sum, entry) => sum + entry.seconds, 0);
                    const left = timeLeftText((performance.now() - lastFinishedAt) / 1000, active.seconds + waitFirst, later);
                    el('ocrProgressFields').textContent = `${readyCount} of ${total} ${unitWord} scanned · ${left}`;
                    setOcrProgress(ocrProgress, `Scanning batch (${readyCount} of ${total} ${unitWord})`,
                        noWorker ? `${stage} · ${batchPageName(active.item)}. ${WORKER_HINT}` : `${stage} · ${batchPageName(active.item)}`);

                    await wait(700, controller.signal);

                    const open = trackList.filter((entry) => !entry.done);
                    const sweep = performance.now() - lastSweepAt >= BATCH_STATUS_SWEEP_MS;
                    if (sweep) lastSweepAt = performance.now();
                    const toCheck = sweep ? open : open.filter((entry, index) => index < 2 || inProgress(entry));
                    reconnecting = false;

                    for (const entry of toCheck) {
                        const answer = await fetchBatchPageStatus(entry.page.statusUrl, controller.signal);
                        if (answer.retry) {
                            reconnecting = true;
                            continue;
                        }
                        lastAnswerAt = performance.now();
                        if (answer.gone) {
                            // The page is gone from the server (or refused): it fails alone.
                            failBatchEntry(entry, responseErrorMessage(answer.response, answer.payload));
                            lastFinishedAt = performance.now();
                            lastMovedAt = lastFinishedAt;
                            continue;
                        }
                        const next = answer.page;
                        if (next.status !== entry.page?.status) lastMovedAt = performance.now();
                        entry.page = next;
                        entry.item.page = next;

                        if (next.status === 'failed' && next.ocrUnreachable) {
                            // Not the page's fault: it waits to be scanned again, and a
                            // second such page ends the run instead of failing every page.
                            entry.done = true;
                            ocrDown.push(entry);
                            if (ocrDown.length >= 2) throw new Error(OCR_DOWN_MESSAGE);
                            lastFinishedAt = performance.now();
                            continue;
                        }
                        if (next.status === 'failed') {
                            // A page that cannot be read does not stop the batch: the
                            // others are read, and this one can be re-aligned or removed.
                            failBatchEntry(entry, next.error || `The page "${entry.item.name}" could not be processed.`);
                            lastFinishedAt = performance.now();
                            waitFirst = 0;
                            continue;
                        }
                        if (next.status === 'ready') {
                            entry.done = true;
                            entry.item.status = 'ready';
                            lastFinishedAt = performance.now();
                            waitFirst = 0;
                            const nextActiveIdx = batchState.items.findIndex((it) => it.status === 'scanning');
                            if (nextActiveIdx !== -1) {
                                batchState.currentIndex = nextActiveIdx;
                            }
                            renderBatchQueueBanner();
                        }
                    }

                    if (reconnecting && performance.now() - lastAnswerAt > BATCH_STATUS_OFFLINE_MS) {
                        throw new Error('The server stopped answering. The pages are still being read on the server: check the connection, then press Scan All again to pick them up.');
                    }
                }

                // The last page could not be read because the OCR service is down.
                if (ocrDown.length > 0) throw new Error(OCR_DOWN_MESSAGE);

                trackList.forEach((entry) => {
                    if (!entry.failed) entry.item.status = 'ready';
                });
                const failedCount = trackList.filter((entry) => entry.failed).length;
                const readCount = total - failedCount;
                el('ocrProgressFields').textContent = `${readCount} of ${total} ${unitWord} scanned`;
                if (failedCount === 0) {
                    await completeOcrProgress('Batch scan complete', 'Opening the first record for verification.');
                } else {
                    const them = failedCount === 1 ? 'it' : 'them';
                    await completeOcrProgress(
                        `${readCount} of ${total} ${unitWord} read`,
                        `${failedCount} ${failedCount === 1 ? 'page' : 'pages'} could not be read: re-align or remove ${them}.`,
                    );
                }
                batchState.readingAll = false;
                // The first record read opens in Verify; with none, the first failed page in Align.
                await continueBatch(after, { prefer });
            } catch (error) {
                if (!run.cancelled) {
                    showOcrError(error.message || 'Batch scan failed.');
                }
                // Its own pages wait again; pages read in the background carry on.
                itemsToScan.forEach((it) => {
                    if (it.status === 'scanning') it.status = 'saved';
                });
                batchState.readingAll = false;
                renderBatchQueueBanner();
            } finally {
                batchState.readingAll = false;
                if (activeRun === run) activeRun = null;
                stopOcrProgress();
                await closeProgressModal(progress);
                scanInProgress = false;
                button.innerHTML = originalButtonContent;
                button.disabled = false;
                el('detectBtn').disabled = false;
                renderBatchQueueBanner();
            }
        }

        // ------------------------------------------- when a batch's pages are read
        // Every page is aligned and queued first, and Scan all reads them together
        // (a page sent back by Re-align is read the same way, on its own).

        /** Queued pages not read yet (waiting for Scan all). */
        const unsentBatchPages = () => batchState.items.filter((it) => it.pageId && it.status === 'saved');

        /** What the big button in Align does in a batch now, and its label. */
        function batchScanAction() {
            const queuedAll = batchState.items.every((it) => it.status !== 'pending' && it.status !== 'saving'
                && !(it.status === 'failed' && !it.pageId));
            const toRead = batchPagesToRead().length;
            if (queuedAll && toRead > 0) {
                return {
                    kind: 'scan-all',
                    label: toRead === batchState.items.length ? 'Scan All' : 'Scan Queued',
                    count: `${toRead} page${toRead === 1 ? '' : 's'}`,
                    run: runBatchScanAll,
                };
            }
            if (!queuedAll) return { kind: 'none', label: '', run: async () => {} };
            const records = batchRecords().filter((record) => !recordDone(record));
            const ready = records.filter((record) => recordState(record.members) === 'ready').length;
            if (records.length === 0) return { kind: 'none', label: '', run: async () => {} };
            return {
                kind: 'verify',
                label: ready === records.length ? `Verify (${records.length} record${records.length === 1 ? '' : 's'})` : `Verify (${ready} of ${records.length} read)`,
                run: startBatchVerify,
            };
        }

        /** Every page is queued and read: on to Verify. */
        async function startBatchVerify() {
            if (scanInProgress) return;
            keepCurrentAlignment();
            await continueBatch();
        }

        // ------------------------------------------- a batch kept on the server
        // From its first files, a batch is kept on the server (documents.batches):
        // each page's state, alignment and flags as they change (small requests,
        // one at a time, retried, never holding up the page), and each record's
        // draft a moment after typing stops. A reload, a closed tab or a crash
        // loses no more than the page being worked on: the workspace offers to
        // resume it. Leaving keeps it; only Discard throws it away.

        function randomKey() {
            return globalThis.crypto?.randomUUID?.()
                ?? `k${Date.now().toString(36)}${Math.random().toString(36).slice(2, 12)}`;
        }

        // This tab's name (kept over a reload of the same tab).
        const batchTab = (() => {
            try {
                let id = window.sessionStorage.getItem('crms.scan.tab');
                if (!id) {
                    id = randomKey();
                    window.sessionStorage.setItem('crms.scan.tab', id);
                }
                return id;
            } catch {
                return randomKey();
            }
        })();
        // The layout of page 1 (config.templateId follows the page shown).
        const PAGE_ONE_TEMPLATE_ID = config.templateId;

        const batchServer = {
            id: null,
            version: 0,
            // One write at a time, in order: each carries the version the one before left.
            chain: Promise.resolve(),
            // What the server has of each page and draft (as sent), and pages removed since.
            sent: new Map(),
            drafts: new Map(),
            removed: new Set(),
            syncTimer: 0,
            draftTimer: 0,
            pingTimer: 0,
            // Another tab took it over, or it was finished or discarded elsewhere.
            lost: false,
        };

        const batchOpenOnServer = () => batchState.active && batchServer.id !== null && !batchServer.lost;

        /** No batch kept on the server any more (its queue was emptied): the next one is created afresh. */
        function resetServerBatch() {
            window.clearTimeout(batchServer.syncTimer);
            window.clearTimeout(batchServer.draftTimer);
            window.clearInterval(batchServer.pingTimer);
            Object.assign(batchServer, {
                id: null, version: 0, chain: Promise.resolve(), sent: new Map(), drafts: new Map(), removed: new Set(), lost: false,
            });
        }

        /** The scan of the record open now is on the server (a queued batch page): no local file is needed. */
        function batchScanOnServer() {
            return batchState.active && Boolean(batchState.items[batchState.currentIndex]?.workspaceScanPath);
        }

        async function batchRequest(name, method, body = null, { versioned = true, retried = false } = {}) {
            const url = config.batchUrls[name].replace('__BATCH__', String(batchServer.id));
            const response = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': config.csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ ...(versioned ? { version: batchServer.version, tab: batchTab } : {}), ...(body ?? {}) }),
            });
            const payload = (response.headers.get('content-type') || '').includes('application/json')
                ? await response.json()
                : null;
            if (response.status === 409) {
                // This tab's own change whose answer was lost: go on from the server's version.
                if (payload?.reason === 'stale' && !retried && typeof payload.version === 'number') {
                    batchServer.version = payload.version;
                    return batchRequest(name, method, body, { versioned, retried: true });
                }
                batchLost(payload);
                const error = new Error(payload?.message || 'This batch was changed in another tab.');
                error.batchLost = true;
                throw error;
            }
            if (!response.ok) {
                const error = new Error(responseErrorMessage(response, payload));
                error.status = response.status;
                throw error;
            }
            if (typeof payload?.version === 'number') batchServer.version = payload.version;
            return payload;
        }

        function batchWrite(task) {
            const run = batchServer.chain.then(() => (batchOpenOnServer() ? task() : null));
            batchServer.chain = run.catch(() => {});
            return run;
        }

        /** A page as the server keeps it (passing states become the ones they stand for). */
        function serverRow(item, position) {
            const status = { saving: 'pending', verifying: 'ready' }[item.status] ?? item.status;
            return {
                key: item.key,
                position,
                source_name: String(item.sourceName ?? item.name ?? 'scan').slice(0, 255),
                source_hash: item.sourceHash ?? null,
                page_no: item.pageNo ?? 1,
                page_total: item.pageTotal ?? 1,
                record_key: String(item.record ?? item.key).slice(0, 120),
                status,
                document_page_id: item.pageId ?? null,
                workspace_scan_path: item.workspaceScanPath ?? null,
                alignment: item.boxes
                    ? {
                        boxes: item.boxes,
                        rows: item.rows ?? [],
                        tilts: item.tilts ?? null,
                        geometry: item.geometry ?? null,
                        autoFields: item.autoFields ? [...item.autoFields] : null,
                    }
                    : null,
                flags: {
                    skipped: Boolean(item.skipped),
                    existingAccepted: Boolean(item.existingAccepted),
                    realign: Boolean(item.realign),
                    addedAnyway: Boolean(item.addedAnyway),
                    serverCopy: Boolean(item.serverCopy),
                    approved: item.approved ?? null,
                    // The page open when the batch was left: Resume opens it again, at its step.
                    lastOpen: batchState.items[batchState.currentIndex] === item,
                    scanKind: item.scanKind ?? 'full',
                    recordId: item.recordId ?? null,
                    recordUrl: item.recordUrl ?? null,
                    // Step 1's book and page, kept so a resumed batch has them.
                    bookNo: item.bookNo ? String(item.bookNo) : null,
                    pageNoRef: item.pageNoRef ? String(item.pageNoRef) : null,
                },
                error: item.error ? String(item.error).slice(0, 2000) : null,
            };
        }

        /** Kept on the server from the first files (a batch that cannot be kept still works, without resume). */
        async function startServerBatch() {
            if (batchServer.id !== null || !config.batchUrls) return;
            try {
                const response = await fetch(config.batchUrls.store, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': config.csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        doc_type: config.docType,
                        document_template_id: PAGE_ONE_TEMPLATE_ID,
                        page_two_template_id: config.pageTwo?.templateId ?? null,
                        batch_key: batchState.key,
                        tab: batchTab,
                    }),
                });
                const payload = response.ok ? await response.json() : null;
                if (!payload?.id) throw new Error(`The server answered ${response.status}.`);
                batchServer.id = payload.id;
                batchServer.version = payload.version ?? 0;
                startBatchPing();
                scheduleBatchSync(0);
            } catch (error) {
                console.warn('This batch could not be kept on the server (it cannot be resumed after a reload):', error);
            }
        }

        /** Send the pages that changed, a moment after the last change. */
        function scheduleBatchSync(delay = 800) {
            if (!batchOpenOnServer()) return;
            window.clearTimeout(batchServer.syncTimer);
            batchServer.syncTimer = window.setTimeout(() => {
                batchWrite(syncBatchPages).catch((error) => {
                    if (error.batchLost) return;
                    // Refused as it is (422): sending it again would not help.
                    if (error.status === 422) {
                        console.error('The server refused a change to this batch:', error);
                        return;
                    }
                    console.warn('The batch could not be saved on the server; trying again shortly:', error);
                    scheduleBatchSync(5000);
                });
            }, delay);
        }

        async function syncBatchPages() {
            const changed = [];
            batchState.items.forEach((item, position) => {
                if (!item.key) item.key = randomKey();
                const row = serverRow(item, position);
                const json = JSON.stringify(row);
                if (batchServer.sent.get(item.key) !== json) changed.push({ key: item.key, row, json });
            });
            const removed = [...batchServer.removed];
            if (changed.length === 0 && removed.length === 0) return;
            // In parts, so a register of hundreds of pages is never one large request.
            for (let start = 0; start === 0 || start < changed.length; start += 100) {
                const part = changed.slice(start, start + 100);
                // eslint-disable-next-line no-await-in-loop
                await batchRequest('pages', 'PUT', { pages: part.map((entry) => entry.row), removed: start === 0 ? removed : [] });
                part.forEach((entry) => batchServer.sent.set(entry.key, entry.json));
                if (start === 0) {
                    removed.forEach((key) => {
                        batchServer.removed.delete(key);
                        batchServer.sent.delete(key);
                    });
                }
            }
        }

        /** A record's draft as the server keeps it: what was typed and ticked, or once saved its table. */
        function draftOf(item) {
            if (pageDone(item)) return item.exportTable ? { exportTable: item.exportTable } : null;
            if (!item.verifyDraft && !(item.reviewExtras?.size > 0) && !item.inReview) return null;
            return {
                verify: item.verifyDraft
                    ? [...item.verifyDraft].map(([line, entry]) => ({ line, value: entry.value, verified: entry.verified }))
                    : [],
                review: item.reviewExtras ? [...item.reviewExtras] : [],
                // Left on Review & Export: a resume opens it there.
                step: item.inReview ? 'review' : 'verify',
            };
        }

        function syncBatchDraft(item) {
            if (!batchOpenOnServer() || !item) return;
            const recordKey = String(item.record ?? item.key).slice(0, 120);
            const draft = draftOf(item);
            const json = JSON.stringify(draft);
            if ((batchServer.drafts.get(recordKey) ?? 'null') === json) return;
            batchWrite(async () => {
                await batchRequest('draft', 'PUT', { record_key: recordKey, draft });
                batchServer.drafts.set(recordKey, json);
            }).catch((error) => {
                if (!error.batchLost) console.warn('A draft could not be saved on the server:', error);
            });
        }

        // Typing in Verify or Review & Export: the draft is saved a moment after it stops.
        ['input', 'change'].forEach((type) => {
            [el('step-verify'), el('step-review')].forEach((step) => step.addEventListener(type, () => {
                if (!batchOpenOnServer()) return;
                window.clearTimeout(batchServer.draftTimer);
                batchServer.draftTimer = window.setTimeout(() => {
                    const lead = currentVerifyItem();
                    if (lead) keepVerifyDraft(lead, { commit: false });
                }, 1500);
            }));
        });

        /** Still working on it (so a second tab knows); refused once another tab took it over. */
        function startBatchPing() {
            window.clearInterval(batchServer.pingTimer);
            batchServer.pingTimer = window.setInterval(() => {
                if (!batchOpenOnServer() || document.visibilityState !== 'visible') return;
                batchWrite(() => batchRequest('ping', 'POST')).catch(() => {});
            }, 30 * 1000);
        }

        /** Everything on screen now, saved (before leaving with Keep for later). */
        async function flushServerBatch() {
            if (!batchOpenOnServer()) return;
            // Pages still on their way are in (else they are Pending, aligned, on resume).
            await waitForBatchUploads();
            keepCurrentAlignment();
            const lead = currentVerifyItem();
            if (lead) keepVerifyDraft(lead);
            window.clearTimeout(batchServer.syncTimer);
            try {
                await batchWrite(syncBatchPages);
            } catch (error) {
                console.warn('The batch could not be saved before leaving:', error);
            }
        }

        /** Every record is saved or left out: the batch is finished. */
        function finishServerBatch() {
            if (!batchOpenOnServer() || !batchState.items.every(pageDone)) return;
            window.clearTimeout(batchServer.syncTimer);
            // Every page is saved or left out: no file is needed again, so this browser lets them go.
            forgetBatchFiles(batchState.items.map((item) => item.sourceHash));
            batchWrite(syncBatchPages)
                .then(() => batchWrite(() => batchRequest('done', 'POST')))
                .then(() => window.clearInterval(batchServer.pingTimer))
                .catch((error) => console.warn('The batch could not be marked finished:', error));
        }

        /** Discard: the batch is thrown away, and its queued pages let go. */
        async function discardServerBatch(id = batchServer.id) {
            if (id === null) return;
            // A page on its way gets its id first, so it is let go with the rest.
            if (id === batchServer.id) await waitForBatchUploads();
            batchState.items.forEach((item) => {
                if (!pageDone(item)) cancelPageOnServer(item.pageId);
            });
            forgetBatchFiles(batchState.items.map((item) => item.sourceHash));
            try {
                await fetch(config.batchUrls.abandon.replace('__BATCH__', String(id)), {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': config.csrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                    credentials: 'same-origin',
                });
            } catch (error) {
                console.warn('The batch could not be discarded on the server:', error);
            }
            if (id === batchServer.id) {
                batchServer.lost = true;
                window.clearInterval(batchServer.pingTimer);
            }
        }

        /** Another tab took the batch over (or it ended elsewhere): nothing here is saved from now on. */
        function batchLost(payload) {
            if (batchServer.lost) return;
            batchServer.lost = true;
            window.clearTimeout(batchServer.syncTimer);
            window.clearInterval(batchServer.pingTimer);
            const closed = payload?.reason === 'closed';
            el('batchTakenTitle').textContent = closed ? 'This batch has ended' : 'This batch continued in another tab';
            el('batchTakenText').textContent = closed
                ? 'It was finished or discarded in another tab. What you do here is no longer saved.'
                : 'What you do here is no longer saved. Reload this page to pick the batch up where it stands, or close this tab.';
            el('batchTakenRecordsLink').href = config.recordsUrl;
            window.bootstrap.Modal.getOrCreateInstance(el('batchTakenModal')).show();
        }
        el('batchTakenReloadBtn').addEventListener('click', () => {
            leaving = true;
            window.location.reload();
        });

        // ------------------------------------------- resume a batch
        // Opening the workspace with a batch left open of this type offers it
        // back. Pages queued already need nothing; the files of pages not queued
        // yet are picked again, matched by fingerprint (names may differ).

        function setUploadLock(locked) {
            const zone = document.getElementById('documentDropzone');
            // No type chosen yet: the drop area is a placeholder, gray on its own.
            if (!zone || zone.hasAttribute('data-requires-type')) return;
            uploadLocked = locked;
            zone.classList.toggle('is-disabled', locked);
            zone.classList.toggle('is-locked', locked);
            if (locked) {
                zone.setAttribute('aria-disabled', 'true');
                zone.title = 'Resume or discard the unfinished batch first';
            } else {
                zone.removeAttribute('aria-disabled');
                zone.removeAttribute('title');
            }
            // A disabled input is not opened by its label.
            scanFileInput.disabled = locked;
            if (scanFolderInput instanceof HTMLInputElement) scanFolderInput.disabled = locked;
            showUploadModeText();
        }

        /** Tried to upload while locked: say why, and point at the banner. */
        function explainUploadLock() {
            const banner = el('batchResume');
            banner.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            banner.classList.remove('is-nudged');
            void banner.offsetWidth;
            banner.classList.add('is-nudged');
            tellInApp('Finish the unfinished batch first',
                'Press Resume to carry on with it, or Discard to throw it away. Then you can upload another file.');
        }

        function showResumeOffer() {
            // A resumed batch whose pages all wait for their files: pick them here.
            if (batchState.active && activeStep === 'upload' && batchState.items.some(pageNeedsFile)) {
                setUploadLock(false);
                const waiting = batchState.items.filter(pageNeedsFile).length;
                el('batchResume').classList.remove('d-none');
                el('batchResume').dataset.mode = 'files';
                el('batchResumeTitle').textContent = 'Pick the files of this batch again';
                el('batchResumeDetail').textContent = `${waiting} page${waiting === 1 ? '' : 's'} wait${waiting === 1 ? 's' : ''} for ${waiting === 1 ? 'its file' : 'their files'}: pick them again to align them.`;
                el('batchResumeLabel').textContent = 'Pick files';
                el('batchResumeDiscardBtn').classList.remove('d-none');
                el('batchResumeViewBtn').classList.add('d-none');
                el('batchResumeBtn').classList.remove('d-none');
                return;
            }
            delete el('batchResume').dataset.mode;
            const open = Array.isArray(config.openBatches) ? config.openBatches : [];
            // Newest first (the server sends them so; kept here in case it does not).
            const byNewest = (a, b) => Date.parse(b.updatedAt ?? 0) - Date.parse(a.updatedAt ?? 0);
            const sameType = open.filter((batch) => batch.docType === config.docType).sort(byNewest);
            const others = open.filter((batch) => batch.docType !== config.docType).sort(byNewest);
            const total = sameType.length + others.length;
            el('batchResume').classList.toggle('d-none', total === 0 || batchState.active);
            setUploadLock(sameType.length > 0 && !batchState.active);
            renderResumeList(sameType, others);
            if (total === 0) {
                window.bootstrap.Modal.getInstance(el('batchResumeModal'))?.hide();
                return;
            }

            // One line on the page; with several batches, the list is in a window (View all).
            const single = sameType.length === 1 && others.length === 0;
            const [latest] = sameType;
            el('batchResumeDiscardBtn').classList.toggle('d-none', !single);
            el('batchResumeViewBtn').classList.toggle('d-none', single);
            el('batchResumeViewBtn').textContent = `View all ${total}`;
            el('batchResumeBtn').classList.toggle('d-none', !latest);
            if (single) {
                el('batchResumeTitle').textContent = 'Unfinished batch';
                el('batchResumeDetail').textContent = batchDetailText(latest);
                el('batchResumeLabel').textContent = 'Resume';
            } else if (latest) {
                el('batchResumeTitle').textContent = `${sameType.length} unfinished batch${sameType.length === 1 ? '' : 'es'}`;
                el('batchResumeDetail').textContent = [
                    ...(latest.updatedAt ? [`latest ${friendlyTime(latest.updatedAt)}`] : []),
                    ...(others.length > 0 ? [`${others.length} in other document types`] : []),
                ].join(' · ');
                el('batchResumeLabel').textContent = 'Resume latest';
            } else {
                el('batchResumeTitle').textContent = `${others.length} unfinished batch${others.length === 1 ? '' : 'es'}`;
                el('batchResumeDetail').textContent = 'in other document types';
            }
            if (latest) {
                el('batchResumeBtn').dataset.batch = String(latest.id);
                el('batchResumeDiscardBtn').dataset.batch = String(latest.id);
            }
        }

        /**
         * The window's list, grouped by document type: this page's type first,
         * then the others. Each group is newest first, its newest marked.
         */
        function renderResumeList(sameType, others) {
            const total = sameType.length + others.length;
            el('batchResumeModalSub').textContent = total === 0 ? ''
                : 'Pick one to carry on. Discarding a batch keeps the records already saved from it.';
            const groups = new Map();
            if (sameType.length > 0) groups.set(config.docType, sameType);
            others.forEach((batch) => {
                if (!groups.has(batch.docType)) groups.set(batch.docType, []);
                groups.get(batch.docType).push(batch);
            });
            const rows = [];
            groups.forEach((batches, docType) => {
                const here = docType === config.docType;
                rows.push(resumeGroupTitle(batches[0].typeLabel ?? docType, batches.length, here));
                batches.forEach((batch, index) => rows.push(resumeRow({
                    text: batchProgressText(batch),
                    when: [
                        batch.realigning > 0 ? `${batch.realigning} to re-align` : '',
                        batch.leftOn ? `left on ${batch.leftOn}` : '',
                        batch.updatedAt ? friendlyTime(batch.updatedAt) : '',
                    ].filter(Boolean).join(' · '),
                    latest: index === 0 && batches.length > 1,
                    primary: index === 0,
                    resume: here ? () => resumeFromList(batch.id) : null,
                    href: here ? null : resumeUrl(batch),
                    discard: () => discardBatchById(batch.id, { ask: false }),
                })));
            });
            el('batchResumeList').replaceChildren(...rows);
            resetDiscardAll(total);
        }

        /** "Book of Birth · 2", with "this page" on the type open here. */
        function resumeGroupTitle(label, count, here) {
            const li = document.createElement('li');
            li.className = 'batch-resume__group';
            li.setAttribute('role', 'presentation');
            const name = document.createElement('span');
            name.className = 'batch-resume__group-name';
            name.textContent = label;
            name.title = label;
            const meta = document.createElement('span');
            meta.className = 'batch-resume__group-meta';
            meta.textContent = here ? `${count} · this page` : String(count);
            li.append(name, meta);
            return li;
        }

        /** Another type's page, set to resume this batch as it opens. */
        function resumeUrl(batch) {
            const url = new URL(batch.workspaceUrl, window.location.href);
            url.searchParams.set('resume', String(batch.id));
            return url.toString();
        }

        /** The window closes first (Resume may ask something in its own window), then the batch opens. */
        function resumeFromList(id) {
            const modalEl = el('batchResumeModal');
            const modal = window.bootstrap.Modal.getInstance(modalEl);
            if (!modal || !modalEl.classList.contains('show')) return resumeBatch(id);
            return new Promise((resolve) => {
                modalEl.addEventListener('hidden.bs.modal', () => resolve(resumeBatch(id)), { once: true });
                modal.hide();
            });
        }

        el('batchResumeViewBtn').addEventListener('click', () => {
            window.bootstrap.Modal.getOrCreateInstance(el('batchResumeModal')).show();
        });

        /** "0 of 2 records saved · 4 pages · 1 to re-align · today, 1:12 PM". */
        function batchDetailText(batch) {
            return [
                batchProgressText(batch),
                ...(batch.realigning > 0 ? [`${batch.realigning} to re-align`] : []),
                // Where Resume opens it again.
                ...(batch.leftOn ? [`left on ${batch.leftOn}`] : []),
                ...(batch.updatedAt ? [friendlyTime(batch.updatedAt)] : []),
            ].filter(Boolean).join(' · ');
        }

        /** "0 of 2 records saved · 4 pages". */
        function batchProgressText(batch) {
            const plural = (count, noun) => `${count} ${noun}${count === 1 ? '' : 's'}`;
            return `${batch.saved ?? 0} of ${plural(batch.records ?? 0, 'record')} saved · ${plural(batch.pages ?? 0, 'page')}`;
        }

        /** "today, 1:12 PM", "yesterday, 9:03 AM", or "Nov 19, 1:12 PM". */
        function friendlyTime(value) {
            const when = new Date(value);
            if (Number.isNaN(when.getTime())) return '';
            const time = when.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
            const day = new Date(when.getFullYear(), when.getMonth(), when.getDate());
            const now = new Date();
            const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            const daysAgo = Math.round((today - day) / 86400000);
            if (daysAgo === 0) return `today, ${time}`;
            if (daysAgo === 1) return `yesterday, ${time}`;
            const date = when.toLocaleDateString(undefined, {
                month: 'short',
                day: 'numeric',
                ...(when.getFullYear() !== now.getFullYear() ? { year: 'numeric' } : {}),
            });
            return `${date}, ${time}`;
        }

        /** One quiet row under the offer: what it is, when, and Discard / Resume (or Open). */
        function resumeRow({ text, when, resume = null, discard = null, href = null, latest = false, primary = latest }) {
            const li = document.createElement('li');
            li.className = `batch-resume__row${latest ? ' is-latest' : ''}`;
            const label = document.createElement('span');
            label.className = 'batch-resume__row-text';
            // Two lines: what it holds, then when (and "Latest" on the newest).
            const title = document.createElement('span');
            title.className = 'batch-resume__row-title';
            title.textContent = text;
            const meta = document.createElement('span');
            meta.className = 'batch-resume__row-meta';
            if (latest) {
                const badge = document.createElement('span');
                badge.className = 'badge bg-label-primary batch-resume__latest';
                badge.textContent = 'Latest';
                meta.append(badge);
            }
            if (when) meta.append(when);
            label.append(title, meta);
            const actions = document.createElement('span');
            actions.className = 'batch-resume__row-actions';
            let discardButton = null;
            if (discard) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-sm btn-icon btn-label-secondary';
                button.title = 'Discard this batch';
                button.setAttribute('aria-label', 'Discard this batch');
                button.innerHTML = '<i class="icon-base bx bx-trash icon-sm" aria-hidden="true"></i>';
                // Asked in the row itself (a second window over this one would stack).
                button.addEventListener('click', () => {
                    const keep = document.createElement('button');
                    keep.type = 'button';
                    keep.className = 'btn btn-sm btn-label-secondary';
                    keep.textContent = 'Keep';
                    const confirmBtn = document.createElement('button');
                    confirmBtn.type = 'button';
                    confirmBtn.className = 'btn btn-sm btn-danger';
                    confirmBtn.textContent = 'Discard';
                    const question = document.createElement('span');
                    question.className = 'batch-resume__ask';
                    question.textContent = 'Discard this batch?';
                    const before = [...actions.childNodes];
                    li.classList.add('is-asking');
                    actions.replaceChildren(question, keep, confirmBtn);
                    keep.addEventListener('click', () => {
                        li.classList.remove('is-asking');
                        actions.replaceChildren(...before);
                    });
                    confirmBtn.addEventListener('click', async () => {
                        keep.disabled = true;
                        confirmBtn.disabled = true;
                        await discard();
                    });
                    confirmBtn.focus();
                });
                discardButton = button;
            }
            if (resume) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = `btn btn-sm ${primary ? 'btn-primary' : 'btn-label-primary'}`;
                button.textContent = 'Resume';
                button.addEventListener('click', () => {
                    button.disabled = true;
                    Promise.resolve(resume()).finally(() => { button.disabled = false; });
                });
                actions.append(button);
            }
            if (href) {
                // Its own type's page, which resumes it as it opens.
                const link = document.createElement('a');
                link.className = `btn btn-sm ${primary ? 'btn-primary' : 'btn-label-primary'} batch-resume__open`;
                link.href = href;
                link.title = 'Opens its document type and resumes this batch';
                link.innerHTML = 'Resume <i class="icon-base bx bx-right-arrow-alt icon-sm" aria-hidden="true"></i>';
                actions.append(link);
            }
            // Resume first, the quiet 🗑 last at the edge.
            if (discardButton) actions.append(discardButton);
            li.prepend(label);
            li.append(actions);
            return li;
        }

        const askDiscardBatch = () => askInApp({
            title: 'Discard this batch?',
            text: 'Its queued pages and drafts are thrown away. Records saved from it stay saved.',
            confirm: 'Discard batch',
            cancel: 'Keep it',
            tone: 'danger',
        });

        el('batchResumeBtn').addEventListener('click', () => {
            if (el('batchResume').dataset.mode === 'files') askForBatchFiles({ thenContinue: true });
            else resumeBatch(Number(el('batchResumeBtn').dataset.batch));
        });
        el('batchResumeDiscardBtn').addEventListener('click', async () => {
            // The batch resumed here: discarded, and the workspace starts afresh.
            if (el('batchResume').dataset.mode === 'files') {
                if (await askDiscardBatch() !== 'confirm') return;
                await discardServerBatch();
                leaving = true;
                window.location.reload();
                return;
            }
            const id = Number(el('batchResumeDiscardBtn').dataset.batch);
            el('batchResumeDiscardBtn').disabled = true;
            try {
                await discardBatchById(id);
            } finally {
                el('batchResumeDiscardBtn').disabled = false;
            }
        });

        /** The footer's Discard all, back to its button (after a change, or Keep). */
        function resetDiscardAll(count) {
            const footer = el('batchResumeModalFooter');
            footer.querySelector('.batch-resume__ask-group')?.remove();
            const button = el('batchResumeDiscardAllBtn');
            button.classList.toggle('d-none', count < 2);
            button.disabled = false;
            button.textContent = `Discard all ${count}`;
        }

        el('batchResumeDiscardAllBtn').addEventListener('click', async () => {
            const ids = (config.openBatches ?? []).map((batch) => batch.id);
            if (ids.length === 0) return;
            el('batchResumeDiscardAllBtn').disabled = true;
            for (const id of ids) {
                // eslint-disable-next-line no-await-in-loop
                await discardBatchById(id, { ask: false });
            }
        });

        /** Discard a batch left open (asked first, unless `ask` is false): its queued pages are let go too. */
        async function discardBatchById(id, { ask = true } = {}) {
            if (!id || (ask && await askDiscardBatch() !== 'confirm')) return;
            try {
                const response = await fetch(config.batchUrls.show.replace('__BATCH__', String(id)), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const batch = response.ok ? await response.json() : null;
                // Its queued pages are let go too.
                (batch?.pages ?? []).forEach((page) => {
                    if (!['imported', 'rejected'].includes(page.status)) cancelPageOnServer(page.documentPageId);
                });
            } catch {
                // The batch itself is still discarded below.
            }
            await discardServerBatch(id);
            config.openBatches = (config.openBatches ?? []).filter((batch) => batch.id !== id);
            showResumeOffer();
        }

        async function resumeBatch(id) {
            if (!id || batchState.active) return;
            const button = el('batchResumeBtn');
            const spinner = button.querySelector('.spinner-border');
            button.disabled = true;
            spinner?.classList.remove('d-none');
            try {
                const response = await fetch(config.batchUrls.show.replace('__BATCH__', String(id)), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const batch = response.ok ? await response.json() : null;
                if (!batch || batch.status !== 'open') throw new Error('This batch can no longer be resumed: it was finished or discarded.');

                // Open in another tab just now: take it over, or leave it there.
                const seen = batch.seenAt ? Date.now() - Date.parse(batch.seenAt) : Infinity;
                if (batch.tab && batch.tab !== batchTab && seen < 75 * 1000 && await askInApp({
                    title: 'This batch is open in another tab',
                    text: 'Continue it here? The other tab stops saving it.',
                    confirm: 'Continue here',
                    cancel: 'Leave it there',
                }) !== 'confirm') return;

                // The layout changed since: pages queued keep theirs; the others use the current one.
                const layoutChanged = Number(batch.templateId) !== Number(PAGE_ONE_TEMPLATE_ID);
                if (layoutChanged && await askInApp({
                    title: batch.templateArchived ? 'The layout of this batch was deleted' : 'The layout changed since this batch was started',
                    text: 'Pages already queued keep the layout they were read with. Pages not queued yet use the layout published now.',
                    confirm: 'Resume',
                    tone: 'info',
                }) !== 'confirm') return;

                batchServer.id = batch.id;
                batchServer.version = batch.version;
                batchServer.lost = false;
                await batchRequest('claim', 'POST', { tab: batchTab }, { versioned: false });

                const { key, items } = rebuildBatchState(batch, { layoutChanged });
                // A Scan all cut short: its pages wait for Scan all, which picks each up where the worker has it.
                items.forEach((item) => {
                    if (item.status === 'scanning') item.status = 'saved';
                });
                batchState.active = true;
                batchState.key = key;
                batchState.readingAll = false;
                batchState.items = items;
                batchState.currentIndex = 0;
                setUploadMode('batch', { remember: false });
                // What the server has already is not sent again.
                batchServer.sent = new Map(items.map((item, position) => [item.key, JSON.stringify(serverRow(item, position))]));
                batchServer.drafts = new Map((batch.drafts ?? []).map((entry) => [entry.recordKey, JSON.stringify(entry.draft)]));
                if (layoutChanged) scheduleBatchSync();
                startBatchPing();
                showResumeOffer();

                // Pages not queued yet: their files are picked again first.
                // Files this browser kept are used; only the rest are asked for.
                if (items.some(pageNeedsFile)) {
                    const kept = await recallBatchFiles(items.filter(pageNeedsFile).map((item) => item.sourceHash));
                    const { matched } = matchFilesToBatch(kept, items);
                    items.forEach((item) => {
                        const file = item.sourceHash ? matched.get(item.sourceHash) : null;
                        if (file && !item.file) item.file = file;
                    });
                }
                if (items.some(pageNeedsFile)) await askForBatchFiles();
                await resumeWhereLeft();
            } catch (error) {
                if (!error.batchLost) tellInApp('This batch could not be resumed', error.message || '');
                if (!batchState.active) batchServer.id = null;
            } finally {
                button.disabled = false;
                spinner?.classList.add('d-none');
            }
        }

        /** Back where the batch was: a page still to align, else the records to verify. */
        async function resumeWhereLeft() {
            // The page open when it was left comes back first, at the step it was on.
            const last = batchState.items.find((item) => item.lastOpen && !pageDone(item));
            if (last && pageAlignable(last)) {
                await openBatchQueueItem(batchState.items.indexOf(last));
                return;
            }
            if (last && recordState(recordOf(last).members) === 'ready') {
                await continueBatch(null, { askForFiles: false, prefer: last });
                const lead = currentVerifyItem();
                if (lead?.inReview && !reviewing && activeStep === 'verify') openReview({ switching: true });
                return;
            }
            const align = batchState.items.findIndex((item) => item.status === 'pending' && pageAlignable(item));
            if (align !== -1) {
                await openBatchQueueItem(align);
                return;
            }
            // Its files were just asked for: not again (the upload step offers them).
            await continueBatch(null, { askForFiles: false });
            // The record was left on Review & Export: it opens there again.
            const lead = currentVerifyItem();
            if (lead?.inReview && !reviewing && activeStep === 'verify') openReview({ switching: true });
        }

        /**
         * A resumed record's pages: only where they are on the server is known,
         * so what was read is asked for. A page the server no longer has fails
         * (to be re-aligned or removed). Answers 'ok', 'changed' (a page is not
         * read, or is gone: the record cannot open) or 'offline' (the server
         * could not be reached: nothing changed, so it can be opened again).
         */
        async function loadBatchPages(members) {
            let ok = 'ok';
            for (const item of members) {
                if (!item.page?.statusUrl || item.page.imageUrl || pageDone(item)) continue;
                try {
                    // eslint-disable-next-line no-await-in-loop
                    const response = await fetch(item.page.statusUrl, {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    // eslint-disable-next-line no-await-in-loop
                    const page = response.ok ? await response.json() : null;
                    if (!response.ok && response.status !== 404) return 'offline';
                    if (!page || page.status !== 'ready') {
                        // Still with the worker (a Scan all that was cut short): it waits for Scan all, which picks it up where it is.
                        const withWorker = Boolean(page) && ['queued', 'detecting', 'reading'].includes(page.status);
                        item.status = withWorker ? 'saved' : 'failed';
                        item.error = withWorker ? null : 'This page could not be loaded from the server. Re-align it and queue it again.';
                        if (page) item.page = page;
                        item.sentToScan = withWorker;
                        ok = 'changed';
                        continue;
                    }
                    item.page = page;
                } catch (error) {
                    console.warn(`${item.name} could not be loaded:`, error);
                    return 'offline';
                }
            }
            return ok;
        }

        // ------------------------------------------- pick the files again
        let batchFilesResolve = null;
        let batchFilesContinue = false;
        // Pages read already whose file is wanted too (to re-align them).
        let batchFilesAlso = [];
        // Picked for a re-align: it goes on once the file is at hand.
        let batchFilesThenRealign = false;
        const BATCH_FILES_TITLE = el('batchFilesTitle').textContent.trim();
        const BATCH_FILES_TEXT = el('batchFilesText').textContent.trim().replace(/\s+/g, ' ');

        /** Say which files are wanted, and match what is picked. Resolves when the window closes. */
        function askForBatchFiles({ thenContinue = false, also = [], thenRealign = false } = {}) {
            batchFilesContinue = batchFilesContinue || thenContinue;
            batchFilesAlso = also;
            batchFilesThenRealign = thenRealign;
            // A re-align says so, and names the document; otherwise the window's own words.
            if (thenRealign && also.length > 0) {
                el('batchFilesTitle').textContent = 'Pick the file to re-align';
                el('batchFilesText').textContent = `To re-align ${recordLabel(recordOf(also[0]))}, pick its file again (or its folder). A renamed copy still matches.`;
            } else {
                el('batchFilesTitle').textContent = BATCH_FILES_TITLE;
                el('batchFilesText').textContent = BATCH_FILES_TEXT;
            }
            renderBatchFilesList();
            el('batchFilesResult').classList.add('d-none');
            const modal = window.bootstrap.Modal.getOrCreateInstance(el('batchFilesModal'));
            return new Promise((resolve) => {
                const previous = batchFilesResolve;
                batchFilesResolve = () => {
                    previous?.();
                    resolve();
                };
                modal.show();
            });
        }

        function renderBatchFilesList() {
            const { missing } = matchFilesToBatch([], batchState.items);
            batchFilesAlso = batchFilesAlso.filter((it) => !it.file);
            batchFilesAlso.forEach((it) => {
                const entry = missing.find((candidate) => candidate.sourceHash === it.sourceHash);
                if (entry) entry.pages = Math.max(entry.pages, batchFilesAlso.filter((other) => other.sourceHash === it.sourceHash).length);
                else missing.push({ sourceName: it.sourceName ?? it.name, sourceHash: it.sourceHash, pages: batchFilesAlso.filter((other) => other.sourceHash === it.sourceHash).length });
            });
            el('batchFilesList').replaceChildren(...missing.map((entry) => {
                const li = document.createElement('li');
                li.textContent = `${entry.sourceName} · ${entry.pages} page${entry.pages === 1 ? '' : 's'} to align`;
                return li;
            }));
            return missing;
        }

        el('batchFilesModal').addEventListener('hidden.bs.modal', async () => {
            const resolve = batchFilesResolve;
            const thenContinue = batchFilesContinue;
            const thenRealign = batchFilesThenRealign;
            batchFilesResolve = null;
            batchFilesContinue = false;
            batchFilesThenRealign = false;
            resolve?.();
            renderBatchQueueBanner();
            // Picked for a re-align: on with it (its question comes next), not a second click.
            if (thenRealign && realignablePages().length > 0) {
                await realignBatchPages(realignablePages());
                return;
            }
            // Nothing else could open: go on once files are at hand (else the upload step offers them).
            if (thenContinue && batchState.items.some((item) => pageAlignable(item) || recordStateOf(item) === 'ready')) {
                await continueBatch(null, { askForFiles: false });
            } else if (activeStep === 'upload') {
                showResumeOffer();
            }
        });
        el('batchFilesPickBtn').addEventListener('click', () => {
            el('batchRepickFiles').value = '';
            el('batchRepickFiles').click();
        });
        el('batchFilesFolderBtn').addEventListener('click', () => {
            el('batchRepickFolder').value = '';
            el('batchRepickFolder').click();
        });
        el('batchFilesNoticeBtn').addEventListener('click', () => askForBatchFiles());
        [el('batchRepickFiles'), el('batchRepickFolder')].forEach((input) => input.addEventListener('change', async () => {
            const picked = Array.from(input.files ?? []).filter((file) => !SYSTEM_FILE.test(file.name) && SUPPORTED_SCAN_EXT.test(file.name));
            if (picked.length === 0) return;
            const button = el('batchFilesPickBtn');
            const spinner = button.querySelector('.spinner-border');
            button.disabled = true;
            el('batchFilesFolderBtn').disabled = true;
            spinner?.classList.remove('d-none');
            try {
                const hashed = [];
                for (const file of picked) {
                    let hash = null;
                    try {
                        // eslint-disable-next-line no-await-in-loop
                        hash = await fileFingerprint(file);
                    } catch (error) {
                        console.warn(`${file.name} could not be fingerprinted:`, error);
                    }
                    hashed.push({ file, hash });
                }
                const { matched, unmatched } = matchFilesToBatch(hashed, batchState.items);
                // Kept in this browser too, so the next resume does not ask again.
                matched.forEach((file, hash) => { rememberBatchFile(hash, file); });
                batchState.items.forEach((item) => {
                    const file = item.sourceHash ? matched.get(item.sourceHash) : null;
                    if (file && !item.file) item.file = file;
                });
                const missing = renderBatchFilesList();
                const lines = [];
                if (matched.size > 0) lines.push(`${matched.size} file${matched.size === 1 ? '' : 's'} matched.`);
                if (unmatched.length > 0) lines.push(`Not part of this batch: ${unmatched.slice(0, 5).join(', ')}${unmatched.length > 5 ? ` and ${unmatched.length - 5} more` : ''}.`);
                el('batchFilesResult').textContent = lines.join(' ');
                el('batchFilesResult').classList.toggle('d-none', lines.length === 0);
                renderBatchQueueBanner();
                // Every file found: on with the batch.
                if (missing.length === 0) window.bootstrap.Modal.getInstance(el('batchFilesModal'))?.hide();
            } finally {
                button.disabled = false;
                el('batchFilesFolderBtn').disabled = false;
                spinner?.classList.add('d-none');
            }
        }));

        showResumeOffer();

        // Came from another type's list ("?resume=12"): that batch resumes now.
        {
            const url = new URL(window.location.href);
            const wanted = Number(url.searchParams.get('resume'));
            if (url.searchParams.has('resume')) {
                url.searchParams.delete('resume');
                window.history.replaceState(window.history.state, '', url);
            }
            if (wanted && (config.openBatches ?? []).some((batch) => batch.id === wanted && batch.docType === config.docType)) {
                resumeBatch(wanted);
            }
        }

        // Scan all asks about every waiting page at least this often (see runBatchScanAll).
        const BATCH_STATUS_SWEEP_MS = 10000;
        // ...and gives up only after the server has not answered for this long.
        const BATCH_STATUS_OFFLINE_MS = 15000;
        const OCR_DOWN_MESSAGE = 'The OCR service is not running, so the pages cannot be read. Start the OCR service, then press Scan All again. Pages already read are kept.';

        /**
         * A page's status for Scan all: { page } when it answered, { gone } when
         * the server refused it for good (the page fails alone), or { retry } for
         * a check worth asking again (no connection, the server busy or restarting).
         */
        async function fetchBatchPageStatus(url, signal) {
            let response;
            try {
                response = await fetch(url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal,
                });
            } catch (error) {
                if (error.name === 'AbortError') throw error;
                return { retry: true };
            }
            const payload = (response.headers.get('content-type') || '').includes('application/json')
                ? await response.json().catch(() => null)
                : null;
            if (response.ok && payload) return { page: payload };
            if (response.ok || response.status >= 500 || [408, 429].includes(response.status)) return { retry: true };
            return { gone: true, response, payload };
        }

        /** True only when the server says the OCR service cannot be reached; a failed check says nothing. */
        async function ocrServiceDown(signal) {
            try {
                const response = await fetch(config.ocrStatusUrl, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal,
                });
                if (!response.ok) return false;
                return (await response.json())?.reachable === false;
            } catch (error) {
                if (error.name === 'AbortError') throw error;
                return false;
            }
        }

        /** A page Scan all could not read: marked failed with the reason, and the run goes on. */
        function failBatchEntry(entry, message) {
            entry.done = true;
            entry.failed = true;
            entry.item.status = 'failed';
            entry.item.error = message || 'This page could not be read.';
            entry.item.sentToScan = false;
            renderBatchQueueBanner();
        }

        /**
         * Verify a queued record, as single mode does once a scan is read: a page
         * alone, or a pair of pages (1–2, 3–4, when the layout has a page 2) side
         * by side, both live, saved together as one record. `index` may be any
         * page of the record; its first page stands for it (currentIndex).
         */
        async function openBatchVerifyItem(index) {
            const picked = batchState.items[index];
            // Only a record whose pages are all read opens in Verify.
            if (!picked || recordStateOf(picked) !== 'ready') return;
            // A resumed batch knows only where its pages are: what was read is asked for now.
            const loaded = await loadBatchPages(recordOf(picked).members);
            if (loaded === 'offline') {
                tellInApp('This record could not be loaded', 'The server could not be reached. Check the connection, then open it again from the queue.');
                renderBatchQueueBanner();
                return;
            }
            if (loaded === 'changed') {
                renderBatchQueueBanner();
                await continueBatch(picked);
                return;
            }
            const record = recordOf(picked);
            const members = record.members.filter((it) => it.page?.imageUrl && !['rejected', 'failed'].includes(it.status));
            const lead = members[0];
            if (!lead) return;

            // Leaving a record keeps what was typed and ticked on it (in Verify and
            // in Review & Export), and one only looked at goes back to "Scanned".
            const leaving = batchState.items[batchState.currentIndex];
            if (leaving && !members.includes(leaving)) {
                keepVerifyDraft(leaving);
                recordOf(leaving).members.forEach((it) => {
                    if (it.status === 'verifying') it.status = 'ready';
                });
            }

            batchState.currentIndex = batchState.items.indexOf(lead);
            scheduleBatchSync();
            useLayoutForPage(lead.pageNo ?? 1);
            members.forEach((it) => {
                if (it.status !== 'imported') it.status = 'verifying';
            });
            renderBatchQueueBanner();

            // The record knows the file and page(s) it came from, as a single scan does.
            scanFile = lead.file;
            scanPageNumber = lead.pageNo ?? 1;
            scanPageCount = lead.pageTotal ?? 1;
            scanFingerprint = lead.sourceHash ?? null;
            verifyDoc = members.length > 1
                ? {
                    pages: members.map((it) => ({ number: it.pageNo, page: it.page, layout: layoutFor(it.pageNo), item: it })),
                    offsets: [0, 0],
                }
                : null;
            processedPage = lead.page;

            if (!verifyDoc) {
                await marker.loadFromUrl(lead.page.imageUrl);
                if (lead.boxes && lead.rows) {
                    setGrid(geometryMarkers(lead.page.geometry, lead.page), lead.page.geometry?.ruled_ys ?? lead.rows);
                }
            }

            cropped = verifyItems();
            readings = cropped.map((entry) => entry.reading);
            requiredInput(document, '#ocrModelKey').value = (processedPage.modelKey || '').trim();
            requiredInput(document, '#documentPageId').value = String(processedPage.id);
            el('summaryModel').textContent = processedPage.model || '—';
            const records = batchRecords();
            const position = records.findIndex((candidate) => candidate.members.includes(lead));
            // The bar says which record of how many, and its file and pages.
            el('workspaceContextDetail').textContent = config.typeLabel;
            el('workspaceContextDetail').title = `${config.typeLabel} · Record ${position + 1} of ${records.length} · ${recordLabel(record)}`;

            clearValidationSubmitError();
            renderVerifyRows();
            if (verifyDoc) await loadVerifySpread();
            showStep('verify');
            if (verifyDoc) prepareSpreadComparison();
            else prepareValidationComparison();
            // Re-align kept some lines' readings on new lines: what was typed on them follows.
            members.forEach((it) => {
                const map = it.page?.keptLines;
                if (!map || Array.isArray(map) || !lead.verifyDraft) return;
                lead.verifyDraft = new Map([...lead.verifyDraft].map(([line, entry]) => [map[String(line)] ?? line, entry]));
            });
            // What was typed and ticked on it before (after the step is set up afresh).
            restoreVerifyDraft(lead);
            // A blank page (a cover, an empty sheet) has nothing to tick or save.
            if (cropped.length === 0) {
                showValidationSubmitError('No handwriting was found on this page, so there is nothing to save. Take it out of the batch with the × beside it in the queue list to go on.');
            }

            // Continue anyway, once chosen for this record, is not asked again.
            const existing = members.map((it) => it.page.existingRecord).find(Boolean) ?? null;
            checkAndShowExistingRecord(lead.existingAccepted ? null : existing);
            existingRecordAccepted = Boolean(lead.existingAccepted);
        }

        // Continue anyway was chosen for this scan's match with a saved record.
        let existingRecordAccepted = false;

        function checkAndShowExistingRecord(existing) {
            existingRecordAccepted = false;
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
            const description = `What was read matches a saved record ("${recordTitle}"). `
                + 'Continue to save it again as a new record, or reject this scan.';

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

            // Nothing is rejected until Reject is pressed: Continue keeps the scan.
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
                    const current = recordOf(batchState.items[batchState.currentIndex]);
                    const hasMore = batchRecords().some((record) => record.key !== current.key && !recordDone(record));
                    // (A record still waiting or blocked comes after: its page opens in Align.)
                    rejectLabel.textContent = hasMore ? 'Reject & Next Record' : 'Reject & Complete Batch';
                } else {
                    rejectLabel.textContent = 'Reject & End';
                }
            }
            if (modalEl) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        }

        /**
         * Leave the scan out. In a batch, this record's pages are marked (rejected
         * as a match, or `skipped`: a blank page, a cover, one saved already) and
         * the next record opens, or the batch's summary.
         */
        async function rejectCurrentExistingDocument({ skipped = false } = {}) {
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
                // This record (its page, or both pages of a pair); the file's other pages go on.
                const currentItem = batchState.items[batchState.currentIndex];
                if (currentItem) {
                    recordOf(currentItem).members.forEach((it) => {
                        it.status = 'rejected';
                        it.skipped = skipped;
                        it.verifyDraft = null;
                        it.reviewExtras = null;
                    });
                    syncBatchDraft(currentItem);
                }
                renderBatchQueueBanner();

                // The next record read; else a page still to align (one that failed); else the summary.
                await continueBatch(currentItem ?? null);
                return;
            }

            scanFile = null;
            processedPage = null;
            scanFileInput.value = '';
            showStep('upload');
        }

        document.getElementById('confirmRejectExistingBtn')?.addEventListener('click', () => rejectCurrentExistingDocument());
        document.getElementById('validationExistingRejectBtn')?.addEventListener('click', () => rejectCurrentExistingDocument());

        // ------------------------------------------------ Re-align (a batch)
        // A record in Verify (or Review & Export) whose page was read badly goes
        // back to Align with the markers it was read with. What was typed and
        // ticked stays with the record: the page not read again keeps its values,
        // and a page read again starts fresh. Queued again, it is read at once,
        // whichever reading mode is set, and the record comes back to Verify in
        // its place in the queue.

        /** The pages of the record open now that can be sent back to Align. */
        function realignablePages() {
            const current = batchState.active ? batchState.items[batchState.currentIndex] : null;
            if (!current || recordSubmitting) return [];
            // With its file, or (after a resume) the server's copy of the page and its original.
            return recordOf(current).members.filter((it) => !pageDone(it) && it.page && it.pageId);
        }

        /**
         * Ask (or tell) in the app's own window, not the browser's box. Resolves 'confirm',
         * 'cancel', or null when closed with x or Esc. With `cancel: null` it only tells.
         * `tone`: 'warning' (default), 'danger' (the main button is red) or 'info'.
         */
        function askInApp({ title, text = '', confirm = 'OK', cancel = 'Cancel', tone = 'warning' }) {
            const modalEl = el('appAskModal');
            el('appAskTitle').textContent = title;
            el('appAskText').replaceChildren(...String(text).split(/\n{2,}/).filter(Boolean).map((part) => {
                const paragraph = document.createElement('p');
                paragraph.textContent = part;
                return paragraph;
            }));
            const toneClass = tone === 'danger' ? 'bg-label-danger' : (tone === 'info' ? 'bg-label-primary' : 'bg-label-warning');
            el('appAskIcon').className = `leave-scan-modal__icon ${toneClass}`;
            el('appAskGlyph').className = `icon-base bx ${tone === 'info' ? 'bx-info-circle' : 'bx-error'}`;
            const confirmBtn = el('appAskConfirm');
            confirmBtn.textContent = confirm;
            confirmBtn.className = `btn ${tone === 'danger' ? 'btn-danger' : 'btn-primary'} text-nowrap`;
            const cancelBtn = el('appAskCancel');
            cancelBtn.textContent = cancel ?? '';
            cancelBtn.classList.toggle('d-none', !cancel);
            el('appAskActions').classList.toggle('is-three', !cancel);
            return new Promise((resolve) => {
                let answer = null;
                const close = (value) => {
                    answer = value;
                    window.bootstrap.Modal.getInstance(modalEl)?.hide();
                };
                confirmBtn.onclick = () => close('confirm');
                cancelBtn.onclick = () => close('cancel');
                modalEl.addEventListener('hidden.bs.modal', () => resolve(answer), { once: true });
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            });
        }

        /** Tell something in the app's own window (one button). */
        const tellInApp = (title, text = '') => askInApp({ title, text, cancel: null });

        /** Ask in the app's own window (not the browser's box); resolves true on the main button. */
        function askRealign(title, doc, text, {
            confirm = 'Re-align', cancel = 'Cancel', list = false, warning = false,
        } = {}) {
            el('realignConfirmTitle').textContent = title;
            el('realignConfirmDoc').textContent = doc;
            el('realignConfirmText').textContent = text;
            el('realignConfirmBtn').textContent = confirm;
            el('realignConfirmCancel').textContent = cancel;
            el('realignConfirmList').classList.toggle('d-none', !list);
            el('realignConfirmWarning').classList.toggle('d-none', !warning);
            return new Promise((resolve) => {
                const modalEl = el('realignConfirmModal');
                let chosen = false;
                el('realignConfirmBtn').onclick = () => {
                    chosen = true;
                    window.bootstrap.Modal.getInstance(modalEl)?.hide();
                };
                modalEl.addEventListener('hidden.bs.modal', () => resolve(chosen), { once: true });
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            });
        }

        /** The whole document goes back: every page of the record open now (a pair goes as one). */
        async function realignBatchPages(pages) {
            if (pages.length === 0 || recordSubmitting) return;
            const doc = recordLabel(recordOf(pages[0]));
            const many = pages.length > 1;
            const text = `${many ? 'Both pages go' : 'The page goes'} back to Align. Only the fields you move are read again; the rest keep their reading and what you typed.`;
            if (!(await askRealign('Re-align this document?', doc, text))) return;

            // Every page is drawn as the server has it, with the markers it was read with on it
            // (the server may have straightened the page). Queued again, the same page is read
            // again from there: what the detector found is reused, and only fields cut out
            // differently are read (the rest keep their reading).
            const fromServer = pages;
            try {
                await Promise.all(fromServer.map(ensureServerPage));
            } catch (error) {
                showValidationSubmitError(`This document cannot be re-aligned now: ${error.message}`);
                return;
            }

            // The old reading stays until the new one is queued, so Cancel re-align can bring it back.
            leaveBatchVerify();
            fromServer.forEach((it) => {
                it.serverCopy = true;
                it.readBaseline = null;
                it.boxes = cloneBoxes(geometryMarkers(it.page.geometry, it.page));
                it.rows = (it.page.geometry?.ruled_ys ?? it.rows ?? []).map(Number);
                it.tilts = it.rows.map(() => 0);
                it.autoFields = null;
                it.rowsByHand = false;
            });
            pages.forEach((it) => {
                it.status = 'pending';
                it.realign = true;
                // Read again once queued: it is checked again before it counts as Done.
                it.approved = null;
                it.detected = null;
                it.sentToScan = false;
                it.error = null;
            });
            await openBatchQueueItem(batchState.items.indexOf(pages[0]));
        }

        /** Re-align, taken back: the pages keep the reading they had, and the record opens in Verify again. */
        async function cancelRealign() {
            const current = batchState.items[batchState.currentIndex];
            if (!current || !batchAligning() || batchState.readingAll) return;
            const record = recordOf(current);
            const back = record.members.filter((it) => it.realign && it.page);
            if (back.length === 0) return;
            back.forEach((it) => {
                it.realign = false;
                it.serverCopy = false;
                it.status = 'ready';
                it.error = null;
            });
            renderBatchQueueBanner();
            await openBatchVerifyItem(batchState.items.indexOf(record.members[0]));
        }
        el('cancelRealignBtn').addEventListener('click', cancelRealign);

        function setUpRealign(prefix) {
            el(`${prefix}RealignBtn`).addEventListener('click', () => {
                const pages = realignablePages();
                // A resumed record: its file is picked again first.
                const current = batchState.items[batchState.currentIndex];
                // Only a page the server keeps no original of needs its file picked again.
                const fileless = current
                    ? recordOf(current).members.filter((it) => !pageDone(it) && !it.file && !(it.pageId && it.workspaceScanPath))
                    : [];
                if (pages.length === 0 && fileless.length > 0) {
                    askForBatchFiles({ also: fileless, thenRealign: true });
                    return;
                }
                realignBatchPages(pages);
            });
        }
        setUpRealign('verify');

        // Continue anyway: the scan is kept and verified as usual, and saving it
        // makes a new record without asking again.
        function continueWithExistingRecord() {
            existingRecordAccepted = true;
            // A queued record remembers it, so opening it again does not ask again.
            const current = currentVerifyItem();
            if (current) current.existingAccepted = true;
            window.bootstrap.Modal.getInstance(el('existingRecordModal'))?.hide();
            el('validationExistingBanner').classList.add('d-none');
        }
        // ------------------------------------------------ the end of a batch
        // Every record is saved or rejected: say what was saved, link each
        // record, and offer every saved record's table as one download (taken
        // as Review & Export showed it when that record was saved).
        function batchExportTable() {
            // A register's records join as its own columns only (no Record ID or Document).
            return combineTables(batchRecords()
                .filter((record) => record.members[0].exportTable)
                .map((record) => ({
                    recordId: record.members[0].recordId,
                    label: recordLabel(record),
                    table: record.members[0].exportTable,
                })), { plain: Boolean(registry) });
        }

        /**
         * Save & next in a batch: the record passed the same checks Save does,
         * and is Done. What was typed and ticked is kept with the batch (so a
         * resume has it), nothing is saved to Saved Records yet, and it can
         * be opened and changed again from the queue. Then the next record to
         * check opens, or, with none left, Save & finish batch saves them all.
         */
        async function approveBatchRecord(lead, { allowMissing, allowDuplicate }) {
            saveReferenceInputsForCurrentPage();
            if (!validateDocumentReference()) {
                recordSubmitting = false;
                return false;
            }
            lead.bookNo = getBookNumber();
            lead.pageNoRef = getPageNumber();
            recordSubmitting = true;
            // The loading circle first, while what was typed is kept with the batch;
            // only then does the bar move on, and then the next record opens.
            const button = el('reviewSaveBtn');
            const idleButton = button.innerHTML;
            const label = el('reviewSaveLabel')?.textContent.trim() || 'Save & next';
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>';
            button.append(label);
            const started = performance.now();
            try {
                reviewTable?.commit();
                const approval = { allowMissing: Boolean(allowMissing), allowDuplicate: Boolean(allowDuplicate) };
                recordOf(lead).members.forEach((it) => {
                    it.approved = approval;
                    // Saving it was chosen: "Continue anyway" is not asked again when it is saved.
                    if (existingRecordAccepted) it.existingAccepted = true;
                });
                keepVerifyDraft(lead);
                // On the server (so a resume has it), or given up on after a while: it is kept here anyway.
                await Promise.race([batchServer.chain, wait(4000)]);
                // Long enough to be seen.
                const rest = 450 - (performance.now() - started);
                if (rest > 0 && !reducedMotion()) await wait(rest);
                renderBatchQueueBanner();
                // The bar glides on before the next record takes the screen.
                if (!reducedMotion()) await wait(350);
                // The circle stays until the next record is open (no second press meanwhile).
                if (batchRecords().some((record) => !recordSettled(record))) {
                    await continueBatch(lead);
                    return;
                }
            } finally {
                recordSubmitting = false;
                button.innerHTML = idleButton;
                button.disabled = false;
            }
            // None left to check: Save & finish batch saves them all (its own button state).
            await finishBatchSave();
        }

        /**
         * Save & finish batch: every Done record is saved, one after another, each
         * opened in Review & Export and saved by submitRecord as a record always
         * is. A record that cannot be saved stops it there, with its message, and
         * stays Done; Save & finish batch (or the question's answer) goes on.
         */
        async function finishBatchSave() {
            if (batchFinishing) return;
            batchFinishing = true;
            el('backToVerify').disabled = true;
            renderBatchQueueBanner();
            try {
                for (;;) {
                    const record = batchRecords().find(recordApproved);
                    if (!record) break;
                    const lead = record.members[0];
                    const onScreen = () => record.members.includes(batchState.items[batchState.currentIndex]);
                    if (!onScreen() || activeStep !== 'review') {
                        // eslint-disable-next-line no-await-in-loop
                        await openBatchVerifyItem(batchState.items.indexOf(lead));
                        if (!onScreen()) break;
                        if (activeStep === 'verify' && !openReview({ switching: true })) break;
                    }
                    // Saving it was chosen when it was made Done.
                    window.bootstrap.Modal.getInstance(el('existingRecordModal'))?.hide();
                    // eslint-disable-next-line no-await-in-loop
                    const saved = await submitRecord({ ...lead.approved, finalBatchSave: true });
                    if (!saved) break;
                }
                // Every record saved: the green line fills to the end, with "All 4 records
                // saved", before the batch's summary takes the step's place.
                if (batchState.items.every(pageDone)) {
                    renderBatchQueueBanner();
                    const button = el('reviewSaveBtn');
                    button.disabled = true;
                    button.innerHTML = '<i class="icon-base bx bx-check me-2" aria-hidden="true"></i>Saved';
                    if (!reducedMotion()) await wait(1000);
                }
            } finally {
                batchFinishing = false;
                el('backToVerify').disabled = false;
            }
            if (batchState.items.every(pageDone)) {
                showBatchSummary();
                return;
            }
            renderBatchQueueBanner();
            updateReviewSaveLabel();
        }

        function showBatchSummary() {
            finishServerBatch();
            // Nothing is left to lose: leaving the page no longer asks.
            scanFile = null;
            batchSummaryShown = true;
            if (activeStep !== 'review') showStep('review');
            renderBatchQueueBanner();

            const records = batchRecords();
            const saved = records.filter((record) => record.members.every((it) => it.status === 'imported'));
            const left = records.filter((record) => record.members.some((it) => it.status === 'rejected'));
            const skipped = left.filter((record) => record.members[0].skipped).length;
            const rejected = left.length - skipped;
            const plural = (count, noun) => `${count} ${noun}${count === 1 ? '' : 's'}`;
            el('batchSummaryDescription').textContent = [
                `${plural(saved.length, 'record')} saved`,
                ...(rejected > 0 ? [`${rejected} rejected`] : []),
                ...(skipped > 0 ? [`${skipped} skipped`] : []),
            ].join(' · ');

            const cell = (text, className = '') => {
                const td = document.createElement('td');
                if (className) td.className = className;
                td.textContent = text;
                return td;
            };
            el('batchSummaryList').replaceChildren(...records.map((record, index) => {
                const [lead] = record.members;
                const isSaved = lead.status === 'imported';
                const tr = document.createElement('tr');
                if (!isSaved) tr.className = 'is-left-out';

                // The file's name, and the record's number under it (as the archive shows it).
                const doc = document.createElement('td');
                const name = document.createElement('span');
                name.className = 'batch-done__name';
                name.textContent = lead.sourceName ?? lead.name;
                name.title = name.textContent;
                doc.append(name);
                if (isSaved && lead.recordId) {
                    const sub = document.createElement('small');
                    sub.className = 'd-block text-muted';
                    sub.textContent = `Record #${lead.recordId}`;
                    doc.append(sub);
                }

                const pages = lead.pageTotal > 1 ? pageRanges(record.members.map((it) => it.pageNo)) : '1';
                // Rows as Review & Export showed them when it was saved (the header left out).
                const rows = isSaved && Array.isArray(lead.exportTable) ? String(lead.exportTable.length - 1) : '—';

                const status = document.createElement('td');
                const badge = document.createElement('span');
                badge.className = `badge ${isSaved ? 'bg-label-success' : lead.skipped ? 'bg-label-secondary' : 'bg-label-danger'}`;
                badge.textContent = isSaved ? 'Saved' : lead.skipped ? 'Skipped' : 'Rejected';
                status.append(badge);

                const open = document.createElement('td');
                open.className = 'text-end';
                if (isSaved && lead.recordUrl) {
                    const link = document.createElement('a');
                    link.href = lead.recordUrl;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    link.className = 'btn btn-sm btn-outline-secondary';
                    link.textContent = 'View';
                    link.title = `Open record #${lead.recordId ?? ''} in a new tab`.trim();
                    open.append(link);
                }

                tr.append(cell(String(index + 1), 'batch-done__num text-muted'), doc, cell(pages),
                    cell(rows, 'text-end'), status, open);
                return tr;
            }));

            const canDownload = batchSavedRecordIds().length > 0 || batchExportTable().length > 1;
            el('batchSummaryDownloadBtn').disabled = !canDownload;
            el('batchSummaryRecordsBtn').href = config.recordsUrl;
            el('batchSummaryRestartBtn').href = config.restartUrl;

            // In the step's place: the scan and the table go, the card comes in.
            el('reviewNotices').classList.add('d-none');
            el('reviewGrid').classList.add('d-none');
            el('batchSummaryPanel').classList.remove('d-none');
            // Step 4 is done too: every step shows its tick, and there is nothing left to cancel.
            document.querySelector('[data-flow-step="review"]')?.classList.add('is-complete');
            el('workspaceCancel').classList.add('d-none');
            el('batchSummaryRestartBtn').focus({ preventScroll: true });
        }

        const batchFileName = () => exportFileName(config.typeLabel, 'Batch', new Date().toISOString().slice(0, 10));

        /** The records this batch saved, in queue order. */
        function batchSavedRecordIds() {
            return batchRecords()
                .map((record) => record.members[0])
                .filter((lead) => lead.status === 'imported' && lead.recordId)
                .map((lead) => lead.recordId);
        }

        /**
         * Download all: every saved record's table as the archive has it now (later
         * edits included), as one CSV, or one Excel file with an "All records" sheet
         * and a sheet per record. If the server cannot give them, the tables kept in
         * this page (as Review & Export showed them) are used instead.
         */
        async function downloadBatch(format) {
            const button = el('batchSummaryDownloadBtn');
            const spinner = button.querySelector('.spinner-border');
            button.disabled = true;
            spinner?.classList.remove('d-none');
            try {
                const ids = batchSavedRecordIds();
                let records = [];
                if (ids.length > 0) {
                    const params = new URLSearchParams();
                    ids.forEach((id) => params.append('ids[]', String(id)));
                    try {
                        records = await fetchRecordTables(config.recordsExportUrl, params);
                    } catch (error) {
                        console.warn('The saved records could not be fetched; using the tables kept in this page:', error);
                    }
                }
                if (records.length > 0) await downloadRecordTables(records, batchFileName(), format);
                else if (format === 'csv') await downloadCsv(batchExportTable(), batchFileName());
                else await downloadXlsx(batchExportTable(), batchFileName());
            } catch (error) {
                console.error('The batch download failed:', error);
                tellInApp('The file could not be made', format === 'xlsx' ? 'Try CSV instead.' : (error.message || 'Try again.'));
            } finally {
                button.disabled = false;
                spinner?.classList.add('d-none');
            }
        }
        el('batchSummaryCsv').addEventListener('click', () => downloadBatch('csv'));
        el('batchSummaryXlsx').addEventListener('click', () => downloadBatch('xlsx'));
        // OK is the safe default: Enter never adds duplicates by accident.
        el('batchIntakeModal').addEventListener('shown.bs.modal', () => el('batchIntakeOkBtn').focus());

        el('continueExistingBtn').addEventListener('click', continueWithExistingRecord);
        el('validationExistingContinueBtn').addEventListener('click', continueWithExistingRecord);

        scanFileInput.addEventListener('change', (event) => {
            if (!(event.currentTarget instanceof HTMLInputElement)) return;
            if (uploadLocked) {
                event.currentTarget.value = '';
                explainUploadLock();
                return;
            }
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
        // A batch kept on the server is not lost by leaving, but Cancel asks: keep it, or discard it.
        const batchToKeep = () => batchOpenOnServer() && !leaving && batchSummaryPhase(batchState.items) !== 'done';

        // A single scan can be kept too: Keep for later turns its file into a batch kept on
        // the server (its pages not saved yet, with the markers placed and anything read and
        // typed), so it waits under Unfinished batches and Resume carries on in Batch mode.
        const singleToKeep = () => !batchState.active && scanFile !== null && !recordSubmitting && !leaving
            && !scanInProgress && Boolean(config.batchUrls);

        /** The open single scan as batch pages: each page of its file not saved yet. */
        function singleScanItems() {
            const pairs = Boolean(config.pageTwo);
            const group = `single-${Date.now().toString(36)}`;
            const sourceName = scanFile?.name ?? 'scan';
            const pageTotal = Math.max(1, scanPageCount);
            // Read and open in Verify or Review & Export (one page, or both of a register): kept as read.
            const read = ['verify', 'review'].includes(activeStep) && processedPage
                ? new Map(docEntries().filter((entry) => entry.page?.id).map((entry) => [entry.number, entry.page]))
                : new Map();
            const items = [];
            for (let pageNo = 1; pageNo <= pageTotal; pageNo += 1) {
                const current = pageNo === scanPageNumber;
                // A page saved before is left out, unless it is the one being worked on now.
                if (savedPages.has(pageNo) && !read.has(pageNo) && !current) continue;
                const layout = current ? null : pageLayouts.get(pageNo);
                const grid = current ? gridState() : layout?.grid ?? null;
                const page = read.get(pageNo) ?? null;
                const ref = pageReferences.get(pageNo);
                items.push({
                    key: randomKey(),
                    file: scanFile,
                    name: pageTotal > 1 ? pageFileName(sourceName, pageNo) : sourceName,
                    group,
                    record: `${group}#${recordLeadPage(pageNo, pageTotal, pairs)}`,
                    pageNo,
                    pageTotal,
                    sourceName,
                    sourceHash: scanFingerprint,
                    status: page ? 'ready' : 'pending',
                    pageId: page?.id ?? null,
                    page,
                    workspaceScanPath: null,
                    scanKind: 'full',
                    geometry: page?.geometry ?? null,
                    recordUrl: null,
                    recordId: null,
                    error: null,
                    boxes: grid ? cloneBoxes(grid.boxes) : null,
                    rows: grid ? [...grid.rows] : null,
                    tilts: grid?.tilts ? [...grid.tilts] : null,
                    autoFields: current ? new Map(autoFields) : layout?.autoFields ? new Map(layout.autoFields) : null,
                    rowsByHand: Boolean(grid?.byHand),
                    existingAccepted: Boolean(page) && existingRecordAccepted,
                    addedAnyway: savedPages.has(pageNo),
                    ...(ref?.bookNo ? { bookNo: String(ref.bookNo) } : {}),
                    ...(ref?.pageNo ? { pageNoRef: String(ref.pageNo) } : {}),
                });
            }
            return items;
        }

        /**
         * Keep for later, from a single scan: its file becomes a batch kept on the server.
         * Answers whether it was kept (if not, nothing changed and the scan stays open).
         */
        async function keepSingleScanForLater() {
            saveReferenceInputsForCurrentPage();
            if (!scanFingerprint && scanFile) {
                try {
                    scanFingerprint = await fileFingerprint(scanFile);
                } catch (error) {
                    console.warn('The file could not be fingerprinted (it is picked again on resume):', error);
                }
            }
            const items = singleScanItems();
            if (items.length === 0) {
                tellInApp('Nothing to keep for later', 'Every page of this file is saved already.');
                return false;
            }

            batchState.active = true;
            batchState.key = 'batch_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8);
            batchState.readingAll = false;
            batchState.items = items;
            batchState.currentIndex = Math.max(0, items.findIndex((item) => item.pageNo === scanPageNumber));
            try {
                await startServerBatch();
                if (!batchOpenOnServer()) throw new Error('The server could not keep this scan.');
                window.clearTimeout(batchServer.syncTimer);
                await batchWrite(syncBatchPages);
                // What was typed and ticked in Verify, on the record's first page.
                const lead = items.find((item) => item.status === 'ready');
                if (lead) {
                    keepVerifyDraft(lead);
                    await batchServer.chain;
                }
                // The file stays in this browser, so Resume needs no re-pick.
                if (scanFingerprint) await rememberBatchFile(scanFingerprint, scanFile);
                return true;
            } catch (error) {
                console.warn('This scan could not be kept for later:', error);
                const id = batchServer.id;
                if (id !== null) {
                    // Half kept: not left behind as an empty batch.
                    fetch(config.batchUrls.abandon.replace('__BATCH__', String(id)), {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': config.csrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                        credentials: 'same-origin',
                    }).catch(() => {});
                }
                resetServerBatch();
                batchState.active = false;
                batchState.key = null;
                batchState.items = [];
                batchState.currentIndex = 0;
                tellInApp('This scan could not be kept for later', 'Check your connection and try again, or stay and finish it now.');
                return false;
            }
        }

        // A link in the page (Cancel, the menu) asks with the page's own dialog.
        document.addEventListener('click', (event) => {
            if (!(workInProgress() || batchToKeep()) || event.defaultPrevented || event.button !== 0) return;
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
            if (workInProgress() || batchToKeep()) askToLeave(BACK);
        });

        /** Where the batch stands, one short line each: what is saved, and what is still to do. */
        function batchLeaveSummary() {
            const plural = (count, noun) => `${count} ${noun}${count === 1 ? '' : 's'}`;
            const records = batchRecords();
            const saved = records.filter((record) => record.members.every((it) => it.status === 'imported')).length;
            const inStatus = (...statuses) => batchState.items.filter((it) => statuses.includes(it.status)).length;
            const toAlign = inStatus('pending', 'saving', 'failed');
            const toRead = inStatus('saved', 'scanning', 'ready', 'verifying');
            return {
                lines: [
                    `${saved} of ${plural(records.length, 'record')} saved`,
                    ...(toRead > 0 ? [`${plural(toRead, 'page')} queued or read, not saved yet`] : []),
                    ...(toAlign > 0 ? [`${plural(toAlign, 'page')} still to align`] : []),
                ],
                unsaved: toAlign + toRead,
            };
        }

        // Discard is asked twice: its question replaces the first one in the same window.
        let discardAsked = false;

        /** The window's two faces: Keep for later / Stay / Discard, and "Discard this batch?". */
        /** Where a single scan stands, as it would be kept: the file, what is read, what is still to align. */
        function singleLeaveSummary() {
            const plural = (count, noun) => `${count} ${noun}${count === 1 ? '' : 's'}`;
            const items = singleScanItems();
            const read = items.filter((it) => it.status === 'ready').length;
            const toAlign = items.length - read;
            return {
                lines: [
                    `${scanFile?.name ?? 'This file'}${scanPageCount > 1 ? ` · ${plural(scanPageCount, 'page')}` : ''}`,
                    ...(read > 0 ? [`${plural(read, 'page')} read, not saved yet`] : []),
                    ...(toAlign > 0 ? [`${plural(toAlign, 'page')} still to align`] : []),
                ],
                unsaved: items.length,
            };
        }

        function showLeaveChoice({ discard = false } = {}) {
            discardAsked = discard;
            const single = !batchToKeep() && singleToKeep();
            const keep = batchToKeep() || single;
            const summary = keep ? (single ? singleLeaveSummary() : batchLeaveSummary()) : null;
            const icon = discard ? 'bx-trash' : keep ? 'bx-time-five' : 'bx-error';
            el('leaveScanIcon').className = `leave-scan-modal__icon ${discard ? 'bg-label-danger' : keep ? 'bg-label-primary' : 'bg-label-warning'}`;
            el('leaveScanIconGlyph').className = `icon-base bx ${icon}`;

            if (single && discard) {
                el('leaveScanModalTitle').textContent = 'Discard this scan?';
                el('leaveScanModalDescription').textContent = 'Your markers, and anything read and typed on it, will be thrown away. Records already saved stay saved.';
            } else if (single) {
                el('leaveScanModalTitle').textContent = 'Leave this scan?';
                el('leaveScanModalDescription').textContent = `Keep for later saves your markers and checked values. Open this document type's workspace within ${config.batchKeepDays} days and press Resume to carry on.`;
            } else if (discard) {
                el('leaveScanModalTitle').textContent = 'Discard this batch?';
                el('leaveScanModalDescription').textContent = summary.unsaved > 0
                    ? `${summary.unsaved} page${summary.unsaved === 1 ? '' : 's'} not saved yet, and anything typed on ${summary.unsaved === 1 ? 'it' : 'them'}, will be thrown away. Records already saved stay saved.`
                    : 'The batch will be thrown away. Records already saved stay saved.';
            } else {
                el('leaveScanModalTitle').textContent = keep ? 'Leave this batch?' : 'Leave this scan?';
                el('leaveScanModalDescription').textContent = keep
                    ? `Your work is saved. Open this document type's workspace within ${config.batchKeepDays} days and press Resume to carry on.`
                    : 'Your markers and checked values are not saved yet.';
            }

            // The summary stays under the first question only.
            el('leaveScanSummary').classList.toggle('d-none', !keep || discard);
            if (keep) {
                el('leaveScanSummary').replaceChildren(...summary.lines.map((text) => {
                    const li = document.createElement('li');
                    li.textContent = text;
                    return li;
                }));
            }

            el('leaveScanStayBtn').textContent = discard ? 'Go back' : 'Stay';
            el('confirmLeaveScanBtn').textContent = keep ? 'Keep for later' : 'Leave';
            el('confirmLeaveScanBtn').classList.toggle('btn-danger', !keep);
            el('confirmLeaveScanBtn').classList.toggle('btn-primary', keep);
            el('confirmLeaveScanBtn').classList.toggle('d-none', discard);
            const what = single ? 'scan' : 'batch';
            el('discardBatchLeaveBtn').textContent = discard ? `Yes, discard ${what}` : `Discard ${what}`;
            el('discardBatchLeaveBtn').classList.toggle('d-none', !keep);
            el('discardBatchLeaveBtn').classList.toggle('btn-outline-danger', !discard);
            el('discardBatchLeaveBtn').classList.toggle('btn-danger', discard);
            el('leaveScanActions').classList.toggle('is-three', keep && !discard);
            el('leaveScanActions').classList.toggle('is-discard', discard);
        }

        function askToLeave(target) {
            leaveTo = target;
            el('confirmLeaveScanBtn').disabled = false;
            el('discardBatchLeaveBtn').disabled = false;
            showLeaveChoice();
            window.bootstrap.Modal.getOrCreateInstance(el('leaveScanModal')).show();
        }

        // Stay: closes the window; in Discard's question it goes back to the first choice instead.
        el('leaveScanStayBtn').addEventListener('click', () => {
            if (discardAsked) showLeaveChoice();
            else window.bootstrap.Modal.getInstance(el('leaveScanModal'))?.hide();
        });

        // The safe choice has the focus, so Enter never leaves by accident.
        el('leaveScanModal').addEventListener('shown.bs.modal', () => el('leaveScanStayBtn').focus());

        // Staying after Back: put the entry back, so the next Back asks again.
        el('leaveScanModal').addEventListener('hidden.bs.modal', () => {
            if (workInProgress() || batchToKeep()) guardBackButton();
        });

        el('confirmLeaveScanBtn').addEventListener('click', async () => {
            if (!leaveTo) return;
            // Kept for later: what is on screen now is saved first.
            if (batchToKeep()) {
                el('confirmLeaveScanBtn').disabled = true;
                await flushServerBatch();
            } else if (singleToKeep()) {
                // A single scan becomes a kept batch; if it cannot be kept, the scan stays open.
                el('confirmLeaveScanBtn').disabled = true;
                el('discardBatchLeaveBtn').disabled = true;
                if (!await keepSingleScanForLater()) {
                    el('confirmLeaveScanBtn').disabled = false;
                    el('discardBatchLeaveBtn').disabled = false;
                    window.bootstrap.Modal.getInstance(el('leaveScanModal'))?.hide();
                    return;
                }
            }
            leaving = true;
            if (leaveTo !== BACK) {
                window.location.assign(leaveTo);
                return;
            }

            history.back();
            // Opened in a new tab, there is nothing to go back to: start again.
            backFallback = window.setTimeout(() => window.location.assign(el('workspaceCancel').href), 500);
        });
        window.addEventListener('pagehide', () => {
            window.clearTimeout(backFallback);
            // Left (Keep for later, a link, the tab closed): the batch is no longer open here, so
            // opening it again soon, in another tab, does not warn about this one.
            if (batchOpenOnServer() && config.batchUrls?.release && navigator.sendBeacon) {
                const form = new FormData();
                form.set('_token', config.csrf);
                form.set('tab', batchTab);
                navigator.sendBeacon(config.batchUrls.release.replace('__BATCH__', String(batchServer.id)), form);
            }
        });

        // Discard: the batch is thrown away (its queued pages let go), then leave.
        el('discardBatchLeaveBtn').addEventListener('click', async () => {
            if (!leaveTo) return;
            // First click: ask. Second ("Yes, discard batch"): do it.
            if (!discardAsked) {
                showLeaveChoice({ discard: true });
                return;
            }
            el('discardBatchLeaveBtn').disabled = true;
            // A single scan has no batch on the server (no id): it is just left.
            await discardServerBatch();
            el('confirmLeaveScanBtn').disabled = false;
            // It was kept until now: leaving after a discard has nothing left to flush.
            leaving = true;
            el('confirmLeaveScanBtn').click();
        });

        // Closing the tab or reloading: only the browser's own dialog can ask.
        // A batch kept on the server is resumed instead: nothing to ask.
        window.addEventListener('beforeunload', (event) => {
            if (!workInProgress() || batchOpenOnServer()) return;
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
            if (uploadLocked) {
                explainUploadLock();
                return;
            }
            const transfer = event.dataTransfer;
            // Read while the drop event lasts: its items are gone after it.
            const folder = dropHasFolder(transfer);
            const several = folder || (transfer?.files?.length ?? 0) > 1;
            if (uploadMode !== 'batch' && !batchState.active && !several) {
                openDocument(transfer?.files?.[0]);
                return;
            }

            const files = await extractDroppedScanFiles(transfer);
            if (uploadMode === 'batch' || batchState.active) {
                startBatchQueue(files);
                return;
            }

            // Several files, or a folder, in single mode: that is a batch.
            const what = folder ? 'A folder' : `${files.length} files`;
            const choice = await askInApp({
                title: `${what} dropped`,
                text: `Queue ${folder ? 'everything in it' : 'them all'} with Batch File Upload, or open only the first file?`,
                confirm: 'Use Batch File Upload',
                cancel: 'Open the first file',
                tone: 'info',
            });
            if (choice === null) return;
            if (choice === 'confirm') {
                setUploadMode('batch');
                startBatchQueue(files);
                return;
            }
            const first = files
                .filter((file) => SUPPORTED_SCAN_EXT.test(file.name) && !SYSTEM_FILE.test(file.name))
                .sort((a, b) => compareQueuePaths(queuePath(a), queuePath(b), naturalOrder))[0];
            if (first) openDocument(first);
            else alert('No PDF or image file was found in what was dropped.');
        });

        // A file dropped beside the drop area (or while files are being prepared,
        // when the area takes no pointer) must not open in this tab instead.
        ['dragover', 'drop'].forEach((eventName) => {
            window.addEventListener(eventName, (event) => {
                if (!event.defaultPrevented && Array.from(event.dataTransfer?.types ?? []).includes('Files')) {
                    event.preventDefault();
                }
            });
        });
        dropzone.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                if (uploadLocked) explainUploadLock();
                else scanFileInput.click();
            }
        });
        // Locked: a click on the drop area (a label for the input) explains instead of opening the picker.
        dropzone.addEventListener('click', (event) => {
            if (!uploadLocked) return;
            event.preventDefault();
            event.stopPropagation();
            explainUploadLock();
        }, true);

        // ---------------------------------------------------------------- step 2
        /**
         * The page shown beside the one being aligned, for the Fields list: its
         * number and its fields (as its grid shows them). Null on a lone page.
         */
        function otherPageFields() {
            // Hidden until the spread is set up (updateSpread), so nothing below is read early.
            if (el('docCompanionPage').classList.contains('d-none')) return null;
            const mate = batchPartner();
            const number = mate ? mate.item.pageNo : companionPageFor(scanPageNumber);
            if (number === null || number === undefined) return null;
            const mainNumber = mate ? batchState.items[batchState.currentIndex].pageNo : scanPageNumber;
            return { number, mainNumber, mate, boxes: companionGridFor(number, mate)?.boxes ?? [] };
        }

        /** "Page 2" over that page's fields in the list. */
        function fieldListPageHeading(number, note) {
            const li = document.createElement('li');
            li.className = 'field-list-page';
            li.setAttribute('role', 'presentation');
            li.textContent = `Page ${number}`;
            if (note) {
                const small = document.createElement('span');
                small.className = 'field-list-page__note';
                small.textContent = ` · ${note}`;
                li.append(small);
            }
            return li;
        }

        /**
         * The other page's fields, in the same list: each opens that page and
         * selects the field (what the page beside does when clicked).
         */
        function appendOtherPageFields(list, other) {
            list.appendChild(fieldListPageHeading(other.number, 'click a field to open it'));
            other.boxes.forEach((box, index) => {
                const li = document.createElement('li');
                li.className = 'field-list-item is-other-page';
                li.title = `Open page ${other.number} and select ${box.name}`;
                li.innerHTML = `
                    <span class="badge bg-label-secondary field-list-item__number">${index + 1}</span>
                    <span class="field-list-item__name"></span>`;
                li.querySelector('.field-list-item__name').textContent = box.name;
                li.addEventListener('click', () => openOtherPageField(other, index));
                list.appendChild(li);
            });
        }

        async function openOtherPageField(other, index) {
            if (pageTurning || scanInProgress) return;
            if (other.mate) await openBatchDocument(other.mate.index);
            else await turnToPage(other.number);
            if (index < marker.toJSON().length) marker.selectBox(index, { source: 'marker' });
        }

        function renderFieldList(boxes) {
            const list = el('fieldList');
            list.innerHTML = '';

            // Two pages side by side: both pages' fields in one list, in book order.
            const other = otherPageFields();
            if (other && other.number < other.mainNumber) appendOtherPageFields(list, other);
            if (other) list.appendChild(fieldListPageHeading(other.mainNumber, 'aligning'));

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

            if (other && other.number > other.mainNumber) appendOtherPageFields(list, other);

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
        // 100%: both pages of a two-page document, easing back; one page, at once.
        el('validationZoomOutBtn').addEventListener('click', () => validationMarker.zoomBy(-0.1));
        el('validationZoomInBtn').addEventListener('click', () => validationMarker.zoomBy(0.1));
        el('validationZoomResetBtn').addEventListener('click', () => (verifyDoc ? showVerifyBoth() : validationMarker.resetZoom()));

        function restoreTemplateFields() {
            rowsSource = null;
            const target = resetTarget();
            setGrid(cloneBoxes(target.boxes), [...target.rows], { remember: true, tilts: target.tilts });
            marker.resetZoom();
            el('docViewport').scrollTo({ top: 0, left: 0 });
            clearOcrError();
        }

    // Grid lines off: the row lines and every field box are hidden (not
    // removed), so the page can be seen as scanned.
    // Both pages of a spread follow it (the page being aligned and the one beside
    // it), in single and in batch mode.
    el('showGridToggle').addEventListener('change', (event) => {
        const hidden = !event.target.checked;
        ['fieldOverlay', 'companionOverlay'].forEach((id) => el(id).classList.toggle('is-grid-hidden', hidden));
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
        pageNote.textContent = realignBase
            ? 'Re-aligning: this page goes back to the markers it was read with (not the layout\'s blank grid), so nothing on it is read again.'
            : `Only page ${scanPageNumber} is reset. The other pages keep their own layouts.`;
        pageNote.classList.toggle('d-none', !multiPage() && !realignBase);
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

            // The arrow keys move the selected markers one screen pixel (Shift: 10), as in the Template Builder.
            const arrow = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[event.key];
            if (!commandPressed && !event.altKey && arrow && marker.selectedIndexes().length > 0) {
                event.preventDefault();
                const step = event.shiftKey ? 10 : 1;
                marker.nudgeSelected(arrow[0] * step, arrow[1] * step);
                return;
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

        // A page still queued this long after the worker last moved on: no worker is running.
        const WORKER_HINT_AFTER_MS = 20000;
        const WORKER_HINT = 'No background worker has picked this page up. Run php artisan queue:work (serve.ps1 does it for you).';

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
                    waitedMs > WORKER_HINT_AFTER_MS ? WORKER_HINT : 'The page is queued for line detection.',
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

        async function completeOcrProgress(title = 'Scan complete', note = 'Opening the results in Verify.') {
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

        /** The page's printed lines and the markers fitted to them (this page, or another drawn off-screen). */
        async function requestSnap(signal = null, { canvas = marker.canvas, geometry = currentGeometry() } = {}) {
            const form = new FormData();
            form.set('geometry_json', JSON.stringify(geometry));
            form.set('page', await canvasBlob(canvas), 'page.png');
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

        /**
         * Fit the markers to what Snap to table found: the columns on the table and
         * the page's own row lines, as one undoable change (Ctrl+Z) like any marker
         * edit. Fields moved (or added) by hand stay where they were put.
         */
        function applySnapFit(payload) {
            const rows = payload.geometry?.ruled_ys ?? gridRows;
            rowsSource = payload.rows ?? null;
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
                applySnapFit(payload);
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
                    // A batch keeps the Smoke test result of each page it was only reading.
                    body: JSON.stringify({ keep_detection: run.kind === 'read' || Boolean(run.keepDetectionIds?.has(id)) }),
                }).catch((error) => console.warn('Cancelling the page on the server failed:', error));
            });
        }

        el('ocrProgressCancel').addEventListener('click', cancelActiveRun);

        el('scanNowBtn').addEventListener('click', async () => {
            if (scanInProgress) return;
            saveReferenceInputsForCurrentPage();
            if (!batchState.active) {
                if (!validateDocumentReference()) {
                    return;
                }
            }
            // In a batch, this button is Scan all or Verify (see batchScanAction).
            if (batchState.active) {
                await batchScanAction().run();
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
                setOcrProgress(96, 'Preparing Verify', partner
                    ? 'Putting both pages side by side, one person to a row.'
                    : 'The handwriting results are ready for your review.');
                renderVerifyRows();
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

        // ------------------------------------------- Verify's layout, and a batch's drafts
        // One page fills Verify. A two-page register (verifyDoc), from a single
        // scan or a batch, has both pages side by side (prepareSpreadComparison).
        // A batch keeps what was typed and ticked on each record while another
        // one is shown, so going back to it loses nothing.

        /** The queued record being verified (its first page), while a batch is in Verify. */
        function currentVerifyItem() {
            const item = batchState.active ? batchState.items[batchState.currentIndex] ?? null : null;
            return item?.status === 'verifying' ? item : null;
        }

        /** One page in Verify: its labels, its width and the Submit wording. */
        function setVerifySpreadLayout() {
            const pill = el('validationPagePill');
            pill.textContent = `Page ${scanPageNumber} of ${scanPageCount}`;
            pill.classList.toggle('d-none', !multiPage());
            el('validationMainLabel').classList.add('d-none');

            validationMarker.fitShare = 1;
            el('validationCompanionPage').classList.add('d-none');
            el('validationCompanionPage').classList.remove('is-live');
            el('validationSpread').classList.remove('is-joined', 'is-page-focused');
            document.querySelector('#step-verify .validation-comparison-grid')?.classList.remove('is-two-page'); window.dispatchEvent(new Event('crms:verify-layout'));
            verifyFocus = null;
            verifyFocusZoom = null;
            updateRowHint();
            el('submitLabelHead').textContent = 'Submit';
            el('validationPagesNote').classList.add('d-none');
        }

        /**
         * What was typed and ticked on a queued record (in Verify, and in the
         * cells of Review & Export where nothing was read), kept on its first
         * page while another record is shown.
         */
        function keepVerifyDraft(item, { commit = true } = {}) {
            if (!item || !processedPage || !docEntries().some((entry) => entry.page === item.page)) return;
            // A cell still being typed in counts (not while it is typed in: the draft saved as you go).
            if (commit) reviewTable?.commit();
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
            item.reviewExtras = new Map(reviewExtras);
            // The step it was left on (a resume opens it there again).
            item.inReview = reviewing;
            syncBatchDraft(item);
        }

        /** Call after Verify is set up for the record (that clears what was typed in Review & Export). */
        function restoreVerifyDraft(item) {
            item?.reviewExtras?.forEach((value, key) => reviewExtras.set(key, value));
            const draft = item?.verifyDraft;
            if (!draft) return;
            // Lines of a page read again are new: what was kept for the old ones goes.
            if (cropped.length > 0) {
                const live = new Set(cropped.map((entry) => entry.lineId));
                item.verifyDraft = new Map([...draft].filter(([line]) => live.has(line)));
            }
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

        function prepareValidationComparison() {
            const source = el('pageCanvas');
            const target = el('validationPageCanvas');
            const context = target.getContext('2d');

            if (!context || source.width === 0 || source.height === 0) {
                throw new Error('The original document could not be prepared for comparison. Please scan again.');
            }

            setVerifySpreadLayout();
            activeValidationIndex = null;
            // A new scan: nothing typed in Review & Export yet.
            reviewExtras.clear();
            reviewModel = null;
            reviewTable?.reset();
            stopAddingMissedField();
            stopGroupingFields();
            // A deleted field being drawn again belongs to the page before.
            stopRedrawField();
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
                if (initialGroup) focusFirstVerifyField(initialGroup);
            });
            companionLines.clear();
        }

        // On while the first field gets the cursor after a scan, so the view stays whole.
        let quietVerifyReveal = false;

        /**
         * Straight after a scan: the first field of the first person is selected
         * on the right, with the cursor in it, so the reviewer can start checking
         * without a click. The pages stay at 100%.
         */
        function focusFirstVerifyField(group) {
            const index = group.indexes.find((candidate) => !cropped[candidate]?.cropDeleted) ?? group.indexes[0];
            if (!Number.isInteger(index)) return;
            // The list stays at its top: the person's notes above the first field (what was
            // not read, say) are seen first, not scrolled out of view.
            activateValidationField(index, 'marker', { keepList: true });
            el('verifyRows').scrollTop = 0;

            // The pages stay whole (100%, both side by side): the cursor goes in
            // the box without the view zooming to it.
            const input = validationRow(index)?.querySelector('.verified');
            if (!(input instanceof HTMLInputElement)) return;
            quietVerifyReveal = true;
            try {
                input.focus({ preventScroll: true });
            } finally {
                quietVerifyReveal = false;
            }
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
            reviewExtras.clear();
            reviewModel = null;
            reviewTable?.reset();
            stopAddingMissedField();
            stopGroupingFields();
            stopRedrawField();
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
            document.querySelector('#step-verify .validation-comparison-grid')?.classList.add('is-two-page'); window.dispatchEvent(new Event('crms:verify-layout'));
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
                if (initialGroup) focusFirstVerifyField(initialGroup);
            });
        }

        // -------------------------------------------- two pages in view (Verify)
        // A two-page document starts with both pages side by side. A click on a
        // page zooms in until that page fills the view, with one easing motion;
        // a second click, or 100%, goes back. A
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

        /** Which page is in view, and the cursor that says what a click does. */
        function updateVerifyPageView() {
            const focused = verifyFocusIsCurrent();
            el('validationSpread').classList.toggle('is-page-focused', focused);
            // Zoomed in on a field (or by hand), no page in focus: a click must not undo it.
            el('validationSpread').classList.toggle('is-zoomed-in', !focused && validationMarker.zoom > 1.011);
            el('validationSpread').dataset.focus = focused ? String(verifyFocus) : '';
            // The queue list (when open) marks the page in view.
            if (queuePanel.open) renderBatchQueuePanel();
            scheduleRowHint();
        }

        // A click on a page, beside every outline: that page is focused on (Both goes back).
        el('validationSpread').addEventListener('click', (event) => {
            if (!verifyDoc || !(event.target instanceof Element)) return;
            if (addingMissedField || groupingFields || redrawingLineId !== null || editingLineIndex !== null || recordSubmitting) return;
            if (event.target.closest('.line-marker, .line-overlay.is-editing, button, a, input, select')) return;
            const pane = event.target.closest('#validationMainPage, #validationCompanionPage');
            if (!pane) return;
            if (el('validationSpread').classList.contains('is-zoomed-in')) return;
            const side = pane.id === 'validationMainPage' ? 0 : 1;
            if (!(verifyFocusIsCurrent() && verifyFocus === side)) focusVerifyPage(side);
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

            slideVerifyViewTo(target);
        }

        /**
         * A field picked on the right: the document glides to centre on it, every
         * time, even when it is already in view. A field of the other page, while
         * one page is zoomed in on, brings that page in first.
         */
        // The field the view is sliding to. One click on a card focuses it and then
        // clicks it: the slide already on its way is left to finish, not restarted.
        let glidingToField = null;
        // Step 4: a picked cell's writing is shown this close (500%, the most the view zooms).
        const REVIEW_CELL_ZOOM = 5;
        function revealValidationMarker(index, { alignTo = null } = {}) {
            if (quietVerifyReveal) return;
            if (placedVerifyBoxes([index]).length === 0) return;
            if (glidingToField === index && validationMarker.gliding) return;
            glidingToField = index;
            // Close enough to read the writing: about twice the whole-page size
            // (a two-page document's 100% is half as wide, so it goes further).
            // Already zoomed in further by hand: that zoom is kept. Review & Export
            // compares a cell with its writing: every pick glides in to 500% (the most).
            const zoom = reviewing
                ? REVIEW_CELL_ZOOM
                : Math.max(validationMarker.zoom, verifyDoc ? 2.2 : 1.6);
            verifyFocus = null;
            verifyFocusZoom = null;
            validationMarker.glideTo(zoom, () => {
                const box = placedVerifyBoxes([index])[0];
                return box ? { x: (box.left + box.right) / 2, y: (box.top + box.bottom) / 2 } : null;
            }, {
                // Picked beside the page (a card in the list, a cell in the table): the
                // field ends up level with it, so the eye goes straight across.
                level: alignTo ? () => levelWith(alignTo) : null,
            });
            if (verifyDoc) updateVerifyPageView();
        }

        /** How far down the page's view `element`'s middle is (px from the view's top), or null when it is not shown. */
        function levelWith(element) {
            const viewport = validationMarker.viewport;
            if (!(element instanceof Element) || !element.isConnected || !viewport) return null;
            const box = element.getBoundingClientRect();
            if (box.height === 0) return null;
            // Scrolled out of sight (in its list or table): nothing to level with, the field is centred.
            const holder = element.closest('#verifyRows, .validation-output-pane, #reviewTable') ?? document.documentElement;
            const shown = holder.getBoundingClientRect();
            if (box.bottom < shown.top || box.top > shown.bottom) return null;
            return box.top + box.height / 2 - viewport.getBoundingClientRect().top;
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

        function activateValidationField(index, source = 'row', { alignTo = null, keepList = false } = {}) {
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
                    if (activeValidationIndex === index && !keepList) revealValidationRow(index);
                });
                // Review & Export: the field's cell in the table.
                if (reviewing) reviewTable?.selectRef(index);
            }
            if (source === 'row') revealValidationMarker(index, { alignTo });
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

        let submitErrorTimer = 0;
        function clearValidationSubmitError() {
            window.clearTimeout(submitErrorTimer);
            el('validationSubmitMessage').textContent = '';
            el('validationSubmitError').classList.remove('is-leaving');
            el('validationSubmitError').classList.add('d-none');
        }

        /** The message fades up and out, then is cleared (the timer's way; actions clear it at once). */
        function fadeValidationSubmitError() {
            const box = el('validationSubmitError');
            if (box.classList.contains('d-none')) return;
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                clearValidationSubmitError();
                return;
            }
            box.classList.add('is-leaving');
            box.addEventListener('animationend', () => {
                if (box.classList.contains('is-leaving')) clearValidationSubmitError();
            }, { once: true });
        }

        function showValidationSubmitError(message) {
            el('validationSubmitMessage').textContent = message;
            el('validationSubmitError').classList.remove('d-none', 'is-leaving');
            // Replayed for a message shown again while one is up.
            el('validationSubmitError').style.animation = 'none';
            void el('validationSubmitError').offsetWidth;
            el('validationSubmitError').style.animation = '';
            el('validationSubmitError').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            // The message goes by itself (a longer one stays a little longer), as in the Template Builder.
            window.clearTimeout(submitErrorTimer);
            submitErrorTimer = window.setTimeout(fadeValidationSubmitError, Math.max(5000, message.length * 70));
        }

        function updateVerificationSummary() {
            // A field whose crop was deleted is left out of the record: not counted.
            const checkboxes = [...el('verifyRows').querySelectorAll('.validation-verified')]
                .filter((checkbox) => !checkbox.disabled);
            const verified = checkboxes.filter((checkbox) => checkbox.checked).length;
            const total = checkboxes.length;
            const percentage = total > 0 ? Math.round((verified / total) * 100) : 0;

            el('verifiedCount').textContent = `${verified} of ${total}`;
            const all = el('verifyAllFields');
            all.checked = total > 0 && verified === total;
            all.indeterminate = verified > 0 && verified < total;
            all.disabled = total === 0;
            el('verifiedProgress').setAttribute('aria-valuenow', String(percentage));
            el('verifiedProgressBar').style.width = `${percentage}%`;
            el('submitVerifiedCount').textContent = String(verified);
            el('submitBtn').disabled = verified === 0 || recordSubmitting;
            el('reviewSaveBtn').disabled = verified === 0 || recordSubmitting;
            // Nothing ticked yet: Review & Export offers only the way back to Verify.
            const verifyFirst = verified === 0 && total > 0;
            el('reviewVerifyFirstBtn').classList.toggle('d-none', !verifyFirst);
            el('reviewSaveBtn').classList.toggle('d-none', verifyFirst);
            el('backToVerify').classList.toggle('d-none', verifyFirst);
            updateValidationGroupSummaries();
            updateValidationMarkerStates();
            if (reviewing) {
                reviewTable?.refresh();
                updateReviewSummary();
            }
        }

        function updateValidationGroupSummaries() {
            validationGroups.forEach((group) => {
                const section = el('verifyRows').querySelector(`[data-group-id="${group.id}"]`);
                if (!(section instanceof HTMLElement)) return;

                const checkedStates = group.indexes.filter((index) => !cropped[index]?.cropDeleted).map((index) => {
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

        /**
         * Tick (or untick) every field in `indexes` that has a value. A field whose
         * crop was deleted is left out of the record, so it is skipped quietly.
         * Returns the blank fields that could not be ticked.
         */
        function verifyFields(indexes, checked) {
            const blank = [];
            indexes.forEach((index) => {
                const row = validationRow(index);
                if (!(row instanceof HTMLElement) || cropped[index]?.cropDeleted) return;

                const input = requiredInput(row, '.verified');
                const checkbox = requiredInput(row, '.validation-verified');
                if (checked && !canVerifyValue(input.value)) {
                    checkbox.checked = false;
                    row.classList.remove('is-verified');
                    setValidationFieldError(index, 'Enter or confirm a value before marking this field as verified.');
                    blank.push(index);
                    return;
                }

                checkbox.checked = checked;
                row.classList.toggle('is-verified', checked);
                clearValidationFieldError(index);
            });
            updateVerificationSummary();
            return blank;
        }

        /** After Verify all: point at the first blank field left unticked, if any. */
        function reportBlankFields(blank, owner) {
            if (blank.length === 0) {
                clearValidationSubmitError();
                return;
            }
            showValidationSubmitError(blank.length === 1
                ? `${owner} has 1 blank field left unchecked. Type its value, or leave it out.`
                : `${owner} has ${blank.length} blank fields left unchecked. Type their values, or leave them out.`);
            activateValidationField(blank[0], 'marker');
        }

        function setGroupVerified(group, checked) {
            reportBlankFields(verifyFields(group.indexes, checked), group.label);
        }

        /** Verify all, above the list: every field of every person and detail at once. */
        function setAllVerified(checked) {
            // Fields in no person's row ("Needs review") wait for a decision first.
            const indexes = validationGroups
                .filter((group) => group.id !== 'needs-review')
                .flatMap((group) => group.indexes);
            reportBlankFields(verifyFields(indexes, checked), 'This document');
        }

        el('verifyAllFields').addEventListener('change', (event) => setAllVerified(event.currentTarget.checked));

        /** The crop TrOCR read, larger, with what it read and what the value is now. */
        function openCropPreview(row, name, url, thumbnail) {
            el('cropPreviewTitle').textContent = name;
            const picture = el('cropPreviewImage');
            // The thumbnail is already loaded, so the same picture is on screen at
            // once. Its shape is set first so nothing jumps while it is drawn.
            if (thumbnail?.naturalWidth) picture.style.aspectRatio = `${thumbnail.naturalWidth} / ${thumbnail.naturalHeight}`;
            if (picture.getAttribute('src') !== url) picture.src = url;
            picture.alt = `Crop TrOCR read for ${name}`;
            window.bootstrap.Modal.getOrCreateInstance(el('cropPreviewModal')).show();
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
                thumbnail.title = 'Click to enlarge the exact image TrOCR read';
                // A click (or Enter) opens it larger in a modal.
                thumbnail.tabIndex = 0;
                thumbnail.setAttribute('role', 'button');
                thumbnail.setAttribute('aria-label', `Enlarge the crop of ${displayName}`);
                const openCrop = (event) => {
                    event.stopPropagation();
                    openCropPreview(row, displayName, item.cropUrl, thumbnail);
                };
                thumbnail.addEventListener('click', openCrop);
                thumbnail.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        openCrop(event);
                    }
                });
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
            redrawCount > 0 ? `${redrawCount} crop deleted` : null,
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
        // in its Person and Column lists. The column starts chosen; nothing is
        // saved until the reviewer picks the Person.
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
                const suggestion = { ...(placementSuggestions.get(item.lineId) ?? {}) };
                // After a reload the server's suggestion is gone: the column the
                // field's middle sits in is the same guess.
                if (!Number.isInteger(suggestion.column_index) && item.source === 'manual') {
                    const middle = item.x + item.w / 2;
                    const found = columns.findIndex((candidate) => {
                        const [left, , width] = candidate.box ?? [];
                        return Number.isFinite(left) && Number.isFinite(width) && middle >= left && middle <= left + width;
                    });
                    if (found >= 0) suggestion.column_index = found;
                }
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
                // A field added by hand that has no person yet starts on the column
                // it seems to sit in: choosing its Person then saves both, so it
                // fills that column instead of a "Drawn line" column of its own.
                const awaitingPerson = item.gridRow === null && item.label === 'Drawn line'
                    && Number.isInteger(suggestion.column_index) && suggestion.column_index < columns.length;
                const selectedColumn = item.gridColumn !== null
                    ? `i:${item.gridColumn}`
                    : (removed.some((name) => nameKey(name) === nameKey(item.label)) ? `n:${item.label}`
                        : (awaitingPerson ? `i:${suggestion.column_index}` : ''));
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
            if (response.status === 413) return 'The uploaded document is too large. A PDF may be up to 1 GB (one page of it is sent), an image up to 20 MB.';
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

            // Cells typed in Review & Export where nothing was read count as submitted.
            const typed = reviewTypedCells();
            const untouchedRows = [];
            validationGroups.forEach((group) => {
                const row = Number(cropped[group.indexes[0]]?.personGroup);
                // A person started by hand (past the last row) has no register row to complete.
                const ledgerRow = group.kind === 'person'
                    && pages.some((page) => row <= page.tableRows)
                    && group.indexes.some((index) => settingsFor(index)?.kind === 'column');
                if (!ledgerRow) return;

                const checkedHere = group.indexes.filter((index) => checkedIndexes.has(index));
                const typedHere = typed.get(group.id) ?? [];
                if (checkedHere.length === 0 && typedHere.length === 0) {
                    untouchedRows.push(row);
                    return;
                }

                const left = pages.flatMap((page) => {
                    if (row > page.tableRows) return [];
                    const columnsHere = new Set([
                        ...checkedHere
                            .filter((index) => sideOf(index) === page.side)
                            .map((index) => nameKey(cropped[index]?.label)),
                        ...typedHere.filter((cell) => cell.side === page.side).map((cell) => cell.name),
                    ]);
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
            // Asked on the way to Review & Export: go on there.
            if (missingThenReview) {
                missingThenReview = false;
                openReview({ allowMissing: true });
                return;
            }
            submitRecord({ ...lastSubmitChoices, allowMissing: true });
        });

        // A record is saved from Review & Export (Save record), one document or a
        // batch alike. Enter in one of Verify's value boxes would submit this form
        // through its hidden button and save the record from Verify, past step 4
        // (and, in a batch, without its table for Download all): it does nothing.
        el('submitForm').addEventListener('submit', (event) => {
            event.preventDefault();
        });

        // ------------------------------------------------ step 4: Review & Export
        // The people as one table, a row per person and a column per field. A
        // cell is that person's Verify field(s) in that column: the table reads
        // and writes Verify's own value boxes, so an edit shows in both and Save
        // record sends it through submitRecord() as Verify would. A cell picked
        // here is shown on the scan by Verify's own activateValidationField, on
        // Verify's own document pane, which is moved into this step while it is
        // shown (as the batch banner is moved between steps).

        /**
         * Verify goes on to Review & Export, where the record is saved, one
         * document or a batch alike. A queued page was read as it was queued:
         * it is aligned again by queuing it again, so Back to marking is not
         * offered in a batch (its Scan would read a page the queue does not know).
         */
        function syncVerifyButtons() {
            el('submitBtn').classList.add('d-none');
            el('reviewExportBtn').classList.remove('d-none');
            el('backToMark').classList.toggle('d-none', Boolean(batchState?.active));
            el('verifyRealignGroup').classList.toggle('d-none', !batchState?.active);
        }

        /**
         * "Save record", or in a batch: "Save & next" while another record still
         * has to be checked (this one is then Done, saved with the rest at the
         * end), else "Save & finish batch", which saves every Done record.
         */
        function updateReviewSaveLabel() {
            let label = 'Save record';
            if (batchState.active) {
                const records = batchRecords();
                const current = recordOf(batchState.items[batchState.currentIndex]);
                const more = records.some((record) => record.key !== current.key && !recordSettled(record));
                label = more ? 'Save & next' : 'Save & finish batch';
            }
            el('reviewSaveLabel').textContent = label;
        }

        /** Move Verify's document pane and its notices into step 4 (on) or back to Verify (off). */
        function setReviewMode(on) {
            if (reviewing === on) return;
            reviewing = on;
            // Kept with the batch at once: a resume opens the record on this step.
            const lead = batchState.active ? currentVerifyItem() : null;
            if (lead) {
                lead.inReview = on;
                syncBatchDraft(lead);
            }
            const pane = document.querySelector('.validation-document-pane');
            const notices = [el('validationExistingBanner'), el('validationSubmitError')];
            if (on) {
                el('reviewNotices').append(...notices);
                el('reviewGrid').prepend(pane);
            } else {
                reviewTable?.commit();
                const verifyGrid = el('step-verify').querySelector('.validation-comparison-grid');
                verifyGrid.prepend(pane);
                verifyGrid.before(...notices);
            }
            // The picked field's outline is a box (a rectangle) in Verify and in Review & Export.
            verifyOverlays.forEach((overlay) => {
                overlay.setBoxCurrent(true);
                if (!on) { overlay.setRange([]); overlay.setCleared([]); }
            });
            // The pane changed width: fit the page to it.
            window.requestAnimationFrame(() => window.dispatchEvent(new Event('resize')));
        }

        const verifyInputOf = (index) => {
            const input = validationRow(index)?.querySelector('.verified');
            return input instanceof HTMLInputElement ? input : null;
        };
        const verifyTickOf = (index) => {
            const checkbox = validationRow(index)?.querySelector('.validation-verified');
            return checkbox instanceof HTMLInputElement ? checkbox : null;
        };
        const verifyValueOf = (index) => verifyInputOf(index)?.value ?? '';
        const extraKey = (cell) => `${cell.row.id}|${cell.column.key}`;

        /** What the table is drawn from: each page's columns, the people and the document details. */
        function reviewInput() {
            const pages = docEntries().map((entry) => {
                const settings = verifyDoc ? layoutSettings(entry.layout) : templateSettings;
                const onGrid = (entry.page?.geometry?.columns ?? []).map((column) => column.name);
                const names = onGrid.length > 0
                    ? onGrid
                    : entry.layout.boxes.filter((box) => box.kind === 'column').map((box) => box.name);
                return {
                    number: entry.number,
                    columns: names.map((name) => ({ name, settings: settings.get(nameKey(name)) ?? null })),
                    settings: Object.fromEntries(settings),
                };
            });
            const kept = (index) => !cropped[index]?.cropDeleted;
            const field = (index) => ({
                side: cropped[index]?.pageIndex ?? 0,
                column: cropped[index]?.label || readings[index]?.name || `Field ${index + 1}`,
                ref: index,
            });
            // A row (person) with no field ticked Verified is not saved, so it is not in
            // the table (nor the downloads); one with something typed in step 4 stays.
            const ticked = (index) => Boolean(verifyTickOf(index)?.checked);
            const typedIn = (group) => [...reviewExtras.keys()].some((key) => key.startsWith(`${group.id}|`) && String(reviewExtras.get(key) ?? '').trim() !== '');
            const people = validationGroups
                .filter((group) => group.kind === 'person')
                .filter((group) => group.indexes.some(ticked) || typedIn(group))
                .map((group, position) => ({
                    id: group.id,
                    // The register row, as the record's CSV numbers it (else the person's place).
                    label: String(Number(String(group.id).match(/^person-(\d+)$/)?.[1]) || position + 1).padStart(2, '0'),
                    // A deleted crop is left out: its cell is empty, and can be typed in.
                    fields: group.indexes.filter(kept).map(field),
                }));
            const details = validationGroups
                .filter((group) => group.kind !== 'person' && group.id !== 'needs-review')
                .flatMap((group) => group.indexes.filter(kept).map(field));
            return { pages, people, details };
        }

        /**
         * A cell's value: its Verify fields' values (two written lines join), or what was
         * typed in an empty cell, or (nothing typed) a register field's starting value.
         */
        function reviewValue(cell) {
            if (cell.refs.length === 0) {
                if (cell.column.detail) return '';
                return reviewExtras.get(extraKey(cell)) ?? registryDefault(cell.column);
            }
            return cell.refs.map(verifyValueOf).filter((value) => value.trim() !== '').join(' ');
        }

        // ------------------------------------------------ step 4: the register's columns
        // For a type with a register table (birth_records, …), step 4 shows the
        // register's own columns in its order (see applyRegistry). A scanned
        // column fills a field as chosen in "Columns → fields" (kept per layout);
        // any other field is typed in. Save sends each row for that table.
        const registry = config.registry;
        const fieldMaps = { ...(config.fieldMaps ?? {}) };
        // What each layout keeps now: a choice made here is kept with the record (fieldMapsToSend).
        const keptFieldMaps = JSON.parse(JSON.stringify(config.fieldMaps ?? {}));
        // The choices before the last change, for its Undo (null: nothing to undo).
        let fieldMapUndo = null;
        // The table as read (scanned columns), before it is laid out as the register's.
        let reviewBaseModel = null;

        /** The layout a column is on (page 2 of a spread may have its own). */
        const templateOfSide = (side) => docEntries()[side]?.layout?.templateId ?? config.templateId;

        /** That layout's saved { column name: field }, or null when it has none yet. */
        function savedFieldMap(column) {
            const map = fieldMaps[templateOfSide(column.side)];
            return map && Object.keys(map).length > 0 ? map : null;
        }

        /** The scanned table laid out as the register's (or as it is, for a type with none). */
        function reviewModelFrom(base) {
            return registry ? applyRegistry(base, registry.fields, savedFieldMap, registry.pairs ?? []) : base;
        }

        /**
         * What a register field's cell holds until something is typed in it: the
         * book and page entered in step 1, or the field's default (late_registration: false).
         */
        function registryDefault(column) {
            if (!registry || !column.field) return '';
            const item = batchState.active ? batchState.items[batchState.currentIndex] : null;
            // The record's pages share one book and page: what was entered on either of them
            // (a re-aligned page keeps its own), else what the step 1 boxes hold now.
            const members = item ? recordOf(item).members : [];
            const enteredOn = (key) => [item, ...members].map((member) => member?.[key]).find((value) => String(value ?? '').trim() !== '');
            if (column.field === 'book_no') return String(enteredOn('bookNo') || getBookNumber() || '');
            if (column.field === 'page_no') return String(enteredOn('pageNoRef') || getPageNumber() || '');
            return registry.fields.find((field) => field.key === column.field)?.default ?? '';
        }

        /** "Columns → fields": which scanned column fills each field (manual fields are listed apart). */
        function paintFieldMap() {
            const rows = el('reviewFieldMapRows');
            if (!rows || !registry || !reviewBaseModel) return;
            const scanned = reviewBaseModel.columns;
            rows.replaceChildren(...registry.fields.filter((field) => !field.manual).map((field) => {
                const current = reviewModel?.columns.find((column) => column.field === field.key && !column.manual);
                const id = `reviewFieldMap-${field.key}`;
                const row = document.createElement('div');
                row.className = 'review-field-map__row';
                const label = document.createElement('label');
                label.className = 'form-label small mb-0 text-truncate';
                label.htmlFor = id;
                label.textContent = field.key;
                if (field.required) {
                    const star = document.createElement('span');
                    star.className = 'text-danger ms-1';
                    star.textContent = '*';
                    star.title = 'Required in every row';
                    label.append(star);
                }
                const select = document.createElement('select');
                select.className = 'form-select form-select-sm';
                select.id = id;
                const automatic = { book_no: 'Automatic (from step 1)', page_no: 'Automatic (from step 1)' }[field.key];
                select.append(new Option(automatic ?? 'Type by hand', ''));
                scanned.forEach((column) => {
                    // A column fills one field: say which one has it, as choosing it here takes it away.
                    const usedBy = reviewModel?.columns.find((other) => other.key === column.key && other.field && !other.manual)?.field;
                    const text = usedBy && usedBy !== field.key ? `${column.header} · now in ${usedBy}` : column.header;
                    select.append(new Option(text, column.key, false, column.key === current?.key));
                });
                // The second field of a pair (wife_name): the second line of the first one's column.
                const first = (registry.pairs ?? []).find(([, second]) => second === field.key)?.[0];
                const firstSource = first ? reviewModel?.columns.find((column) => column.field === first && !column.manual) : null;
                if (firstSource && firstSource.line !== 2) {
                    const key = firstSource.key + LINE_TWO;
                    select.append(new Option(`${firstSource.sourceHeader} · line 2`, key, false, key === current?.key));
                }
                select.addEventListener('change', () => setFieldSource(field.key, select.value));
                row.append(label, select);
                return row;
            }));
            const manual = registry.fields.filter((field) => field.manual).map((field) => field.key);
            el('reviewFieldMapManual').textContent = manual.length > 0 ? `Always typed by hand: ${manual.join(', ')}.` : '';
        }

        /**
         * `field` is now filled from the scanned column `columnKey` ('' : typed by hand),
         * for each layout involved, and the table is drawn again. Kept on the layout
         * when the record is saved (fieldMapsToSend). Says what else changed with it
         * (a column taken from another field, typed values hidden), with Undo.
         */
        function setFieldSource(field, columnKey) {
            if (!registry || !reviewBaseModel || !reviewTable) return;
            reviewTable.commit();
            const snapshot = JSON.parse(JSON.stringify(fieldMaps));
            // The field the chosen column fills now, if another one.
            const takenFrom = columnKey
                ? reviewModel.columns.find((column) => column.key === columnKey && column.field && column.field !== field && !column.manual)?.field ?? null
                : null;
            const wasTyped = reviewModel.columns.some((column) => column.field === field && column.manual);
            const typed = wasTyped && columnKey ? typedValuesOf(`m|${field}`) : 0;
            // Each layout's saved choices (a column named like a field fills it without one),
            // changed for this field: the chosen column fills it; "Type by hand" keeps the
            // column that filled it apart, so its name does not fill the field again.
            const sides = new Set(reviewBaseModel.columns.map((column) => column.side));
            const maps = new Map([...sides].map((side) => [templateOfSide(side), { ...(fieldMaps[templateOfSide(side)] ?? {}) }]));
            const before = reviewModel.columns.find((column) => column.field === field && !column.manual);
            maps.forEach((map) => Object.keys(map).forEach((name) => {
                if (map[name] === field) delete map[name];
            }));
            const source = reviewBaseModel.columns.find((column) => column.key === columnKey);
            const lineTwoOf = reviewBaseModel.columns.find((column) => column.key + LINE_TWO === columnKey);
            if (lineTwoOf) {
                // Back to the second line of the pair's column: nothing turns it off.
                delete maps.get(templateOfSide(lineTwoOf.side))[nameKey(lineTwoOf.name) + LINE_TWO];
            } else {
                // Away from a second line: that line no longer fills the field.
                if (before?.line === 2) maps.get(templateOfSide(before.side))[nameKey(before.name) + LINE_TWO] = NOT_A_FIELD;
                if (source) {
                    maps.get(templateOfSide(source.side))[nameKey(source.name)] = field;
                } else if (before && before.line !== 2) {
                    maps.get(templateOfSide(before.side))[nameKey(before.name)] = NOT_A_FIELD;
                }
            }
            maps.forEach((map, templateId) => { fieldMaps[templateId] = map; });

            reviewModel = reviewModelFrom(reviewBaseModel);
            reviewTable.render(reviewModel);
            paintFieldMap();
            updateVerificationSummary();

            const header = reviewModel.columns.find((column) => column.field === field && !column.manual)?.sourceHeader;
            const lost = takenFrom && !reviewModel.columns.some((column) => column.field === takenFrom && !column.manual);
            const parts = [header ? `${field} now reads from “${header}”.` : `${field} is now typed by hand.`];
            if (lost) parts.push(`${takenFrom} lost that column and is typed by hand now.`);
            if (typed > 0) parts.push(`${typed} ${typed === 1 ? 'value' : 'values'} you typed in ${field} ${typed === 1 ? 'is' : 'are'} hidden (Undo brings ${typed === 1 ? 'it' : 'them'} back).`);
            showFieldMapNotice(parts.join(' '), snapshot, Boolean(lost || typed > 0));
        }

        /** How many cells of the typed-in column `columnKey` ("m|place_of_birth") hold something. */
        function typedValuesOf(columnKey) {
            return [...reviewExtras].filter(([key, value]) => key.endsWith(`|${columnKey}`) && String(value ?? '').trim() !== '').length;
        }

        /** What the last choice did, with Undo back to `snapshot` (`warn`: something else changed too). */
        function showFieldMapNotice(text, snapshot, warn) {
            fieldMapUndo = snapshot;
            const notice = el('reviewFieldMapNotice');
            if (!notice) return;
            el('reviewFieldMapNoticeText').textContent = text;
            notice.classList.toggle('is-warning', warn);
            notice.classList.remove('d-none');
        }

        function hideFieldMapNotice() {
            fieldMapUndo = null;
            el('reviewFieldMapNotice')?.classList.add('d-none');
        }

        /** Undo: the choices as they were before the last change. */
        function undoFieldSource() {
            if (!fieldMapUndo || !reviewBaseModel || !reviewTable) return;
            reviewTable.commit();
            Object.keys(fieldMaps).forEach((id) => { delete fieldMaps[id]; });
            Object.assign(fieldMaps, fieldMapUndo);
            hideFieldMapNotice();
            reviewModel = reviewModelFrom(reviewBaseModel);
            reviewTable.render(reviewModel);
            paintFieldMap();
            updateVerificationSummary();
        }

        /**
         * The choices to keep with the record being saved: those of the layouts it
         * was read with that changed here, { template id: map }, or null for none.
         */
        function fieldMapsToSend() {
            if (!registry || !reviewBaseModel) return null;
            const same = (a = {}, b = {}) => {
                const keys = Object.keys(a);
                return keys.length === Object.keys(b).length && keys.every((key) => a[key] === b[key]);
            };
            const ids = new Set([config.templateId, ...reviewBaseModel.columns.map((column) => templateOfSide(column.side))].map(String));
            const changed = Object.fromEntries([...ids]
                .filter((id) => fieldMaps[id] && !same(fieldMaps[id], keptFieldMaps[id] ?? {}))
                .map((id) => [id, fieldMaps[id]]));
            return Object.keys(changed).length > 0 ? changed : null;
        }

        /** The record was saved with `sent` (fieldMapsToSend): the layouts keep those now. */
        function fieldMapsKept(sent) {
            if (!sent) return;
            Object.entries(sent).forEach(([id, map]) => { keptFieldMaps[id] = { ...map }; });
            hideFieldMapNotice();
        }

        if (registry) {
            el('reviewFieldMap')?.addEventListener('show.bs.dropdown', () => paintFieldMap());
            el('reviewFieldMapUndo')?.addEventListener('click', () => undoFieldSource());
        }

        /** Put a value in a Verify field and tick it (an empty value is left unticked). */
        function setVerifyValue(index, value) {
            const input = verifyInputOf(index);
            if (!input || input.disabled) return;
            input.value = value;
            // Verify's own reaction: its value check, and the tick taken off.
            input.dispatchEvent(new Event('input', { bubbles: true }));
            const checkbox = verifyTickOf(index);
            if (checkbox && value.trim() !== '') {
                checkbox.checked = true;
                validationRow(index)?.classList.add('is-verified');
            }
            clearValidationFieldError(index);
        }

        /** Verify's ticks of a cell's fields as they were before a change (Ctrl+Z). */
        function restoreReviewTicks(cell, ticks) {
            if (!Array.isArray(ticks)) return;
            cell.refs.forEach((index, position) => {
                const checkbox = verifyTickOf(index);
                if (!checkbox || ticks[position] === null || checkbox.checked === ticks[position]) return;
                checkbox.checked = ticks[position];
                // Verify's own reaction: the row, its outline and the count.
                checkbox.dispatchEvent(new Event('change', { bubbles: true }));
            });
            updateVerificationSummary();
        }

        function reviewChange(cell, value) {
            const text = value.trim();
            if (cell.refs.length === 0) {
                // A field with a starting value (book, page, false) emptied on purpose stays empty.
                if (text === '' && registryDefault(cell.column) === '') reviewExtras.delete(extraKey(cell));
                else reviewExtras.set(extraKey(cell), text);
            } else {
                // Two written lines in one cell: the whole value goes in the first.
                cell.refs.forEach((index, position) => setVerifyValue(index, position === 0 ? text : ''));
            }
            clearValidationSubmitError();
            updateVerificationSummary();
        }

        /**
         * Picking down a column can scroll the table, moving the first picked cell:
         * once the pick settles, its field on the scan is levelled with it again.
         */
        let relevelTimer = 0;
        function relevelReviewScan() {
            window.clearTimeout(relevelTimer);
            relevelTimer = window.setTimeout(() => {
                const cell = el('reviewTable').querySelector('td.is-active');
                if (!reviewing || !cell || !Number.isInteger(activeValidationIndex)) return;
                // Still level (the table did not scroll): nothing to move.
                const box = placedVerifyBoxes([activeValidationIndex])[0];
                const level = levelWith(cell);
                const viewport = el('validationDocViewport');
                if (box && level !== null
                    && Math.abs((box.top + box.bottom) / 2 - viewport.scrollTop - level) < 4) return;
                revealValidationMarker(activeValidationIndex, { alignTo: cell });
            }, 180);
        }

        /** A cell picked: its field on the scan, as a click in Verify's list does; an empty cell shows its person. */
        function locateReviewCell(cell) {
            const index = cell.refs.find((ref) => placedVerifyBoxes([ref]).length > 0) ?? cell.refs[0];
            if (Number.isInteger(index)) {
                activateValidationField(index, 'row', { alignTo: el('reviewTable').querySelector('td.is-active') });
                return;
            }
            // A field typed by hand (attendant, late_registration, an added column) is
            // not on the scan: the scan stays where it is while it is filled in.
            if (cell.column.manual || cell.column.custom) return;
            if (!cell.column.detail) activateValidationGroup(cell.row.id, 'group');
        }

        function updateReviewSummary() {
            if (!reviewModel) return;
            const people = reviewModel.rows.length;
            const pages = docEntries().length;
            // Values shown greyed: none of their fields ticked Verified, so not saved.
            const unverified = reviewModel.rows.reduce((count, row) => count + reviewModel.columns.filter((column) => {
                const refs = row.cells[column.key]?.refs ?? [];
                return refs.length > 0
                    && refs.every((index) => !verifyTickOf(index)?.checked)
                    && refs.some((index) => verifyValueOf(index).trim() !== '');
            }).length, 0);
            // Rows left out: nothing on them ticked Verified (see reviewInput).
            const hiddenRows = validationGroups.filter((group) => group.kind === 'person').length - people;
            // A register's table: its own columns (scanned columns no field takes are not saved).
            const savedColumns = reviewModel.columns.filter((column) => !column.noExport).length;
            el('reviewPaneSummary').textContent = `${people} ${people === 1 ? 'person' : 'people'} · `
                + `${savedColumns} column${savedColumns === 1 ? '' : 's'}`
                + (pages > 1 ? ` · ${pages} pages` : '')
                + (hiddenRows > 0 ? ` · ${hiddenRows} row${hiddenRows === 1 ? '' : 's'} not verified (left out)` : '')
                + (unverified > 0 ? ` · ${unverified} not verified (greyed, not saved)` : '');
        }

        // Required fields left out were accepted on the way in (Continue anyway):
        // Save record does not ask again. missingThenReview: the question is for step 4.
        let reviewAllowMissing = false;
        let missingThenReview = false;

        /**
         * Verify's "Review & export": the checks Submit makes in Verify, in the
         * same order as submitRecord(), then the table.
         */
        /**
         * Verify → Review & Export. `switching`: another record picked from the
         * queue while on this step opens here too, without Verify's checks on the
         * way in (Save still makes every one of them).
         */
        function openReview({ allowMissing = false, switching = false } = {}) {
            clearValidationSubmitError();
            const rows = [...el('verifyRows').querySelectorAll('.validation-field')];
            rows.forEach((row) => clearValidationFieldError(Number(row.dataset.fieldIndex)));

            // A field whose crop was deleted and not drawn again is simply left out.
            const verifiedIndexes = rows
                .filter((row) => requiredInput(row, '.validation-verified').checked)
                .map((row) => Number(row.dataset.fieldIndex));
            if (switching) {
                if (!scanFile && !batchScanOnServer()) return false;
                reviewAllowMissing = false;
            } else {
                if (verifiedIndexes.length === 0) {
                    showValidationSubmitError('Check Verified on at least one field before going on.');
                    if (rows[0]) {
                        activateValidationField(Number(rows[0].dataset.fieldIndex), 'marker');
                        requiredInput(rows[0], '.validation-verified').focus();
                    }
                    return;
                }

                const invalidIndexes = verifiedIndexes.filter((index) => {
                    const row = validationRow(index);
                    if (!row) return true;
                    if (requiredInput(row, '.verified').value.trim()) return false;
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

                if (!scanFile && !batchScanOnServer()) {
                    showValidationSubmitError('The original scan is no longer available. Return to marking and choose the file again.');
                    return;
                }

                if (!allowMissing) {
                    const missing = requiredNotSubmitted(verifiedIndexes);
                    if (missing.length > 0) {
                        missingThenReview = true;
                        el('missingRequiredModalTitle').textContent = 'Continue without required fields?';
                        el('confirmMissingRequiredBtn').textContent = 'Continue anyway';
                        askToSubmitWithoutRequired(missing);
                        return;
                    }
                }

                if (verifyDoc) {
                    const unchecked = verifyDoc.pages.find((_, side) => !verifiedIndexes.some((index) => (cropped[index]?.pageIndex ?? 0) === side));
                    if (unchecked) {
                        showValidationSubmitError(`Check Verified on at least one field of page ${unchecked.number}.`);
                        return;
                    }
                }
                reviewAllowMissing = allowMissing;
            }

            stopAddingMissedField();
            stopGroupingFields();
            cancelOutlineEdit();
            // Drawing a deleted field again, unsaved: left as it is (out of the record).
            stopRedrawField();

            reviewBaseModel = buildReviewModel(reviewInput());
            reviewModel = reviewModelFrom(reviewBaseModel);
            // A cell typed into before that has a field now (Add missed field): the field wins.
            reviewModel.rows.forEach((row) => reviewModel.columns.forEach((column) => {
                if ((row.cells[column.key]?.refs.length ?? 0) > 0) reviewExtras.delete(`${row.id}|${column.key}`);
            }));
            if (!reviewTable) {
                reviewTable = new ReviewTable({
                    container: el('reviewTable'),
                    valueOf: reviewValue,
                    onChange: reviewChange,
                    onLocate: locateReviewCell,
                    // An edit ticks Verified; Ctrl+Z puts each tick back as it was.
                    snapshotOf: (cell) => cell.refs.map((index) => verifyTickOf(index)?.checked ?? null),
                    restore: restoreReviewTicks,
                    // Cells dragged together show on the scan as well.
                    onRange: (cells) => {
                        lineOverlay.setRange(cells.flatMap((cell) => cell.refs));
                        // Only for several cells: a single click's own glide is not restarted.
                        if (cells.length > 0) relevelReviewScan();
                        else window.clearTimeout(relevelTimer);
                    },
                    // Emptied cells show red on the scan as well.
                    onCleared: (refs) => lineOverlay.setCleared(reviewing ? refs : []),
                    // Only fields ticked Verified are saved (submitRecord): a cell none of
                    // whose fields is ticked shows greyed and is left out of the downloads.
                    // A cell typed into where nothing was read is saved, so it counts.
                    isUnverified: (cell) => cell.refs.length > 0
                        && cell.refs.every((index) => !verifyTickOf(index)?.checked),
                    // A register field every row does not need can be removed from its header.
                    removableFields: true,
                });
                // Ctrl+Z / Ctrl+Y after a click on the scan or elsewhere on this step.
                reviewTable.listenForUndoKeys(() => reviewing);
                attachAddColumn(reviewTable, el('reviewPane'));
                // How this type's register writes its dates, as last chosen on this computer.
                const dateOrderKey = `crms.dateOrder.${config.registry?.type ?? config.typeLabel ?? 'any'}`;
                const dateOrderSelect = el('reviewDateOrder');
                const chosenOrder = (() => {
                    try { return window.localStorage.getItem(dateOrderKey) || 'auto'; } catch { return 'auto'; }
                })();
                reviewTable.dateOrder = chosenOrder;
                if (dateOrderSelect) {
                    dateOrderSelect.value = chosenOrder;
                    dateOrderSelect.addEventListener('change', () => {
                        reviewTable.commit();
                        reviewTable.setDateOrder(dateOrderSelect.value);
                        try { window.localStorage.setItem(dateOrderKey, dateOrderSelect.value); } catch { /* private mode */ }
                        updateVerificationSummary();
                    });
                }
            }

            showStep('review');
            updateReviewSaveLabel();
            reviewTable.render(reviewModel);
            updateVerificationSummary();
            // Start on the first cell, without zooming the scan.
            if (reviewModel.rows.length > 0 && reviewModel.columns.length > 0) reviewTable.select(0, 0, { locate: false });
            return true;
        }

        // Nothing verified on this record yet: back to Verify, as Back to verify does.
        el('reviewVerifyFirstBtn').addEventListener('click', () => el('backToVerify').click());

        // The question Submit asks; asked on the way to step 4 it says Continue.
        el('missingRequiredModal').addEventListener('hidden.bs.modal', () => {
            missingThenReview = false;
            el('missingRequiredModalTitle').textContent = 'Submit without required fields?';
            el('confirmMissingRequiredBtn').textContent = 'Submit anyway';
        });

        el('reviewExportBtn').addEventListener('click', () => openReview());
        el('reviewSaveBtn').addEventListener('click', () => {
            // A cell still being typed counts.
            reviewTable?.commit();
            // "Continue anyway" is not asked again, but once the cells typed here
            // fill every required field the server checks the record as usual.
            const verifiedNow = [...el('verifyRows').querySelectorAll('.validation-field')]
                .filter((row) => requiredInput(row, '.validation-verified').checked)
                .map((row) => Number(row.dataset.fieldIndex));
            submitRecord({ allowMissing: reviewAllowMissing && requiredNotSubmitted(verifiedNow).length > 0 });
        });
        el('backToVerify').addEventListener('click', () => {
            reviewTable?.commit();
            clearValidationSubmitError();
            showStep('verify');
            if (activeValidationIndex !== null) revealValidationRow(activeValidationIndex);
        });
        // Grid off: the scan without its field outlines (the picked field and its row stay).
        el('reviewGridToggle').addEventListener('change', (event) => {
            document.querySelector('.validation-document-pane')?.classList.toggle('is-grid-hidden', !event.target.checked);
        });

        attachSplit({
            split: el('reviewGrid'),
            splitter: el('reviewSplitter'),
            onResize: () => {
                if (reviewing) window.requestAnimationFrame(() => window.dispatchEvent(new Event('resize')));
            },
        });

        // Align and Verify have the same bar. Each starts where its layout always
        // put it, on every visit: a dragged width lasts until the page is left.
        // The page refits once per frame while the bar is dragged, not once per
        // pointer move (several can come in one frame).
        const refitShown = (step) => {
            let pending = false;
            return () => {
                if (pending || el(step).classList.contains('d-none')) return;
                pending = true;
                window.requestAnimationFrame(() => {
                    pending = false;
                    window.dispatchEvent(new Event('resize'));
                });
            };
        };
        const alignSplit = attachSplit({
            split: document.querySelector('#step-mark .field-marker-comparison-grid'),
            splitter: el('alignSplitter'),
            property: '--align-page-width',
            // The page took 3 parts of 4 on a wide screen, 2 of 3 below 1200px.
            initial: () => (window.innerWidth >= 1200 ? 75 : 66.7),
            min: 45,
            max: 85,
            describe: (left) => `Page ${left}%, panel ${100 - left}%`,
            onResize: refitShown('step-mark'),
        });
        const verifySplit = attachSplit({
            split: el('verifyGrid'),
            splitter: el('verifySplitter'),
            property: '--verify-page-width',
            // One page 1.04 : 0.96 of the width; two pages side by side 1.4 : 0.9.
            initial: () => (el('verifyGrid').classList.contains('is-two-page') ? 60.9 : 52),
            min: 30,
            max: 75,
            describe: (left) => `Document ${left}%, fields ${100 - left}%`,
            onResize: refitShown('step-verify'),
        });
        // A window crossing 1200px, or a second page, moves a width the user did not set.
        window.addEventListener('resize', () => alignSplit.refresh());
        window.addEventListener('crms:verify-layout', () => verifySplit.refresh());

        /** "births-2splitpages-pages-1-2-2026-11-15" */
        function reviewFileName() {
            const today = new Date().toISOString().slice(0, 10);
            // A register's page: "Book of Birth - Book 12 Page 23 - 2026-11-25".
            const book = getBookNumber();
            const page = getPageNumber();
            if (registry && (book || page)) {
                return exportFileName(config.typeLabel, [book && `Book ${book}`, page && `Page ${page}`].filter(Boolean).join(' '), today);
            }
            const pages = verifyDoc
                ? `pages ${verifyDoc.pages.map((entry) => entry.number).join(' ')}`
                : (multiPage() ? `page ${scanPageNumber}` : '');
            // A queued page's file is cut from its PDF ("… (page 3).pdf"): name the PDF itself.
            const name = batchState.active ? (batchState.items[batchState.currentIndex]?.sourceName ?? scanFile?.name) : scanFile?.name;
            return exportFileName(config.typeLabel, name, pages, new Date().toISOString().slice(0, 10));
        }

        el('reviewExportCsv').addEventListener('click', () => {
            if (!reviewTable) return;
            reviewTable.commit();
            downloadCsv(reviewTable.exportTable(), reviewFileName());
        });
        el('reviewExportXlsx').addEventListener('click', async () => {
            if (!reviewTable) return;
            reviewTable.commit();
            // The spinner shows on the Download button (the menu closes on the click).
            const button = el('reviewDownloadBtn');
            const spinner = button.querySelector('.spinner-border');
            button.disabled = true;
            spinner?.classList.remove('d-none');
            try {
                await downloadXlsx(reviewTable.exportTable(), reviewFileName());
            } catch (error) {
                console.error('Excel export failed:', error);
                showValidationSubmitError('The Excel file could not be made. Try CSV instead.');
            } finally {
                button.disabled = false;
                spinner?.classList.add('d-none');
            }
        });

        /**
         * Values typed into cells where nothing was read, as fields of the
         * record (no outline; the box is the cell on the page's grid), each
         * with the page side it belongs to. `personGroups` numbers the people
         * as the record does.
         */
        function reviewExtraFields(personGroups) {
            if (!reviewModel) return [];
            const fields = [];
            const order = new Map();
            reviewModel.rows.forEach((row) => {
                reviewModel.columns.forEach((column) => {
                    if (column.detail || (row.cells[column.key]?.refs.length ?? 0) > 0) return;
                    const value = String(reviewExtras.get(`${row.id}|${column.key}`) ?? '').trim();
                    if (value === '') return;
                    // A two-line entry: the value goes on its own line's row.
                    const group = personGroups.find((candidate) => candidate.id === reviewLineId(row, column));
                    if (!group) return;
                    if (!order.has(group)) order.set(group, group.indexes.length);
                    const tableRow = Number(String(group.id).match(/^person-(\d+)$/)?.[1] ?? 0) || personGroups.indexOf(group) + 1;
                    const box = unreadCellBox(column, tableRow);
                    fields.push({
                        side: column.side,
                        field: {
                            verified: '1',
                            name: `${column.name} · row ${tableRow}`,
                            ocr_text: null,
                            ocr_confidence: null,
                            verified_value: value,
                            person_group: personGroups.indexOf(group) + 1,
                            person_field_order: order.get(group),
                            x: box.x.toFixed(5),
                            y: box.y.toFixed(5),
                            width: box.w.toFixed(5),
                            height: box.h.toFixed(5),
                            line_id: null,
                        },
                    });
                    order.set(group, order.get(group) + 1);
                });
            });
            return fields;
        }

        /** The register row (person group id) a cell is on: a two-line entry's own line, else the row's. */
        function reviewLineId(row, column) {
            if (!row.lines) return row.id;
            return (column.line === 2 ? row.lines[1] : row.lines[0]) ?? row.lines.find(Boolean) ?? row.id;
        }

        /** The cells typed in Review & Export where nothing was read: person id -> [{side, name}]. */
        function reviewTypedCells() {
            const typed = new Map();
            if (!reviewModel) return typed;
            reviewModel.rows.forEach((row) => {
                reviewModel.columns.forEach((column) => {
                    if (column.detail || (row.cells[column.key]?.refs.length ?? 0) > 0) return;
                    if (String(reviewExtras.get(`${row.id}|${column.key}`) ?? '').trim() === '') return;
                    const id = reviewLineId(row, column);
                    if (!typed.has(id)) typed.set(id, []);
                    typed.get(id).push({ side: column.side, name: nameKey(column.name) });
                });
            });
            return typed;
        }

        /** Where an unread cell is on its page: its column across, its table row down. */
        function unreadCellBox(column, tableRow) {
            const page = docEntries()[column.side]?.page;
            const columnBox = (page?.geometry?.columns ?? []).find((candidate) => nameKey(candidate.name) === nameKey(column.name))?.box;
            const ruled = page?.geometry?.ruled_ys ?? [];
            const top = Number(ruled[tableRow - 1]);
            const bottom = Number(ruled[tableRow]);
            const clamp = (value) => Math.min(1, Math.max(0, value));
            const x = clamp(Number(columnBox?.[0] ?? 0));
            const y = Number.isFinite(top) ? clamp(top) : 0;
            return {
                x,
                y,
                w: Math.max(0.00001, Math.min(1 - x, Number(columnBox?.[2] ?? 0.01) || 0.01)),
                h: Math.max(0.00001, Math.min(1 - y, Number.isFinite(bottom) && bottom > top ? bottom - top : 0.01)),
            };
        }

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
                // Align's page bar is a single PDF's; a batch has its queue instead.
                if (!batchState.active) updateScanNotices();
            }
            // A batch can leave the record out and go on, so it is never stuck here.
            el('duplicatePageSkipBtn').classList.toggle('d-none', !batchState.active);
            window.bootstrap.Modal.getOrCreateInstance(el('duplicatePageModal')).show();
        }

        // The safe choice has the focus, so Enter never saves a page twice by accident.
        el('duplicatePageModal').addEventListener('shown.bs.modal', () => el('duplicatePageBackBtn').focus());

        el('duplicatePageSkipBtn').addEventListener('click', () => {
            window.bootstrap.Modal.getInstance(el('duplicatePageModal'))?.hide();
            // As Reject does for a match: its pages are let go, and the next record opens.
            rejectCurrentExistingDocument({ skipped: true });
        });

        el('confirmDuplicatePageBtn').addEventListener('click', () => {
            window.bootstrap.Modal.getInstance(el('duplicatePageModal'))?.hide();
            submitRecord({ ...lastSubmitChoices, allowDuplicate: true });
        });

        // ------------------------------------------------ the next page of a PDF
        const SUBMIT_IDLE_HTML = el('submitBtn').innerHTML;
        const REVIEW_SAVE_IDLE_HTML = el('reviewSaveBtn').innerHTML;

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
                el('reviewSaveBtn').innerHTML = REVIEW_SAVE_IDLE_HTML;
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
            if (!isPdfFile(scanFile)) return scanFile;
            // A one-page PDF goes whole, unless it is too large to send: then as an image of its page.
            if (!multiPage() && scanFile.size <= MAX_SENT_PDF_BYTES) return scanFile;
            try {
                return await pdfPageFile(scanFile, pageNumber);
            } catch (error) {
                console.warn('The page could not be cut out of the PDF:', error);
                if (scanFile.size <= MAX_SENT_PDF_BYTES) return scanFile;
                throw new Error(`Page ${pageNumber} could not be taken out of this PDF. Save that page as its own file and upload it.`);
            }
        }

        // What the user last chose to go ahead with, so answering one question
        // ("Submit anyway", "Save again") keeps the answer to the other.
        let lastSubmitChoices = { allowMissing: false, allowDuplicate: false };

        // Only "Submit anyway" passes allowMissing, and only "Save again"
        // allowDuplicate, after the same checks again.
        //
        // In a batch, Save & next does not save: after the same checks the record
        // is Done (approveBatchRecord), kept with the batch and still open to
        // change. Save & finish batch saves every Done record, each through here
        // with `finalBatchSave` (finishBatchSave); that answers true once saved.
        async function submitRecord({ allowMissing = false, allowDuplicate = false, finalBatchSave = false } = {}) {
            if (recordSubmitting) return false;
            saveReferenceInputsForCurrentPage();
            if (!validateDocumentReference()) {
                return false;
            }
            // Save & finish batch is saving the Done records: a press meanwhile is not a second save.
            if (batchFinishing && !finalBatchSave) return false;
            // A batch record saved (or left out) already, still on screen while the
            // next one is read: it is not sent twice.
            const shownBatchItem = batchState.active ? batchState.items[batchState.currentIndex] : null;
            if (shownBatchItem && pageDone(shownBatchItem)) {
                showValidationSubmitError('This record is saved already. The next one opens as soon as it is read, or open another from the queue.');
                return;
            }
            // Continue anyway was chosen when the scan matched a saved record.
            allowDuplicate = allowDuplicate || existingRecordAccepted;
            // Kept so a question's answer (Submit anyway, Save again) goes on the same way.
            lastSubmitChoices = { allowMissing, allowDuplicate, finalBatchSave };

            clearValidationSubmitError();

            // A register's table: every row must be one its table can take (register
            // number, book, page and names filled; digits; one of the fixed values).
            if (registry && reviewing && reviewTable) {
                reviewTable.commit();
                const issue = reviewTable.firstFieldIssue();
                if (issue) {
                    showValidationSubmitError(issue.message);
                    reviewTable.select(issue.row, issue.column, { locate: false });
                    return false;
                }
            }
            const rows = [...el('verifyRows').querySelectorAll('.validation-field')];
            rows.forEach((row) => clearValidationFieldError(Number(row.dataset.fieldIndex)));

            // A field whose crop was deleted and not drawn again is simply left out
            // (it cannot be ticked, so it is not among the verified fields).
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

            if (!scanFile && !batchScanOnServer()) {
                showValidationSubmitError('The original scan is no longer available. Return to marking and choose the file again.');
                return;
            }

            // Values typed in Review & Export where nothing was read: saved as fields with no outline.
            const personGroupsForExtras = validationGroups.filter((candidate) => candidate.kind === 'person');
            const extraFields = reviewExtraFields(personGroupsForExtras);

            if (!allowMissing) {
                const missing = requiredNotSubmitted(verifiedIndexes);
                if (missing.length > 0) {
                    askToSubmitWithoutRequired(missing);
                    return;
                }
            }

            // A batch: Save & next makes the record Done; it is saved with the rest
            // by Save & finish batch.
            if (batchState.active && !finalBatchSave && batchState.items[batchState.currentIndex]) {
                if (verifyDoc) {
                    const sides = new Set([
                        ...verifiedIndexes.map((index) => cropped[index]?.pageIndex ?? 0),
                        ...extraFields.map((extra) => extra.side),
                    ]);
                    const unchecked = verifyDoc.pages.find((_, side) => !sides.has(side));
                    if (unchecked) {
                        showValidationSubmitError(`Check Verified on at least one field of page ${unchecked.number}.`);
                        return false;
                    }
                }
                await approveBatchRecord(batchState.items[batchState.currentIndex], { allowMissing, allowDuplicate });
                return false;
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
            // A batch kept on the server: its page(s) are marked saved, with the record.
            if (currentBatchItem && batchServer.id) {
                data.set('scan_batch_id', String(batchServer.id));
                recordOf(currentBatchItem).members.forEach((it, index) => {
                    if (it.key) data.set(`scan_batch_pages[${index}]`, it.key);
                });
            }
            if (allowMissing) data.set('allow_missing', '1');
            if (allowDuplicate) data.set('allow_duplicate', '1');
            // Which file, and which of its pages, this record is (a queued page: the PDF it was cut from).
            data.set('source_name', currentBatchItem?.sourceName ?? scanFile?.name ?? 'scan');
            data.set('source_page', String(scanPageNumber));
            data.set('source_page_count', String(scanPageCount));
            if (scanFingerprint) data.set('source_hash', scanFingerprint);
            const bookNoToSend = (currentBatchItem && currentBatchItem.bookNo) ? currentBatchItem.bookNo : getBookNumber();
            const pageNoToSend = (currentBatchItem && currentBatchItem.pageNoRef) ? currentBatchItem.pageNoRef : getPageNumber();
            data.set('book_no', bookNoToSend);
            data.set('page_no', pageNoToSend);
            // Each row of the table, saved as one row of the register's table (birth_records, …).
            if (registry && reviewing && reviewTable) {
                data.set('registry_rows_json', JSON.stringify(reviewTable.fieldRows()));
            }
            // "Columns → fields" choices changed here: kept on the layout with this record.
            const sentFieldMaps = registry && reviewing ? fieldMapsToSend() : null;
            if (sentFieldMaps) data.set('field_maps_json', JSON.stringify(sentFieldMaps));

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
            data.set('fields_json', JSON.stringify([...submittedFields, ...extraFields.map((extra) => extra.field)]));

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
                extraFields.forEach((extra) => pageFields[extra.side]?.push(extra.field));
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
                    // A queued pair: the server keeps page 2's file from the batch's folder.
                    ...(second.item?.workspaceScanPath ? { workspace_scan_path: second.item.workspaceScanPath } : {}),
                    source_page: second.number,
                    fields: secondFields,
                }]));
                spreadPages = verifyDoc.pages.map((entry) => entry.number);
                combined = true;
            }
            // What the table showed when this record was saved: a batch downloads its records as one.
            const exportedTable = batchState.active && reviewing && reviewTable ? reviewTable.exportTable() : null;

            const button = reviewing ? el('reviewSaveBtn') : el('submitBtn');
            const idleButton = button.innerHTML;
            const controller = new AbortController();
            const timeoutId = window.setTimeout(() => controller.abort(), 120000);
            let submissionSucceeded = false;
            recordSubmitting = true;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Submitting verified fields...';

            try {
                if (currentBatchItem?.workspaceScanPath) {
                    // Queued: the server keeps each page's file from the batch's folder.
                } else if (spreadPages) {
                    // Each page as its own file, cut out of the PDF.
                    const [firstScan, secondScan] = await Promise.all(spreadPages.map((page) => scanForRecord(page)));
                    data.set('scan', firstScan, firstScan.name);
                    data.set('extra_scans[0]', secondScan, secondScan.name);
                } else {
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

                // Saved: the layouts keep the column choices sent with it.
                fieldMapsKept(sentFieldMaps);
                if (!payload?.redirect) {
                    throw new Error('The record was saved, but its page was not returned. Open Saved Records to confirm it.');
                }

                if (batchState.active && currentBatchItem) {
                    // This record's page(s) are saved; on to the next record, or the batch's summary.
                    recordOf(currentBatchItem).members.forEach((item) => {
                        item.status = 'imported';
                        item.recordUrl = payload.redirect;
                        item.recordId = payload.record?.recordId ?? null;
                        item.verifyDraft = null;
                        item.reviewExtras = null;
                        item.approved = null;
                    });
                    currentBatchItem.exportTable = exportedTable;
                    syncBatchDraft(currentBatchItem);
                    renderBatchQueueBanner();

                    // Saved as part of Save & finish batch: it goes on with the next Done
                    // record (and goes on again here after a question was answered).
                    recordSubmitting = false;
                    button.innerHTML = idleButton;
                    if (!batchFinishing) finishBatchSave();
                    return true;
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
                    ? 'Submission timed out. Check your connection, then confirm the record in Saved Records before trying again.'
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

        syncReferenceInputsForCurrentPage();

    </script>
@endpush