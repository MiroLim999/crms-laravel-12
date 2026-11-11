@extends('layouts.app')

@section('title', $template ? 'Edit Template Layout' : 'New Template Layout')
@section('body-class', 'template-builder-focused')

@section('content')
    @php
        $workingFields = old('fields', $fields);
        $published = (bool) $template?->is_active;
        $usage ??= ['published' => false, 'records' => 0, 'pages' => 0];
        $parent ??= null;
        $pageTwoChoices ??= collect();
        // Published, or read by records or pages in progress: saving new markers
        // makes a new version, so what was read with this one stays as it was.
        $inUse = $template !== null && ($usage['published'] || $usage['records'] > 0 || $usage['pages'] > 0);
        $usageParts = array_filter([
            $usage['records'] > 0 ? $usage['records'].' '.Str::plural('record', $usage['records']).' read with it' : null,
            $usage['pages'] > 0 ? $usage['pages'].' unsubmitted '.Str::plural('page', $usage['pages']) : null,
        ]);
        $currentGroupingMode = old('grouping_mode', $template?->grouping_mode ?? 'auto');
        $workingColumns = old('columns_json') ? json_decode(old('columns_json'), true) : $columns;
        $workingRuledYs = old('ruled_ys_json') ? json_decode(old('ruled_ys_json'), true) : $ruledYs;
        $builderConfig = [
            'initialFields' => $workingFields,
            'baselineFields' => $fields,
            'initialColumns' => is_array($workingColumns) ? $workingColumns : [],
            'baselineColumns' => $columns,
            'initialRuledYs' => is_array($workingRuledYs) ? $workingRuledYs : [],
            'baselineRuledYs' => $ruledYs,
            // The layout of page 2 of a two-page spread, drawn beside page 1 (null: one page).
            'pageTwo' => $pageTwo ?? null,
            // Already published: a save that changes nothing is not a new publish.
            'published' => $published,
            'detectGridUrl' => route('templates.detect-grid'),
            'csrf' => csrf_token(),
            'initialGroupingMode' => $currentGroupingMode,
            'baselineGroupingMode' => $template?->grouping_mode ?? 'auto',
            'maxFields' => App\Support\Limits::MAX_FIELDS,
            'maxFieldNameLength' => 120,
            // The blank page's shape (width : height) until a sample is opened.
            'pageRatio' => $template?->page_ratio ?? App\Models\DocumentTemplate::DEFAULT_PAGE_RATIO,
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
                {{-- The document type, unless the layout's name already says it. --}}
                @if (! $template || mb_strtolower(trim($template->name)) !== mb_strtolower(trim($docType->label())))
                    <span class="template-builder-page__eyebrow">{{ $docType->label() }}</span>
                @endif
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <h1 class="h4 mb-0">
                        {{ $template ? $template->name : 'New template layout' }}
                    </h1>
                    @if ($published)
                        <span class="badge bg-label-success">Published for Staff</span>
                    @else
                        <span class="badge bg-label-secondary">Draft</span>
                    @endif
                    @if ($parent)
                        <a href="{{ route('templates.edit', $parent) }}" class="template-builder-page__parent">
                            {{ $template?->is_version ? 'New version of' : 'Based on' }} “{{ $parent->name }}”
                        </a>
                    @endif
                </div>
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
            <input type="hidden" name="publish" value="0" id="publishIntent">
            <input type="hidden" name="publish_anyway" value="0" id="publishAnyway">
            @if ($template)
                {{-- The revision this editor opened: an older copy cannot overwrite a newer save. --}}
                <input type="hidden" name="revision" value="{{ old('revision', $template->revision) }}">
            @endif
            <input type="hidden" name="grouping_mode" value="{{ $currentGroupingMode }}" id="groupingMode">
            <div id="fieldInputs"></div>

            <div class="row g-3 template-builder-grid">
                <div class="col-xl-9 col-lg-8">
                    <section class="card document-canvas-card template-builder-canvas-card"
                             aria-label="Template document editor">
                        <div class="marker-toolbar">
                            <div class="marker-toolbar__primary">
                                <label for="sampleScan" class="btn btn-sm btn-outline-secondary template-sample-control"
                                       id="sampleScanLabel" tabindex="0" role="button"
                                       title="{{ $template?->sample_path ? 'Replace the stored sample document' : 'Choose a sample document' }}. It is stored privately with this layout when you save.">
                                    <i class="icon-base bx bx-file" aria-hidden="true"></i>
                                    <span id="sampleFileName">{{ $template?->sample_original_name ?? 'Choose sample' }}</span>
                                </label>
                                <input type="file" id="sampleScan" name="sample_document" form="templateBuilderForm"
                                       class="visually-hidden"
                                       accept="application/pdf,image/png,image/jpeg,image/webp,image/bmp">

                                {{-- Only for a file picked but not saved yet: puts back the stored sample, or the blank page. --}}
                                <button type="button" class="btn btn-sm btn-outline-danger template-sample-delete-button d-none"
                                        id="removePickedSampleBtn"
                                        data-bs-toggle="modal" data-bs-target="#removePickedSampleModal"
                                        title="{{ $template?->sample_path ? 'Remove the picked file and go back to the stored sample' : 'Remove the picked file' }}"
                                        aria-label="Remove the picked file">
                                    <i class="icon-base bx bx-trash icon-sm" aria-hidden="true"></i>
                                    <span>Remove</span>
                                </button>

                                @if ($template?->sample_path)
                                    <button type="button" class="btn btn-sm btn-outline-danger template-sample-delete-button" id="deleteStoredSampleBtn"
                                            data-bs-toggle="modal" data-bs-target="#deleteTemplateSampleModal"
                                            title="Delete stored sample document" aria-label="Delete sample">
                                        <i class="icon-base bx bx-trash icon-sm" aria-hidden="true"></i>
                                        <span>Delete sample</span>
                                    </button>
                                @endif

                                <span class="marker-toolbar__divider" aria-hidden="true"></span>
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
                                {{-- A register's left and right pages are laid out differently: both pages side by side,
                                     each with its own markers, saved as page 1 and page 2 of this layout. --}}
                                <div class="form-check form-switch marker-grid-toggle"
                                     title="Lay out page 2 of a two-page spread beside page 1">
                                    <input class="form-check-input" type="checkbox" role="switch" id="twoPageToggle"
                                           @checked(! empty($pageTwo))>
                                    <label class="form-check-label" for="twoPageToggle">Two pages</label>
                                </div>

                                {{-- Hides the row lines and every field box to look at the sample itself; on at every visit. --}}
                                <div class="form-check form-switch marker-grid-toggle"
                                     title="Show or hide the row lines and field boxes over the page">
                                    <input class="form-check-input" type="checkbox" role="switch" id="showGridToggle" checked>
                                    <label class="form-check-label" for="showGridToggle">Grid lines</label>
                                </div>

                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle marker-help-button" data-bs-toggle="dropdown"
                                            aria-expanded="false" aria-label="Show editor shortcuts">
                                        <i class="icon-base bx bx-terminal icon-sm me-1" aria-hidden="true"></i>
                                        <span>Shortcuts</span>
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-end marker-shortcuts-menu">
                                        <div class="marker-shortcuts-menu__title">Editor shortcuts</div>
                                        <div><span><kbd>Ctrl</kbd> + scroll</span><small>Zoom document</small></div>
                                        <div><span><kbd>Ctrl</kbd> + drag</span><small>Move document</small></div>
                                        <div><span>Drag empty document area</span><small>Select fields in a rectangle</small></div>
                                        <div><span><kbd>Shift</kbd> + drag</span><small>Add to selection</small></div>
                                        <div><span><kbd>Shift</kbd> + click</span><small>Select multiple</small></div>
                                        <div><span>Drag selection</span><small>Move selected fields</small></div>
                                        <div><span>Drag resize handle</span><small>Resize selected fields</small></div>
                                        <div><span>Arrow keys</span><small>Move selected 1 px (<kbd>Shift</kbd>: 10 px)</small></div>
                                        <div><span><kbd>Alt</kbd> + drag</span><small>Place without snapping to printed lines</small></div>
                                        <div><span>Drag the rotate knob below a field</span><small>Tilt that field (<kbd>Shift</kbd>: 5° steps)</small></div>
                                        <div><span><kbd>[</kbd> or <kbd>]</kbd></span><small>Tilt selected 0.5° (<kbd>Shift</kbd>: 5°)</small></div>
                                        <div><span>Double-click the knob</span><small>Straighten</small></div>
                                        <div><span><kbd>Ctrl</kbd> + <kbd>A</kbd></span><small>Select all markers</small></div>
                                        <div><span><kbd>Ctrl</kbd> + <kbd>C</kbd></span><small>Copy selected</small></div>
                                        <div><span><kbd>Ctrl</kbd> + <kbd>V</kbd></span><small>Paste fields</small></div>
                                        <div><span><kbd>Del</kbd> or <kbd>Backspace</kbd></span><small>Delete selected</small></div>
                                        <div><span><kbd>Ctrl</kbd> + <kbd>Z</kbd></span><small>Undo last change (row lines too)</small></div>
                                        <div><span>Click a row line's grip</span><small>Select it: <kbd>↑</kbd> <kbd>↓</kbd> move, <kbd>Del</kbd> removes</small></div>
                                        <div><span><kbd>Shift</kbd> + drag a grip</span><small>Move every row line</small></div>
                                    </div>
                                </div>

                                <button type="button" class="btn btn-sm btn-outline-secondary marker-reset-button" id="resetFieldsBtn"
                                        title="Restore the saved layout and document view" disabled>
                                    <i class="icon-base bx bx-refresh icon-sm me-1" aria-hidden="true"></i>
                                    <span>Reset</span>
                                </button>

                                <span class="marker-selection-summary" id="selectionSummary">
                                    <i class="icon-base bx bx-list-check icon-sm" aria-hidden="true"></i>
                                    <span>0 selected</span>
                                </span>
                            </div>
                        </div>

                        @error('sample_document')
                            <div class="template-builder-sample-error" role="alert">
                                <i class="icon-base bx bx-error" aria-hidden="true"></i>
                                <span>{{ $message }}</span>
                            </div>
                        @enderror

                        <div class="doc-viewport" id="docViewport">
                            {{-- Fields placed with no sample open: the small reminder. --}}
                            <div class="template-builder-canvas-note d-none" id="sampleHint" role="status">
                                <i class="icon-base bx bx-cloud-upload" aria-hidden="true"></i>
                                <span><strong>Blank preview</strong> &mdash; choose a sample PDF or image to align markers precisely.</span>
                            </div>
                            {{-- Nothing on the page yet: a sample can be dropped anywhere in this area, or chosen
                                 here. The picture shows what happens: a page lands, then its fields are marked. --}}
                            <div class="template-dropzone {{ $template?->sample_path ? 'd-none' : '' }}" id="sampleDropzone">
                                <div class="template-dropzone__panel">
                                    <div class="template-dropzone__art" aria-hidden="true">
                                        <span class="template-dropzone__tray"></span>
                                        <span class="template-dropzone__sheet">
                                            <span class="template-dropzone__line"></span>
                                            <span class="template-dropzone__line"></span>
                                            <span class="template-dropzone__line"></span>
                                            <span class="template-dropzone__line"></span>
                                            <span class="template-dropzone__marker template-dropzone__marker--1"></span>
                                            <span class="template-dropzone__marker template-dropzone__marker--2"></span>
                                            <span class="template-dropzone__marker template-dropzone__marker--3"></span>
                                        </span>
                                    </div>
                                    <p class="template-dropzone__title">
                                        <span class="template-dropzone__when-idle">Drop a sample document here</span>
                                        <span class="template-dropzone__when-dragging">Release to open it</span>
                                    </p>
                                    <p class="template-dropzone__text">
                                        A scan or PDF of the form. You mark where each field is on top of it.
                                    </p>
                                    <label for="sampleScan" class="btn btn-primary template-dropzone__button">
                                        <i class="icon-base bx bx-cloud-upload icon-sm me-1" aria-hidden="true"></i>Choose sample
                                    </label>
                                    <span class="document-dropzone__formats" aria-label="Accepted formats">
                                        <span>PDF</span><span>PNG</span><span>JPG</span><span>WEBP</span><span>BMP</span>
                                    </span>
                                    <p class="template-dropzone__note">Up to 20 MB. No sample? Add a field on the right to start on a blank page.</p>
                                </div>
                            </div>
                            {{-- Two pages side by side when the layout has a page 2: the page being edited is
                                 live, the other shows its own layout, and a click on it edits that page. --}}
                            <div class="doc-spread" id="docSpread">
                                <div class="doc-spread__page doc-spread__page--main" id="docMainPage">
                                    <div class="doc-spread__label d-none" id="docMainLabel"></div>
                                    <div class="doc-stage" id="docStage">
                                        <canvas id="pageCanvas" width="900" height="1200"></canvas>
                                        <div class="field-overlay" id="fieldOverlay">
                                            <div class="field-selection-marquee" id="fieldSelectionMarquee"
                                                 aria-hidden="true"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="doc-spread__page doc-spread__page--companion d-none" id="docCompanionPage"
                                     title="Edit this page's layout instead">
                                    <div class="doc-spread__label" id="docCompanionLabel"></div>
                                    <div class="doc-stage" id="docCompanionStage">
                                        <canvas id="companionCanvas" width="900" height="1200"></canvas>
                                        <div class="field-overlay" id="companionOverlay"></div>
                                        <button type="button" class="doc-spread__switch" id="docCompanionSwitch"
                                                aria-label="Edit this page's layout instead">
                                            <i class="icon-base bx bx-edit-alt me-1" aria-hidden="true"></i>
                                            <span>Edit this page</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="col-xl-3 col-lg-4">
                    <aside class="template-builder-side-panel">
                        {{--
                            Cards as on the Staff Align panel: the ones used now and then
                            (details, rows and columns, person rows) start closed and open
                            from their header; tips sit behind a ?. Details open for a new
                            layout, or when the name or notes came back with an error.
                        --}}
                        @php
                            $detailsOpen = ! $template || $errors->hasAny(['name', 'description', 'page_two_template_id']);
                            $personRowsOpen = $currentGroupingMode === 'custom';
                        @endphp
                        <section class="card card-disclosure template-builder-details-card mb-3"
                                 aria-labelledby="detailsCardToggle">
                            <div class="card-header d-flex align-items-center gap-1 position-relative card-disclosure__header {{ $detailsOpen ? 'is-expanded' : '' }}">
                                <h2 class="card-title h5 mb-0">
                                    <button type="button" class="btn btn-link card-disclosure__toggle" id="detailsCardToggle"
                                            aria-expanded="{{ $detailsOpen ? 'true' : 'false' }}" aria-controls="detailsCardBody">
                                        Layout details
                                    </button>
                                </h2>
                                <i class="icon-base bx bx-chevron-down card-disclosure__chevron" aria-hidden="true"></i>
                            </div>
                            <div class="card-disclosure__body" id="detailsCardBody" @if (! $detailsOpen) hidden @endif>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="name" class="form-label">Name</label>
                                        <input type="text" id="name" name="name" maxlength="120"
                                               value="{{ old('name', $template?->name ?? $docType->label()) }}"
                                               class="form-control form-control-sm @error('name') is-invalid @enderror" required>
                                        @error('name')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>

                                    {{-- A register's left and right pages are often laid out differently: this
                                         layout is page 1, and page 2 is its own (hidden) layout. It can start as a
                                         copy of another layout; changing it later never changes that layout. --}}
                                    @if ($pageTwo ?? null)
                                        <div class="mb-3">
                                            <span class="form-label d-block">Page 2</span>
                                            <div class="form-text mt-0">
                                                This layout has its own page 2. Turn on <strong>Two pages</strong> above to change it,
                                                or turn it off and save to remove it.
                                            </div>
                                        </div>
                                    @else
                                        <div class="mb-3">
                                            <label for="page_two_template_id" class="form-label">Copy page 2 from</label>
                                            <select id="page_two_template_id" name="page_two_template_id"
                                                    class="form-select form-select-sm @error('page_two_template_id') is-invalid @enderror">
                                                <option value="">None: page 2 is laid out like page 1</option>
                                                @foreach ($pageTwoChoices as $choice)
                                                    <option value="{{ $choice->getKey() }}"
                                                            @selected((string) old('page_two_template_id') === (string) $choice->getKey())>
                                                        {{ $choice->name }}{{ $choice->is_active ? ' (published)' : '' }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <div class="form-text">
                                                For a two-page spread whose pages are laid out differently. Saving copies the
                                                chosen layout as this layout's page 2; the chosen layout itself stays as it is.
                                            </div>
                                            @error('page_two_template_id')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    @endif

                                    <div>
                                        <label for="description" class="form-label">Notes</label>
                                        <textarea id="description" name="description" rows="2" maxlength="1000"
                                                  class="form-control form-control-sm @error('description') is-invalid @enderror"
                                                  placeholder="Form revision or internal note">{{ old('description', $template?->description) }}</textarea>
                                        @error('description')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="card card-disclosure document-grid-card template-ledger-card mb-3"
                                 aria-labelledby="ledgerCardToggle">
                            <div class="card-header d-flex align-items-center gap-1 position-relative card-disclosure__header">
                                <h2 class="card-title h5 mb-0">
                                    <button type="button" class="btn btn-link card-disclosure__toggle" id="ledgerCardToggle"
                                            aria-expanded="false" aria-controls="ledgerCardBody">
                                        Table grid
                                    </button>
                                </h2>
                                <div class="card-help">
                                    <button type="button" class="btn btn-link card-help__toggle"
                                            aria-label="Table grid tips" aria-describedby="ledgerTips">
                                        <i class="icon-base bx bx-help-circle" aria-hidden="true"></i>
                                    </button>
                                    <div class="card-help__tips" id="ledgerTips" role="tooltip">
                                        <strong>Tips</strong>
                                        <ul>
                                            <li>For ruled register books. Detect from sample finds the columns and row lines from the sample's printed rules.</li>
                                            <li>Drag a row line by the grip at either end of the table, or click a grip and press <kbd>↑</kbd> <kbd>↓</kbd>. <kbd>Shift</kbd> + drag moves every line. Double-click a grip to remove its line.</li>
                                            <li>Lines snap to the printed rules. Hold <kbd>Alt</kbd> to place one freely.</li>
                                            <li>The − and + buttons remove or add a row line. Type a number and press <kbd>Enter</kbd> to space that many rows evenly.</li>
                                            <li>Page scanned a little turned? Tilt turns the columns and row lines together.</li>
                                            <li>Staff scans read each row on its own, so writing that crosses a rule is still read whole.</li>
                                        </ul>
                                    </div>
                                </div>
                                {{-- Something to check inside while the card is closed. --}}
                                <i class="icon-base bx bx-error-circle text-warning card-disclosure__alert d-none" id="ledgerCardAlert"
                                   title="Check Table grid" aria-hidden="true"></i>
                                <i class="icon-base bx bx-chevron-down card-disclosure__chevron" aria-hidden="true"></i>
                            </div>

                            <div class="card-disclosure__body" id="ledgerCardBody" hidden>
                                <div class="card-body">
                                    <div class="document-grid-card__line">
                                        <button type="button" class="btn btn-sm btn-outline-primary w-100" id="detectGridBtn"
                                                title="Find the columns and row lines from the sample's printed rules">
                                            <i class="icon-base bx bx-grid-alt icon-sm me-1" aria-hidden="true"></i>
                                            Detect from sample
                                        </button>
                                    </div>

                                    <div class="document-grid-card__line">
                                        <span class="document-grid-card__label" id="ledgerRowsLabel">Rows</span>
                                        <div class="input-group input-group-sm template-ledger-rows" role="group" aria-labelledby="ledgerRowsLabel">
                                            <button type="button" class="btn btn-outline-secondary" id="removeRuledLineBtn" disabled
                                                    title="Remove the selected row line, or the last one" aria-label="Remove a row line">
                                                <i class="icon-base bx bx-minus icon-sm" aria-hidden="true"></i>
                                            </button>
                                            <input type="number" class="form-control" id="evenRowsInput" min="1" max="399" step="1"
                                                   inputmode="numeric" aria-label="Number of rows"
                                                   title="Type a number and press Enter to space that many rows evenly">
                                            <button type="button" class="btn btn-outline-secondary" id="addRuledLineBtn"
                                                    title="Add a line under the selected one, or at the bottom" aria-label="Add a row line">
                                                <i class="icon-base bx bx-plus icon-sm" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                        {{-- A page scanned a little turned: the columns and row lines turn together. --}}
                                        <div class="btn-group btn-group-sm" role="group" id="gridTiltGroup" aria-label="Tilt of the table">
                                            <button type="button" class="btn btn-outline-secondary" id="tiltLeftBtn"
                                                    title="Turn the table anticlockwise by 0.1° (Shift: 1°)"
                                                    aria-label="Turn the table anticlockwise">
                                                <i class="icon-base bx bx-rotate-left icon-sm" aria-hidden="true"></i>
                                            </button>
                                            <input type="text" class="document-grid-card__count" id="gridTiltValue"
                                                   value="0.0°" inputmode="decimal" autocomplete="off"
                                                   aria-label="How far the table is turned, in degrees"
                                                   title="Tilt: how far the table is turned">
                                            <button type="button" class="btn btn-outline-secondary" id="tiltRightBtn"
                                                    title="Turn the table clockwise by 0.1° (Shift: 1°)"
                                                    aria-label="Turn the table clockwise">
                                                <i class="icon-base bx bx-rotate-right icon-sm" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <p class="template-ledger-card__summary d-none" id="ledgerGridSummary" aria-live="polite"></p>

                                    <div class="template-ledger-card__problems d-none" id="ledgerProblems" role="status">
                                        <i class="icon-base bx bx-error icon-sm" aria-hidden="true"></i>
                                        <ul class="mb-0" id="ledgerProblemList"></ul>
                                    </div>

                                    <div class="template-ledger-card__covered d-none" id="ledgerCoveredNotice">
                                        <span id="ledgerCoveredMessage"></span>
                                        <span class="template-ledger-card__covered-actions">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" id="selectCoveredFieldsBtn">
                                                Select them
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger" id="removeCoveredFieldsBtn">
                                                Remove them
                                            </button>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="card document-fields-card template-builder-fields-card mb-3">
                            <div class="card-header d-flex align-items-center gap-1 position-relative">
                                <h2 class="card-title h5 mb-0">Fields</h2>
                                <div class="card-help">
                                    <button type="button" class="btn btn-link card-help__toggle"
                                            aria-label="Fields tips" aria-describedby="fieldTips">
                                        <i class="icon-base bx bx-help-circle" aria-hidden="true"></i>
                                    </button>
                                    <div class="card-help__tips" id="fieldTips" role="tooltip">
                                        <strong>Tips</strong>
                                        <ul>
                                            <li>Keep each marker tight around one handwritten value.</li>
                                            <li>Staff check the fields in this order.</li>
                                            <li>Click a name to select its marker. Its settings show below the list.</li>
                                            <li>A field is read once. For something on every row of a table, select it and set Read as to Column.</li>
                                            <li><kbd>Ctrl</kbd> + <kbd>A</kbd> then <kbd>Delete</kbd> clears the page: every marker and the row lines. <kbd>Ctrl</kbd> + <kbd>Z</kbd> brings them back.</li>
                                        </ul>
                                    </div>
                                </div>
                                <span class="badge bg-label-primary ms-auto" id="fieldCount">0 fields</span>
                            </div>
                            <div class="card-body">
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

                                <ul class="list-unstyled marker-field-list template-builder-field-list mb-3"
                                    id="fieldList"></ul>

                                <label class="visually-hidden" for="newFieldName">New field name</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" id="newFieldName" class="form-control"
                                           maxlength="120" placeholder="e.g. Page No.">
                                    <button class="btn btn-outline-primary" type="button" id="addFieldBtn"
                                            title="One marker, read once. Drag it onto the value on the sample">Add field</button>
                                </div>
                            </div>
                        </section>

                        <section class="card template-field-settings-card mb-3 d-none" id="fieldSettingsCard"
                                 aria-labelledby="fieldSettingsHeading">
                            <div class="card-header">
                                <h2 class="card-title h5 mb-0" id="fieldSettingsHeading">Field settings</h2>
                                <small class="text-muted d-block text-truncate" id="fieldSettingsName"></small>
                            </div>
                            <div class="card-body">
                                {{-- A field is read once; a column once per row of the ledger. --}}
                                <div class="mb-2">
                                    <span class="form-label d-block" id="fieldKindLabel">Read as</span>
                                    <div class="btn-group btn-group-sm w-100" role="group" aria-labelledby="fieldKindLabel">
                                        <button type="button" class="btn btn-outline-secondary" id="makeFieldsBtn"
                                                aria-pressed="false" title="Read once, as one rectangle">
                                            <i class="icon-base bx bx-rectangle icon-sm me-1" aria-hidden="true"></i>
                                            Field
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary" id="makeColumnsBtn"
                                                aria-pressed="false" title="Read once per row of the ledger">
                                            <i class="icon-base bx bx-columns icon-sm me-1" aria-hidden="true"></i>
                                            Column
                                        </button>
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="fieldRole">Holds</label>
                                    <select class="form-select form-select-sm" id="fieldRole">
                                        <option value="">Any value</option>
                                        <option value="name">The person's name</option>
                                        <option value="entry">The entry number</option>
                                    </select>
                                    <div class="form-text">The name titles each person in Verify and the records archive.</div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="fieldType">Value</label>
                                    <select class="form-select form-select-sm" id="fieldType">
                                        <option value="text">Text</option>
                                        <option value="date">A date</option>
                                        <option value="number">A number</option>
                                        <option value="choice">One of a list</option>
                                    </select>
                                </div>
                                <div class="mb-2 d-none" id="fieldOptionsGroup">
                                    <label class="form-label" for="fieldOptions">Choices</label>
                                    <input type="text" class="form-control form-control-sm" id="fieldOptions"
                                           maxlength="1900" placeholder="Male, Female">
                                    <div class="form-text">Separate them with commas. Verify points out any other value.</div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="fieldHint">Hint for Staff</label>
                                    <input type="text" class="form-control form-control-sm" id="fieldHint"
                                           maxlength="200" placeholder="e.g. Month day, year">
                                </div>
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" id="fieldRequired">
                                    <label class="form-check-label" for="fieldRequired">
                                        Required: point out a person without it
                                    </label>
                                </div>
                            </div>
                        </section>

                        {{-- Who each row of fields belongs to in Verify: found by position unless set by hand. --}}
                        <section class="card card-disclosure template-person-card mb-3" aria-labelledby="personCardToggle">
                            <div class="card-header d-flex align-items-center gap-1 position-relative card-disclosure__header {{ $personRowsOpen ? 'is-expanded' : '' }}">
                                <h2 class="card-title h5 mb-0">
                                    <button type="button" class="btn btn-link card-disclosure__toggle" id="personCardToggle"
                                            aria-expanded="{{ $personRowsOpen ? 'true' : 'false' }}" aria-controls="personCardBody">
                                        Person rows
                                    </button>
                                </h2>
                                <div class="card-help">
                                    <button type="button" class="btn btn-link card-help__toggle"
                                            aria-label="Person rows tips" aria-describedby="personTips">
                                        <i class="icon-base bx bx-help-circle" aria-hidden="true"></i>
                                    </button>
                                    <div class="card-help__tips" id="personTips" role="tooltip">
                                        <strong>Tips</strong>
                                        <ul>
                                            <li>Automatic: Verify finds repeated rows from where the markers sit.</li>
                                            <li>To set a row by hand, select one person's fields and click Group as person.</li>
                                            <li>Ledger columns are always read once per row, so they need no group.</li>
                                        </ul>
                                    </div>
                                </div>
                                <span class="badge bg-label-secondary ms-auto" id="groupingModeBadge">Automatic</span>
                                <i class="icon-base bx bx-chevron-down card-disclosure__chevron" aria-hidden="true"></i>
                            </div>

                            <div class="card-disclosure__body" id="personCardBody" @if (! $personRowsOpen) hidden @endif>
                                <div class="card-body template-person-builder">
                                    <div class="template-person-builder__actions">
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                id="groupSelectedBtn" disabled>
                                            <i class="icon-base bx bx-group icon-sm me-1" aria-hidden="true"></i>
                                            Group as person
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                                id="ungroupSelectedBtn" disabled>
                                            <i class="icon-base bx bx-x icon-sm me-1" aria-hidden="true"></i>
                                            Ungroup
                                        </button>
                                    </div>

                                    <div class="template-person-group-list" id="personGroupList"></div>

                                    <button type="button" class="btn btn-sm btn-outline-secondary template-person-builder__automatic d-none"
                                            id="useAutomaticGroupsBtn" disabled>
                                        <i class="icon-base bx bx-refresh icon-sm me-1" aria-hidden="true"></i>
                                        Back to automatic
                                    </button>
                                </div>
                            </div>
                        </section>

                        <div class="template-builder-error d-none" id="builderError" role="alert" aria-live="assertive">
                            <i class="icon-base bx bx-error" aria-hidden="true"></i>
                            <span id="builderErrorMessage"></span>
                        </div>

                        <div class="template-builder-save-panel">
                            {{-- A layout in use is never overwritten: its buttons say so on hover. --}}
                            @if ($inUse)
                                @php
                                    $inUseNote = 'In use'.($usageParts ? ': '.implode(', ', $usageParts) : '')
                                        .'. Saving makes a new version; records already read keep this one.';
                                @endphp
                                <button type="submit" class="btn btn-outline-secondary" data-publish="0" title="{{ $inUseNote }}">
                                    Save as new draft
                                </button>
                                <button type="submit" class="btn btn-primary" data-publish="1" title="{{ $inUseNote }}">
                                    <i class="icon-base bx bx-check icon-sm me-1" aria-hidden="true"></i>
                                    Save &amp; publish new version
                                </button>
                            @else
                                <button type="submit" class="btn btn-outline-secondary" data-publish="0">
                                    Save draft
                                </button>
                                <button type="submit" class="btn btn-primary" data-publish="1">
                                    <i class="icon-base bx bx-check icon-sm me-1" aria-hidden="true"></i>
                                    Save &amp; publish for Staff
                                </button>
                            @endif
                        </div>
                    </aside>
                </div>
            </div>
        </form>
    </div>

    {{-- A file picked but not saved yet: nothing is stored, so this only drops the choice. --}}
    <div class="modal fade" id="removePickedSampleModal" tabindex="-1"
         aria-labelledby="removePickedSampleModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="removePickedSampleModalTitle">Remove this sample?</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Remove <strong id="removePickedSampleName">the picked file</strong> from this layout?</p>
                    <p class="mb-0 text-muted small">
                        {{ $template?->sample_path
                            ? 'It has not been saved yet. The stored sample comes back. Markers and layout settings are not changed.'
                            : 'It has not been saved yet. Markers and layout settings are not changed.' }}
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmRemovePickedSampleBtn">
                        <i class="icon-base bx bx-trash icon-sm me-1" aria-hidden="true"></i>
                        Remove sample
                    </button>
                </div>
            </div>
        </div>
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
                        <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
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

    {{-- Asked before a save that publishes, or that keeps guessed (amber) row lines.
         template-builder.js fills it in. --}}
    <div class="modal fade" id="saveCheckModal" tabindex="-1" aria-labelledby="saveCheckTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <div class="text-uppercase small fw-semibold mb-1" id="saveCheckKicker">Before saving</div>
                        <h2 class="modal-title h5" id="saveCheckTitle">Save this layout?</h2>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2" id="saveCheckLead"></p>
                    <ul class="mb-0 ps-3" id="saveCheckList"></ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Go back</button>
                    <button type="button" class="btn btn-outline-secondary d-none" id="saveCheckDraftBtn">Save as draft</button>
                    <button type="button" class="btn btn-primary" id="saveCheckConfirmBtn">Save</button>
                </div>
            </div>
        </div>
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
                        Restore the markers, person rows and row lines that were loaded when this editor opened. Added and copied fields will be removed.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
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
