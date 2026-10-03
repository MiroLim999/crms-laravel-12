/**
 * The Template Builder's test on its sample: what Detect made of the page with
 * the layout being built, summed up for the admin. Pure functions, so they are
 * unit-tested without a browser.
 */

import { FLAG_NO_ROW, FLAG_SHARED_CELL, SOURCE_FIELD, SOURCE_TEMPLATE } from './line-geometry.js';

/** How a line is drawn: in a row, needing review, sharing a cell, or a field's. */
export function testLineStatus(line) {
    const flags = Array.isArray(line?.flags) ? line.flags : [];
    if (flags.includes(FLAG_NO_ROW)) return 'no-row';
    if (flags.includes(FLAG_SHARED_CELL)) return 'shared';
    if (line?.source === SOURCE_TEMPLATE || line?.source === SOURCE_FIELD) return 'field';
    return 'placed';
}

/**
 * @param {{lines: Array<object>, fit?: object|null, deskew?: number, ignored?: number}} result
 * @param {string[]} requiredColumns  Names of the ledger's required columns.
 */
export function summariseTest(result, requiredColumns = []) {
    const lines = Array.isArray(result?.lines) ? result.lines : [];
    const ledger = lines.filter((line) => line.source !== SOURCE_TEMPLATE && line.source !== SOURCE_FIELD);
    const statuses = lines.map(testLineStatus);

    // Rows that got some writing, and which required columns they lack.
    const rows = new Map();
    ledger.forEach((line) => {
        if (!Number.isInteger(line.row)) return;
        if (!rows.has(line.row)) rows.set(line.row, new Set());
        rows.get(line.row).add(String(line.column).trim().toLowerCase());
    });
    const incompleteRows = [...rows.entries()]
        .sort(([a], [b]) => a - b)
        .map(([row, columns]) => ({
            row,
            missing: requiredColumns.filter((name) => !columns.has(String(name).trim().toLowerCase())),
        }))
        .filter((row) => row.missing.length > 0);

    return {
        lines: lines.length,
        rows: rows.size,
        fieldLines: statuses.filter((status) => status === 'field').length,
        noRow: statuses.filter((status) => status === 'no-row').length,
        shared: statuses.filter((status) => status === 'shared').length,
        ignored: Number(result?.ignored) || 0,
        incompleteRows,
        fitted: result?.fit ? Boolean(result.fit.fitted) : null,
        deskew: Number(result?.deskew) || 0,
    };
}

/** The summary as sentences for the admin, most important first. */
export function testFindings(summary) {
    const plural = (count, one, many) => `${count} ${count === 1 ? one : many}`;
    const findings = [];

    if (summary.fitted === false) {
        findings.push({ level: 'warning', text: 'The markers could not be fitted to a printed table on this page: they were read where you placed them.' });
    }
    if (summary.noRow > 0) {
        findings.push({ level: 'warning', text: `${plural(summary.noRow, 'line sits', 'lines sit')} between two rows. Staff would have to place ${summary.noRow === 1 ? 'it' : 'them'} by hand; check the row lines there.` });
    }
    if (summary.shared > 0) {
        findings.push({ level: 'warning', text: `${plural(summary.shared, 'line shares', 'lines share')} a cell with another line: a row line may be missing, or a word was split.` });
    }
    if (summary.incompleteRows.length > 0) {
        const shown = summary.incompleteRows.slice(0, 6)
            .map(({ row, missing }) => `row ${row} (${missing.length > 2 ? `${missing.length} columns` : missing.join(', ')})`);
        const more = summary.incompleteRows.length - shown.length;
        findings.push({
            level: 'info',
            text: `Required cells with nothing read: ${shown.join('; ')}${more > 0 ? `; and ${more} more ${more === 1 ? 'row' : 'rows'}` : ''}. `
                + 'Blank cells on the sample are fine; otherwise check the columns and row lines there.',
        });
    }
    if (summary.ignored > 0) {
        findings.push({ level: 'info', text: `${plural(summary.ignored, 'written line lies', 'written lines lie')} outside every marker and would not be read.` });
    }
    if (Math.abs(summary.deskew) >= 0.05) {
        findings.push({ level: 'info', text: `The page was straightened by ${Math.abs(summary.deskew).toFixed(1)}° first, as Staff scans are.` });
    }

    return findings;
}
