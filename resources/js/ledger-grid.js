/**
 * Ledger grid helpers for the Template Builder.
 *
 * A ruled register is described by its column markers and the y of every
 * printed row line (page fractions, top to bottom). The Align step reads the
 * row lines relative to the columns' band - the median top and bottom of the
 * columns - and stretches them to wherever Staff put the columns
 * (alignedGeometry() in line-geometry.js). So a template is only right when
 * its row lines sit where they belong inside that band. These helpers keep
 * the two together while an admin edits them.
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
 * @returns {{ys: number[], index: number}|null} null when there is no room
 */
export function insertRuledY(ys, after = null, minGap = MIN_ROW_GAP) {
    // A ledger needs two lines before it has a row.
    if (ys.length === 0) return { ys: [0.2, 0.25], index: 1 };

    const index = after === null || after >= ys.length - 1 ? ys.length - 1 : Math.max(0, after);
    const below = ys[index + 1];
    const y = below === undefined
        ? ys[index] + (ys.length > 1 ? typicalGap(ys) : 0.05)
        : (ys[index] + below) / 2;
    const room = below === undefined ? 1 - ys[index] : below - ys[index];
    if (y > 1 || room < 2 * minGap) return null;

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
 * Returns the same array when no column moves.
 */
export function followFirstAndLastLine(boxes, before, after) {
    if (before.length < 2 || after.length < 2) return boxes;

    const [oldTop, oldBottom] = [before[0], before[before.length - 1]];
    const [newTop, newBottom] = [after[0], after[after.length - 1]];
    if (Math.abs(oldTop - newTop) < 1e-9 && Math.abs(oldBottom - newBottom) < 1e-9) return boxes;

    let moved = false;
    const next = boxes.map((box) => {
        if (!isColumn(box)) return box;
        const onTop = Math.abs(box.y - oldTop) < ATTACHED;
        const onBottom = Math.abs(box.y + box.h - oldBottom) < ATTACHED;
        if (!onTop && !onBottom) return box;

        const top = onTop ? newTop : box.y;
        const bottom = onBottom ? newBottom : box.y + box.h;
        if (bottom - top <= 0.01) return box;
        moved = true;
        return { ...box, y: top, h: bottom - top };
    });

    return moved ? next : boxes;
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
