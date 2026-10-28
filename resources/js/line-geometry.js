/**
 * Line geometry helpers for the scan workspace.
 *
 * Pure functions, so they are unit-tested without a browser:
 *
 * - alignedGeometry(): turns the markers and row lines Staff aligned on the
 *   page into the geometry line detection runs on. Rectangle fields pass
 *   straight through; ledger columns keep their aligned boxes, and the row
 *   lines go as the Align step holds them (they follow the columns when the
 *   table is moved, and Snap to table takes them from the page). The Python
 *   side then snaps every line to the rules actually printed on the page.
 * - verificationItems(): turns the stored page lines into the field list the
 *   Verify step renders, with one person group per ledger row.
 */

export const FLAG_NO_ROW = 'no_row';
export const FLAG_SHARED_CELL = 'shared_cell';
export const SOURCE_TEMPLATE = 'template';
export const SOURCE_FIELD = 'field';

const clamp01 = (value) => Math.min(1, Math.max(0, value));

/**
 * A marker's tilt (degrees clockwise) as the server takes it: present only
 * when the marker is turned. Columns share the grid's tilt.
 */
function angleOf(marker) {
    const angle = Math.round(Number(marker?.angle) * 10) / 10;
    return Number.isFinite(angle) && angle !== 0 ? { angle } : {};
}

/**
 * @param {Array<{name: string, x: number, y: number, w: number, h: number, kind?: string, columnIndex?: number, personGroup?: number, personFieldOrder?: number}>} aligned
 *        Markers as Staff left them (FieldMarker#toJSON()).
 * @param {number[]} ruledYs The row lines as the Align step holds them, page fractions.
 * @param {{keepRows?: boolean}} [options] keepRows: Staff set the row lines by
 *        hand, so Detect keeps them instead of taking the page's rows.
 */
export function alignedGeometry(aligned, ruledYs, { keepRows = false } = {}) {
    const columns = aligned.filter((box) => box.kind === 'column');
    const fields = aligned.filter((box) => box.kind !== 'column');

    let ruled = [];
    if (columns.length > 0 && ruledYs.length >= 2) {
        // On the page and strictly increasing, as the server requires.
        const sorted = ruledYs.map((y) => clamp01(Number(y))).sort((a, b) => a - b);
        ruled = sorted.filter((y, index) => index === 0 || y > sorted[index - 1] + 1e-6);
    }

    return {
        columns: columns.map((column) => {
            // A column never runs past the page: the server refuses a marker
            // that does, and rows the page does not have hold nothing.
            const y = clamp01(column.y);
            return {
                name: column.name,
                box: [column.x, y, column.w, Math.min(column.h, 1 - y)],
                ...angleOf(column),
            };
        }),
        ruled_ys: ruled,
        fields: fields.map((field) => ({
            name: field.name,
            box: [field.x, field.y, field.w, field.h],
            person_group: Number.isInteger(field.personGroup) ? field.personGroup : null,
            person_field_order: Number.isInteger(field.personFieldOrder) ? field.personFieldOrder : null,
            ...angleOf(field),
        })),
        ...(keepRows && ruled.length >= 2 ? { keep_rows: true } : {}),
    };
}

/**
 * Where Snap to table took the row lines from, as a sentence for the Align
 * step, or '' when there is nothing to say. `rows` is the server's summary,
 * {from_page, count, template, estimated: [indexes], reason?}, or null when
 * the rows did not come from Snap (the template's, or Detect's). `estimated`
 * is how many row lines have no printed line under them now.
 *
 * @param {{from_page?: boolean, count?: number, template?: number, estimated?: number[], reason?: string}|null|undefined} rows
 * @param {number} [estimated]
 */
export function snapRowsMessage(rows, estimated = Array.isArray(rows?.estimated) ? rows.estimated.length : 0) {
    const plural = (count, noun) => `${count} ${noun}${count === 1 ? '' : 's'}`;
    const check = estimated > 0
        ? ` ${plural(estimated, 'amber line')} ${estimated === 1 ? 'has' : 'have'} no printed line under ${estimated === 1 ? 'it' : 'them'}: check against the page.`
        : '';
    if (!rows || typeof rows !== 'object') return check.trim();

    if (rows.from_page) {
        const count = Number(rows.count) || 0;
        const template = Number(rows.template) || 0;
        return `Rows found on the page${count === template ? '' : ` (template: ${template})`}.${check}`;
    }

    const reasons = {
        no_rows: 'no evenly ruled rows were found on this page',
        uneven_rows: 'its rows differ in height',
        row_height: 'this page’s rows are a different height',
        no_match: 'the page’s rows do not line up with the grid',
    };
    const reason = reasons[rows.reason];
    return reason ? `Rows kept from the template: ${reason}.${check}` : check.trim();
}

/**
 * Stored page lines -> the entries the Verify step lists and submits.
 *
 * Each entry carries the metadata the existing grouping code uses: ledger lines
 * are grouped per row (personGroup = row, personFieldOrder = column), template
 * rectangles keep their own grouping, and a line flagged no_row is kept out of
 * every person so a reviewer places it deliberately. A rectangle Detect split
 * into written lines keeps its grouping too; its lines are numbered top to
 * bottom ("Diseases · line 2"), or keep the plain field name when there is one.
 *
 * @param {Array<object>} lines  PageLine#toClient() payloads.
 * @param {{width: number, height: number}} page
 */
export function verificationItems(lines, page) {
    const width = Math.max(1, page.width);
    const height = Math.max(1, page.height);
    const taken = new Map();
    const linesPerField = new Map();
    lines.filter((line) => line.source === SOURCE_FIELD).forEach((line) => {
        linesPerField.set(line.column, (linesPerField.get(line.column) ?? 0) + 1);
    });

    const uniqueName = (base) => {
        const key = base.toLocaleLowerCase();
        const count = (taken.get(key) ?? 0) + 1;
        taken.set(key, count);
        return count === 1 ? base : `${base} (${count})`;
    };

    return lines.map((line) => {
        const [bx, by, bw, bh] = line.bbox;
        const flags = Array.isArray(line.flags) ? line.flags : [];
        const inField = line.source === SOURCE_TEMPLATE || line.source === SOURCE_FIELD;
        const noRow = flags.includes(FLAG_NO_ROW);

        let name;
        // What Verify shows above the value. A ledger line is headed by its
        // column alone, because its person's row is the group it sits in; the
        // written lines of one drawn box need their line number to tell them
        // apart, since they all share the box's name.
        let heading = line.column;
        let personGroup = null;
        let personFieldOrder = null;
        if (inField) {
            const numbered = line.source === SOURCE_FIELD && linesPerField.get(line.column) > 1;
            if (numbered) heading = `${line.column} · line ${line.row}`;
            name = uniqueName(numbered ? heading : line.column);
            personGroup = Number.isInteger(line.personGroup) ? line.personGroup : null;
            personFieldOrder = Number.isInteger(line.personFieldOrder) ? line.personFieldOrder : null;
        } else if (noRow) {
            name = uniqueName(`${line.column} · needs review`);
        } else {
            name = uniqueName(`${line.column} · row ${line.row}`);
            personGroup = line.row;
            personFieldOrder = line.columnIndex;
        }

        return {
            lineId: line.id,
            name,
            // The template field or ledger column this line was read under:
            // how its settings are looked up, so never numbered.
            label: line.column,
            heading,
            x: clamp01(bx / width),
            y: clamp01(by / height),
            w: Math.max(0.00001, Math.min(1 - clamp01(bx / width), bw / width)),
            h: Math.max(0.00001, Math.min(1 - clamp01(by / height), bh / height)),
            ...(personGroup !== null ? { personGroup } : {}),
            ...(personGroup !== null && personFieldOrder !== null ? { personFieldOrder } : {}),
            needsReview: flags.length > 0,
            adjusted: Boolean(line.adjusted),
            flags,
            polygon: line.polygon,
            cropUrl: line.cropUrl,
            reading: {
                name,
                text: line.text ?? '',
                confidence: line.confidence ?? 0,
                ...(line.error ? { error: line.error } : {}),
            },
        };
    });
}

/**
 * The geometry Detect fitted to the page -> markers for the Align step.
 *
 * Inverse of alignedGeometry(): columns come back as column markers, fields as
 * ordinary markers keeping their person grouping.
 *
 * @param {{columns: Array<{name: string, box: number[]}>, fields: Array<{name: string, box: number[], person_group?: number|null, person_field_order?: number|null}>}} geometry
 */
export function geometryMarkers(geometry, page = null) {
    const fields = (geometry?.fields ?? []).map((field) => ({
        name: field.name,
        ...fieldBox(field, page),
        ...(Number.isInteger(field.person_group) ? { personGroup: field.person_group } : {}),
        ...(Number.isInteger(field.person_group) && Number.isInteger(field.person_field_order)
            ? { personFieldOrder: field.person_field_order } : {}),
    }));
    const columns = (geometry?.columns ?? []).map((column, columnIndex) => ({
        name: column.name,
        x: column.box[0],
        y: column.box[1],
        w: column.box[2],
        h: column.box[3],
        kind: 'column',
        columnIndex,
        ...angleOf(column),
    }));
    return [...fields, ...columns];
}

/**
 * A field's marker box (and tilt) from what the server sent back.
 *
 * When the server straightened a page it moves each field onto it as a turned
 * four-corner outline; the marker is that outline's own upright box, turned.
 * Undoing the turn needs the page's pixel size, because a turn is only a turn
 * in pixels, not in page fractions of a non-square page.
 */
function fieldBox(field, page) {
    const polygon = Array.isArray(field.polygon) ? field.polygon : null;
    const width = Number(page?.width);
    const height = Number(page?.height);
    if (!polygon || polygon.length !== 4 || !(width > 0) || !(height > 0)) {
        return { x: field.box[0], y: field.box[1], w: field.box[2], h: field.box[3], ...angleOf(field) };
    }
    const corners = polygon.map(([x, y]) => [x * width, y * height]);
    const [[x0, y0], [x1, y1], , [x3, y3]] = corners;
    const w = Math.hypot(x1 - x0, y1 - y0);
    const h = Math.hypot(x3 - x0, y3 - y0);
    const cx = corners.reduce((sum, [x]) => sum + x, 0) / 4;
    const cy = corners.reduce((sum, [, y]) => sum + y, 0) / 4;
    return {
        x: clamp01((cx - w / 2) / width),
        y: clamp01((cy - h / 2) / height),
        w: Math.min(1, w / width),
        h: Math.min(1, h / height),
        ...angleOf({ angle: Math.atan2(y1 - y0, x1 - x0) * 180 / Math.PI }),
    };
}

/**
 * What Staff should check about the grid as a whole, as sentences.
 *
 * The page job reports these as codes with numbers (ml/line_markers.py,
 * _grid_notes); they are worded here so the wording can be tested. A note is a
 * question to Staff, never a verdict: the grid may be right.
 *
 * @param {Array<{code: string, count?: number, rows?: number|number[]}>|null|undefined} notes
 * @returns {string[]}
 */
export function gridNotes(notes) {
    const plural = (count, singular, many) => (count === 1 ? singular : many);
    // "21", "20 and 21", "3, 4, 5, 6 and 7", "3, 4, 5, 6, 7 and 2 more".
    const list = (numbers, total) => {
        const shown = numbers.slice(0, 5);
        if (total > shown.length) return `${shown.join(', ')} and ${total - shown.length} more`;
        return shown.length > 1 ? `${shown.slice(0, -1).join(', ')} and ${shown[shown.length - 1]}` : String(shown[0]);
    };

    return (Array.isArray(notes) ? notes : []).flatMap((note) => {
        const count = Number(note?.count) || 0;
        if (note?.code === 'rows_uneven' && Array.isArray(note.rows) && note.rows.length > 0) {
            const total = Math.max(count, note.rows.length);
            return [`${total > 1 ? 'Rows' : 'Row'} ${list(note.rows.map(Number), total)} ${total > 1 ? 'differ' : 'differs'} `
                + 'a lot in height from the template’s. Check that the row lines sit on the printed lines.'];
        }
        if (note?.code === 'rows_do_not_fit' && count > 0) {
            return [`This page does not look like the ledger the template was made for: the writing does not sit in the template's rows. `
                + `${count === 1 ? 'The column was' : `${count} columns were`} read as free text, line by line, like an added field.`];
        }
        if (note?.code === 'grid_moved' && Number(note.rows) !== 0 && Number.isFinite(Number(note.rows))) {
            const rows = Math.abs(Number(note.rows));
            return [`Detect moved the grid ${Number(note.rows) > 0 ? 'down' : 'up'} ${rows} ${plural(rows, 'row', 'rows')} from where you placed it. `
                + 'If your first row was already on the first entry, move the markers back and use Scan with OCR.'];
        }
        if (note?.code === 'lines_below_grid' && count > 0) {
            const rows = Math.max(1, Number(note.rows) || 1);
            return [`${count} written ${plural(count, 'line lies', 'lines lie')} below the last row and ${plural(count, 'is', 'are')} not read `
                + `(about ${rows} more ${plural(rows, 'row', 'rows')}). This page may have more rows than the template.`];
        }
        if (note?.code === 'rows_empty' && Array.isArray(note.rows) && note.rows.length > 0) {
            const total = Math.max(count, note.rows.length);
            return [`Nothing was read in ${total > 1 ? 'rows' : 'row'} ${list(note.rows.map(Number), total)}, though later rows were. `
                + `If the page has writing there, check ${total > 1 ? 'those rows' : 'that row'} before you submit.`];
        }
        if (note?.code === 'lines_above_grid' && count > 0) {
            return [`${count} written ${plural(count, 'line sits', 'lines sit')} in the row directly above the first row. `
                + 'If that row holds entries, move the grid up one row.'];
        }

        return [];
    });
}

/**
 * One line of summary after Detect, e.g.
 * "11 columns · 20 rows · 219 handwritten lines · 2 need review" or
 * "1 field · 8 handwritten lines".
 */
export function detectionSummary(page) {
    const lines = Array.isArray(page?.lines) ? page.lines : [];
    const ledger = lines.filter((line) => line.source !== SOURCE_TEMPLATE && line.source !== SOURCE_FIELD);
    const rows = new Set(ledger.filter((line) => Number.isInteger(line.row)).map((line) => line.row)).size;
    const columns = page?.geometry?.columns?.length ?? 0;
    const fields = page?.geometry?.fields?.length ?? 0;
    const written = lines.filter((line) => line.source !== SOURCE_TEMPLATE).length;
    const flagged = lines.filter((line) => Array.isArray(line.flags) && line.flags.length > 0).length;
    const degrees = Math.abs(Number(page?.deskew ?? 0));
    const plural = (count, noun) => `${count} ${noun}${count === 1 ? '' : 's'}`;
    const parts = [];
    if (columns > 0) parts.push(plural(columns, 'column'));
    if (rows > 0) parts.push(plural(rows, 'row'));
    if (fields > 0) parts.push(plural(fields, 'field'));
    if (written > 0 || parts.length === 0) parts.push(plural(written, 'handwritten line'));
    if (flagged > 0) parts.push(`${flagged} need${flagged === 1 ? 's' : ''} review`);
    return {
        title: degrees >= 0.1 ? `Page straightened by ${degrees.toFixed(1)}°` : 'Page is straight',
        text: parts.join(' · '),
        flagged,
        notes: gridNotes(page?.notes),
    };
}

/**
 * A short explanation for a flagged line, shown under its text box.
 */
export function flagExplanation(flags) {
    if (flags.includes(FLAG_NO_ROW)) {
        return 'Needs review: this line sits between two rows. Check which person it belongs to.';
    }
    if (flags.includes(FLAG_SHARED_CELL)) {
        return 'Needs review: this cell holds more than one line. Check the outline covers the right writing.';
    }
    return '';
}
