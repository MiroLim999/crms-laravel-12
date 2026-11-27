/**
 * A ledger's row lines over the page in the Align step.
 *
 * Each line runs across the table's columns with a grip on the table's left
 * and right edges. A grip selects its line and drags it (Shift: every line
 * together); Delete removes the selected line. A dragged line moves
 * freely and stays where it is let go; held with Alt, it snaps onto the page's
 * printed lines. Only the grips take
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
 * With `bendable`, every column has a small handle on each line, just right of
 * the column's left edge: dragging it moves that column's part of the line up
 * or down (`bends[i][c]`, page fractions; the line is then drawn one piece per
 * column). A bend is what is seen and lined up against only.
 *
 * The layer keeps no rows of its own: the page renders the rows it holds and
 * is told about every change, so undo and the server see one set of rows.
 */

import { clampRuledY, nearestLine, rowLineY, shiftRuledYs } from './ledger-grid.js';

/** The shortest a stretched line may be, as a share of the page's width. */
const MIN_SPAN = 0.05;
/** The most a line may be turned (degrees). */
const MAX_LINE_TILT = 25;

/** With Alt held, a line dragged within this many screen pixels of a printed one snaps onto it. */
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
     * @param {(spans: {l: number, r: number}[]) => void} [options.onStretchAll]  Every line selected and a grip
     *   dragged sideways: that end of every line moved by the same amount (each line's new span).
     * @param {boolean} [options.bendable]  Each column's part of a line can be moved up or down.
     * @param {(index: number, column: number, dy: number, side: 'l'|'r') => void} [options.onBend]
     */
    constructor({
        overlay, printedLines = () => null, onSelect = () => {}, onMove = () => {}, onRemove = () => {},
        stretchable = false, onStretch = () => {}, onStretchAll = () => {}, bendable = false, onBend = () => {},
    }) {
        this.stretchable = stretchable;
        this.onStretch = onStretch;
        this.onStretchAll = onStretchAll;
        this.bendable = bendable;
        this.onBend = onBend;
        this.overlay = overlay;
        this.printedLines = printedLines;
        this.onSelect = onSelect;
        this.onMove = onMove;
        this.onRemove = onRemove;
        this.state = { rows: [], left: 0, right: 1, selected: null, estimated: new Set(), locked: false, tilt: 0, tilts: [], spans: [], columns: [], bends: [], aspect: 1 };

        this.layer = document.createElement('div');
        this.layer.className = 'row-line-layer';
        this.layer.setAttribute('aria-hidden', 'true');
        overlay.appendChild(this.layer);

        this.layer.addEventListener('pointerdown', (event) => this.drag(event));
        // A double-click on a grip no longer removes its line: clicking a grip again
        // to drag it read as a double-click, and lines went while being adjusted.
        // A line is removed with Delete or the Rows − button.
        this.layer.addEventListener('dblclick', (event) => {
            if (!gripOf(event)) return;
            event.preventDefault();
            event.stopPropagation();
        });

        // Each column's piece of each line, with its handle (bendable only). A layer of
        // its own: the row layer's children are its lines, one each.
        this.pieces = document.createElement('div');
        this.pieces.className = 'row-line-layer row-line-layer--pieces';
        this.pieces.setAttribute('aria-hidden', 'true');
        overlay.appendChild(this.pieces);
        this.pieces.addEventListener('pointerdown', (event) => this.bend(event));
    }

    /**
     * Draw the rows as they stand. `dragging` marks the lines being moved,
     * drawn where the pointer has them before anything is committed.
     *
     * `tilt` is the table's turn in degrees clockwise, `tilts` how far each
     * line is turned beyond it, `aspect` the page's width over its height in
     * pixels.
     *
     * @param {{rows?: number[], left?: number, right?: number, selected?: number|'all'|null, estimated?: Set<string>, locked?: boolean, tilt?: number, tilts?: number[], aspect?: number}} state
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
            // selected 'all': every line is selected (Select all lines), and they move together.
            row.classList.toggle('is-selected', selected === 'all' || index === selected);
            row.classList.toggle('is-estimated', isEstimated);
            row.classList.toggle('is-dragging', Boolean(dragging?.has(index)));
            // Bent: drawn one piece per column (renderPieces), not as one rule.
            row.classList.toggle('is-bent', this.isBent(index));
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
                        ? `Row line ${index + 1}: drag up or down to move (Shift: every line), sideways to stretch, Alt+drag to pull this end anywhere. Delete removes it.`
                        : `Row line ${index + 1}: drag to move (Shift: every line). Delete removes it.`);
            });
        });
        this.renderPieces(dragging);
    }

    /**
     * How far the `side` ('l' or 'r') end of column `column`'s part of line `index`
     * is moved (page fraction), 0 when not. Kept per column as [left, right]; a lone
     * number (an older bend) is its left end.
     */
    bendOf(index, column, side = 'l') {
        const raw = this.state.bends?.[index]?.[column];
        if (Array.isArray(raw)) return Number(raw[side === 'r' ? 1 : 0]) || 0;
        return side === 'l' ? Number(raw) || 0 : 0;
    }

    /**
     * Line `index` as one piece per column it crosses. Each piece has a handle at
     * each end (on the column's left and right edges): dragging one moves that end
     * only, the piece's other end stays, and no other column changes. An end on the
     * line's own end has its grip there instead: no handle, and it does not move.
     */
    piecesOf(index) {
        const { l, r } = this.spanOf(index);
        const pieces = [];
        (this.state.columns ?? []).forEach((column, c) => {
            const xa = Math.max(column.l, l);
            const xb = Math.min(column.r, r);
            if (xb - xa <= 0.002) return;
            const left = column.l > l + 0.002;
            const right = column.r < r - 0.002;
            pieces.push({
                column: c, xa, xb, left, right,
                dl: left ? this.bendOf(index, c, 'l') : 0,
                dr: right ? this.bendOf(index, c, 'r') : 0,
            });
        });
        return pieces;
    }

    isBent(index) {
        return this.bendable && (this.state.columns?.length ?? 0) > 0
            && this.piecesOf(index).some((piece) => Math.abs(piece.dl) > 1e-9 || Math.abs(piece.dr) > 1e-9);
    }

    /** Each line drawn one piece per column (when bent), with a handle at each end of each piece. */
    renderPieces(dragging = null) {
        const { rows, columns, aspect, selected, estimated, locked } = this.state;
        this.pieces.classList.toggle('is-locked', Boolean(locked));
        const pieces = [];
        if (this.bendable && columns?.length) {
            rows.forEach((y, index) => {
                const angle = this.angleOf(index);
                const own = this.piecesOf(index).map((piece) => ({
                    ...piece,
                    index,
                    // Each end where its own handle put it, on the line as it is turned.
                    ya: rowLineY(y + piece.dl, piece.xa, angle, aspect),
                    yb: rowLineY(y + piece.dr, piece.xb, angle, aspect),
                }));
                // The line stays one line: where no column covers it (before the first column,
                // between columns apart, after the last), a plain piece joins the ends either
                // side of the gap, from the line's own end grips to the columns' squares.
                const { l, r } = this.spanOf(index);
                const joint = (xa, ya, xb, yb) => {
                    if (xb - xa > 0.0005) pieces.push({ index, connector: true, xa, xb, ya, yb, left: false, right: false, column: -1 });
                };
                let x = l;
                let yAt = rowLineY(y, l, angle, aspect);
                own.forEach((piece) => {
                    joint(x, yAt, piece.xa, piece.ya);
                    pieces.push(piece);
                    x = piece.xb;
                    yAt = piece.yb;
                });
                joint(x, yAt, r, rowLineY(y, r, angle, aspect));
            });
        }
        while (this.pieces.children.length > pieces.length) this.pieces.lastElementChild.remove();
        while (this.pieces.children.length < pieces.length) this.pieces.appendChild(createPiece());

        pieces.forEach((piece, k) => {
            const node = this.pieces.children[k];
            // In pixels the run is (xb - xa) * width and the rise (yb - ya) * height (aspect = width / height).
            const turn = Math.atan2(piece.yb - piece.ya, (piece.xb - piece.xa) * aspect) * 180 / Math.PI;
            const length = Math.hypot(piece.xb - piece.xa, (piece.yb - piece.ya) / aspect);
            node.style.top = `${piece.ya * 100}%`;
            node.style.left = `${piece.xa * 100}%`;
            node.style.width = `${length * 100}%`;
            node.style.transform = Math.abs(turn) > 1e-6 ? `rotate(${turn}deg)` : '';
            node.classList.toggle('is-bent', this.isBent(piece.index));
            node.classList.toggle('is-selected', selected === 'all' || piece.index === selected);
            node.classList.toggle('is-estimated', estimated.has(rowKey(rows[piece.index])));
            node.classList.toggle('is-dragging', Boolean(dragging?.has(piece.index)));
            // An end on the line's own end has the grip there: no handle.
            node.querySelectorAll('.row-line__bend').forEach((handle) => {
                const side = handle.dataset.side;
                const shown = side === 'l' ? piece.left : piece.right;
                handle.classList.toggle('d-none', !shown);
                handle.dataset.index = String(piece.index);
                handle.dataset.column = String(shown ? piece.column : -1);
                handle.title = `Row line ${piece.index + 1}, column ${piece.column + 1}, ${side === 'l' ? 'left' : 'right'} end: `
                    + 'drag up or down to move this end (the other end and the other columns stay)';
            });
        });
    }

    /** A column edge's handle dragged: that point of its line moves up or down, between its neighbours. */
    bend(event) {
        const handle = event.target instanceof Element ? event.target.closest('.row-line__bend') : null;
        if (!handle || Number(handle.dataset.column) < 0 || event.button !== 0 || this.state.locked) return;
        event.preventDefault();
        event.stopPropagation();

        const index = Number(handle.dataset.index);
        const column = Number(handle.dataset.column);
        const side = handle.dataset.side === 'r' ? 'r' : 'l';
        const { rows } = this.state;
        const y = rows[index];
        const origin = this.bendOf(index, column, side);
        const originalBends = this.state.bends;
        const gap = 0.004;
        // It stays between the lines above and below.
        const low = (index > 0 ? rows[index - 1] + gap : 0) - y;
        const high = (index < rows.length - 1 ? rows[index + 1] - gap : 1) - y;
        const pageY = (clientY) => {
            const box = this.pieces.getBoundingClientRect();
            return (clientY - box.top) / Math.max(1, box.height);
        };
        const startY = pageY(event.clientY);
        const count = this.state.columns.length;
        const withBend = (dy) => rows.map((_, line) => {
            const own = Array.from({ length: count }, (_, c) => [this.bendOf(line, c, 'l'), this.bendOf(line, c, 'r')]);
            if (line === index) own[column][side === 'r' ? 1 : 0] = dy;
            return own;
        });
        let current = origin;
        this.onSelect(index);

        const move = (moveEvent) => {
            current = Math.min(high, Math.max(low, origin + pageY(moveEvent.clientY) - startY));
            this.render({ bends: withBend(current) }, new Set([index]));
        };
        const end = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', end);
            window.removeEventListener('pointercancel', end);
            if (Math.abs(current - origin) > 1e-9) this.onBend(index, column, current, side);
            else this.render({ bends: originalBends });
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', end);
        window.addEventListener('pointercancel', end);
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
        // Shift, or every line selected: the lines move together.
        const allSelected = this.state.selected === 'all';
        const together = event.shiftKey || allSelected;
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
        // Every line selected (not Shift): sideways stretches that end of all of them, up or down moves them.
        const stretchAll = this.stretchable && allSelected && !event.shiftKey;
        // 'move' (the line, up or down), 'x' (this end sideways), 'free' (this end anywhere)
        // or 'all-x' (this end of every line sideways).
        let mode = stretchable ? (event.altKey ? 'free' : null) : (stretchAll ? null : 'move');
        let current = origin;
        let stretched = null;
        let stretchedAll = null;
        const spans0 = origin.map((_, i) => this.spanOf(i));
        // With every line selected, they stay selected while dragged.
        if (!allSelected) this.onSelect(index);

        const withSpan = (span) => Array.from({ length: origin.length }, (_, i) => (i === index ? span : this.state.spans?.[i] ?? null));

        const move = (moveEvent) => {
            if (mode === null) {
                // Up or down moves the line, sideways stretches it: decided once the pointer has gone a few pixels.
                const dx = moveEvent.clientX - event.clientX;
                const dy = moveEvent.clientY - event.clientY;
                if (Math.hypot(dx, dy) < 4) return;
                mode = Math.abs(dx) > Math.abs(dy) ? (stretchAll ? 'all-x' : 'x') : 'move';
            }

            if (mode === 'all-x') {
                // The same end of every line, moved by how far this grip went (each kept its own length).
                const box = bounds();
                const dx = (moveEvent.clientX - event.clientX) / Math.max(1, box.width);
                stretchedAll = spans0.map((span) => (isEnd
                    ? { l: span.l, r: Math.min(1, Math.max(span.l + MIN_SPAN, span.r + dx)) }
                    : { l: Math.max(0, Math.min(span.r - MIN_SPAN, span.l + dx)), r: span.r }));
                this.render({ spans: stretchedAll }, moving);
                return;
            }

            if (mode === 'move') {
                const { y, height } = pageY(moveEvent.clientY);
                // Free by default: the line stays exactly where it is let go. Alt: snap onto a printed line.
                const printed = moveEvent.altKey ? this.printedLines()?.horizontal : null;
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

            if (stretchedAll && mode === 'all-x') {
                this.onStretchAll(stretchedAll);
            } else if (stretched && (mode === 'x' || mode === 'free')) {
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
function createPiece() {
    const piece = document.createElement('div');
    piece.className = 'row-line row-line__piece';
    const rule = document.createElement('span');
    rule.className = 'row-line__rule';
    piece.appendChild(rule);
    // A handle at each end of the piece (its column's left and right edges).
    ['l', 'r'].forEach((side) => {
        const handle = document.createElement('span');
        handle.className = `row-line__bend is-${side === 'l' ? 'start' : 'end'}`;
        handle.dataset.side = side;
        piece.appendChild(handle);
    });
    return piece;
}

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
