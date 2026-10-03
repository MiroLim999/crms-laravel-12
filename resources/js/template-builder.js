import {
    FieldMarker,
    markerAngleMetadata,
    markerFieldSettings,
    markerInsidePage,
    normaliseAngle,
} from './field-marker';
import {
    clampRuledY,
    columnBand,
    distributeRuledYs,
    fieldsOverLedger,
    followFirstAndLastLine,
    insertRuledY,
    isColumn,
    ledgerGridProblems,
    nearestLine,
    remapRuledYs,
    sameBand,
    shiftRuledYs,
} from './ledger-grid';
import { attachMarqueeSelection } from './marquee-selection';
import {
    nonNegativeInteger,
    positiveInteger,
    templatePersonPayload,
} from './person-grouping';
import {
    builderShortcutIsBlocked,
    handleSelectedFieldDeletion,
} from './template-builder-shortcuts';
import { LayoutHistory } from './template-history';
import { summariseTest, testFindings, testLineStatus } from './layout-test';
import { gridNotes } from './line-geometry';

const configNode = document.getElementById('templateBuilderConfig');

if (!(configNode instanceof HTMLScriptElement)) {
    throw new Error('Template Builder configuration is missing.');
}

const config = JSON.parse(configNode.textContent || '{}');
const maxFields = Number(config.maxFields) || 450;
const maxFieldNameLength = Number(config.maxFieldNameLength) || 500;

function element(id, Type = HTMLElement) {
    const node = document.getElementById(id);

    if (!(node instanceof Type)) {
        throw new Error(`Template Builder element #${id} is missing.`);
    }

    return node;
}

const form = element('templateBuilderForm', HTMLFormElement);
const canvas = element('pageCanvas', HTMLCanvasElement);
const overlay = element('fieldOverlay');
const viewport = element('docViewport');
const fileInput = element('sampleScan', HTMLInputElement);
const fileLabel = element('sampleScanLabel');
const selectAllInput = element('selectAllFields', HTMLInputElement);
const newFieldInput = element('newFieldName', HTMLInputElement);
const publishIntent = element('publishIntent', HTMLInputElement);
const groupingModeInput = element('groupingMode', HTMLInputElement);
const paperSizeSelect = element('paper_size', HTMLSelectElement);
const customPaperFields = element('customPaperSizeFields');
const customWidthInput = element('custom_width_mm', HTMLInputElement);
const customHeightInput = element('custom_height_mm', HTMLInputElement);
const sampleSizePanel = element('samplePageSize');
const useSampleSizeButton = element('useSampleSizeBtn', HTMLButtonElement);
const paperSizes = new Map((config.paperSizes ?? []).map((size) => [size.value, size]));
const baselinePaperSize = String(config.baselinePaperSize ?? 'letter');
const baselineOrientation = String(config.baselineOrientation ?? 'portrait');
const baselineCustomWidth = finiteNumber(config.baselineCustomWidth, 210);
const baselineCustomHeight = finiteNumber(config.baselineCustomHeight, 297);
const baselineGroupingMode = config.baselineGroupingMode === 'custom' ? 'custom' : 'auto';
const initialGroupingMode = config.initialGroupingMode === 'custom' ? 'custom' : 'auto';

const cloneBoxes = (boxes) => boxes.map(({
    name, x, y, w, h, personGroup = null, personFieldOrder = null, kind = null, angle = 0, ...settings
}) => ({
    name,
    x,
    y,
    w,
    h,
    personGroup,
    personFieldOrder,
    ...(kind === 'column' ? { kind } : {}),
    ...markerAngleMetadata({ angle }),
    ...markerFieldSettings(settings),
}));

/** A saved field's or column's settings, as the server sends them. */
const savedSettings = (item) => markerFieldSettings({
    role: item.role,
    required: item.required ?? item.is_required,
    type: item.type ?? item.value_type,
    options: item.options,
    hint: item.hint,
});
const snapshot = (boxes) => JSON.stringify(cloneBoxes(boxes));

function finiteNumber(value, fallback) {
    const number = Number(value);
    return Number.isFinite(number) ? number : fallback;
}

function normaliseBoxes(fields) {
    if (!Array.isArray(fields)) return [];

    return fields.map((field, index) => {
        const x = Math.min(0.99, Math.max(0, finiteNumber(field.x, 0.08)));
        const y = Math.min(0.99, Math.max(0, finiteNumber(field.y, 0.08)));
        const width = Math.min(1 - x, Math.max(0.01, finiteNumber(field.width ?? field.w, 0.35)));
        const height = Math.min(1 - y, Math.max(0.01, finiteNumber(field.height ?? field.h, 0.06)));
        const personGroup = positiveInteger(field.personGroup ?? field.person_group);

        return {
            name: String(field.name ?? `Field ${index + 1}`),
            x,
            y,
            w: width,
            h: height,
            personGroup,
            personFieldOrder: personGroup === null
                ? null
                : nonNegativeInteger(field.personFieldOrder ?? field.person_field_order),
            ...markerAngleMetadata(field),
            ...savedSettings(field),
        };
    });
}

/** Ledger columns become ordinary markers flagged as columns. */
function normaliseColumns(columns) {
    if (!Array.isArray(columns)) return [];

    return columns.map((column, index) => {
        const [bx, by, bw, bh] = Array.isArray(column.box) ? column.box : [];
        const x = Math.min(0.99, Math.max(0, finiteNumber(bx, 0.1)));
        const y = Math.min(0.99, Math.max(0, finiteNumber(by, 0.1)));
        return {
            name: String(column.name ?? `Column ${index + 1}`),
            x,
            y,
            w: Math.min(1 - x, Math.max(0.01, finiteNumber(bw, 0.1))),
            h: Math.min(1 - y, Math.max(0.01, finiteNumber(bh, 0.5))),
            personGroup: null,
            personFieldOrder: null,
            kind: 'column',
            ...markerAngleMetadata(column),
            ...savedSettings(column),
        };
    });
}

function normaliseRuledYs(values) {
    if (!Array.isArray(values)) return [];
    return values
        .map((value) => finiteNumber(value, NaN))
        .filter((value) => value >= 0 && value <= 1)
        .sort((a, b) => a - b);
}

const baselineBoxes = [
    ...normaliseBoxes(config.baselineFields),
    ...normaliseColumns(config.baselineColumns),
];
const initialBoxes = [
    ...normaliseBoxes(config.initialFields),
    ...normaliseColumns(config.initialColumns),
];
const baselineRuledYs = normaliseRuledYs(config.baselineRuledYs);
// Printed row lines of a ruled register, page fractions, top to bottom.
let ruledYs = normaliseRuledYs(config.initialRuledYs);
groupingModeInput.value = initialGroupingMode;

// Markers, person-row mode and row lines, undone together (Ctrl+Z).
const history = new LayoutHistory();
let restoringHistory = false;
// Ledger grid state (see "ledger grid" below).
let coveredFieldIndexes = [];
let tableBand = null;
let tableColumnCount = 0;
let selectedRule = null;
let printedLines = null;
let estimatedYs = new Set();
let sampleGrid = null;
let clipboard = [];
let pasteSequence = 0;
let invalidFieldIndexes = new Set();
let sampleLoaded = false;
let sampleMeasurement = null;
// Keep the exact file represented by the preview. Some browsers can display a
// dropped/selected File even when their file input is later reconstructed or
// loses its FileList, which used to produce a saved layout with no sample.
let pendingSampleFile = null;

function selectedOrientation() {
    const input = form.querySelector('input[name="orientation"]:checked');
    return input instanceof HTMLInputElement ? input.value : 'portrait';
}

function selectedPaper() {
    const selected = paperSizes.get(paperSizeSelect.value) ?? paperSizes.values().next().value;

    if (paperSizeSelect.value !== 'custom') return selected;

    const width = Math.min(2000, Math.max(50, finiteNumber(customWidthInput.value, 210)));
    const height = Math.min(2000, Math.max(50, finiteNumber(customHeightInput.value, 297)));

    return {
        ...selected,
        width,
        height,
        dimensionsLabel: `${formatDimension(width)} × ${formatDimension(height)} mm`,
    };
}

function formatDimension(value) {
    return Number(value.toFixed(2)).toString();
}

function renderSampleMeasurement(measurement) {
    sampleMeasurement = measurement;
    sampleSizePanel.classList.remove('d-none');

    const physicalSize = element('samplePhysicalSize');
    const pixelSize = element('samplePixelSize');
    const note = element('sampleSizeNote');
    const hasPhysicalSize = Number.isFinite(measurement?.widthMm)
        && Number.isFinite(measurement?.heightMm);

    pixelSize.textContent = measurement?.kind === 'pdf'
        ? `${measurement.widthPx.toLocaleString()} × ${measurement.heightPx.toLocaleString()} px rendered preview`
        : `${measurement.widthPx.toLocaleString()} × ${measurement.heightPx.toLocaleString()} px image`;

    if (!hasPhysicalSize) {
        physicalSize.textContent = 'Physical size unavailable';
        note.textContent = 'The pixel size is exact. This image has no usable DPI metadata, so millimetres would only be a guess.';
        useSampleSizeButton.disabled = true;
        return;
    }

    const width = Number(measurement.widthMm);
    const height = Number(measurement.heightMm);
    const withinRange = width >= 50 && width <= 2000 && height >= 50 && height <= 2000;
    physicalSize.textContent = `${formatDimension(width)} × ${formatDimension(height)} mm`;
    note.textContent = measurement.kind === 'pdf'
        ? `Exact PDF page size${measurement.pageCount > 1 ? ` · page 1 of ${measurement.pageCount}` : ''}.`
        : `Calculated from ${measurement.physicalSource}.`;
    useSampleSizeButton.disabled = !withinRange;
}

function useSampleAsCustomSize() {
    if (!sampleMeasurement || !Number.isFinite(sampleMeasurement.widthMm)
        || !Number.isFinite(sampleMeasurement.heightMm)) return;

    const sampleWidth = Number(sampleMeasurement.widthMm);
    const sampleHeight = Number(sampleMeasurement.heightMm);
    const landscape = sampleWidth > sampleHeight;

    paperSizeSelect.value = 'custom';
    customWidthInput.value = formatDimension(landscape ? sampleHeight : sampleWidth);
    customHeightInput.value = formatDimension(landscape ? sampleWidth : sampleHeight);

    const orientation = form.querySelector(
        `input[name="orientation"][value="${landscape ? 'landscape' : 'portrait'}"]`,
    );
    if (orientation instanceof HTMLInputElement) orientation.checked = true;

    handlePaperSettingChange();
    customWidthInput.focus();
}

function expectedPaper() {
    const paper = selectedPaper();
    const orientation = selectedOrientation();
    const portraitWidth = finiteNumber(paper?.width, 215.9);
    const portraitHeight = finiteNumber(paper?.height, 279.4);
    const landscape = orientation === 'landscape';
    const width = landscape ? portraitHeight : portraitWidth;
    const height = landscape ? portraitWidth : portraitHeight;

    return {
        paper,
        orientation,
        width,
        height,
        ratio: width / height,
    };
}

function resizeBlankCanvas() {
    const { ratio } = expectedPaper();
    const longEdge = 1600;

    if (ratio >= 1) {
        canvas.width = longEdge;
        canvas.height = Math.round(longEdge / ratio);
    } else {
        canvas.height = longEdge;
        canvas.width = Math.round(longEdge * ratio);
    }
}

function drawBlankPage() {
    const context = canvas.getContext('2d');
    if (!context) throw new Error('The document preview could not be created.');

    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.strokeStyle = '#d9dee3';
    context.lineWidth = 2;
    context.strokeRect(1, 1, canvas.width - 2, canvas.height - 2);
}

resizeBlankCanvas();
drawBlankPage();

const marker = new FieldMarker({
    canvas,
    overlay,
    viewport,
    onChange: handleMarkerChange,
    onSelectionChange: updateSelectionUI,
    onZoomChange: updateZoomUI,
    onDrag: (boxes) => previewTableFollow(boxes),
    fieldSettings: true,
});
attachMarqueeSelection({
    marker,
    overlay,
    marquee: element('fieldSelectionMarquee'),
});

function layoutMatchesBaseline() {
    const customDimensionsMatch = paperSizeSelect.value !== 'custom'
        || (finiteNumber(customWidthInput.value, 210) === baselineCustomWidth
            && finiteNumber(customHeightInput.value, 297) === baselineCustomHeight);

    return snapshot(marker.toJSON()) === snapshot(baselineBoxes)
        && JSON.stringify(ruledYs) === JSON.stringify(baselineRuledYs)
        && groupingModeInput.value === baselineGroupingMode
        && paperSizeSelect.value === baselinePaperSize
        && selectedOrientation() === baselineOrientation
        && customDimensionsMatch;
}

function updateResetUI() {
    const changed = !layoutMatchesBaseline() || Math.abs(marker.zoom - 1) > 0.001;
    element('resetFieldsBtn', HTMLButtonElement).disabled = !changed;
}

/** Everything one undo step restores. */
function layoutState(boxes = marker.toJSON()) {
    return {
        boxes: cloneBoxes(boxes),
        groupingMode: groupingModeInput.value === 'custom' ? 'custom' : 'auto',
        ruledYs: [...ruledYs],
    };
}

function handleMarkerChange(boxes) {
    // Before recording: carrying the row lines along is part of this change.
    followTable(boxes);
    if (!restoringHistory) history.record(layoutState(boxes));
    renderFieldList(boxes);
    renderLedgerGrid();
    updateLedgerChecks(boxes);
    updateResetUI();
}

function personGroups(boxes) {
    const groups = new Map();

    boxes.forEach((box, index) => {
        const key = positiveInteger(box.personGroup);
        if (key === null) return;
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(index);
    });

    return [...groups.entries()]
        .sort(([left], [right]) => left - right)
        .map(([key, indexes], displayIndex) => ({
            key,
            displayNumber: displayIndex + 1,
            indexes: indexes.sort((left, right) => {
                const leftOrder = nonNegativeInteger(boxes[left].personFieldOrder);
                const rightOrder = nonNegativeInteger(boxes[right].personFieldOrder);
                return (leftOrder ?? left) - (rightOrder ?? right) || left - right;
            }),
        }));
}

function displayPersonGroup(boxes, key) {
    const group = personGroups(boxes).find((candidate) => candidate.key === positiveInteger(key));
    return group?.displayNumber ?? null;
}

function selectPersonGroup(indexes) {
    const firstIndex = indexes[0];
    marker.selectIndexes(indexes, { source: 'group' });

    if (!Number.isInteger(firstIndex)) return;
    // Selection highlighting is synchronous, but wait for the browser's next
    // layout pass before measuring the corresponding field row. Keep this
    // navigation inside the field list so clicking a Person row never moves the
    // surrounding side panel or page.
    window.requestAnimationFrame(() => {
        centerFieldListRow(firstIndex, { centerPanel: false });
    });
}

function removePersonGroup(key) {
    const boxes = marker.toJSON().map((box) => (
        positiveInteger(box.personGroup) === key
            ? { ...box, personGroup: null, personFieldOrder: null }
            : box
    ));

    groupingModeInput.value = 'custom';
    marker.setBoxes(boxes);
    clearBuilderError();
}

function renderPersonGroups(boxes) {
    const custom = groupingModeInput.value === 'custom';
    const groups = personGroups(boxes);
    const list = element('personGroupList');
    const modeBadge = element('groupingModeBadge');
    const automaticButton = element('useAutomaticGroupsBtn', HTMLButtonElement);

    modeBadge.textContent = custom ? 'Custom' : 'Automatic';
    modeBadge.classList.toggle('bg-label-primary', custom);
    modeBadge.classList.toggle('bg-label-secondary', !custom);
    automaticButton.disabled = !custom;
    list.replaceChildren();

    if (!custom) {
        const note = document.createElement('p');
        note.className = 'template-person-group-list__empty';
        note.textContent = 'Staff validation will detect repeated rows from the marker positions.';
        list.appendChild(note);
        return;
    }

    if (groups.length === 0) {
        const note = document.createElement('p');
        note.className = 'template-person-group-list__empty';
        note.textContent = 'No person rows. All fields will appear under document details.';
        list.appendChild(note);
        return;
    }

    groups.forEach((group) => {
        const row = document.createElement('div');
        row.className = 'template-person-group';

        const selectButton = document.createElement('button');
        selectButton.type = 'button';
        selectButton.className = 'btn btn-outline-secondary border-0 rounded-0 template-person-group__select';
        selectButton.setAttribute(
            'aria-label',
            `Select Person ${String(group.displayNumber).padStart(2, '0')}`,
        );

        const icon = document.createElement('span');
        icon.className = 'template-person-group__number';
        icon.textContent = String(group.displayNumber).padStart(2, '0');

        const copy = document.createElement('span');
        copy.className = 'template-person-group__copy';
        const label = document.createElement('strong');
        label.textContent = `Person ${String(group.displayNumber).padStart(2, '0')}`;
        const count = document.createElement('small');
        count.textContent = `${group.indexes.length} field${group.indexes.length === 1 ? '' : 's'} in this row`;
        copy.append(label, count);
        selectButton.append(icon, copy);
        selectButton.addEventListener('click', () => selectPersonGroup(group.indexes));

        const removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.className = 'btn btn-sm btn-icon btn-outline-danger border-0 rounded-0 template-person-group__remove';
        removeButton.setAttribute(
            'aria-label',
            `Remove Person ${String(group.displayNumber).padStart(2, '0')} group`,
        );
        removeButton.title = 'Remove group and keep its fields';
        const removeIcon = document.createElement('i');
        removeIcon.className = 'icon-base bx bx-x icon-sm';
        removeIcon.setAttribute('aria-hidden', 'true');
        removeButton.appendChild(removeIcon);
        removeButton.addEventListener('click', () => removePersonGroup(group.key));

        row.append(selectButton, removeButton);
        list.appendChild(row);
    });
}

function createFieldRow(index) {
    const item = document.createElement('li');
    item.className = 'field-list-item template-builder-field-item';
    item.dataset.fieldIndex = String(index);

    const number = document.createElement('span');
    number.className = 'badge bg-label-primary template-builder-field-number';
    item.appendChild(number);

    const input = document.createElement('input');
    input.type = 'text';
    input.maxLength = maxFieldNameLength;
    input.className = 'form-control form-control-sm template-builder-field-name';
    input.setAttribute('aria-label', `Field ${index + 1} name`);
    item.appendChild(input);

    const tags = document.createElement('span');
    tags.className = 'template-builder-field-tags d-none';
    item.appendChild(tags);

    const groupBadge = document.createElement('span');
    groupBadge.className = 'template-builder-field-group d-none';
    item.appendChild(groupBadge);

    const removeButton = document.createElement('button');
    removeButton.type = 'button';
    removeButton.className = 'btn btn-sm btn-icon btn-outline-danger rounded-pill';
    removeButton.setAttribute('aria-label', `Remove field ${index + 1}`);

    const removeIcon = document.createElement('i');
    removeIcon.className = 'icon-base bx bx-trash icon-sm';
    removeIcon.setAttribute('aria-hidden', 'true');
    removeButton.appendChild(removeIcon);
    item.appendChild(removeButton);

    item.addEventListener('pointerdown', (event) => {
        if (event.shiftKey && !(event.target instanceof Element && event.target.closest('button'))) {
            // Shift-click is a marker selection gesture, not native text selection.
            event.preventDefault();
        }
    });

    item.addEventListener('click', (event) => {
        if (event.target instanceof Element && event.target.closest('button')) return;
        if (event.target === input && !event.shiftKey) return;

        const currentIndex = Number(item.dataset.fieldIndex);
        marker.selectBox(currentIndex, {
            additive: event.shiftKey,
            toggle: event.shiftKey,
        });
    });

    input.addEventListener('focus', () => {
        marker.selectBox(Number(item.dataset.fieldIndex));
    });

    input.addEventListener('input', () => {
        const currentIndex = Number(item.dataset.fieldIndex);
        invalidFieldIndexes.delete(currentIndex);
        input.classList.remove('is-invalid');
        clearBuilderError();
        marker.renameBox(currentIndex, input.value);
    });

    removeButton.addEventListener('click', (event) => {
        event.stopPropagation();
        marker.removeBox(Number(item.dataset.fieldIndex));
    });

    return item;
}

function renderFieldList(boxes) {
    const list = element('fieldList');
    let items = [...list.querySelectorAll('.template-builder-field-item')];

    if (items.length !== boxes.length) {
        list.replaceChildren();

        if (boxes.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'marker-field-empty';

            const icon = document.createElement('i');
            icon.className = 'icon-base bx bx-list-check';
            icon.setAttribute('aria-hidden', 'true');

            const text = document.createElement('span');
            text.textContent = 'No fields yet. Add one below.';
            empty.append(icon, text);
            list.appendChild(empty);
        } else {
            boxes.forEach((_, index) => list.appendChild(createFieldRow(index)));
        }

        items = [...list.querySelectorAll('.template-builder-field-item')];
    }

    items.forEach((item, index) => {
        const box = boxes[index];
        item.dataset.fieldIndex = String(index);

        const number = item.querySelector('.template-builder-field-number');
        const input = item.querySelector('.template-builder-field-name');
        const groupBadge = item.querySelector('.template-builder-field-group');
        const removeButton = item.querySelector('button');

        if (number) number.textContent = String(index + 1);
        if (input instanceof HTMLInputElement) {
            if (document.activeElement !== input) input.value = box.name;
            input.classList.toggle('is-invalid', invalidFieldIndexes.has(index));
            input.setAttribute('aria-label', `Field ${index + 1} name`);
        }
        if (groupBadge instanceof HTMLElement) {
            const group = displayPersonGroup(boxes, box.personGroup);
            if (box.kind === 'column') {
                groupBadge.classList.remove('d-none');
                groupBadge.classList.add('is-column');
                groupBadge.textContent = 'Col';
                groupBadge.title = 'Ledger column: read line by line inside the ruled rows';
            } else {
                groupBadge.classList.remove('is-column');
                groupBadge.classList.toggle('d-none', group === null);
                groupBadge.textContent = group === null ? '' : `P${String(group).padStart(2, '0')}`;
                groupBadge.title = group === null ? '' : `Person ${String(group).padStart(2, '0')}`;
            }
        }
        const tags = item.querySelector('.template-builder-field-tags');
        if (tags instanceof HTMLElement) {
            const text = settingsTags(box);
            tags.textContent = text;
            tags.title = text ? `${box.name}: ${text}` : '';
            tags.classList.toggle('d-none', text === '');
        }
        removeButton?.setAttribute('aria-label', `Remove field ${index + 1}`);
    });

    element('fieldCount').textContent = `${boxes.length} field${boxes.length === 1 ? '' : 's'}`;
    selectAllInput.disabled = boxes.length === 0;
    renderPersonGroups(boxes);
    updateSelectionUI(marker.selectedIndexes());
}

const ROLE_LABELS = { name: 'Name', entry: 'Entry no.' };
const TYPE_LABELS = { date: 'Date', number: 'Number', choice: 'List' };

/** A marker's settings in a few words for the field list: "Name · Date · optional". */
function settingsTags(box) {
    const settings = markerFieldSettings(box);
    return [
        ROLE_LABELS[settings.role],
        TYPE_LABELS[settings.type],
        settings.required === false ? 'optional' : null,
    ].filter(Boolean).join(' · ');
}

function centerFieldListRow(index, { centerPanel = true } = {}) {
    const list = element('fieldList');
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
    // Apply the inner scroll immediately. Calculating the outer panel while a
    // smooth inner scroll is still moving can leave distant rows off-screen.
    list.scrollTop = clampedListTarget;

    if (!centerPanel) return;

    const panel = list.closest('.template-builder-side-panel');
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
    const selected = new Set(indexes);
    if (indexes.length > 0 && selectedRule !== null) {
        selectedRule = null;
        renderLedgerGrid();
    }
    updateLedgerButtons(indexes);
    renderFieldSettings(indexes);

    document.querySelectorAll('#fieldList .template-builder-field-item').forEach((item) => {
        item.classList.toggle('is-selected', selected.has(Number(item.dataset.fieldIndex)));
    });

    const count = indexes.length;
    const total = marker.toJSON().length;
    const summary = element('selectionSummary');
    const summaryText = summary.querySelector('span');
    if (summaryText) summaryText.textContent = `${count} selected`;
    summary.classList.toggle('is-active', count > 0);

    selectAllInput.checked = total > 0 && count === total;
    selectAllInput.indeterminate = count > 0 && count < total;
    element('deleteSelectedBtn', HTMLButtonElement).disabled = count === 0;
    element('deleteFieldsBtn', HTMLButtonElement).disabled = count === 0;
    element('groupSelectedBtn', HTMLButtonElement).disabled = count === 0;
    element('ungroupSelectedBtn', HTMLButtonElement).disabled = !indexes.some((index) => (
        positiveInteger(marker.toJSON()[index]?.personGroup) !== null
    ));

    if (context.source === 'marker' && Number.isInteger(context.activeIndex)) {
        centerFieldListRow(context.activeIndex);
    }
}

function groupSelectedAsPerson() {
    const selected = marker.selectedIndexes();
    if (selected.length === 0) return;

    const boxes = marker.toJSON();
    const nextGroup = Math.max(0, ...boxes.map((box) => positiveInteger(box.personGroup) ?? 0)) + 1;
    const ordered = [...selected].sort((left, right) => (
        boxes[left].x - boxes[right].x
        || boxes[left].y - boxes[right].y
        || left - right
    ));

    ordered.forEach((index, order) => {
        boxes[index] = {
            ...boxes[index],
            personGroup: nextGroup,
            personFieldOrder: order,
        };
    });

    groupingModeInput.value = 'custom';
    marker.setBoxes(boxes);
    marker.selectIndexes(selected, { source: 'group' });
    clearBuilderError();
}

function ungroupSelectedFields() {
    const selected = marker.selectedIndexes();
    if (selected.length === 0) return;

    const selectedSet = new Set(selected);
    const boxes = marker.toJSON().map((box, index) => (
        selectedSet.has(index)
            ? { ...box, personGroup: null, personFieldOrder: null }
            : box
    ));

    groupingModeInput.value = 'custom';
    marker.setBoxes(boxes);
    marker.selectIndexes(selected, { source: 'group' });
    clearBuilderError();
}

function useAutomaticPersonDetection() {
    const boxes = marker.toJSON().map((box) => ({
        ...box,
        personGroup: null,
        personFieldOrder: null,
    }));

    groupingModeInput.value = 'auto';
    marker.setBoxes(boxes);
    clearBuilderError();
}

// ------------------------------------------------------------- field settings
//
// What the selected fields hold and how Staff check their values in Verify.
// With several selected, a change applies to all of them (the role only fits
// one field per person, so it is set one field at a time).

const fieldRole = element('fieldRole', HTMLSelectElement);
const fieldType = element('fieldType', HTMLSelectElement);
const fieldOptions = element('fieldOptions', HTMLInputElement);
const fieldHint = element('fieldHint', HTMLInputElement);
const fieldRequired = element('fieldRequired', HTMLInputElement);

function renderFieldSettings(indexes = marker.selectedIndexes()) {
    const boxes = marker.toJSON();
    const selected = indexes.map((index) => boxes[index]).filter(Boolean);
    element('fieldSettingsCard').classList.toggle('d-none', selected.length === 0);
    if (selected.length === 0) return;

    const [first] = selected;
    const settings = markerFieldSettings(first);
    element('fieldSettingsName').textContent = selected.length === 1
        ? `${first.name}${isColumn(first) ? ' · ledger column' : ''}`
        : `${selected.length} selected: changes apply to all of them`;

    fieldRole.disabled = selected.length !== 1;
    fieldRole.value = selected.length === 1 ? (settings.role ?? '') : '';
    fieldType.value = settings.type ?? 'text';
    if (document.activeElement !== fieldOptions) fieldOptions.value = (settings.options ?? []).join(', ');
    if (document.activeElement !== fieldHint) fieldHint.value = settings.hint ?? '';
    fieldRequired.checked = settings.required !== false;
    element('fieldOptionsGroup').classList.toggle('d-none', fieldType.value !== 'choice');
}

/** Whether two markers are in the same person row: one name and one entry number each. */
function sameRow(a, b) {
    if (isColumn(a) || isColumn(b)) return isColumn(a) && isColumn(b);
    return groupingModeInput.value === 'custom'
        && (positiveInteger(a.personGroup) ?? null) === (positiveInteger(b.personGroup) ?? null);
}

function applyFieldSettings(patch) {
    const indexes = marker.selectedIndexes();
    if (indexes.length === 0) return;

    const boxes = marker.toJSON();
    const chosen = new Set(indexes);
    // Giving a field a role takes it from the field in the same row that had it.
    const holder = patch.role ? boxes[indexes[0]] : null;
    const affected = boxes
        .map((box, index) => index)
        .filter((index) => chosen.has(index)
            || (holder && boxes[index].role === patch.role && sameRow(boxes[index], holder)));

    marker.updateBoxes(affected, (box, index) => (chosen.has(index) ? patch : { role: null }));
    clearBuilderError();
}

const choiceList = (text) => text.split(',').map((option) => option.trim()).filter(Boolean);

fieldRole.addEventListener('change', () => applyFieldSettings({ role: fieldRole.value || null }));
fieldType.addEventListener('change', () => {
    element('fieldOptionsGroup').classList.toggle('d-none', fieldType.value !== 'choice');
    applyFieldSettings({ type: fieldType.value, options: choiceList(fieldOptions.value) });
});
fieldOptions.addEventListener('input', () => applyFieldSettings({ options: choiceList(fieldOptions.value) }));
fieldHint.addEventListener('input', () => applyFieldSettings({ hint: fieldHint.value }));
fieldRequired.addEventListener('change', () => applyFieldSettings({ required: fieldRequired.checked }));

function updateZoomUI(zoom) {
    element('zoomResetBtn', HTMLButtonElement).textContent = `${Math.round(zoom * 100)}%`;
    updateResetUI();
}

function showBuilderError(message) {
    element('builderErrorMessage').textContent = message;
    element('builderError').classList.remove('d-none');
}

function clearBuilderError() {
    element('builderError').classList.add('d-none');
    element('builderErrorMessage').textContent = '';
}

function updatePaperPreview() {
    const status = element('paperPreviewStatus');
    const icon = element('paperPreviewIcon');
    const title = element('paperPreviewTitle');
    const message = element('paperPreviewMessage');
    const { paper, orientation, ratio } = expectedPaper();
    const orientationLabel = orientation === 'landscape' ? 'Landscape' : 'Portrait';

    title.textContent = `${paper?.label ?? 'Paper'} · ${orientationLabel}`;
    status.classList.remove('is-warning', 'is-match');
    icon.classList.remove('bx-error', 'bx-check-circle');
    icon.classList.add('bx-file');

    if (!sampleLoaded) {
        message.textContent = `${paper?.dimensionsLabel ?? ''} blank preview. Upload a sample to check its page shape.`;
        return;
    }

    const actualRatio = canvas.width / canvas.height;
    const actualOrientation = actualRatio >= 1 ? 'landscape' : 'portrait';
    const orientationMismatch = actualOrientation !== orientation;
    const ratioDifference = Math.abs(actualRatio - ratio) / ratio;

    if (orientationMismatch || ratioDifference > 0.1) {
        status.classList.add('is-warning');
        icon.classList.remove('bx-file');
        icon.classList.add('bx-error');
        message.textContent = orientationMismatch
            ? `The sample looks ${actualOrientation}, but this template is ${orientation}. It will not be stretched.`
            : 'The sample proportions differ from this paper preset. Check the selected paper size before publishing.';
        return;
    }

    status.classList.add('is-match');
    icon.classList.remove('bx-file');
    icon.classList.add('bx-check-circle');
    message.textContent = 'The sample proportions are consistent with this template setting.';
}

function handlePaperSettingChange() {
    const customSelected = paperSizeSelect.value === 'custom';
    customPaperFields.classList.toggle('d-none', !customSelected);
    customWidthInput.required = customSelected;
    customHeightInput.required = customSelected;

    if (!sampleLoaded) {
        resizeBlankCanvas();
        drawBlankPage();
        marker.layout();
        window.requestAnimationFrame(() => marker.resetZoom());
    }

    updatePaperPreview();
    updateResetUI();
}

function undoLayoutChange() {
    const previous = history.undo();
    if (!previous) return;

    restoringHistory = true;
    groupingModeInput.value = previous.groupingMode;
    // Row lines first: restoring the markers redraws the grid from them.
    ruledYs = normaliseRuledYs(previous.ruledYs);
    selectedRule = null;
    marker.setBoxes(cloneBoxes(previous.boxes));
    restoringHistory = false;
    history.sync(layoutState());
    clearBuilderError();
}

function copySelectedFields() {
    const boxes = marker.toJSON();
    clipboard = marker.selectedIndexes().map((index) => ({ ...boxes[index] }));
    pasteSequence = 0;

    const summaryText = element('selectionSummary').querySelector('span');
    if (summaryText && clipboard.length > 0) {
        summaryText.textContent = `${clipboard.length} copied`;
    }
}

function nextCopyName(name, takenNames) {
    const base = name.trim() || 'Field';
    let suffix = 1;
    let tail = ' copy';
    let candidate = `${base.slice(0, maxFieldNameLength - tail.length)}${tail}`;

    while (takenNames.has(candidate.toLocaleLowerCase())) {
        suffix += 1;
        tail = ` copy ${suffix}`;
        candidate = `${base.slice(0, maxFieldNameLength - tail.length)}${tail}`;
    }

    takenNames.add(candidate.toLocaleLowerCase());
    return candidate;
}

function pasteCopiedFields() {
    if (clipboard.length === 0) return;

    const existing = marker.toJSON();
    if (existing.length + clipboard.length > maxFields) {
        showBuilderError(`A layout can contain at most ${maxFields} fields.`);
        return;
    }

    const minX = Math.min(...clipboard.map((box) => box.x));
    const minY = Math.min(...clipboard.map((box) => box.y));
    const maxX = Math.max(...clipboard.map((box) => box.x + box.w));
    const maxY = Math.max(...clipboard.map((box) => box.y + box.h));
    const distance = Math.min(0.1, 0.018 * (pasteSequence + 1));
    const dx = maxX + distance <= 1 ? distance : (minX - distance >= 0 ? -distance : 0);
    const dy = maxY + distance <= 1 ? distance : (minY - distance >= 0 ? -distance : 0);
    const takenNames = new Set(existing.map((box) => box.name.trim().toLocaleLowerCase()));
    // A pasted copy is an ordinary field, never a second ledger column.
    const copies = clipboard.map(({ kind, columnIndex, role, ...box }) => ({
        ...box,
        name: nextCopyName(box.name, takenNames),
        x: box.x + dx,
        y: box.y + dy,
        personGroup: null,
        personFieldOrder: null,
    }));
    const firstCopyIndex = existing.length;

    pasteSequence += 1;
    invalidFieldIndexes.clear();
    marker.setBoxes([...existing, ...copies]);
    copies.forEach((_, index) => marker.selectBox(firstCopyIndex + index, {
        additive: index > 0,
    }));
    clearBuilderError();
}

function addPendingField() {
    const name = newFieldInput.value.trim();
    if (!name) return true;

    const boxes = marker.toJSON();
    if (boxes.length >= maxFields) {
        showBuilderError(`A layout can contain at most ${maxFields} fields.`);
        return false;
    }

    if (boxes.some((box) => box.name.trim().toLocaleLowerCase() === name.toLocaleLowerCase())) {
        showBuilderError(`A field named “${name}” already exists.`);
        newFieldInput.focus();
        return false;
    }

    const offset = (boxes.length % 10) * 0.018;
    const width = 0.34;
    const height = 0.06;
    marker.addBox(name, {
        x: Math.min(1 - width, 0.08 + offset),
        y: Math.min(1 - height, 0.08 + offset),
        w: width,
        h: height,
    });
    newFieldInput.value = '';
    clearBuilderError();
    return true;
}

function validateFields() {
    const boxes = marker.toJSON();
    invalidFieldIndexes = new Set();

    if (boxes.length === 0) {
        showBuilderError('Add at least one field before saving this layout.');
        renderFieldList(boxes);
        return false;
    }

    if (boxes.length > maxFields) {
        showBuilderError(`A layout can contain at most ${maxFields} fields.`);
        return false;
    }

    const seen = new Map();
    boxes.forEach((box, index) => {
        const name = box.name.trim();
        const key = name.toLocaleLowerCase();

        if (!name || name.length > maxFieldNameLength) invalidFieldIndexes.add(index);
        if (seen.has(key)) {
            invalidFieldIndexes.add(index);
            invalidFieldIndexes.add(seen.get(key));
        } else if (name) {
            seen.set(key, index);
        }

        if (box.x < 0 || box.y < 0 || box.w < 0.01 || box.h < 0.01
            || box.x + box.w > 1.00001 || box.y + box.h > 1.00001
            || (normaliseAngle(box.angle) !== 0 && !markerInsidePage(box, canvas.width, canvas.height))) {
            invalidFieldIndexes.add(index);
        }
    });

    renderFieldList(boxes);

    if (invalidFieldIndexes.size > 0) {
        showBuilderError('Every field needs a unique name and a marker fully inside the document (a tilted marker\'s corners too).');
        const firstInvalid = element('fieldList').querySelector('.template-builder-field-name.is-invalid');
        firstInvalid?.focus();
        return false;
    }

    clearBuilderError();
    return true;
}

function serialiseFields() {
    const container = element('fieldInputs');
    container.replaceChildren();

    const boxes = marker.toJSON();
    const fields = boxes.filter((box) => !isColumn(box)).map((box) => ({
            name: box.name.trim(),
            x: box.x.toFixed(5),
            y: box.y.toFixed(5),
            width: box.w.toFixed(5),
            height: box.h.toFixed(5),
            angle: normaliseAngle(box.angle),
            ...templatePersonPayload(box, groupingModeInput.value === 'custom'),
            ...settingsPayload(box),
    }));
    // Left to right: a ledger row is read in column order.
    const columns = boxes
        .filter(isColumn)
        .sort((a, b) => a.x - b.x)
        .map((box) => ({
            name: box.name.trim(),
            box: [box.x, box.y, box.w, box.h].map((value) => Number(value.toFixed(5))),
            ...markerAngleMetadata(box),
            ...settingsPayload(box),
        }));

    // One JSON input each avoids PHP's max_input_vars truncating large layouts.
    [
        ['fields_json', fields],
        ['columns_json', columns],
        ['ruled_ys_json', columns.length > 0 ? ruledYs.map((y) => Number(y.toFixed(5))) : []],
    ].forEach(([name, value]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = JSON.stringify(value);
        container.appendChild(input);
    });
}

/** A marker's settings as the server takes them, defaults spelled out. */
function settingsPayload(box) {
    const settings = markerFieldSettings(box);
    return {
        role: settings.role ?? null,
        required: settings.required !== false,
        type: settings.type ?? 'text',
        options: settings.options ?? null,
        hint: settings.hint ?? null,
    };
}

async function openSample(file, { pendingUpload = true } = {}) {
    if (pendingUpload) pendingSampleFile = null;
    if (!file) return;

    if (file.size > 20 * 1024 * 1024) {
        showBuilderError('Choose a sample smaller than 20 MB.');
        fileInput.value = '';
        return;
    }

    fileLabel.classList.add('is-loading');
    fileLabel.setAttribute('aria-busy', 'true');
    clearBuilderError();

    try {
        const measurement = await marker.load(file);
        sampleLoaded = true;
        forgetSampleGrid();
        loadPrintedLines();
        if (pendingUpload) pendingSampleFile = file;
        element('sampleFileName').textContent = file.name;
        element('sampleHint').classList.add('d-none');
        renderSampleMeasurement(measurement);
        updatePaperPreview();
        window.requestAnimationFrame(() => marker.resetZoom());
    } catch (error) {
        fileInput.value = '';
        showBuilderError(error instanceof Error ? error.message : 'That sample could not be opened.');
    } finally {
        fileLabel.classList.remove('is-loading');
        fileLabel.removeAttribute('aria-busy');
    }
}

async function openStoredSample() {
    const storedSample = config.sample;
    if (!storedSample?.url) return;

    try {
        const response = await window.fetch(storedSample.url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        });

        if (!response.ok) throw new Error('The stored sample document could not be loaded.');

        const preview = await response.json();
        if (!preview?.data || typeof preview.data !== 'string') {
            throw new Error('The stored sample document response is invalid.');
        }

        const binary = window.atob(preview.data);
        const bytes = new Uint8Array(binary.length);
        for (let index = 0; index < binary.length; index += 1) {
            bytes[index] = binary.charCodeAt(index);
        }

        const mime = preview.mime || storedSample.mime || 'application/octet-stream';
        const blob = new Blob([bytes], { type: mime });
        const file = new File(
            [blob],
            preview.name || storedSample.originalName || 'stored-sample',
            { type: mime },
        );
        await openSample(file, { pendingUpload: false });
    } catch (error) {
        showBuilderError(error instanceof Error
            ? error.message
            : 'The stored sample document could not be loaded.');
    }
}

function selectDroppedSample(file) {
    if (!file) return;

    const transfer = new DataTransfer();
    transfer.items.add(file);
    fileInput.files = transfer.files;
    openSample(file);
}

element('zoomOutBtn', HTMLButtonElement).addEventListener('click', () => marker.zoomBy(-0.1));
element('zoomInBtn', HTMLButtonElement).addEventListener('click', () => marker.zoomBy(0.1));
element('zoomResetBtn', HTMLButtonElement).addEventListener('click', () => marker.resetZoom());

element('deleteSelectedBtn', HTMLButtonElement).addEventListener('click', () => marker.removeSelected());
element('deleteFieldsBtn', HTMLButtonElement).addEventListener('click', () => marker.removeSelected());
element('groupSelectedBtn', HTMLButtonElement).addEventListener('click', groupSelectedAsPerson);
element('ungroupSelectedBtn', HTMLButtonElement).addEventListener('click', ungroupSelectedFields);
element('useAutomaticGroupsBtn', HTMLButtonElement).addEventListener('click', useAutomaticPersonDetection);

selectAllInput.addEventListener('change', () => {
    if (selectAllInput.checked) marker.selectAll();
    else marker.clearSelection();
});

element('addFieldBtn', HTMLButtonElement).addEventListener('click', () => {
    if (addPendingField()) newFieldInput.focus();
});

newFieldInput.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    if (addPendingField()) newFieldInput.focus();
});

fileInput.addEventListener('change', () => openSample(fileInput.files?.[0]));
fileLabel.addEventListener('keydown', (event) => {
    if (!['Enter', ' '].includes(event.key)) return;
    event.preventDefault();
    fileInput.click();
});

['dragenter', 'dragover'].forEach((eventName) => {
    viewport.addEventListener(eventName, (event) => {
        event.preventDefault();
        viewport.classList.add('is-dragging');
    });
});

['dragleave', 'drop'].forEach((eventName) => {
    viewport.addEventListener(eventName, (event) => {
        event.preventDefault();
        viewport.classList.remove('is-dragging');
    });
});

viewport.addEventListener('drop', (event) => selectDroppedSample(event.dataTransfer?.files?.[0]));

paperSizeSelect.addEventListener('change', handlePaperSettingChange);
useSampleSizeButton.addEventListener('click', useSampleAsCustomSize);
[customWidthInput, customHeightInput].forEach((input) => {
    input.addEventListener('input', handlePaperSettingChange);
});
form.querySelectorAll('input[name="orientation"]').forEach((input) => {
    input.addEventListener('change', handlePaperSettingChange);
});

function restoreBaseline() {
    invalidFieldIndexes.clear();
    groupingModeInput.value = baselineGroupingMode;
    paperSizeSelect.value = baselinePaperSize;
    customWidthInput.value = String(baselineCustomWidth);
    customHeightInput.value = String(baselineCustomHeight);
    const orientation = form.querySelector(`input[name="orientation"][value="${baselineOrientation}"]`);
    if (orientation instanceof HTMLInputElement) orientation.checked = true;

    if (!sampleLoaded) {
        resizeBlankCanvas();
        drawBlankPage();
    }

    ruledYs = [...baselineRuledYs];
    selectedRule = null;
    estimatedYs = new Set();
    replaceBoxes(baselineBoxes);
    marker.resetZoom();
    viewport.scrollTo({ top: 0, left: 0 });
    updatePaperPreview();
    clearBuilderError();
}

handlePaperSettingChange();

element('resetFieldsBtn', HTMLButtonElement).addEventListener('click', () => {
    const layoutChanged = !layoutMatchesBaseline();
    const zoomChanged = Math.abs(marker.zoom - 1) > 0.001;
    if (!layoutChanged && !zoomChanged) return;

    if (!layoutChanged) {
        restoreBaseline();
        return;
    }

    window.bootstrap.Modal.getOrCreateInstance(element('resetFieldsModal')).show();
});

element('confirmResetFieldsBtn', HTMLButtonElement).addEventListener('click', () => {
    window.bootstrap.Modal.getInstance(element('resetFieldsModal'))?.hide();
    restoreBaseline();
});

document.addEventListener('keydown', (event) => {
    const shortcutOptions = {
        modalOpen: document.querySelector('.modal.show') !== null,
    };

    if (builderShortcutIsBlocked(event, shortcutOptions)) return;

    const commandPressed = event.ctrlKey || event.metaKey;
    const key = event.key.toLocaleLowerCase();

    if (commandPressed && !event.shiftKey && key === 'c' && marker.selectedIndexes().length > 0) {
        event.preventDefault();
        copySelectedFields();
        return;
    }

    if (commandPressed && !event.shiftKey && key === 'v' && clipboard.length > 0) {
        event.preventDefault();
        pasteCopiedFields();
        return;
    }

    if (commandPressed && !event.shiftKey && key === 'z' && history.canUndo) {
        event.preventDefault();
        undoLayoutChange();
        return;
    }

    // A selected row line: arrow keys move it (Shift: 10 px), Delete removes it.
    if (!commandPressed && !event.altKey && selectedRule !== null) {
        if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            event.preventDefault();
            nudgeRule((event.key === 'ArrowUp' ? -1 : 1) * (event.shiftKey ? 10 : 1));
            return;
        }
        if (event.key === 'Delete' || event.key === 'Backspace') {
            event.preventDefault();
            removeRule(selectedRule);
            return;
        }
        if (event.key === 'Escape') {
            selectRule(null);
            return;
        }
    }

    // Arrow keys move the selected markers one screen pixel (Shift: 10).
    const arrow = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[event.key];
    if (!commandPressed && !event.altKey && arrow && marker.selectedIndexes().length > 0) {
        event.preventDefault();
        const step = event.shiftKey ? 10 : 1;
        marker.nudgeSelected(arrow[0] * step, arrow[1] * step);
        return;
    }

    // [ and ] tilt the selection half a degree; with Shift, five degrees.
    if (!commandPressed && ['BracketLeft', 'BracketRight'].includes(event.code) && marker.selectedIndexes().length > 0) {
        event.preventDefault();
        marker.rotateSelected((event.code === 'BracketLeft' ? -1 : 1) * (event.shiftKey ? 5 : 0.5));
        return;
    }

    handleSelectedFieldDeletion(event, marker, shortcutOptions);
});

form.querySelectorAll('button[type="submit"][data-publish]').forEach((button) => {
    button.addEventListener('click', () => {
        publishIntent.value = button.dataset.publish === '1' ? '1' : '0';
    });
});

// ------------------------------------------------------------ leaving the page
//
// Closing the tab, going back or following a link with unsaved changes asks
// first. Saving the layout never does.

const initialText = new Map(['name', 'description'].map((id) => [id, element(id).value]));
let leavingBySave = false;

function hasUnsavedChanges() {
    return !layoutMatchesBaseline()
        || pendingSampleFile !== null
        || [...initialText].some(([id, value]) => element(id).value !== value);
}

window.addEventListener('beforeunload', (event) => {
    if (leavingBySave || !hasUnsavedChanges()) return;
    event.preventDefault();
    // Some browsers still ask only when returnValue is set.
    event.returnValue = '';
});

form.addEventListener('submit', (event) => {
    if (!addPendingField() || !validateFields()) {
        event.preventDefault();
        return;
    }

    if (!form.checkValidity()) {
        event.preventDefault();
        form.classList.add('was-validated');
        form.reportValidity();
        return;
    }

    const blocking = updateLedgerChecks().find((problem) => problem.blocking);
    if (blocking) {
        event.preventDefault();
        showBuilderError(blocking.message);
        return;
    }

    if (coveredFieldIndexes.length > 0 && !window.confirm(
        `${coveredFieldIndexes.length === 1 ? '1 field covers' : `${coveredFieldIndexes.length} fields cover`} ledger cells. `
        + 'Staff scans would read the handwriting under them as those fields, and those rows would lose their cells.\n\n'
        + 'OK: save anyway.\nCancel: go back and move or remove them.',
    )) {
        event.preventDefault();
        return;
    }

    if (publishIntent.value === '1' && ledgerColumns().length > 0 && !sampleLoaded && !window.confirm(
        'This ledger has no sample page, so its row lines have not been checked against a real page.\n\n'
        + 'OK: publish anyway.\nCancel: go back and add a sample.',
    )) {
        event.preventDefault();
        return;
    }

    leavingBySave = true;
    serialiseFields();
    form.setAttribute('aria-busy', 'true');
    form.querySelectorAll('button[type="submit"]').forEach((button) => {
        button.disabled = true;
    });
});

// Explicitly attach the file that produced the current preview. Native file
// inputs already contribute it in the common case; set() also covers dropped
// files and browsers that lose the input's FileList before submission.
form.addEventListener('formdata', (event) => {
    if (pendingSampleFile instanceof File) {
        event.formData.set('sample_document', pendingSampleFile, pendingSampleFile.name);
    }
});

// ------------------------------------------------------------- ledger grid
//
// A ruled register is described by its columns (ordinary markers flagged as
// columns) and the y of every printed row line. Row lines are drawn in one
// SVG over the page whose viewBox is 0-1000 in both directions, so they follow
// every zoom without re-layout.
//
// The Align step places the row lines relative to the columns' band (their
// median top and bottom), so the builder keeps the two together: moving or
// stretching the table carries its row lines along, and columns sitting on
// the first and last line follow those lines when they move.

const SVG_NS = 'http://www.w3.org/2000/svg';
const ledgerLayer = document.createElementNS(SVG_NS, 'svg');
ledgerLayer.setAttribute('class', 'ledger-rule-layer');
ledgerLayer.setAttribute('viewBox', '0 0 1000 1000');
ledgerLayer.setAttribute('preserveAspectRatio', 'none');
ledgerLayer.setAttribute('aria-hidden', 'true');
overlay.appendChild(ledgerLayer);

// A row line dragged within this many screen pixels of a printed rule snaps onto it.
const RULE_SNAP_PIXELS = 8;

const ruleKey = (y) => Number(y).toFixed(5);

function ledgerColumns(boxes = marker.toJSON()) {
    return boxes.filter(isColumn);
}

/**
 * Draw row lines. While a line is dragged they are drawn where the pointer
 * has them, before anything is committed; `dragging` marks the moving ones.
 */
function drawRules(ys = ruledYs, columns = ledgerColumns(), dragging = null) {
    const left = columns.length ? Math.min(...columns.map((c) => c.x)) : 0.02;
    const right = columns.length ? Math.max(...columns.map((c) => c.x + c.w)) : 0.98;

    ledgerLayer.replaceChildren();
    ys.forEach((y, index) => {
        const estimated = estimatedYs.has(ruleKey(y));
        const group = document.createElementNS(SVG_NS, 'g');
        group.setAttribute('class', 'ledger-rule');
        group.classList.toggle('is-selected', index === selectedRule);
        group.classList.toggle('is-estimated', estimated);
        group.classList.toggle('is-dragging', Boolean(dragging?.has(index)));
        group.dataset.index = String(index);
        const line = document.createElementNS(SVG_NS, 'line');
        const hit = document.createElementNS(SVG_NS, 'line');
        [line, hit].forEach((node) => {
            node.setAttribute('x1', String(left * 1000));
            node.setAttribute('x2', String(right * 1000));
            node.setAttribute('y1', String(y * 1000));
            node.setAttribute('y2', String(y * 1000));
        });
        line.setAttribute('class', 'ledger-rule__line');
        hit.setAttribute('class', 'ledger-rule__hit');
        const title = document.createElementNS(SVG_NS, 'title');
        title.textContent = estimated
            ? `Row line ${index + 1} was estimated: no printed rule was found here. Check it against the page.`
            : `Row line ${index + 1}: click to select, drag to move, double-click to remove`;
        group.append(line, hit, title);
        ledgerLayer.appendChild(group);
    });
}

function renderLedgerGrid() {
    const columns = ledgerColumns();
    drawRules(ruledYs, columns);

    const rows = Math.max(0, ruledYs.length - 1);
    const estimated = ruledYs.filter((y) => estimatedYs.has(ruleKey(y))).length;
    const badge = element('ledgerGridBadge');
    const ready = columns.length > 0 && ruledYs.length >= 2;
    badge.textContent = ready ? 'Ledger' : 'None';
    badge.className = `badge ${ready ? 'bg-label-info' : 'bg-label-secondary'}`;
    element('ledgerGridSummary').textContent = columns.length === 0 && ruledYs.length === 0
        ? 'No ledger grid. This layout reads each field as a rectangle.'
        : `${columns.length} column${columns.length === 1 ? '' : 's'} · ${ruledYs.length} row line${ruledYs.length === 1 ? '' : 's'} (${rows} row${rows === 1 ? '' : 's'})`
            + (estimated > 0
                ? ` · ${estimated} estimated below the last printed rule (amber): check ${estimated === 1 ? 'it' : 'them'} against the page.`
                : '');

    const rowsInput = element('evenRowsInput', HTMLInputElement);
    if (document.activeElement !== rowsInput) rowsInput.value = rows > 0 ? String(rows) : '';
    element('evenRowsBtn', HTMLButtonElement).disabled = ruledYs.length < 2;
    element('removeRuledLineBtn', HTMLButtonElement).disabled = selectedRule === null;
    element('clearGridBtn', HTMLButtonElement).disabled = columns.length === 0 && ruledYs.length === 0;
}

/**
 * Fields covering ledger cells, and what is wrong with the grid, shown in the
 * ledger card while the layout changes. Returns the grid's problems.
 */
function updateLedgerChecks(boxes = marker.toJSON()) {
    coveredFieldIndexes = fieldsOverLedger(boxes, ruledYs);
    element('ledgerCoveredNotice').classList.toggle('d-none', coveredFieldIndexes.length === 0);
    element('ledgerCoveredMessage').textContent = coveredFieldIndexes.length === 1
        ? '1 field covers ledger cells. The handwriting under it is read as that field, so those rows lose their cells.'
        : `${coveredFieldIndexes.length} fields cover ledger cells. The handwriting under them is read as those fields, so those rows lose their cells.`;

    const problems = ledgerGridProblems(ledgerColumns(boxes), ruledYs);
    element('ledgerProblemList').replaceChildren(...problems.map((problem) => {
        const item = document.createElement('li');
        item.textContent = problem.message;
        item.classList.toggle('is-blocking', problem.blocking);
        return item;
    }));
    element('ledgerProblems').classList.toggle('d-none', problems.length === 0);

    return problems;
}

/**
 * The columns were moved or stretched as a table (their band changed): carry
 * the row lines along, as the Align step would. A column added, removed or
 * converted only resets the band.
 */
function followTable(boxes) {
    const columns = boxes.filter(isColumn);
    const band = columnBand(columns);

    if (!restoringHistory && tableBand && band && columns.length === tableColumnCount
        && ruledYs.length >= 2 && !sameBand(band, tableBand)) {
        const from = tableBand;
        ruledYs = remapRuledYs(ruledYs, from, band);
        estimatedYs = new Set([...estimatedYs].map((key) => ruleKey(remapRuledYs([Number(key)], from, band)[0])));
    }

    tableBand = band;
    tableColumnCount = columns.length;
}

/** While columns are dragged, their row lines move with them. */
function previewTableFollow(boxes) {
    const columns = boxes.filter(isColumn);
    const band = columnBand(columns);
    if (!tableBand || !band || columns.length !== tableColumnCount || ruledYs.length < 2) return;

    drawRules(sameBand(band, tableBand) ? ruledYs : remapRuledYs(ruledYs, tableBand, band), columns);
}

/** Markers set by the builder itself, with the row lines already where they belong. */
function replaceBoxes(boxes, { keepSelection = false } = {}) {
    const selection = keepSelection ? marker.selectedIndexes() : [];
    const columns = boxes.filter(isColumn);
    tableBand = columnBand(columns);
    tableColumnCount = columns.length;
    marker.setBoxes(cloneBoxes(boxes));
    if (selection.length > 0) marker.selectIndexes(selection);
}

/**
 * New row lines, as one undoable change. Columns sitting on the first or the
 * last line follow it, so the table's top and bottom stay on its lines.
 */
function applyGrid(nextYs, boxes = marker.toJSON()) {
    const next = normaliseRuledYs(nextYs).filter((y, index, all) => index === 0 || y - all[index - 1] > 0.0005);
    const followed = followFirstAndLastLine(boxes, ruledYs, next);
    ruledYs = next;
    if (selectedRule !== null && selectedRule >= ruledYs.length) selectedRule = null;

    if (followed !== boxes) {
        replaceBoxes(followed, { keepSelection: true });
        return;
    }

    history.record(layoutState());
    renderLedgerGrid();
    updateLedgerChecks();
    updateResetUI();
}

/**
 * The row line the arrow keys and Delete act on (null: none). A row line and
 * markers are never selected together.
 */
function selectRule(index) {
    selectedRule = Number.isInteger(index) && index >= 0 && index < ruledYs.length ? index : null;
    if (selectedRule !== null) marker.clearSelection();
    renderLedgerGrid();
}

function removeRule(index) {
    if (!Number.isInteger(index) || index < 0 || index >= ruledYs.length) return;
    selectedRule = null;
    applyGrid(ruledYs.filter((_, candidate) => candidate !== index));
}

/** Move the selected row line by some screen pixels (arrow keys). */
function nudgeRule(pixels) {
    if (selectedRule === null) return;
    const height = Math.max(1, overlay.getBoundingClientRect().height);
    const from = ruledYs[selectedRule];
    const y = clampRuledY(ruledYs, selectedRule, from + pixels / height);
    if (Math.abs(y - from) < 1e-9) return;

    // Placed by hand: no longer an estimate.
    estimatedYs.delete(ruleKey(from));
    applyGrid(ruledYs.map((value, index) => (index === selectedRule ? y : value)));
}

// Click a row line to select it, drag it to move it (Shift: every line
// together), double-click it to remove it. Near a printed rule it snaps on
// (Alt: place freely).
ledgerLayer.addEventListener('pointerdown', (event) => {
    const group = event.target instanceof Element ? event.target.closest('.ledger-rule') : null;
    if (!group || event.button !== 0 || event.ctrlKey) return;
    event.preventDefault();
    event.stopPropagation();

    // Take focus off a name box, where the arrow keys would only move the caret.
    const focused = document.activeElement;
    if (focused instanceof HTMLElement && focused.matches('input, textarea, select')) focused.blur();

    const index = Number(group.dataset.index);
    const together = event.shiftKey;
    const origin = [...ruledYs];
    const pageY = (clientY) => {
        const bounds = overlay.getBoundingClientRect();
        return { y: (clientY - bounds.top) / Math.max(1, bounds.height), height: Math.max(1, bounds.height) };
    };
    const startY = pageY(event.clientY).y;
    const moving = new Set(together ? origin.map((_, i) => i) : [index]);
    let current = origin;
    selectRule(index);

    const move = (moveEvent) => {
        const { y, height } = pageY(moveEvent.clientY);
        const snap = (value) => (printedLines && !moveEvent.altKey
            ? nearestLine(value, printedLines.horizontal, RULE_SNAP_PIXELS / height) ?? value
            : value);

        current = together
            ? shiftRuledYs(origin, snap(origin[index] + y - startY) - origin[index])
            : origin.map((value, i) => (i === index ? clampRuledY(origin, index, snap(y)) : value));
        drawRules(current, ledgerColumns(), moving);
    };

    const end = () => {
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', end);
        window.removeEventListener('pointercancel', end);

        if (current.every((y, i) => Math.abs(y - origin[i]) < 1e-9)) {
            renderLedgerGrid();
            return;
        }

        if (together) {
            const dy = current[0] - origin[0];
            estimatedYs = new Set([...estimatedYs].map((key) => ruleKey(Number(key) + dy)));
        } else {
            estimatedYs.delete(ruleKey(origin[index]));
        }
        applyGrid(current);
    };

    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', end);
    window.addEventListener('pointercancel', end);
});

ledgerLayer.addEventListener('dblclick', (event) => {
    const group = event.target instanceof Element ? event.target.closest('.ledger-rule') : null;
    if (!group) return;
    event.preventDefault();
    removeRule(Number(group.dataset.index));
});

// A click on the page around the markers lets go of the row line.
overlay.addEventListener('pointerdown', (event) => {
    if (event.target === overlay && selectedRule !== null) selectRule(null);
});

function uniqueName(base, taken) {
    let name = base;
    let suffix = 2;
    while (taken.has(name.toLocaleLowerCase())) {
        name = `${base} ${suffix}`;
        suffix += 1;
    }
    taken.add(name.toLocaleLowerCase());
    return name;
}

/**
 * The sample's printed rules and suggested grid, asked once per sample: for
 * magnetic snapping as soon as the sample opens, and for "Detect from sample".
 */
function requestSampleGrid() {
    if (sampleGrid) return sampleGrid;

    const request = (async () => {
        const blob = await new Promise((resolve, reject) => {
            canvas.toBlob((result) => (result ? resolve(result) : reject(new Error('The sample could not be prepared.'))), 'image/png');
        });
        const data = new FormData();
        data.set('image', blob, 'sample.png');

        const response = await window.fetch(config.detectGridUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': config.csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: data,
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok) {
            throw new Error(payload?.message || `Grid detection failed with HTTP ${response.status}.`);
        }
        return payload ?? {};
    })();

    sampleGrid = request;
    // A failed request is asked again next time.
    request.catch(() => {
        if (sampleGrid === request) sampleGrid = null;
    });
    return request;
}

function forgetSampleGrid() {
    sampleGrid = null;
    printedLines = null;
    marker.setSnapLines(null);
}

function usePrintedLines(lines) {
    printedLines = lines && Array.isArray(lines.horizontal)
        ? { horizontal: lines.horizontal, vertical: Array.isArray(lines.vertical) ? lines.vertical : [] }
        : null;
    marker.setSnapLines(printedLines);
}

/** In the background, so markers and row lines snap from the start. */
async function loadPrintedLines() {
    const request = requestSampleGrid();
    try {
        const payload = await request;
        if (request === sampleGrid) usePrintedLines(payload?.lines);
    } catch (error) {
        console.warn('Printed lines for snapping could not be found:', error);
    }
}

async function detectLedgerGrid() {
    if (!sampleLoaded) {
        showBuilderError('Choose a sample page first: the grid is found from its printed rules.');
        return;
    }

    const button = element('detectGridBtn', HTMLButtonElement);
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    clearBuilderError();

    try {
        const payload = await requestSampleGrid();
        if (!Array.isArray(payload?.ruled_ys) || payload.ruled_ys.length < 2) {
            throw new Error('No evenly ruled rows were found on this sample. Add the row lines by hand.');
        }
        if (!printedLines) usePrintedLines(payload.lines);

        const boxes = marker.toJSON();
        const detected = normaliseRuledYs(payload.ruled_ys);
        const top = detected[0];
        const bottom = detected[detected.length - 1];
        let next = boxes;

        if (ledgerColumns(boxes).length === 0 && Array.isArray(payload.columns) && payload.columns.length > 0) {
            // Name each column after the topmost field already sitting in it.
            const fields = boxes.filter((box) => !isColumn(box));
            const taken = new Set(boxes.map((box) => box.name.trim().toLocaleLowerCase()));
            const columns = payload.columns.map((column, index) => {
                const [x, , w] = column.box;
                const inside = fields
                    .filter((field) => field.x + field.w / 2 >= x && field.x + field.w / 2 <= x + w)
                    .sort((a, b) => a.y - b.y)[0];
                const fieldName = inside?.name?.trim();
                return {
                    name: uniqueName(fieldName ? `${fieldName} column` : `Column ${index + 1}`, taken),
                    x,
                    y: top,
                    w,
                    h: bottom - top,
                    personGroup: null,
                    personFieldOrder: null,
                    kind: 'column',
                };
            });
            next = [...boxes, ...columns];
        } else {
            // Keep the admin's columns; stretch them over the detected rows.
            next = boxes.map((box) => (isColumn(box) ? { ...box, y: top, h: bottom - top } : box));
        }

        // Lines below the last printed rule are Detect's estimate: shown
        // amber until each is checked (moved or nudged) against the page.
        const estimated = Math.max(0, Math.min(detected.length, Number(payload.estimated_ys) || 0));
        estimatedYs = new Set(detected.slice(detected.length - estimated).map(ruleKey));
        selectedRule = null;
        ruledYs = detected;
        replaceBoxes(next);
    } catch (error) {
        showBuilderError(error instanceof Error ? error.message : 'The grid could not be detected.');
    } finally {
        button.disabled = false;
        button.removeAttribute('aria-busy');
    }
}

function updateLedgerButtons(indexes = marker.selectedIndexes()) {
    const boxes = marker.toJSON();
    element('makeColumnsBtn', HTMLButtonElement).disabled = indexes.length === 0
        || indexes.every((index) => isColumn(boxes[index]));
    element('makeFieldsBtn', HTMLButtonElement).disabled = !indexes.some((index) => isColumn(boxes[index]));
}

element('detectGridBtn', HTMLButtonElement).addEventListener('click', detectLedgerGrid);

element('makeColumnsBtn', HTMLButtonElement).addEventListener('click', () => {
    const selected = new Set(marker.selectedIndexes());
    if (selected.size === 0) return;
    const top = ruledYs.length >= 2 ? ruledYs[0] : null;
    const bottom = ruledYs.length >= 2 ? ruledYs[ruledYs.length - 1] : null;

    marker.setBoxes(cloneBoxes(marker.toJSON().map((box, index) => {
        if (!selected.has(index)) return box;
        return {
            ...box,
            kind: 'column',
            personGroup: null,
            personFieldOrder: null,
            ...(top !== null ? { y: top, h: bottom - top } : {}),
        };
    })));
});

// Back from a column to a field, read as one rectangle (Detect splits it
// into its written lines). The row lines stay for the columns that remain.
element('makeFieldsBtn', HTMLButtonElement).addEventListener('click', () => {
    const selected = marker.selectedIndexes();
    const boxes = marker.toJSON();
    if (!selected.some((index) => isColumn(boxes[index]))) return;

    const chosen = new Set(selected);
    marker.setBoxes(cloneBoxes(boxes.map((box, index) => (
        chosen.has(index) && isColumn(box) ? { ...box, kind: null } : box
    ))));
    marker.selectIndexes(selected);
});

element('addRuledLineBtn', HTMLButtonElement).addEventListener('click', () => {
    const inserted = insertRuledY(ruledYs, selectedRule);
    if (!inserted) {
        showBuilderError('There is no room for another row line there. Select a taller row, or move the lines apart first.');
        return;
    }
    clearBuilderError();
    applyGrid(inserted.ys);
    selectRule(inserted.index);
});

element('removeRuledLineBtn', HTMLButtonElement).addEventListener('click', () => removeRule(selectedRule));

element('evenRowsBtn', HTMLButtonElement).addEventListener('click', () => {
    const spaced = distributeRuledYs(ruledYs, element('evenRowsInput', HTMLInputElement).value);
    if (!spaced) {
        showBuilderError(ruledYs.length < 2
            ? 'Place the first and the last row line first.'
            : 'Choose a whole number of rows; a row cannot be thinner than a few pixels.');
        return;
    }
    clearBuilderError();
    // Placed by the admin now, not estimated.
    estimatedYs = new Set();
    selectedRule = null;
    applyGrid(spaced);
});

element('evenRowsInput', HTMLInputElement).addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    element('evenRowsBtn', HTMLButtonElement).click();
});

element('clearGridBtn', HTMLButtonElement).addEventListener('click', () => {
    ruledYs = [];
    selectedRule = null;
    estimatedYs = new Set();
    replaceBoxes(marker.toJSON().filter((box) => !isColumn(box)));
});

element('selectCoveredFieldsBtn', HTMLButtonElement).addEventListener('click', () => {
    if (coveredFieldIndexes.length > 0) marker.selectIndexes(coveredFieldIndexes, { source: 'group' });
});

element('removeCoveredFieldsBtn', HTMLButtonElement).addEventListener('click', () => {
    const covered = new Set(coveredFieldIndexes);
    replaceBoxes(marker.toJSON().filter((_, index) => !covered.has(index)));
});

// ------------------------------------------------------------ test on sample
//
// Outline the sample with the layout as it stands, saved or not, exactly as
// Detect does it for Staff: the page straightened, the markers fitted to its
// table, every line cut and put in a row. Nothing is read, and the server
// deletes its copy of the sample once the result is shown.

const TEST_COLOURS = {
    placed: ['rgba(14, 154, 167, .16)', '#0e9aa7'],
    field: ['rgba(105, 108, 255, .16)', '#696cff'],
    shared: ['rgba(255, 171, 0, .22)', '#e59500'],
    'no-row': ['rgba(255, 62, 29, .2)', '#ff3e1d'],
};

// How often the builder asks whether the test has finished, and when it stops.
const TEST_POLL_MS = 1500;
const TEST_GIVE_UP_MS = 15 * 60 * 1000;

/** The markers as the server's line detection takes them (page fractions). */
function testGeometry() {
    const boxes = marker.toJSON();
    const columns = boxes.filter(isColumn);
    return {
        columns: columns.map((box) => ({ name: box.name.trim(), box: [box.x, box.y, box.w, box.h], ...markerAngleMetadata(box) })),
        ruled_ys: columns.length > 0 ? ruledYs : [],
        fields: boxes.filter((box) => !isColumn(box)).map((box) => ({
            name: box.name.trim(),
            box: [box.x, box.y, box.w, box.h],
            ...(groupingModeInput.value === 'custom' && positiveInteger(box.personGroup) !== null
                ? { person_group: positiveInteger(box.personGroup), person_field_order: nonNegativeInteger(box.personFieldOrder) ?? 0 }
                : {}),
            ...markerAngleMetadata(box),
        })),
    };
}

function loadImage(source) {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('The tested page could not be shown.'));
        image.src = source;
    });
}

/** The page with every outline over it, and the row lines the test fitted. */
async function drawTest(result) {
    const target = element('layoutTestCanvas', HTMLCanvasElement);
    const page = result.image ? await loadImage(result.image) : canvas;
    const [width, height] = Array.isArray(result.size) ? result.size : [page.width, page.height];
    target.width = width;
    target.height = height;
    const context = target.getContext('2d');
    context.drawImage(page, 0, 0, width, height);

    const fitted = result.geometry ?? {};
    const columns = Array.isArray(fitted.columns) ? fitted.columns : [];
    if (columns.length > 0) {
        const left = Math.min(...columns.map((column) => column.box[0])) * width;
        const right = Math.max(...columns.map((column) => column.box[0] + column.box[2])) * width;
        context.save();
        context.strokeStyle = 'rgba(14, 154, 167, .7)';
        context.setLineDash([8, 5]);
        context.lineWidth = 1.5;
        (fitted.ruled_ys ?? []).forEach((y) => {
            context.beginPath();
            context.moveTo(left, y * height);
            context.lineTo(right, y * height);
            context.stroke();
        });
        context.restore();
    }

    context.lineWidth = 2;
    result.lines.forEach((line) => {
        if (!Array.isArray(line.polygon) || line.polygon.length < 3) return;
        const [fill, stroke] = TEST_COLOURS[testLineStatus(line)];
        context.beginPath();
        line.polygon.forEach(([x, y], index) => (index === 0 ? context.moveTo(x, y) : context.lineTo(x, y)));
        context.closePath();
        context.fillStyle = fill;
        context.fill();
        context.strokeStyle = stroke;
        context.stroke();
    });
}

function showTestResult(result) {
    const requiredColumns = ledgerColumns()
        .filter((box) => markerFieldSettings(box).required !== false)
        .map((box) => box.name);
    const summary = summariseTest(result, requiredColumns);
    const columns = new Set(result.lines.map((line) => line.column).filter(Boolean)).size;

    element('layoutTestSummary').textContent = [
        `${summary.lines} ${summary.lines === 1 ? 'line' : 'lines'}`,
        summary.rows > 0 ? `${summary.rows} ${summary.rows === 1 ? 'row' : 'rows'}` : null,
        `${columns} ${columns === 1 ? 'column or field' : 'columns and fields'}`,
        summary.noRow + summary.shared > 0 ? `${summary.noRow + summary.shared} to review` : 'none to review',
    ].filter(Boolean).join(' · ');

    const findings = [
        ...testFindings(summary),
        ...gridNotes(result.notes).map((text) => ({ level: 'warning', text })),
    ];
    element('layoutTestFindings').replaceChildren(...(findings.length > 0 ? findings : [{
        level: 'ok',
        text: 'Every line landed in a row and a column. Check the outlines below against the page.',
    }]).map((finding) => {
        const item = document.createElement('li');
        item.className = `is-${finding.level}`;
        item.textContent = finding.text;
        return item;
    }));
}

/** The JSON of a Test layout reply, or an error saying why it failed. */
async function testReply(response) {
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload) {
        const firstError = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
        throw new Error(firstError || payload?.message || `The test failed with HTTP ${response.status}.`);
    }
    return payload;
}

/**
 * Test layout runs in the background queue, so the app stays usable while
 * it does. Poll until its result is ready, as the Verify step does.
 */
async function waitForTest(started, statusText) {
    const begun = Date.now();
    let payload = started;
    while (payload.status !== 'done') {
        if (Date.now() - begun > TEST_GIVE_UP_MS) {
            throw new Error('The test took too long. Check that the queue worker is running, then try again.');
        }
        statusText.textContent = payload.status === 'queued'
            ? 'Waiting for the background worker. If this stays, check that the queue worker is running.'
            : 'Outlining every line on the sample. This takes about half a minute.';
        await new Promise((resolve) => window.setTimeout(resolve, TEST_POLL_MS));
        payload = await testReply(await window.fetch(started.statusUrl, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }));
    }
    if (!Array.isArray(payload.lines)) {
        throw new Error('The test finished without a result. Try again.');
    }
    return payload;
}

async function testLayout() {
    if (!sampleLoaded) {
        showBuilderError('Choose a sample page first: the layout is tested on it.');
        return;
    }
    const blocking = updateLedgerChecks().find((problem) => problem.blocking);
    if (blocking) {
        showBuilderError(blocking.message);
        return;
    }
    if (marker.toJSON().length === 0) {
        showBuilderError('Add a field or a ledger column to test.');
        return;
    }

    const button = element('testLayoutBtn', HTMLButtonElement);
    const status = element('layoutTestStatus');
    const statusText = element('layoutTestStatusText');
    button.disabled = true;
    clearBuilderError();
    status.classList.remove('d-none', 'is-error');
    status.querySelector('.spinner-border')?.classList.remove('d-none');
    statusText.textContent = 'Outlining every line on the sample. This takes about half a minute.';
    element('layoutTestResult').classList.add('d-none');
    window.bootstrap.Modal.getOrCreateInstance(element('layoutTestModal')).show();

    try {
        const blob = await new Promise((resolve, reject) => {
            canvas.toBlob((result) => (result ? resolve(result) : reject(new Error('The sample could not be prepared.'))), 'image/png');
        });
        const data = new FormData();
        data.set('page', blob, 'sample.png');
        data.set('geometry_json', JSON.stringify(testGeometry()));

        const started = await testReply(await window.fetch(config.testLayoutUrl, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf, 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: data,
        }));
        const payload = await waitForTest(started, statusText);

        await drawTest(payload);
        showTestResult(payload);
        status.classList.add('d-none');
        element('layoutTestResult').classList.remove('d-none');
    } catch (error) {
        status.classList.add('is-error');
        status.querySelector('.spinner-border')?.classList.add('d-none');
        statusText.textContent = error instanceof Error ? error.message : 'The layout could not be tested.';
    } finally {
        button.disabled = false;
    }
}

element('testLayoutBtn', HTMLButtonElement).addEventListener('click', testLayout);

marker.setBoxes(cloneBoxes(initialBoxes));
renderLedgerGrid();
updatePaperPreview();
window.requestAnimationFrame(() => marker.resetZoom());
openStoredSample();
