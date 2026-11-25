/**
 * A ledger's row lines over the page in the Align step.
 *
 * Each line runs across the table's columns with a grip on the table's left
 * and right edges. A grip selects its line and drags it (Shift: every line
 * together); double-clicking a grip removes the line. While dragged, a line
 * snaps onto the page's printed lines (Alt: place freely). Only the grips take
 * the pointer, so the columns under the lines can still be dragged.
 *
 * On a page scanned a little turned the lines turn with the table (`tilt`,
 * the columns' typical turn): each is held as its height across the middle of
 * the page and drawn through that point at the tilt (see rowLineY). A line may
 * be turned a little more or less than the table (`tilts[i]` degrees extra),
 * as no two printed rules on a curved page run quite alike.
 *
 * With `stretchable`, a line can also be made shorter or longer than the
 * table (`spans[i]` = {l, r}, page fractions; null = the table's edges):
 * dragging a grip sideways moves that end only, and Alt+drag pulls that end
 * anywhere (up or down too, which turns the line about its other end).
 *
 * The layer keeps no rows of its own: the page renders the rows it holds and
 * is told about every change, so undo and the server see one set of rows.
 */

import { clampRuledY, nearestLine, rowLineY, shiftRuledYs } from './ledger-grid.js';

/** The shortest a stretched line may be, as a share of the page's width. */
const MIN_SPAN = 0.05;
/** The most a line may be turned (degrees). */
const MAX_LINE_TILT = 25;

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
     * @param {boolean} [options.stretchable]  Grips can stretch their line.
     * @param {(index: number, change: {span: {l: number, r: number}, y: number, tilt: number}) => void} [options.onStretch]
     */
    constructor({ overlay, printedLines = () => null, onSelect = () => {}, onMove = () => {}, onRemove = () => {}, stretchable = false, onStretch = () => {} }) {
        this.stretchable = stretchable;
        this.onStretch = onStretch;
        this.overlay = overlay;
        this.printedLines = printedLines;
        this.onSelect = onSelect;
        this.onMove = onMove;
        this.onRemove = onRemove;
        this.state = { rows: [], left: 0, right: 1, selected: null, estimated: new Set(), locked: false, tilt: 0, tilts: [], spans: [], aspect: 1 };

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
     * `tilt` is the table's turn in degrees clockwise, `tilts` how far each
     * line is turned beyond it, `aspect` the page's width over its height in
     * pixels.
     *
     * @param {{rows?: number[], left?: number, right?: number, selected?: number|null, estimated?: Set<string>, locked?: boolean, tilt?: number, tilts?: number[], aspect?: number}} state
     * @param {Set<number>|null} [dragging]
     */
    render(state = {}, dragging = null) {
        this.state = { ...this.state, ...state };
        const { rows, left, right, selected, estimated, locked, aspect } = this.state;

        this.layer.classList.toggle('is-locked', Boolean(locked));
        // The lines' elements are kept and updated, not replaced: a click selects
        // its line and redraws the layer, and a double-click only counts when
        // both clicks land on the same grip.
        while (this.layer.children.length > rows.length) this.layer.lastElementChild.remove();
        while (this.layer.children.length < rows.length) this.layer.appendChild(createRow());

        rows.forEach((y, index) => {
            const { l: lineLeft, r: lineRight } = this.spanOf(index);
            const isEstimated = estimated.has(rowKey(y));
            const row = this.layer.children[index];
            row.classList.toggle('is-selected', index === selected);
            row.classList.toggle('is-estimated', isEstimated);
            row.classList.toggle('is-dragging', Boolean(dragging?.has(index)));
            const angle = this.angleOf(index);
            // Turned about its left end, a line reaches the table's right edge
            // when it is that much longer.
            const length = Math.max(0, lineRight - lineLeft) / Math.cos(angle * Math.PI / 180);
            row.style.top = `${rowLineY(y, lineLeft, angle, aspect) * 100}%`;
            row.style.left = `${lineLeft * 100}%`;
            row.style.width = `${length * 100}%`;
            row.style.transform = angle ? `rotate(${angle}deg)` : '';
            row.querySelectorAll('.row-line__grip').forEach((grip) => {
                grip.dataset.index = String(index);
                grip.title = isEstimated
                    ? `Row line ${index + 1} has no printed line under it. Check it against the page; drag to move it.`
                    : (this.stretchable
                        ? `Row line ${index + 1}: drag up or down to move (Shift: every line), sideways to stretch, Alt+drag to pull this end anywhere, double-click to remove`
                        : `Row line ${index + 1}: drag to move (Shift: every line), double-click to remove`);
            });
        });
    }

    /** Line `index`'s left and right ends (page fractions): its own stretch, else the table's edges. */
    spanOf(index) {
        const { spans, left, right } = this.state;
        const span = spans?.[index];
        return span && Number.isFinite(span.l) && Number.isFinite(span.r) ? { l: span.l, r: span.r } : { l: left, r: right };
    }

    /** Line `index`'s turn in degrees clockwise: the table's and its own. */
    angleOf(index) {
        const { tilt, tilts } = this.state;
        return Math.round(((Number(tilt) || 0) + (Number(tilts?.[index]) || 0)) * 1000) / 1000;
    }

    drag(event) {
        const grip = gripOf(event);
        if (!grip || event.button !== 0 || event.ctrlKey || event.metaKey || this.state.locked) return;
        event.preventDefault();
        event.stopPropagation();

        const index = Number(grip.dataset.index);
        const together = event.shiftKey;
        const isEnd = grip.classList.contains('is-end');
        const origin = [...this.state.rows];
        const original = { rows: this.state.rows, spans: this.state.spans, tilts: this.state.tilts };
        const span0 = this.spanOf(index);
        const angle0 = this.angleOf(index);
        const table = Number(this.state.tilt) || 0;
        // On a turned line the grip is higher or lower than the line's middle
        // height, which is what a line is held as and snapped by.
        const gripX = isEnd ? span0.r : span0.l;
        const offset = rowLineY(0, gripX, angle0, this.state.aspect);
        const bounds = () => this.overlay.getBoundingClientRect();
        const pageY = (clientY) => {
            const box = bounds();
            return { y: (clientY - box.top) / Math.max(1, box.height) - offset, height: Math.max(1, box.height) };
        };
        const startY = pageY(event.clientY).y;
        const moving = new Set(together ? origin.map((_, i) => i) : [index]);
        const stretchable = this.stretchable && !together;
        // 'move' (the line, up or down), 'x' (this end sideways) or 'free' (this end anywhere).
        let mode = stretchable ? (event.altKey ? 'free' : null) : 'move';
        let current = origin;
        let stretched = null;
        this.onSelect(index);

        const withSpan = (span) => Array.from({ length: origin.length }, (_, i) => (i === index ? span : this.state.spans?.[i] ?? null));

        const move = (moveEvent) => {
            if (mode === null) {
                // Up or down moves the line, sideways stretches it: decided once the pointer has gone a few pixels.
                const dx = moveEvent.clientX - event.clientX;
                const dy = moveEvent.clientY - event.clientY;
                if (Math.hypot(dx, dy) < 4) return;
                mode = Math.abs(dx) > Math.abs(dy) ? 'x' : 'move';
            }

            if (mode === 'move') {
                const { y, height } = pageY(moveEvent.clientY);
                const printed = moveEvent.altKey ? null : this.printedLines()?.horizontal;
                const snap = (value) => (printed ? nearestLine(value, printed, SNAP_PIXELS / height) ?? value : value);

                current = together
                    ? shiftRuledYs(origin, snap(origin[index] + y - startY) - origin[index])
                    : origin.map((value, i) => (i === index ? clampRuledY(origin, index, snap(y)) : value));
                this.render({ rows: current }, moving);
                return;
            }

            const box = bounds();
            const px = Math.min(1, Math.max(0, (moveEvent.clientX - box.left) / Math.max(1, box.width)));
            const py = Math.min(1, Math.max(0, (moveEvent.clientY - box.top) / Math.max(1, box.height)));
            const fixedX = isEnd ? span0.l : span0.r;
            const endX = isEnd ? Math.max(fixedX + MIN_SPAN, px) : Math.min(fixedX - MIN_SPAN, px);
            const span = isEnd ? { l: span0.l, r: endX } : { l: endX, r: span0.r };

            if (mode === 'x') {
                stretched = { span, y: origin[index], tilt: Math.round((angle0 - table) * 10) / 10 };
                this.render({ spans: withSpan(span) }, moving);
                return;
            }

            // Free: the other end stays where it is; the line turns about it to reach the pointer.
            const aspect = this.state.aspect;
            const fixedY = rowLineY(origin[index], fixedX, angle0, aspect);
            const rise = (py - fixedY) / ((endX - fixedX) * aspect);
            const total = Math.max(-MAX_LINE_TILT, Math.min(MAX_LINE_TILT, Math.round(Math.atan(rise) * 1800 / Math.PI) / 10));
            const y = Math.min(1, Math.max(0, fixedY - Math.tan(total * Math.PI / 180) * (fixedX - 0.5) * aspect));
            stretched = { span, y, tilt: Math.round((total - table) * 10) / 10 };
            this.render({
                rows: origin.map((value, i) => (i === index ? y : value)),
                spans: withSpan(span),
                tilts: Array.from({ length: origin.length }, (_, i) => (i === index ? stretched.tilt : Number(this.state.tilts?.[i]) || 0)),
            }, moving);
        };

        const end = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', end);
            window.removeEventListener('pointercancel', end);

            if (stretched && (mode === 'x' || mode === 'free')) {
                this.onStretch(index, stretched);
            } else if (current.some((y, i) => Math.abs(y - origin[i]) > 1e-9)) {
                this.onMove(current, { index, together });
            } else {
                this.render(original);
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
