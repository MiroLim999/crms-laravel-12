import { FieldMarker } from './field-marker';
import { attachMarqueeSelection } from './marquee-selection';

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
    name, x, y, w, h, personGroup = null, personFieldOrder = null,
}) => ({ name, x, y, w, h, personGroup, personFieldOrder }));
const snapshot = (boxes) => JSON.stringify(cloneBoxes(boxes));

function finiteNumber(value, fallback) {
    const number = Number(value);
    return Number.isFinite(number) ? number : fallback;
}

function positiveInteger(value) {
    const number = Number(value);
    return Number.isInteger(number) && number > 0 ? number : null;
}

function nonNegativeInteger(value) {
    const number = Number(value);
    return Number.isInteger(number) && number >= 0 ? number : null;
}

function normaliseBoxes(fields) {
    if (!Array.isArray(fields)) return [];

    return fields.map((field, index) => {
        const x = Math.min(0.99, Math.max(0, finiteNumber(field.x, 0.08)));
        const y = Math.min(0.99, Math.max(0, finiteNumber(field.y, 0.08)));
        const width = Math.min(1 - x, Math.max(0.01, finiteNumber(field.width ?? field.w, 0.35)));
        const height = Math.min(1 - y, Math.max(0.01, finiteNumber(field.height ?? field.h, 0.06)));

        return {
            name: String(field.name ?? `Field ${index + 1}`),
            x,
            y,
            w: width,
            h: height,
            personGroup: positiveInteger(field.personGroup ?? field.person_group),
            personFieldOrder: nonNegativeInteger(
                field.personFieldOrder ?? field.person_field_order,
            ),
        };
    });
}

const baselineBoxes = normaliseBoxes(config.baselineFields);
const initialBoxes = normaliseBoxes(config.initialFields);
groupingModeInput.value = initialGroupingMode;

let fieldHistory = [];
let currentSnapshot = null;
let currentGroupingModeSnapshot = null;
let restoringHistory = false;
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
        && groupingModeInput.value === baselineGroupingMode
        && paperSizeSelect.value === baselinePaperSize
        && selectedOrientation() === baselineOrientation
        && customDimensionsMatch;
}

function updateResetUI() {
    const changed = !layoutMatchesBaseline() || Math.abs(marker.zoom - 1) > 0.001;
    element('resetFieldsBtn', HTMLButtonElement).disabled = !changed;
}

function handleMarkerChange(boxes) {
    const next = cloneBoxes(boxes);
    const nextSnapshot = snapshot(next);
    const nextGroupingMode = groupingModeInput.value === 'custom' ? 'custom' : 'auto';

    if (!restoringHistory && currentSnapshot !== null
        && (nextSnapshot !== snapshot(currentSnapshot)
            || nextGroupingMode !== currentGroupingModeSnapshot)) {
        fieldHistory.push({
            boxes: cloneBoxes(currentSnapshot),
            groupingMode: currentGroupingModeSnapshot,
        });
        if (fieldHistory.length > 100) fieldHistory.shift();
    }

    currentSnapshot = next;
    currentGroupingModeSnapshot = nextGroupingMode;
    renderFieldList(boxes);
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
    marker.selectIndexes(indexes, { source: 'group' });
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
        selectButton.className = 'template-person-group__select';
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
        removeButton.className = 'template-person-group__remove';
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

    const groupBadge = document.createElement('span');
    groupBadge.className = 'template-builder-field-group d-none';
    item.appendChild(groupBadge);

    const removeButton = document.createElement('button');
    removeButton.type = 'button';
    removeButton.className = 'btn btn-sm btn-icon btn-text-danger';
    removeButton.setAttribute('aria-label', `Remove field ${index + 1}`);

    const removeIcon = document.createElement('i');
    removeIcon.className = 'icon-base bx bx-x icon-sm';
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
            groupBadge.classList.toggle('d-none', group === null);
            groupBadge.textContent = group === null ? '' : `P${String(group).padStart(2, '0')}`;
            groupBadge.title = group === null ? '' : `Person ${String(group).padStart(2, '0')}`;
        }
        removeButton?.setAttribute('aria-label', `Remove field ${index + 1}`);
    });

    element('fieldCount').textContent = `${boxes.length} field${boxes.length === 1 ? '' : 's'}`;
    selectAllInput.disabled = boxes.length === 0;
    renderPersonGroups(boxes);
    updateSelectionUI(marker.selectedIndexes());
}

function centerFieldListRow(index) {
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
    const title = element('paperPreviewTitle');
    const message = element('paperPreviewMessage');
    const { paper, orientation, ratio } = expectedPaper();
    const orientationLabel = orientation === 'landscape' ? 'Landscape' : 'Portrait';

    title.textContent = `${paper?.label ?? 'Paper'} · ${orientationLabel}`;
    status.classList.remove('is-warning', 'is-match');

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
        message.textContent = orientationMismatch
            ? `The sample looks ${actualOrientation}, but this template is ${orientation}. It will not be stretched.`
            : 'The sample proportions differ from this paper preset. Check the selected paper size before publishing.';
        return;
    }

    status.classList.add('is-match');
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

function undoFieldChange() {
    const previous = fieldHistory.pop();
    if (!previous) return;

    restoringHistory = true;
    groupingModeInput.value = previous.groupingMode;
    marker.setBoxes(cloneBoxes(previous.boxes));
    restoringHistory = false;
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
    const copies = clipboard.map((box) => ({
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
            || box.x + box.w > 1.00001 || box.y + box.h > 1.00001) {
            invalidFieldIndexes.add(index);
        }
    });

    renderFieldList(boxes);

    if (invalidFieldIndexes.size > 0) {
        showBuilderError('Every field needs a unique name and a marker fully inside the document.');
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

    const fields = marker.toJSON().map((box) => ({
            name: box.name.trim(),
            x: box.x.toFixed(5),
            y: box.y.toFixed(5),
            width: box.w.toFixed(5),
            height: box.h.toFixed(5),
            person_group: groupingModeInput.value === 'custom'
                ? positiveInteger(box.personGroup)
                : null,
            person_field_order: groupingModeInput.value === 'custom'
                ? nonNegativeInteger(box.personFieldOrder)
                : null,
    }));

    // One JSON input avoids PHP's max_input_vars truncating large layouts.
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'fields_json';
    input.value = JSON.stringify(fields);
    container.appendChild(input);
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

    marker.setBoxes(cloneBoxes(baselineBoxes));
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
    const target = event.target;
    const editing = target instanceof HTMLElement
        && (target.matches('input, textarea, select') || target.isContentEditable);
    if (editing) return;

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

    if (commandPressed && !event.shiftKey && key === 'z' && fieldHistory.length > 0) {
        event.preventDefault();
        undoFieldChange();
        return;
    }

    if (['Backspace', 'Delete'].includes(event.key) && marker.selectedIndexes().length > 0) {
        event.preventDefault();
        marker.removeSelected();
    }
});

form.querySelectorAll('button[type="submit"][data-publish]').forEach((button) => {
    button.addEventListener('click', () => {
        publishIntent.value = button.dataset.publish === '1' ? '1' : '0';
    });
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

marker.setBoxes(cloneBoxes(initialBoxes));
updatePaperPreview();
window.requestAnimationFrame(() => marker.resetZoom());
openStoredSample();
