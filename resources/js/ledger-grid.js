/**
 * Ledger grid helpers for the Template Builder and the Align step.
 *
 * A ruled register is described by its column markers and the y of every
 * printed row line (page fractions, top to bottom). The Align step starts
 * from the template's row lines and carries them along when Staff move or
 * stretch the columns' band - the median top and bottom of the columns. So a
 * template is only right when its row lines sit where they belong inside
 * that band. These helpers keep the two together while an admin, or Staff
 * on one page, edit them.
 *
 * Pure functions, so they are unit-tested without a browser.
 */

/** Smallest gap between two row lines, as a fraction of the page height. */
export const MIN_ROW_GAP = 0.004;

/** Row lines closer than this are one line (fraction of the page height). */
const SAME_LINE = 0.0005;

/** A column's top or bottom this close to a row line sits on it. */
const ATTACHED = 0.0002;

export const isColumn = (box) => box?.kind === 'column';

const clamp01 = (value) => Math.min(1, Math.max(0, value));

export function median(values) {
    if (values.length === 0) return null;
    const sorted = [...values].sort((a, b) => a - b);
    const middle = Math.floor(sorted.length / 2);
    return sorted.length % 2 === 0 ? (sorted[middle - 1] + sorted[middle]) / 2 : sorted[middle];
}

/** The typical row height: the median gap between neighbouring lines. */
export function typicalGap(ys) {
    return median(ys.slice(1).map((y, index) => y - ys[index])) ?? 0;
}

/**
 * The ledger's band as the Align step reads it: the median top and bottom of
 * the column markers. Null without columns.
 */
export function columnBand(columns) {
    if (columns.length === 0) return null;
    return {
        top: median(columns.map((column) => column.y)),
        bottom: median(columns.map((column) => column.y + column.h)),
    };
}

export function sameBand(a, b) {
    return a !== null && b !== null
        && Math.abs(a.top - b.top) < 1e-6 && Math.abs(a.bottom - b.bottom) < 1e-6;
}

/** Top to bottom, on the page, without two lines on top of each other. */
function tidy(ys) {
    const sorted = ys.map(clamp01).sort((a, b) => a - b);
    return sorted.filter((y, index) => index === 0 || y - sorted[index - 1] > SAME_LINE);
}

/**
 * Row lines carried from one band to another, exactly as the Align step
 * carries the template's lines to the aligned columns: moving the whole table
 * moves its lines, stretching it stretches them.
 */
export function remapRuledYs(ys, from, to) {
    const span = from.bottom - from.top;
    const scale = span > 0 ? (to.bottom - to.top) / span : 1;
    return tidy(ys.map((y) => to.top + (y - from.top) * scale));
}

/**
 * Where a dragged row line may go: between its neighbours with at least
 * `minGap` on either side, and on the page.
 */
export function clampRuledY(ys, index, y, minGap = MIN_ROW_GAP) {
    const low = index > 0 ? ys[index - 1] + minGap : 0;
    const high = index < ys.length - 1 ? ys[index + 1] - minGap : 1;
    return low > high ? ys[index] : Math.min(high, Math.max(low, y));
}

/** Every row line moved by dy, stopped at the top and bottom of the page. */
export function shiftRuledYs(ys, dy) {
    if (ys.length === 0) return [];
    const bounded = Math.min(1 - ys[ys.length - 1], Math.max(-ys[0], dy));
    return ys.map((y) => y + bounded);
}

/**
 * A new row line under line `after` (half way to the next line), or one
 * typical row below the last line. `after` null: at the bottom.
 *
 * A scan often ends on the table's last row, its bottom rule cut off or on the
 * page's edge (Snap then finds one row fewer). A full row no longer fits under
 * the last line there, so the new line goes on the page's bottom edge, as long
 * as half a row fits.
 *
 * @returns {{ys: number[], index: number}|null} null when there is no room
 */
export function insertRuledY(ys, after = null, minGap = MIN_ROW_GAP) {
    // A ledger needs two lines before it has a row.
    if (ys.length === 0) return { ys: [0.2, 0.25], index: 1 };

    const index = after === null || after >= ys.length - 1 ? ys.length - 1 : Math.max(0, after);
    const below = ys[index + 1];
    const row = ys.length > 1 ? typicalGap(ys) : 0.05;
    const y = below === undefined ? Math.min(1, ys[index] + row) : (ys[index] + below) / 2;
    const room = below === undefined ? 1 - ys[index] : below - ys[index];
    if (room < 2 * minGap || (below === undefined && room < row / 2)) return null;

    const next = [...ys.slice(0, index + 1), y, ...ys.slice(index + 1)];
    return { ys: next, index: index + 1 };
}

/**
 * `rows` equal rows between the first and the last line: for a register whose
 * rules are printed evenly, or to set how many rows it has.
 *
 * @returns {number[]|null} null when the rows would be thinner than minGap
 */
export function distributeRuledYs(ys, rows, minGap = MIN_ROW_GAP) {
    const count = Math.round(Number(rows));
    if (ys.length < 2 || !Number.isFinite(count) || count < 1) return null;

    const top = ys[0];
    const bottom = ys[ys.length - 1];
    const step = (bottom - top) / count;
    if (step < minGap) return null;

    return Array.from({ length: count + 1 }, (_, index) => (
        index === count ? bottom : top + index * step
    ));
}

/** The printed line nearest to y within reach (same units), or null. */
export function nearestLine(y, lines, reach) {
    let best = null;
    (lines ?? []).forEach((line) => {
        if (Math.abs(line - y) <= reach && (best === null || Math.abs(line - y) < Math.abs(best - y))) {
            best = line;
        }
    });
    return best;
}

/**
 * Columns that sat on the first or last row line keep sitting on it when the
 * lines change: the table's top is its first line, its bottom its last.
 * `attached` is how close a column's edge must be to count as on the line;
 * the Align step allows half a row, as Staff's columns are aligned by hand.
 * Returns the same array when no column moves.
 */
export function followFirstAndLastLine(boxes, before, after, attached = ATTACHED) {
    if (before.length < 2 || after.length < 2) return boxes;

    const [oldTop, oldBottom] = [before[0], before[before.length - 1]];
    const [newTop, newBottom] = [after[0], after[after.length - 1]];
    if (Math.abs(oldTop - newTop) < 1e-9 && Math.abs(oldBottom - newBottom) < 1e-9) return boxes;

    let moved = false;
    const next = boxes.map((box) => {
        if (!isColumn(box)) return box;
        const onTop = Math.abs(box.y - oldTop) < attached;
        const onBottom = Math.abs(box.y + box.h - oldBottom) < attached;
        if (!onTop && !onBottom) return box;

        const top = onTop ? newTop : box.y;
        const bottom = onBottom ? newBottom : box.y + box.h;
        if (bottom - top <= 0.01) return box;
        moved = true;
        return { ...box, y: top, h: bottom - top };
    });

    return moved ? next : boxes;
}

/** A column narrower than this (fraction of the page width) holds no writing. */
export const MIN_COLUMN_WIDTH = 0.01;

/**
 * A column added in the Align step, among all the markers. The selected
 * column gives it its right half (keeping its left half). With none selected
 * it goes a typical column's width to the right of the table, or takes the
 * last column's right half when the page has no room there.
 *
 * @param {object[]} boxes  Every marker (FieldMarker#toJSON()).
 * @param {object} column   The new column's own properties: name, kind, columnIndex.
 * @param {number|null} selected  Index of the selected column, if one is.
 * @returns {{boxes: object[], index: number}|null} the markers with the new
 *          column last, and its index; null when it would be too narrow
 */
export function addColumn(boxes, column, selected = null) {
    const columns = boxes.flatMap((box, index) => (isColumn(box) ? [index] : []));
    if (columns.length === 0) return null;

    let split = Number.isInteger(selected) && isColumn(boxes[selected]) ? selected : null;
    let place = null;
    if (split === null) {
        const band = columnBand(columns.map((index) => boxes[index]));
        const width = median(columns.map((index) => boxes[index].w));
        const right = Math.max(...columns.map((index) => boxes[index].x + boxes[index].w));
        if (right + width <= 1 + 1e-9) {
            place = { x: right, y: band.top, w: width, h: band.bottom - band.top };
        } else {
            split = columns.reduce((last, index) => (
                boxes[index].x + boxes[index].w > boxes[last].x + boxes[last].w ? index : last
            ));
        }
    }

    const next = [...boxes];
    if (place === null) {
        const old = boxes[split];
        const half = old.w / 2;
        if (half < MIN_COLUMN_WIDTH) return null;
        next[split] = { ...old, w: half };
        place = { x: old.x + half, y: old.y, w: old.w - half, h: old.h, ...(old.angle ? { angle: old.angle } : {}) };
    }
    next.push({ ...place, ...column });
    return { boxes: next, index: next.length - 1 };
}

/** How much of a field's width may lie over the table's columns for it to become one. */
const COLUMN_OVERLAP = 0.25;

/**
 * Fields Staff added over the ledger's table and stretched down two or more of
 * its rows, turned into columns. Such a box is meant to be read row by row,
 * one cell per person, the way the table's own columns are, not as lines of
 * free text (which also runs neighbouring rows' short values together).
 *
 * The column spans the table's rows, from its first row line to its last.
 * A field whose middle lies off the table stays a field, as does one that
 * covers more than a sliver of the table's columns (it would read their cells
 * a second time: only a gap between columns becomes one), and any field
 * `keep` names (the template's own). Returns the same array when nothing
 * changes.
 *
 * @param {object[]} boxes  Every marker (FieldMarker#toJSON()).
 * @param {number[]} ys     The row lines, page fractions, top to bottom.
 * @param {(box: object) => boolean} [keep]
 */
export function fieldsToColumns(boxes, ys, keep = () => false) {
    const columns = boxes.filter(isColumn);
    if (columns.length === 0 || ys.length < 3) return boxes;

    const left = Math.min(...columns.map((column) => column.x));
    const right = Math.max(...columns.map((column) => column.x + column.w));
    const middles = ys.slice(1).map((y, index) => (ys[index] + y) / 2);
    const indexes = columns.map((column) => Number(column.columnIndex)).filter(Number.isInteger);
    let columnIndex = Math.max(-1, ...indexes) + 1;
    let next = boxes;

    boxes.forEach((box, index) => {
        if (isColumn(box) || keep(box)) return;
        const centre = box.x + box.w / 2;
        if (centre < left || centre > right) return;
        const covered = columns.reduce((sum, column) => (
            sum + Math.max(0, Math.min(box.x + box.w, column.x + column.w) - Math.max(box.x, column.x))
        ), 0);
        if (covered > box.w * COLUMN_OVERLAP) return;
        if (middles.filter((middle) => middle >= box.y && middle <= box.y + box.h).length < 2) return;

        const { personGroup, personFieldOrder, ...field } = box;
        if (next === boxes) next = [...boxes];
        next[index] = { ...field, y: ys[0], h: ys[ys.length - 1] - ys[0], kind: 'column', columnIndex };
        columnIndex += 1;
    });

    return next;
}

/**
 * Fields that cover the middle of a ledger cell. Line detection takes the
 * handwriting under a field as the field's, so those cells go missing from
 * the table's rows. Returns the fields' indexes.
 */
export function fieldsOverLedger(boxes, ys) {
    const columns = boxes.filter(isColumn);
    if (columns.length === 0 || ys.length < 2) return [];

    // The middle half of each cell, where its writing sits.
    const rows = ys.slice(1).map((bottom, index) => {
        const quarter = (bottom - ys[index]) / 4;
        return [ys[index] + quarter, bottom - quarter];
    });
    const cells = columns.map((column) => [column.x + column.w / 4, column.x + column.w * 3 / 4]);

    return boxes.flatMap((box, index) => {
        if (isColumn(box)) return [];
        const overColumn = cells.some(([left, right]) => box.x < right && box.x + box.w > left);
        const overRow = rows.some(([top, bottom]) => box.y < bottom && box.y + box.h > top);
        return overColumn && overRow ? [index] : [];
    });
}

/**
 * What is wrong with a ledger grid, as sentences for the admin. `blocking`
 * ones stop the layout from saving (the server refuses them too); the rest
 * are warnings.
 *
 * @returns {Array<{message: string, blocking: boolean}>}
 */
export function ledgerGridProblems(columns, ys, { minGap = MIN_ROW_GAP } = {}) {
    if (columns.length === 0) return [];
    if (ys.length < 2) {
        return [{ message: 'Add the printed row lines: a ledger needs at least two.', blocking: true }];
    }

    const problems = [];
    const tooClose = ys.slice(1).some((y, index) => y - ys[index] < minGap);
    if (tooClose) {
        problems.push({ message: 'Two row lines sit almost on top of each other. Remove one.', blocking: true });
    }

    // A row beyond the columns is still carried along by the Align step;
    // more than that means the columns and the rows were moved apart.
    const band = columnBand(columns);
    const row = typicalGap(ys);
    const tolerance = Math.max(0.01, row);
    if (ys[0] < band.top - tolerance || ys[ys.length - 1] > band.bottom + tolerance) {
        problems.push({
            message: 'The row lines run outside the columns. Staff scans place the rows by the columns, '
                + 'so move the columns over the rows (or the rows into the columns).',
            blocking: true,
        });
    }

    const halfRow = Math.max(0.01, row / 2);
    columns.forEach((column) => {
        if (Math.abs(column.y - band.top) > halfRow || Math.abs(column.y + column.h - band.bottom) > halfRow) {
            problems.push({
                message: `Column “${column.name}” does not span the same rows as the other columns.`,
                blocking: false,
            });
        }
    });

    return problems;
}
