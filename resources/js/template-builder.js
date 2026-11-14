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
    columnsAtPageTilt,
    distributeRuledYs,
    fieldsOverLedger,
    fitColumnsToPrinted,
    followFirstAndLastLine,
    gridTilt,
    insertRuledY,
    isColumn,
    ledgerGridProblems,
    remapRuledYs,
    rowsWithinFrame,
    sameBand,
    tiltColumns,
} from './ledger-grid';
import { attachMarqueeSelection } from './marquee-selection';
import { RowLineLayer, rowKey } from './row-lines';
import {
    nonNegativeInteger,
    positiveInteger,
    templatePersonPayload,
} from './person-grouping';
import {
    builderShortcutIsBlocked,
    handleSelectedFieldDeletion,
    layoutUndoIsBlocked,
} from './template-builder-shortcuts';
import { LayoutHistory } from './template-history';
import { guessedRowCount, namesOnBothPages, publishProblems, rowMismatch } from './layout-publish-checks';
import { setDisclosureExpanded } from './disclosure-motion';

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
// Set when the admin chose "Save and publish" although the publish checks found something missing.
const publishAnyway = element('publishAnyway', HTMLInputElement);
const groupingModeInput = element('groupingMode', HTMLInputElement);
// The blank page's shape (width : height) until a sample is opened; a sample
// brings its own.
const blankPageRatio = Math.min(10, Math.max(0.1, finiteNumber(config.pageRatio, 11 / 8.5)));
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
let history = new LayoutHistory();
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
// The layout's stored sample is still being fetched: no drop area meanwhile.
let sampleExpected = Boolean(config.sample?.url);
// Keep the exact file represented by the preview. Some browsers can display a
// dropped/selected File even when their file input is later reconstructed or
// loses its FileList, which used to produce a saved layout with no sample.
let pendingSampleFile = null;
// The sample as opened (its pages are drawn side by side in a two-page layout).
let sampleFile = null;
let samplePageCount = 1;

function resizeBlankCanvas() {
    const longEdge = 1600;

    if (blankPageRatio >= 1) {
        canvas.width = longEdge;
        canvas.height = Math.round(longEdge / blankPageRatio);
    } else {
        canvas.height = longEdge;
        canvas.width = Math.round(longEdge * blankPageRatio);
    }
}

function drawBlankPage(target = canvas) {
    const context = target.getContext('2d');
    if (!context) throw new Error('The document preview could not be created.');

    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, target.width, target.height);
    context.strokeStyle = '#d9dee3';
    context.lineWidth = 2;
    context.strokeRect(1, 1, target.width - 2, target.height - 2);
}

resizeBlankCanvas();
drawBlankPage();

// The zoom the page being edited was last focused at (null: not focused). While
// the view still sits at it, a change in the room it has (the side bar opening
// or closing, the window resized) fits the page to the new room. Declared before
// the marker, whose zoom callback reads it.
let focusedZoom = null;
// Two pages whose row counts differ (set by renderRowMatch): the Table grid card's
// warning mark shows it while the card is closed.
let rowsMismatched = false;

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

// A layout of two pages (a register's left and right pages): each page has its
// own markers, row lines and person-row mode, and its own saved baseline.
const savedPageTwo = config.pageTwo && typeof config.pageTwo === 'object' ? config.pageTwo : null;
const pageTwoBaseline = {
    boxes: savedPageTwo
        ? [...normaliseBoxes(savedPageTwo.fields), ...normaliseColumns(savedPageTwo.columns)]
        : [],
    ruledYs: savedPageTwo ? normaliseRuledYs(savedPageTwo.ruled_ys) : [],
    grouping: savedPageTwo?.grouping_mode === 'custom' ? 'custom' : 'auto',
};
const pageBaselines = {
    1: { boxes: baselineBoxes, ruledYs: baselineRuledYs, grouping: baselineGroupingMode },
    2: pageTwoBaseline,
};
const pageLayouts = {
    1: null,
    2: { boxes: cloneBoxes(pageTwoBaseline.boxes), ruledYs: [...pageTwoBaseline.ruledYs], grouping: pageTwoBaseline.grouping },
};
// A saved page 2 opens as two pages; a picked sample then decides (see setTwoPage).
let twoPage = savedPageTwo !== null;
let activePage = 1;

function layoutMatchesBaseline() {
    const baseline = pageBaselines[activePage];
    return snapshot(marker.toJSON()) === snapshot(baseline.boxes)
        && JSON.stringify(ruledYs) === JSON.stringify(baseline.ruledYs)
        && groupingModeInput.value === baseline.grouping;
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

// How many markers there were at the last change: deleting every one of them
// takes the row lines with it (they have no table left to belong to).
let previousMarkerCount = 0;

function handleMarkerChange(boxes) {
    if (!restoringHistory && previousMarkerCount > 0 && boxes.length === 0 && ruledYs.length > 0) {
        ruledYs = [];
        selectedRule = null;
        estimatedYs = new Set();
    }
    previousMarkerCount = boxes.length;

    // Before recording: carrying the row lines along is part of this change.
    followTable(boxes);
    if (!restoringHistory) history.record(layoutState(boxes));
    renderFieldList(boxes);
    renderLedgerGrid();
    updateLedgerChecks(boxes);
    updateResetUI();
    updateSampleHint(boxes);
}

/**
 * What the page area says while no sample is open. With nothing on the page
 * it is the drop area (in place of the blank page); once a field or a row
 * line is there, the blank page shows with a small reminder in its corner.
 */
function updateSampleHint(boxes = marker.toJSON()) {
    const waiting = !sampleLoaded && !sampleExpected;
    const empty = waiting && boxes.length === 0 && ruledYs.length === 0;
    element('sampleDropzone').classList.toggle('d-none', !empty);
    element('sampleHint').classList.toggle('d-none', !waiting || empty);
    viewport.classList.toggle('is-empty', empty);
    // The drop area has its own Choose sample button: not twice.
    fileLabel.classList.toggle('d-none', empty);
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

    // Only loose fields (a form) are grouped into people: a register's columns are
    // one person per row already. Kept while rows set by hand need a way back.
    const looseFields = boxes.filter((box) => !isColumn(box)).length;
    element('personCard').classList.toggle('d-none', !custom && looseFields < 2);

    modeBadge.textContent = custom ? 'Custom' : 'Automatic';
    modeBadge.classList.toggle('bg-label-primary', custom);
    modeBadge.classList.toggle('bg-label-secondary', !custom);
    automaticButton.disabled = !custom;
    automaticButton.classList.toggle('d-none', !custom);
    list.replaceChildren();

    // Automatic needs no list; the ? tips say how it works.
    if (!custom) return;

    if (groups.length === 0) {
        const note = document.createElement('p');
        note.className = 'template-person-group-list__empty';
        note.textContent = 'No person rows: every field shows under document details.';
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

    // Naming many fields: Enter goes to the next name, Shift + Enter back to the
    // one before (across the two pages of a spread). It never submits the form.
    input.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.isComposing) return;
        event.preventDefault();
        stepFieldName(Number(item.dataset.fieldIndex), event.shiftKey ? -1 : 1);
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

    // Also on a new layout, which starts with no fields: the list says so.
    if (items.length !== boxes.length || list.childElementCount === 0) {
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

    // Two pages: one person's record runs on from page 1 into page 2, so page 2's
    // numbers follow page 1's. A name on both pages is pointed out on both.
    const other = otherPageLayout();
    const offset = other && activePage === 2 ? other.boxes.length : 0;
    const onBoth = new Set(other
        ? namesOnBothPages(boxes, other.boxes).map((name) => name.toLocaleLowerCase())
        : []);

    items.forEach((item, index) => {
        const box = boxes[index];
        item.dataset.fieldIndex = String(index);

        const number = item.querySelector('.template-builder-field-number');
        const input = item.querySelector('.template-builder-field-name');
        const groupBadge = item.querySelector('.template-builder-field-group');
        const removeButton = item.querySelector('button');

        if (number) number.textContent = String(offset + index + 1);
        if (input instanceof HTMLInputElement) {
            if (document.activeElement !== input) input.value = box.name;
            input.classList.toggle('is-invalid', invalidFieldIndexes.has(index));
            input.classList.toggle('is-duplicate', onBoth.has(box.name.trim().toLocaleLowerCase()));
            input.setAttribute('aria-label', `Field ${offset + index + 1} name`);
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

    const total = boxes.length + (other?.boxes.length ?? 0);
    element('fieldCount').textContent = `${total} field${total === 1 ? '' : 's'}`;
    selectAllInput.disabled = boxes.length === 0;
    renderPersonRecord(boxes, other, onBoth);
    renderPersonGroups(boxes);
    updateSelectionUI(marker.selectedIndexes());
}

/** The page not being edited of a two-page layout ({ boxes, ruledYs, ... }), or null. */
function otherPageLayout() {
    return twoPage ? pageLayouts[3 - activePage] ?? null : null;
}

/** A page's number of ruled rows, or null for a page without a table. */
function pageRowCount(layout) {
    if (!layout || !layout.boxes.some(isColumn) || layout.ruledYs.length < 2) return null;
    return layout.ruledYs.length - 1;
}

/**
 * The Fields card as one person's record. One page: the usual field list. Two
 * pages: page 1's part, then page 2's, each under its heading; the page being
 * edited is the live list, the other a list whose rows open that page.
 */
function renderPersonRecord(boxes, other, onBoth) {
    element('fieldsCardTitle').textContent = other ? 'Person record' : 'Fields';
    // Add field (in the panel's footer) adds to the page being edited.
    element('newFieldName', HTMLInputElement).placeholder = other ? `Page ${activePage} field, e.g. Page No.` : 'e.g. Page No.';
    element('activePageHeading').classList.toggle('d-none', !other);
    element('otherPageSection').classList.toggle('d-none', !other);
    // Page 1 always first, as in the record.
    element('activePageSection').style.order = activePage === 1 ? '0' : '1';
    element('otherPageSection').style.order = activePage === 1 ? '1' : '0';

    if (!other) return;

    const otherPage = 3 - activePage;
    element('activePageHeading').textContent = `Page ${activePage} · editing`;
    element('otherPageHeading').textContent = `Page ${otherPage}`;

    const start = otherPage === 2 ? boxes.length : 0;
    const list = element('otherFieldList');
    list.replaceChildren(...(other.boxes.length === 0
        ? [Object.assign(document.createElement('li'), {
            className: 'marker-field-empty',
            textContent: `No fields on page ${otherPage} yet.`,
        })]
        : other.boxes.map((box, index) => {
            const item = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'template-person-record__item';
            button.title = `Edit page ${otherPage} and select “${box.name}”`;
            button.addEventListener('click', () => openOtherPageField(index));

            const number = document.createElement('span');
            number.className = 'badge bg-label-secondary template-builder-field-number';
            number.textContent = String(start + index + 1);

            const name = document.createElement('span');
            name.className = 'template-person-record__name';
            name.classList.toggle('is-duplicate', onBoth.has(box.name.trim().toLocaleLowerCase()));
            name.textContent = box.name;

            button.append(number, name);
            const text = settingsTags(box);
            if (text) {
                const tags = document.createElement('span');
                tags.className = 'template-builder-field-tags';
                tags.textContent = text;
                button.appendChild(tags);
            }
            if (isColumn(box)) {
                const badge = document.createElement('span');
                badge.className = 'template-builder-field-group is-column';
                badge.textContent = 'Col';
                button.appendChild(badge);
            }
            item.appendChild(button);
            return item;
        })));
}

/** Put the cursor in a field's name box on the page being edited, the name selected, ready to type over. */
function focusFieldName(index) {
    const input = element('fieldList').querySelector(`[data-field-index="${index}"] .template-builder-field-name`);
    if (!(input instanceof HTMLInputElement)) return false;
    input.focus();
    input.select();
    centerFieldListRow(index, { centerPanel: false });
    return true;
}

/**
 * The name box after (or before) field `index`: the next field of the page being edited, and
 * past its last field the first of the other page (the last of it, going back). At the very
 * end it stays where it is.
 */
async function stepFieldName(index, direction) {
    const here = marker.toJSON().length;
    const next = index + direction;
    if (next >= 0 && next < here) {
        focusFieldName(next);
        return;
    }

    const other = otherPageLayout();
    const goesToOther = other && other.boxes.length > 0
        && ((direction > 0 && activePage === 1) || (direction < 0 && activePage === 2));
    if (!goesToOther) return;

    await switchPage(3 - activePage);
    focusFieldName(direction > 0 ? 0 : marker.toJSON().length - 1);
}

/** A field of the other page picked in the record: edit that page, with the field selected. */
async function openOtherPageField(index) {
    await switchPage(3 - activePage);
    marker.selectIndexes([index]);
    window.requestAnimationFrame(() => centerFieldListRow(index, { centerPanel: false }));
}

/**
 * Two pages with tables: row N of each is one person, so the rows must match.
 * Said only when they do not (the amber line and the mark on the card header);
 * matching rows need no words.
 */
function renderRowMatch() {
    const line = element('rowMatch');
    const other = otherPageLayout();
    const here = pageRowCount({ boxes: marker.toJSON(), ruledYs });
    const there = pageRowCount(other);
    const problem = other && here !== null && there !== null
        ? rowMismatch(activePage === 1 ? [here, there] : [there, here])
        : null;

    rowsMismatched = problem !== null;
    line.classList.toggle('d-none', problem === null);
    line.replaceChildren();
    if (problem === null) return;

    const icon = document.createElement('i');
    icon.className = 'icon-base bx bx-error icon-sm';
    icon.setAttribute('aria-hidden', 'true');
    const text = document.createElement('span');
    text.textContent = problem;
    line.append(icon, text);
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
    // Zoomed in (a page in focus, or by hand): a click on the page just works on it.
    // Zoomed by hand since the page was focused: the focus is let go.
    if (focusedZoom !== null && !marker.gliding && Math.abs(zoom - focusedZoom) > 0.011) focusedZoom = null;
    element('docSpread').classList.toggle('is-zoomed-in', zoom > 1.011 || focusedZoom !== null);
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

/** A layout's markers and row lines as the server takes them. */
function layoutPayload(boxes, ruled, grouping) {
    const fields = boxes.filter((box) => !isColumn(box)).map((box) => ({
            name: box.name.trim(),
            x: box.x.toFixed(5),
            y: box.y.toFixed(5),
            width: box.w.toFixed(5),
            height: box.h.toFixed(5),
            angle: normaliseAngle(box.angle),
            ...templatePersonPayload(box, grouping === 'custom'),
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

    return {
        fields,
        columns,
        ruled_ys: columns.length > 0 ? ruled.map((y) => Number(y.toFixed(5))) : [],
        grouping_mode: grouping === 'custom' ? 'custom' : 'auto',
    };
}

function serialiseFields() {
    const container = element('fieldInputs');
    container.replaceChildren();

    // Page 1 is the layout itself; page 2, when there is one, rides along.
    if (twoPage) storeActivePage();
    const first = twoPage
        ? pageLayouts[1]
        : { boxes: marker.toJSON(), ruledYs, grouping: groupingModeInput.value };
    groupingModeInput.value = first.grouping === 'custom' ? 'custom' : 'auto';
    const { fields, columns, ruled_ys: ruledList } = layoutPayload(first.boxes, first.ruledYs, first.grouping);

    // One JSON input each avoids PHP's max_input_vars truncating large layouts.
    [
        ['fields_json', fields],
        ['columns_json', columns],
        ['ruled_ys_json', ruledList],
        // Page 2 of a two-page layout; an empty one removes a page 2 saved before.
        ...(twoPage || savedPageTwo
            ? [['page_two_json', twoPage
                ? layoutPayload(pageLayouts[2].boxes, pageLayouts[2].ruledYs, pageLayouts[2].grouping)
                : { fields: [], columns: [], ruled_ys: [], grouping_mode: 'auto' }]]
            : []),
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

/** The Remove button shows only while a picked file is waiting to be saved. */
function updatePickedSampleUI() {
    element('removePickedSampleBtn').classList.toggle('d-none', pendingSampleFile === null);
    // While a replacement waits to be saved, the stored sample's own button would be a second trash can.
    document.getElementById('deleteStoredSampleBtn')?.classList.toggle('d-none', pendingSampleFile !== null);
}

async function openSample(file, { pendingUpload = true } = {}) {
    if (pendingUpload) pendingSampleFile = null;
    updatePickedSampleUI();
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
        let measurement = await marker.load(file);
        sampleFile = file;
        samplePageCount = measurement?.pageCount ?? 1;
        // Editing page 2: that page of the sample, when it has one.
        if (activePage > 1 && samplePageCount >= activePage) {
            measurement = await marker.load(file, { page: activePage }) ?? measurement;
        }
        sampleLoaded = true;
        forgetSampleGrid();
        loadPrintedLines();
        if (pendingUpload) pendingSampleFile = file;
        updatePickedSampleUI();
        // Only a PDF's first page is drawn, as in the scan workspace.
        element('sampleFileName').textContent = measurement?.pageCount > 1
            ? `${file.name} (${measurement.pageCount} pages)`
            : file.name;
        updateSampleHint();
        window.requestAnimationFrame(() => marker.resetZoom());
        // A sample picked or dropped: exactly two pages are laid out side by side (a
        // register's left and right pages), anything else as one page. A stored
        // sample follows the layout as it was saved.
        if (pendingUpload) {
            await setTwoPage(samplePageCount === 2);
        } else {
            await updateSpread();
        }
    } catch (error) {
        fileInput.value = '';
        showBuilderError(error instanceof Error ? error.message : 'That sample could not be opened.');
    } finally {
        fileLabel.classList.remove('is-loading');
        fileLabel.removeAttribute('aria-busy');
    }
}

/** Let go of a picked, unsaved file: back to the stored sample, or to the blank page. */
async function removePickedSample() {
    pendingSampleFile = null;
    fileInput.value = '';
    updatePickedSampleUI();
    clearBuilderError();
    if (config.sample?.url) {
        // Back to the layout as saved, with its page 2 or without; the stored sample then draws it.
        await setTwoPage(savedPageTwo !== null);
        await openStoredSample();
        return;
    }

    resizeBlankCanvas();
    drawBlankPage();
    marker.layout();
    sampleFile = null;
    samplePageCount = 1;
    sampleLoaded = false;
    forgetSampleGrid();
    element('sampleFileName').textContent = 'Choose sample';
    updateSampleHint();
    window.requestAnimationFrame(() => marker.resetZoom());
    // Back to the layout as saved, with its page 2 or without (now the blank page is drawn).
    await setTwoPage(savedPageTwo !== null);
}

// Asked first, as for a stored sample: the modal names the file.
element('removePickedSampleModal').addEventListener('show.bs.modal', () => {
    element('removePickedSampleName').textContent = pendingSampleFile?.name ?? 'the picked file';
});
element('confirmRemovePickedSampleBtn', HTMLButtonElement).addEventListener('click', () => {
    window.bootstrap.Modal.getInstance(element('removePickedSampleModal'))?.hide();
    removePickedSample();
});

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
    } finally {
        sampleExpected = false;
        updateSampleHint();
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

// Side panel cards that open and close, as on the Align panel: a click
// anywhere on the header but the ? (the title button, for the keyboard).
function setCardOpen(toggle, open) {
    toggle.setAttribute('aria-expanded', String(open));
    toggle.closest('.card-disclosure__header')?.classList.toggle('is-expanded', open);
    return setDisclosureExpanded(document.getElementById(toggle.getAttribute('aria-controls') ?? ''), open);
}

document.querySelectorAll('.template-builder-side-panel .card-disclosure__header').forEach((header) => {
    const toggle = header.querySelector('.card-disclosure__toggle');
    if (!(toggle instanceof HTMLButtonElement)) return;
    header.addEventListener('click', (event) => {
        if (event.target instanceof Element && event.target.closest('.card-help')) return;
        setCardOpen(toggle, toggle.getAttribute('aria-expanded') !== 'true');
    });
});

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
        // Still over the page area, only over another part of it.
        if (eventName === 'dragleave' && event.relatedTarget instanceof Node && viewport.contains(event.relatedTarget)) return;
        viewport.classList.remove('is-dragging');
    });
});

viewport.addEventListener('drop', (event) => selectDroppedSample(event.dataTransfer?.files?.[0]));

function restoreBaseline() {
    const baseline = pageBaselines[activePage];
    invalidFieldIndexes.clear();
    groupingModeInput.value = baseline.grouping;
    ruledYs = [...baseline.ruledYs];
    selectedRule = null;
    estimatedYs = new Set();
    replaceBoxes(baseline.boxes);
    marker.resetZoom();
    viewport.scrollTo({ top: 0, left: 0 });
    clearBuilderError();
}

// Grid lines off: the row lines and every field box are hidden (not removed),
// so the sample shows as scanned.
element('showGridToggle', HTMLInputElement).addEventListener('change', (event) => {
    overlay.classList.toggle('is-grid-hidden', !event.target.checked);
    element('companionOverlay').classList.toggle('is-grid-hidden', !event.target.checked);
});

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

    const commandPressed = event.ctrlKey || event.metaKey;
    const key = event.key.toLocaleLowerCase();

    // Checked before the general rule: a button or checkbox that was just
    // clicked keeps the focus, and Ctrl+Z must still undo that change.
    if (commandPressed && !event.shiftKey && !event.altKey && key === 'z'
        && history.canUndo && !layoutUndoIsBlocked(event, shortcutOptions)) {
        event.preventDefault();
        undoLayoutChange();
        return;
    }

    // Also right after a click on a button or checkbox, which keeps the focus
    // (so the browser would highlight the page's text instead). A text box
    // with text in it keeps its own select-all.
    if (commandPressed && !event.shiftKey && !event.altKey && key === 'a'
        && marker.toJSON().length > 0 && !layoutUndoIsBlocked(event, shortcutOptions)) {
        event.preventDefault();
        marker.selectAll();
        return;
    }

    if (builderShortcutIsBlocked(event, shortcutOptions)) return;

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

/** Whether the page not being edited differs from what is saved. */
function otherPageChanged() {
    if (!twoPage || pageLayouts[3 - activePage] === null) return false;
    const other = pageLayouts[3 - activePage];
    const baseline = pageBaselines[3 - activePage];
    return snapshot(other.boxes) !== snapshot(baseline.boxes)
        || JSON.stringify(other.ruledYs) !== JSON.stringify(baseline.ruledYs)
        || other.grouping !== baseline.grouping;
}

function hasUnsavedChanges() {
    return !layoutMatchesBaseline()
        || otherPageChanged()
        || twoPage !== (savedPageTwo !== null)
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

    // Asked once: not again when the save check sends the form on.
    if (!saveChecked && coveredFieldIndexes.length > 0 && !window.confirm(
        `${coveredFieldIndexes.length === 1 ? '1 field covers' : `${coveredFieldIndexes.length} fields cover`} ledger cells. `
        + 'Scans would read the handwriting under them as those fields, and those rows would lose their cells.\n\n'
        + 'OK: save anyway.\nCancel: go back and move or remove them.',
    )) {
        event.preventDefault();
        return;
    }

    // Publishing, or keeping guessed row lines, is asked first (#saveCheckModal).
    if (!saveChecked) {
        const check = saveCheck();
        if (check.publishing || check.guessed > 0) {
            event.preventDefault();
            openSaveCheck(check);
            return;
        }
    }
    saveChecked = false;

    leavingBySave = true;
    serialiseFields();
    form.setAttribute('aria-busy', 'true');
    form.querySelectorAll('button[type="submit"]').forEach((button) => {
        button.disabled = true;
    });
});

// ------------------------------------------------------------ save check
//
// Before a save that publishes: the same checks the server makes (a sample,
// real column names), and a last "Publish this layout?". Before
// any save: row lines still amber (guessed by Detect) are pointed out.

/** Set once the admin answered the save check, for the submit it starts. */
let saveChecked = false;

function saveCheck() {
    if (twoPage) storeActivePage();
    const pages = twoPage
        ? [pageLayouts[1], pageLayouts[2]]
        : [{ boxes: marker.toJSON(), ruledYs, estimated: [...estimatedYs] }];
    const layoutChanged = !layoutMatchesBaseline()
        || otherPageChanged()
        || twoPage !== (savedPageTwo !== null);
    // A published layout saved without new markers stays published: nothing to publish.
    const publishing = publishIntent.value === '1' && !(config.published && !layoutChanged);

    return {
        publishing,
        problems: publishing
            ? publishProblems({
                hasSample: sampleLoaded,
                pages: pages.map((page) => page.boxes),
                rows: pages.map(pageRowCount),
            })
            : [],
        // Only a page with columns keeps its row lines (see layoutPayload).
        guessed: guessedRowCount(
            pages.map((page) => ({ ruledYs: page.boxes.some(isColumn) ? page.ruledYs : [], estimated: page.estimated })),
            ruleKey,
        ),
    };
}

function openSaveCheck({ publishing, problems, guessed }) {
    const modal = element('saveCheckModal');
    const list = element('saveCheckList');
    const confirm = element('saveCheckConfirmBtn', HTMLButtonElement);
    const draft = element('saveCheckDraftBtn', HTMLButtonElement);
    const guessNote = guessed > 0
        ? `${guessed === 1 ? '1 row line is' : `${guessed} row lines are`} amber: Detect guessed `
            + `${guessed === 1 ? 'it' : 'them'} where the printed line was faint or missing. Check each against the sample `
            + '(drag or nudge it) so rows are cut in the right place.'
        : null;

    let kicker = 'Before saving';
    let title = 'Some row lines are guesses';
    let lead = '';
    let items = guessNote ? [guessNote] : [];

    if (publishing && problems.length > 0) {
        kicker = 'Publish';
        title = 'Publish with these still missing?';
        lead = 'You can scan with this layout, but these are not done yet. Go back to fix them, or publish it as it is:';
        items = [...problems, ...items];
    } else if (publishing) {
        kicker = 'Publish';
        title = 'Publish this layout?';
        lead = config.published
            ? 'Scans use the new version as soon as it is saved. Records already read keep the old one.'
            : 'Scans use this layout as soon as it is saved. It replaces the layout used now for this type.';
    } else {
        // Only the guessed rows to tell: said in the lead, no list.
        lead = guessNote ?? '';
        items = [];
    }

    element('saveCheckKicker').textContent = kicker;
    element('saveCheckKicker').className = `text-uppercase small fw-semibold mb-1 ${publishing ? 'text-success' : 'text-warning'}`;
    element('saveCheckTitle').textContent = title;
    element('saveCheckLead').textContent = lead;
    list.replaceChildren(...items.map((text) => {
        const item = document.createElement('li');
        item.className = 'mb-1';
        item.textContent = text;
        return item;
    }));
    list.classList.toggle('d-none', items.length === 0);

    // Something missing: the admin may still publish it, and the server is told so.
    const anyway = publishing && problems.length > 0;
    confirm.textContent = anyway ? 'Save and publish' : (publishing ? 'Publish' : 'Save anyway');
    confirm.className = `btn ${publishing ? 'btn-success' : 'btn-primary'}`;
    confirm.dataset.anyway = anyway ? '1' : '0';
    draft.classList.add('d-none');

    window.bootstrap.Modal.getOrCreateInstance(modal).show();
}

function submitAfterCheck(publish, anyway = false) {
    window.bootstrap.Modal.getInstance(element('saveCheckModal'))?.hide();
    publishIntent.value = publish ? '1' : '0';
    publishAnyway.value = publish && anyway ? '1' : '0';
    saveChecked = true;
    form.requestSubmit();
}

element('saveCheckConfirmBtn', HTMLButtonElement).addEventListener('click', (event) => submitAfterCheck(
    publishIntent.value === '1',
    event.currentTarget instanceof HTMLElement && event.currentTarget.dataset.anyway === '1',
));
element('saveCheckDraftBtn', HTMLButtonElement).addEventListener('click', () => submitAfterCheck(false));

// Explicitly attach the file that produced the current preview. Native file
// inputs already contribute it in the common case; set() also covers dropped
// files and browsers that lose the input's FileList before submission.
form.addEventListener('formdata', (event) => {
    if (pendingSampleFile instanceof File) {
        event.formData.set('sample_document', pendingSampleFile, pendingSampleFile.name);
    }
    // The page's shape, for the server's check that tilted markers stay on it
    // and for the scan's "may be sideways" hint.
    event.formData.set('page_ratio', String(canvas.width / canvas.height));
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

// The row lines, drawn and dragged as in the Align step: a grip on each
// end of the table selects and drags its line (Shift: every line), a
// double-click on a grip removes it, and a line snaps onto the sample's
// printed rules (Alt: place freely). Only the grips take the pointer, so the
// columns under the lines can still be dragged.
const rowLines = new RowLineLayer({
    overlay,
    printedLines: () => printedLines,
    onSelect: (index) => {
        // Take focus off a name box, where the arrow keys would only move the caret.
        const focused = document.activeElement;
        if (focused instanceof HTMLElement && focused.matches('input, textarea, select')) focused.blur();
        selectRule(index);
    },
    onMove: (rows, { index, together }) => {
        if (together) {
            const dy = rows[0] - ruledYs[0];
            estimatedYs = new Set([...estimatedYs].map((key) => ruleKey(Number(key) + dy)));
        } else {
            // Placed by hand: no longer an estimate.
            estimatedYs.delete(ruleKey(ruledYs[index]));
        }
        applyGrid(rows);
    },
    onRemove: (index) => removeRule(index),
});

const ruleKey = rowKey;

function ledgerColumns(boxes = marker.toJSON()) {
    return boxes.filter(isColumn);
}

/** Draw the row lines across the table's columns (or the page, without columns). */
function drawRules(ys = ruledYs, columns = ledgerColumns()) {
    rowLines.render({
        rows: ys,
        left: columns.length ? Math.min(...columns.map((c) => c.x)) : 0.02,
        right: columns.length ? Math.max(...columns.map((c) => c.x + c.w)) : 0.98,
        selected: selectedRule,
        estimated: estimatedYs,
        // On a page scanned a little turned, the lines turn with the columns.
        tilt: gridTilt(columns),
        aspect: pageAspect(),
    });
}

/** The page's width over its height in pixels: a tilt is an angle in pixels. */
function pageAspect() {
    return canvas.width > 0 && canvas.height > 0 ? canvas.width / canvas.height : 1;
}

function renderLedgerGrid() {
    const columns = ledgerColumns();
    drawRules(ruledYs, columns);

    const rows = Math.max(0, ruledYs.length - 1);
    // Only what the stepper and the page do not show already (the amber lines say the rest).
    const summary = element('ledgerGridSummary');
    summary.textContent = columns.length === 0 && ruledYs.length === 0
        ? 'No grid yet: each field is read as one rectangle.'
        : '';
    summary.classList.toggle('d-none', summary.textContent === '');

    const rowsInput = element('evenRowsInput', HTMLInputElement);
    if (document.activeElement !== rowsInput) rowsInput.value = rows > 0 ? String(rows) : '';
    element('removeRuledLineBtn', HTMLButtonElement).disabled = selectedRule === null && ruledYs.length <= 2;

    renderRowMatch();

    const tiltInput = element('gridTiltValue', HTMLInputElement);
    if (document.activeElement !== tiltInput) tiltInput.value = formatTilt(gridTilt(columns));
    tiltInput.disabled = columns.length === 0;
    element('tiltLeftBtn', HTMLButtonElement).disabled = columns.length === 0;
    element('tiltRightBtn', HTMLButtonElement).disabled = columns.length === 0;
}

// ---------------------------------------------------------------- table tilt
// A page scanned a little turned: the columns and row lines turn together, in
// tenths of a degree, as in the Align step. The tilt is kept in the
// columns' own angles, so it is saved with the layout.
const MAX_TILT = 10;

function formatTilt(degrees) {
    const value = Math.round(degrees * 10) / 10;
    return `${(value === 0 ? 0 : value).toFixed(1).replace('-', '\u2212')}\u00b0`;
}

function parseTilt(text) {
    const value = Number.parseFloat(String(text).replace(/[\u2212\u2013]/g, '-').replace(',', '.').replace(/[^0-9.+-]/g, ''));
    return Number.isFinite(value) ? value : null;
}

/** Turn the table to `degrees` clockwise, as one undoable change. */
function setTableTilt(degrees) {
    const boxes = marker.toJSON();
    const wanted = Math.round(Math.min(MAX_TILT, Math.max(-MAX_TILT, Number(degrees) || 0)) * 10) / 10;
    const turned = tiltColumns(boxes, wanted - gridTilt(ledgerColumns(boxes)), pageAspect());

    if (turned === boxes) {
        renderLedgerGrid();
        return;
    }
    replaceBoxes(turned, { keepSelection: true });
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
    // The card may be closed: a mark on its header says there is something to check.
    element('ledgerCardAlert').classList.toggle(
        'd-none',
        problems.length === 0 && coveredFieldIndexes.length === 0 && !rowsMismatched,
    );

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

    const request = detectGridOn(canvas);
    sampleGrid = request;
    // A failed request is asked again next time.
    request.catch(() => {
        if (sampleGrid === request) sampleGrid = null;
    });
    return request;
}

/** Ask the server for the printed grid of the page drawn on `source`. */
async function detectGridOn(source) {
    const blob = await new Promise((resolve, reject) => {
        source.toBlob((result) => (result ? resolve(result) : reject(new Error('The sample could not be prepared.'))), 'image/png');
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

/**
 * A page's markers and row lines fitted to what Detect found on it: its own
 * column lines (columns already there keep their names and settings), its
 * ruled rows inside the table frame, and which lines are guesses (amber).
 *
 * @returns {{ boxes: object[], ruledYs: number[], estimated: Set<string> }}
 */
function gridFromDetection(payload, boxes, aspect) {
    if (!Array.isArray(payload?.ruled_ys) || payload.ruled_ys.length < 2) {
        throw new Error('No evenly ruled rows were found on this sample. Add the row lines by hand.');
    }

    const found = normaliseRuledYs(payload.ruled_ys);
    // Lines below the table's frame (a signature line, the margin) are no rows.
    const detected = rowsWithinFrame(found, payload.frame_bottom);
    const top = detected[0];
    const bottom = detected[detected.length - 1];
    let next = boxes;

    const printed = (Array.isArray(payload.columns) ? payload.columns : [])
        .map((column) => ({ x: column.box[0], w: column.box[2] }));

    if (printed.length > 0) {
        // This page's own column lines. Columns already in the layout (page 2
        // starts as a copy of page 1's) keep their names and settings but
        // move onto this page's printed columns.
        const fields = boxes.filter((box) => !isColumn(box));
        const fitted = fitColumnsToPrinted(boxes.filter(isColumn), printed, top, bottom);
        const taken = new Set(fields.map((box) => box.name.trim().toLocaleLowerCase()));
        fitted.columns.forEach((column, index) => {
            if (!fitted.created.includes(index)) taken.add(column.name.trim().toLocaleLowerCase());
        });
        fitted.created.forEach((index) => {
            // Name a new column after the topmost field already sitting in it.
            const column = fitted.columns[index];
            const inside = fields
                .filter((field) => field.x + field.w / 2 >= column.x && field.x + field.w / 2 <= column.x + column.w)
                .sort((a, b) => a.y - b.y)[0];
            const fieldName = inside?.name?.trim();
            fitted.columns[index] = {
                ...column,
                name: uniqueName(fieldName ? `${fieldName} column` : `Column ${index + 1}`, taken),
                personGroup: null,
                personFieldOrder: null,
            };
        });
        // A page scanned a little turned: the columns turn with it, as Snap does.
        next = columnsAtPageTilt([...fields, ...fitted.columns], payload.tilt, aspect);
    } else {
        // No column lines found: keep the admin's columns, stretched over the detected rows.
        next = boxes.map((box) => (isColumn(box) ? { ...box, y: top, h: bottom - top } : box));
    }

    // Lines below the last printed rule, and lines put back where a printed
    // rule was too faint to find, are Detect's guesses: shown amber until
    // each is checked (moved or nudged) against the page.
    const estimated = Math.max(0, Math.min(found.length, Number(payload.estimated_ys) || 0));
    const guessed = new Set([
        ...found.slice(found.length - estimated),
        ...normaliseRuledYs(Array.isArray(payload.bridged_ys) ? payload.bridged_ys : []),
    ].map(ruleKey));

    return {
        boxes: next,
        ruledYs: detected,
        estimated: new Set(detected.map(ruleKey).filter((key) => guessed.has(key))),
    };
}

/**
 * Detect from sample, on the page being edited only (one undo step). On a
 * two-page layout the other page is detected when it is the one being edited.
 */
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
        if (!printedLines) usePrintedLines(payload?.lines);

        const result = gridFromDetection(payload, marker.toJSON(), pageAspect());
        estimatedYs = result.estimated;
        selectedRule = null;
        ruledYs = result.ruledYs;
        replaceBoxes(result.boxes);

    } catch (error) {
        showBuilderError(error instanceof Error ? error.message : 'The grid could not be detected.');
    } finally {
        button.disabled = false;
        button.removeAttribute('aria-busy');
    }
}

element('detectGridBtn', HTMLButtonElement).addEventListener('click', detectLedgerGrid);

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

// The selected line, or the last one, as long as a row is left.
element('removeRuledLineBtn', HTMLButtonElement).addEventListener('click', () => {
    removeRule(selectedRule ?? (ruledYs.length > 2 ? ruledYs.length - 1 : null));
});

function spaceRowsEvenly() {
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
}

element('evenRowsInput', HTMLInputElement).addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    spaceRowsEvenly();
});

element('tiltLeftBtn', HTMLButtonElement).addEventListener('click', (event) => (
    setTableTilt(gridTilt(ledgerColumns()) - (event.shiftKey ? 1 : 0.1))
));
element('tiltRightBtn', HTMLButtonElement).addEventListener('click', (event) => (
    setTableTilt(gridTilt(ledgerColumns()) + (event.shiftKey ? 1 : 0.1))
));
element('gridTiltValue', HTMLInputElement).addEventListener('change', (event) => {
    const value = parseTilt(event.currentTarget.value);
    if (value === null) renderLedgerGrid();
    else setTableTilt(value);
});
element('gridTiltValue', HTMLInputElement).addEventListener('keydown', (event) => {
    event.stopPropagation();
    if (event.key === 'Enter') event.currentTarget.blur();
});
element('gridTiltValue', HTMLInputElement).addEventListener('blur', () => renderLedgerGrid());

element('selectCoveredFieldsBtn', HTMLButtonElement).addEventListener('click', () => {
    if (coveredFieldIndexes.length > 0) marker.selectIndexes(coveredFieldIndexes, { source: 'group' });
});

element('removeCoveredFieldsBtn', HTMLButtonElement).addEventListener('click', () => {
    const covered = new Set(coveredFieldIndexes);
    replaceBoxes(marker.toJSON().filter((_, index) => !covered.has(index)));
});

// ------------------------------------------------------------- two pages
// Page 1 and page 2 sit side by side in the one scroll box. The page being
// edited is live (the usual markers, row lines and person rows); the other
// shows its own layout and is edited by clicking it. Each page keeps its own
// markers, row lines and person-row mode while the other is on screen.
const companionMarker = new FieldMarker({
    canvas: element('companionCanvas', HTMLCanvasElement),
    overlay: element('companionOverlay'),
    fitViewport: viewport,
    fitShare: 0.5,
    readOnly: true,
    fieldSettings: true,
});
marker.linked = [companionMarker];
// Its row lines too, for looking at only: no grips.
const companionRows = new RowLineLayer({ overlay: element('companionOverlay') });

/** Draw the other page's own row lines across its columns. */
function renderCompanionRows(layout) {
    const columns = ledgerColumns(layout.boxes);
    const ys = layout.ruledYs ?? [];
    if (columns.length === 0 || ys.length < 2) {
        companionRows.render({ rows: [] });
        return;
    }
    const { width, height } = companionMarker.canvas;
    companionRows.render({
        rows: ys,
        left: Math.min(...columns.map((c) => c.x)),
        right: Math.max(...columns.map((c) => c.x + c.w)),
        locked: true,
        estimated: new Set((layout.estimated ?? []).map(ruleKey)),
        tilt: gridTilt(columns),
        aspect: width > 0 && height > 0 ? width / height : 1,
    });
}

/** What page being edited has now, kept for when it is turned to again. */
function storeActivePage() {
    pageLayouts[activePage] = {
        boxes: cloneBoxes(marker.toJSON()),
        ruledYs: [...ruledYs],
        grouping: groupingModeInput.value === 'custom' ? 'custom' : 'auto',
        // Row lines still shown amber (guessed, not checked): they stay amber on return.
        estimated: [...estimatedYs],
    };
}

/** Draw `page` of the sample (or a blank page) on a canvas through its marker. */
async function drawSamplePage(target, page) {
    if (sampleFile && samplePageCount >= page) {
        await target.load(sampleFile, { page });
        return;
    }
    target.canvas.width = canvas.width;
    target.canvas.height = canvas.height;
    drawBlankPage(target.canvas);
    target.layout();
}

/**
 * What says one page or two in the side panel: the badge, the Detect button,
 * the record list and the row check (both pages' parts change on a switch).
 */
function refreshPageCount() {
    const badge = element('pagesBadge');
    badge.textContent = twoPage ? '2 pages' : '1 page';
    badge.classList.toggle('template-pages-badge--two', twoPage);
    // Two pages: only the page being edited (click the other page to detect that one).
    element('detectGridLabel').textContent = twoPage ? `Detect page ${activePage}` : 'Detect from sample';
    element('detectGridBtn').title = twoPage
        ? `Find page ${activePage}'s columns and row lines from its printed rules`
        : "Find the columns and row lines from the sample's printed rules";
    renderFieldList(marker.toJSON());
    renderLedgerGrid();
    updateLedgerChecks();
}

async function updateSpread({ focus = false } = {}) {
    const pane = element('docCompanionPage');
    const spread = element('docSpread');
    spread.classList.toggle('is-spread', twoPage);
    pane.classList.toggle('d-none', !twoPage);
    element('docMainLabel').classList.toggle('d-none', !twoPage);
    marker.fitShare = twoPage ? 0.5 : 1;
    refreshPageCount();

    if (!twoPage) {
        focusedZoom = null;
        window.requestAnimationFrame(() => marker.resetZoom());
        return;
    }

    const other = 3 - activePage;
    // Page 1 on the left, as in a book.
    pane.style.order = other < activePage ? '-1' : '1';
    element('docMainLabel').textContent = `Page ${activePage} · editing`;
    element('docCompanionLabel').textContent = `Page ${other} — click to edit`;
    element('docCompanionSwitch').classList.remove('d-none');

    try {
        await drawSamplePage(companionMarker, other);
    } catch (error) {
        console.warn(`Page ${other} of the sample could not be shown beside the page:`, error);
    }
    companionMarker.setBoxes(cloneBoxes(pageLayouts[other].boxes));
    renderCompanionRows(pageLayouts[other]);
    window.requestAnimationFrame(() => {
        if (focus) {
            focusActivePage();
        } else {
            focusedZoom = null;
            marker.resetZoom();
        }
    });
}

/**
 * Zoom in on the page being edited and centre it: as large as the room allows,
 * whole, with one easing motion. Called when a page of the spread is clicked.
 */
function focusActivePage({ animate = true } = {}) {
    focusedZoom = marker.focusOn(element('docMainPage'), { duration: animate ? 420 : 0 });
    // In focus: the normal cursors, whatever zoom the room allowed.
    element('docSpread').classList.add('is-zoomed-in');
}

let focusRoom = null;
let focusRefitTimer = 0;
new ResizeObserver(() => {
    const room = { width: viewport.clientWidth, height: viewport.clientHeight };
    const before = focusRoom;
    focusRoom = room;
    if (!before || focusedZoom === null || !twoPage) return;
    if (Math.abs(room.width - before.width) < 4 && Math.abs(room.height - before.height) < 4) return;
    // The side bar slides over a moment: fit once it has settled.
    window.clearTimeout(focusRefitTimer);
    focusRefitTimer = window.setTimeout(() => {
        if (focusedZoom === null) return;
        if (Math.abs(marker.zoom - focusedZoom) > 0.011) {
            focusedZoom = null; // zoomed by hand since: leave it
            return;
        }
        focusActivePage({ animate: false });
    }, 150);
}).observe(viewport);

// Before the page being edited is zoomed on, its first click only does that:
// the markers under it are for working once it is in view.
element('docMainPage').addEventListener('pointerdown', (event) => {
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey) return;
    // Already in focus (even where the fit lands near 100%): work on the page.
    if (!twoPage || focusedZoom !== null || Math.abs(marker.zoom - 1) > 0.01) return;
    if (event.target instanceof Element && event.target.closest('.doc-spread__label')) return;

    event.preventDefault();
    event.stopPropagation();
    const main = element('docMainPage');
    const swallowClick = (clickEvent) => {
        clickEvent.stopPropagation();
        clickEvent.preventDefault();
    };
    main.addEventListener('click', swallowClick, { capture: true, once: true });
    window.setTimeout(() => main.removeEventListener('click', swallowClick, true), 400);
    focusActivePage();
}, true);

/** Edit the other page: its sample page, markers, row lines and person-row mode. */
async function switchPage(page, { focus = false } = {}) {
    if (!twoPage || page === activePage) return;

    storeActivePage();
    activePage = page;
    try {
        await drawSamplePage(marker, page);
    } catch (error) {
        showBuilderError(error instanceof Error ? error.message : `Page ${page} of the sample could not be opened.`);
    }

    const layout = pageLayouts[page];
    invalidFieldIndexes.clear();
    groupingModeInput.value = layout.grouping;
    ruledYs = [...layout.ruledYs];
    selectedRule = null;
    estimatedYs = new Set(layout.estimated ?? []);
    previousMarkerCount = 0;
    replaceBoxes(layout.boxes);
    // Undo steps belong to the page they were made on.
    history = new LayoutHistory();
    previousMarkerCount = layout.boxes.length;
    history.record(layoutState());
    forgetSampleGrid();
    if (sampleLoaded) loadPrintedLines();
    clearBuilderError();
    updateResetUI();
    await updateSpread({ focus });
}

/**
 * Two pages or one, decided by the sample: a PDF of two pages is laid out as
 * page 1 and page 2 side by side, anything else as one page. There is no switch.
 */
async function setTwoPage(on) {
    if (on === twoPage) {
        await updateSpread();
        return;
    }
    if (on) {
        twoPage = true;
        // A page 2 with nothing on it starts from page 1's markers: the pages are often alike.
        if (pageLayouts[2].boxes.length === 0 && pageLayouts[1] === null) {
            pageLayouts[2] = { boxes: cloneBoxes(marker.toJSON()), ruledYs: [...ruledYs], grouping: groupingModeInput.value === 'custom' ? 'custom' : 'auto' };
        }
        pageLayouts[1] = null;
        await updateSpread();
    } else {
        if (activePage === 2) await switchPage(1);
        twoPage = false;
        pageLayouts[1] = null;
        await updateSpread();
    }
    updateResetUI();
}
// A click on the other page: it becomes the page edited, and is brought into focus.
element('docCompanionPage').addEventListener('click', () => switchPage(3 - activePage, { focus: true }));

marker.setBoxes(cloneBoxes(initialBoxes));
renderLedgerGrid();
window.requestAnimationFrame(() => marker.resetZoom());
updateSpread();
openStoredSample();
