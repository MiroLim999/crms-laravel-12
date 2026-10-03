@extends('layouts.app')

@section('title', $template ? 'Edit Template Layout' : 'New Template Layout')
@section('body-class', 'template-builder-focused')

@section('content')
    @php
        $workingFields = old('fields', $fields);
        $published = (bool) $template?->is_active;
        $usage ??= ['published' => false, 'records' => 0, 'pages' => 0];
        $parent ??= null;
        // Published, or read by records or pages in progress: saving new markers
        // makes a new version, so what was read with this one stays as it was.
        $inUse = $template !== null && ($usage['published'] || $usage['records'] > 0 || $usage['pages'] > 0);
        $usageParts = array_filter([
            $usage['published'] ? 'published for Staff' : null,
            $usage['records'] > 0 ? $usage['records'].' '.Str::plural('record', $usage['records']).' read with it' : null,
            $usage['pages'] > 0 ? $usage['pages'].' unsubmitted '.Str::plural('page', $usage['pages']) : null,
        ]);
        $currentPaperSize = old('paper_size', $template?->paper_size?->value ?? App\Enums\PaperSize::Letter->value);
        $currentOrientation = old('orientation', $template?->orientation?->value ?? App\Enums\PageOrientation::Portrait->value);
        $currentCustomWidth = old('custom_width_mm', $template?->custom_width_mm ?? 210);
        $currentCustomHeight = old('custom_height_mm', $template?->custom_height_mm ?? 297);
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
            'detectGridUrl' => route('templates.detect-grid'),
            'testLayoutUrl' => route('templates.test-layout'),
            'csrf' => csrf_token(),
            'initialGroupingMode' => $currentGroupingMode,
            'baselineGroupingMode' => $template?->grouping_mode ?? 'auto',
            'maxFields' => App\Support\Limits::MAX_FIELDS,
            'maxFieldNameLength' => 120,
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
                    @if ($parent)
                        <a href="{{ route('templates.edit', $parent) }}" class="template-builder-page__parent">
                            Based on “{{ $parent->name }}”
                        </a>
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
            <input type="hidden" name="publish" value="0" id="publishIntent">
            @if ($template)
                {{-- The revision this editor opened: an older copy cannot overwrite a newer save. --}}
                <input type="hidden" name="revision" value="{{ old('revision', $template->revision) }}">
            @endif
            <input type="hidden" name="grouping_mode" value="{{ $currentGroupingMode }}" id="groupingMode">
            <div id="fieldInputs"></div>

            @if ($inUse)
                <div class="template-builder-in-use" role="note">
                    <i class="icon-base bx bx-lock-alt" aria-hidden="true"></i>
                    <div>
                        <strong>This layout is in use:</strong> {{ implode(', ', $usageParts) }}.
                        Saving changed markers, row lines or field settings makes a new version, so everything
                        read with this one keeps it. Its name, notes and sample are saved here.
                    </div>
                </div>
            @endif

            <div class="row g-3 template-builder-grid">
                <div class="col-xl-9 col-lg-8">
                    <section class="card document-canvas-card template-builder-canvas-card"
                             aria-label="Template document editor">
                        <div class="marker-toolbar">
                            <div class="marker-toolbar__primary">
                                <label for="sampleScan" class="btn btn-sm btn-outline-secondary template-sample-control"
                                       id="sampleScanLabel" tabindex="0" role="button"
                                       title="{{ $template?->sample_path ? 'Replace the stored sample document' : 'Choose a sample document' }}">
                                    <i class="icon-base bx bx-file" aria-hidden="true"></i>
                                    <span id="sampleFileName">{{ $template?->sample_original_name ?? 'Choose sample' }}</span>
                                </label>
                                <input type="file" id="sampleScan" name="sample_document" form="templateBuilderForm"
                                       class="visually-hidden"
                                       accept="application/pdf,image/png,image/jpeg,image/webp,image/bmp">

                                @if ($template?->sample_path)
                                    <button type="button" class="btn btn-sm btn-outline-danger template-sample-delete-button"
                                            data-bs-toggle="modal" data-bs-target="#deleteTemplateSampleModal"
                                            title="Delete stored sample document">
                                        <i class="icon-base bx bx-trash icon-sm" aria-hidden="true"></i>
                                        <span>Delete sample</span>
                                    </button>
                                @endif

                                <button type="button" class="btn btn-sm btn-outline-primary template-test-button" id="testLayoutBtn"
                                        title="Outline the sample with this layout, as Detect does for Staff (nothing is saved)">
                                    <i class="icon-base bx bx-radar icon-sm" aria-hidden="true"></i>
                                    <span>Test on sample</span>
                                </button>

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
                                        <div><span><kbd>Ctrl</kbd> + <kbd>C</kbd></span><small>Copy selected</small></div>
                                        <div><span><kbd>Ctrl</kbd> + <kbd>V</kbd></span><small>Paste fields</small></div>
                                        <div><span><kbd>Del</kbd> or <kbd>Backspace</kbd></span><small>Delete selected</small></div>
                                        <div><span><kbd>Ctrl</kbd> + <kbd>Z</kbd></span><small>Undo last change (row lines too)</small></div>
                                        <div><span>Click a row line</span><small>Select it: <kbd>↑</kbd> <kbd>↓</kbd> move, <kbd>Del</kbd> removes</small></div>
                                        <div><span><kbd>Shift</kbd> + drag a row line</span><small>Move every row line</small></div>
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
                                <button type="button" class="btn btn-sm btn-outline-danger marker-delete-button"
                                        id="deleteSelectedBtn" disabled>
                                    <i class="icon-base bx bx-trash icon-sm me-1" aria-hidden="true"></i>
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
                                <div class="field-overlay" id="fieldOverlay">
                                    <div class="field-selection-marquee" id="fieldSelectionMarquee"
                                         aria-hidden="true"></div>
                                </div>
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
                                            <label class="btn btn-outline-primary template-orientation-option"
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
                                    <i class="icon-base bx bx-file icon-sm" id="paperPreviewIcon" aria-hidden="true"></i>
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
                                    <button type="button" class="btn btn-sm btn-outline-danger marker-field-delete" id="deleteFieldsBtn" disabled>
                                        <i class="icon-base bx bx-trash icon-sm me-1" aria-hidden="true"></i>
                                        <span>Delete</span>
                                    </button>
                                </div>

                                <div class="template-person-builder" aria-labelledby="personGroupsHeading">
                                    <div class="template-person-builder__heading">
                                        <div>
                                            <h3 class="h6 mb-1" id="personGroupsHeading">Person rows</h3>
                                            <p class="mb-0">Select one person's fields. The selection count becomes that row's field count.</p>
                                        </div>
                                        <span class="badge bg-label-secondary" id="groupingModeBadge">Automatic</span>
                                    </div>

                                    <div class="template-person-builder__actions">
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                id="groupSelectedBtn" disabled>
                                            <i class="icon-base bx bx-group icon-sm me-1" aria-hidden="true"></i>
                                            Group as person
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                                id="ungroupSelectedBtn" disabled>
                                            <i class="icon-base bx bx-x icon-sm me-1" aria-hidden="true"></i>
                                            Ungroup selected
                                        </button>
                                    </div>

                                    <div class="template-person-group-list" id="personGroupList"></div>

                                    <button type="button" class="btn btn-sm btn-outline-secondary template-person-builder__automatic"
                                            id="useAutomaticGroupsBtn" disabled>
                                        <i class="icon-base bx bx-refresh icon-sm me-1" aria-hidden="true"></i>
                                        Use automatic row detection
                                    </button>
                                </div>

                                <ul class="list-unstyled marker-field-list template-builder-field-list mb-3"
                                    id="fieldList"></ul>

                                <label class="form-label small fw-medium" for="newFieldName">Add another field</label>
                                <div class="input-group">
                                    <input type="text" id="newFieldName" class="form-control"
                                           maxlength="120" placeholder="Field name">
                                    <button class="btn btn-outline-primary" type="button" id="addFieldBtn">
                                        <i class="icon-base bx bx-plus icon-sm me-1" aria-hidden="true"></i>Add
                                    </button>
                                </div>

                                <p class="document-tip mt-3 mb-0">
                                    <i class="icon-base bx bx-info-circle icon-xs" aria-hidden="true"></i>
                                    <span>Keep each marker tight around one handwritten value. The sample is stored privately with this layout after you save.</span>
                                </p>
                            </div>
                        </section>

                        <section class="card template-field-settings-card mb-3 d-none" id="fieldSettingsCard"
                                 aria-labelledby="fieldSettingsHeading">
                            <div class="card-header">
                                <h2 class="card-title h5 mb-0" id="fieldSettingsHeading">Field settings</h2>
                                <small class="text-muted d-block text-truncate" id="fieldSettingsName"></small>
                            </div>
                            <div class="card-body">
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

                        <section class="card template-ledger-card mb-3" aria-labelledby="ledgerGridHeading">
                            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                                <div>
                                    <h2 class="card-title h5 mb-0" id="ledgerGridHeading">Ledger grid</h2>
                                    <small class="text-muted">For ruled register books.</small>
                                </div>
                                <span class="badge bg-label-secondary" id="ledgerGridBadge">None</span>
                            </div>
                            <div class="card-body">
                                <p class="template-ledger-card__intro">
                                    Name each column and record every printed row line. Staff scans are then
                                    outlined line by line, so handwriting that drifts across a rule, or a capital
                                    that reaches into the next row, is read whole.
                                </p>

                                <div class="template-ledger-card__actions">
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="detectGridBtn">
                                        <i class="icon-base bx bx-grid-alt icon-sm me-1" aria-hidden="true"></i>
                                        Detect from sample
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="makeColumnsBtn" disabled>
                                        <i class="icon-base bx bx-columns icon-sm me-1" aria-hidden="true"></i>
                                        Make selected columns
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="makeFieldsBtn" disabled
                                            title="Turn the selected columns back into fields read as one rectangle">
                                        <i class="icon-base bx bx-rectangle icon-sm me-1" aria-hidden="true"></i>
                                        Make selected fields
                                    </button>
                                </div>

                                <div class="template-ledger-rows" role="group" aria-labelledby="ledgerRowsHeading">
                                    <h3 class="template-ledger-rows__heading" id="ledgerRowsHeading">Row lines</h3>
                                    <div class="template-ledger-rows__tools">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="addRuledLineBtn"
                                                title="Add a line under the selected one, or at the bottom">
                                            <i class="icon-base bx bx-plus icon-sm me-1" aria-hidden="true"></i>
                                            Add
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="removeRuledLineBtn" disabled
                                                title="Remove the selected row line">
                                            <i class="icon-base bx bx-minus icon-sm me-1" aria-hidden="true"></i>
                                            Remove
                                        </button>
                                        <div class="input-group input-group-sm template-ledger-rows__even">
                                            <input type="number" class="form-control" id="evenRowsInput" min="1" max="399" step="1"
                                                   inputmode="numeric" aria-label="Number of rows">
                                            <button type="button" class="btn btn-outline-secondary" id="evenRowsBtn" disabled
                                                    title="Space this many rows evenly between the first and the last line">
                                                rows, evenly
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <p class="template-ledger-card__summary" id="ledgerGridSummary" aria-live="polite"></p>

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

                                <div class="template-ledger-card__footer">
                                    <p class="document-tip mb-0">
                                        <i class="icon-base bx bx-info-circle icon-xs" aria-hidden="true"></i>
                                        <span>Click a row line to select it, then drag it or press the arrow keys.
                                            <kbd>Shift</kbd> + drag moves every line; lines snap to the sample's printed
                                            rules (<kbd>Alt</kbd>: place freely). Moving all the columns moves their rows.</span>
                                    </p>
                                    <button type="button" class="btn btn-sm btn-outline-danger" id="clearGridBtn" disabled>
                                        <i class="icon-base bx bx-trash icon-sm me-1" aria-hidden="true"></i>
                                        Remove grid
                                    </button>
                                </div>
                            </div>
                        </section>

                        <div class="template-builder-error d-none" id="builderError" role="alert" aria-live="assertive">
                            <i class="icon-base bx bx-error" aria-hidden="true"></i>
                            <span id="builderErrorMessage"></span>
                        </div>

                        <div class="template-builder-save-panel">
                            @if ($inUse)
                                <p class="template-builder-live-note">
                                    <i class="icon-base bx bx-info-circle icon-xs" aria-hidden="true"></i>
                                    Changed markers are saved as a new version; this one stays as it is.
                                </p>
                                <button type="submit" class="btn btn-outline-secondary" data-publish="0">
                                    Save as new draft
                                </button>
                                <button type="submit" class="btn btn-primary" data-publish="1">
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

    <div class="modal fade" id="layoutTestModal" tabindex="-1" aria-labelledby="layoutTestTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title h5" id="layoutTestTitle">Test on the sample</h2>
                        <small class="text-muted">What Detect makes of the sample with this layout, saved or not. Nothing is read or stored.</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="layout-test__status" id="layoutTestStatus" role="status" aria-live="polite">
                        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                        <span id="layoutTestStatusText">Outlining every line on the sample. This takes about half a minute.</span>
                    </div>
                    <div class="layout-test d-none" id="layoutTestResult">
                        <p class="layout-test__summary" id="layoutTestSummary"></p>
                        <ul class="layout-test__findings" id="layoutTestFindings"></ul>
                        <div class="layout-test__legend" aria-hidden="true">
                            <span class="is-placed">In a row</span>
                            <span class="is-field">Field</span>
                            <span class="is-shared">Shares a cell</span>
                            <span class="is-no-row">Between rows</span>
                            <span class="is-rule">Row line, fitted</span>
                        </div>
                        <div class="layout-test__page">
                            <canvas id="layoutTestCanvas" role="img" aria-label="The sample with every outlined line"></canvas>
                        </div>
                    </div>
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
                        Restore the markers, person rows, paper size, and orientation that were loaded when this editor opened. Added and copied fields will be removed.
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
