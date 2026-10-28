/**
 * A ledger's row lines over the page in the Align step.
 *
 * Each line runs across the table's columns with a grip on the table's left
 * and right edges. A grip selects its line and drags it (Shift: every line
 * together); double-clicking a grip removes the line. While dragged, a line
 * snaps onto the page's printed lines (Alt: place freely). Only the grips take
 * the pointer, so the columns under the lines can still be dragged.
 *
 * The layer keeps no rows of its own: the page renders the rows it holds and
 * is told about every change, so undo and the server see one set of rows.
 */

import { clampRuledY, nearestLine, shiftRuledYs } from './ledger-grid.js';

/** A line dragged within this many screen pixels of a printed one snaps onto it. */
const SNAP_PIXELS = 8;

/** How a row line is told apart from its neighbours once moved: its position. */
export const rowKey = (y) => Number(y).toFixed(5);

export class RowLineLayer {
    /**
     * @param {object} options
     * @param {HTMLElement} options.overlay  The markers' overlay, the size of the page.
     * @param {() => ({horizontal?: number[]}|null)} [options.printedLines]  The page's printed lines.
     * @param {(index: number) => void} [options.onSelect]
     * @param {(rows: number[], change: {index: number, together: boolean}) => void} [options.onMove]
     * @param {(index: number) => void} [options.onRemove]
     */
    constructor({ overlay, printedLines = () => null, onSelect = () => {}, onMove = () => {}, onRemove = () => {} }) {
        this.overlay = overlay;
        this.printedLines = printedLines;
        this.onSelect = onSelect;
        this.onMove = onMove;
        this.onRemove = onRemove;
        this.state = { rows: [], left: 0, right: 1, selected: null, estimated: new Set(), locked: false };

        this.layer = document.createElement('div');
        this.layer.className = 'row-line-layer';
        this.layer.setAttribute('aria-hidden', 'true');
        overlay.appendChild(this.layer);

        this.layer.addEventListener('pointerdown', (event) => this.drag(event));
        this.layer.addEventListener('dblclick', (event) => {
            const grip = gripOf(event);
            if (!grip || this.state.locked) return;
            event.preventDefault();
            event.stopPropagation();
            this.onRemove(Number(grip.dataset.index));
        });
    }

    /**
     * Draw the rows as they stand. `dragging` marks the lines being moved,
     * drawn where the pointer has them before anything is committed.
     *
     * @param {{rows?: number[], left?: number, right?: number, selected?: number|null, estimated?: Set<string>, locked?: boolean}} state
     * @param {Set<number>|null} [dragging]
     */
    render(state = {}, dragging = null) {
        this.state = { ...this.state, ...state };
        const { rows, left, right, selected, estimated, locked } = this.state;

        this.layer.classList.toggle('is-locked', Boolean(locked));
        // The lines' elements are kept and updated, not replaced: a click selects
        // its line and redraws the layer, and a double-click only counts when
        // both clicks land on the same grip.
        while (this.layer.children.length > rows.length) this.layer.lastElementChild.remove();
        while (this.layer.children.length < rows.length) this.layer.appendChild(createRow());

        rows.forEach((y, index) => {
            const isEstimated = estimated.has(rowKey(y));
            const row = this.layer.children[index];
            row.classList.toggle('is-selected', index === selected);
            row.classList.toggle('is-estimated', isEstimated);
            row.classList.toggle('is-dragging', Boolean(dragging?.has(index)));
            row.style.top = `${y * 100}%`;
            row.style.left = `${left * 100}%`;
            row.style.width = `${Math.max(0, right - left) * 100}%`;
            row.querySelectorAll('.row-line__grip').forEach((grip) => {
                grip.dataset.index = String(index);
                grip.title = isEstimated
                    ? `Row line ${index + 1} has no printed line under it. Check it against the page; drag to move it.`
                    : `Row line ${index + 1}: drag to move (Shift: every line), double-click to remove`;
            });
        });
    }

    drag(event) {
        const grip = gripOf(event);
        if (!grip || event.button !== 0 || event.ctrlKey || event.metaKey || this.state.locked) return;
        event.preventDefault();
        event.stopPropagation();

        const index = Number(grip.dataset.index);
        const together = event.shiftKey;
        const origin = [...this.state.rows];
        const pageY = (clientY) => {
            const bounds = this.overlay.getBoundingClientRect();
            return { y: (clientY - bounds.top) / Math.max(1, bounds.height), height: Math.max(1, bounds.height) };
        };
        const startY = pageY(event.clientY).y;
        const moving = new Set(together ? origin.map((_, i) => i) : [index]);
        let current = origin;
        this.onSelect(index);

        const move = (moveEvent) => {
            const { y, height } = pageY(moveEvent.clientY);
            const printed = moveEvent.altKey ? null : this.printedLines()?.horizontal;
            const snap = (value) => (printed ? nearestLine(value, printed, SNAP_PIXELS / height) ?? value : value);

            current = together
                ? shiftRuledYs(origin, snap(origin[index] + y - startY) - origin[index])
                : origin.map((value, i) => (i === index ? clampRuledY(origin, index, snap(y)) : value));
            this.render({ rows: current }, moving);
        };

        const end = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', end);
            window.removeEventListener('pointercancel', end);

            if (current.some((y, i) => Math.abs(y - origin[i]) > 1e-9)) {
                this.onMove(current, { index, together });
            } else {
                this.render({ rows: origin });
            }
        };

        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', end);
        window.addEventListener('pointercancel', end);
    }
}

function gripOf(event) {
    return event.target instanceof Element ? event.target.closest('.row-line__grip') : null;
}

/** One row line: the dashed rule and a grip at each end of the table. */
function createRow() {
    const row = document.createElement('div');
    row.className = 'row-line';
    const rule = document.createElement('span');
    rule.className = 'row-line__rule';
    row.appendChild(rule);
    ['start', 'end'].forEach((end) => {
        const grip = document.createElement('span');
        grip.className = `row-line__grip is-${end}`;
        row.appendChild(grip);
    });
    return row;
}
