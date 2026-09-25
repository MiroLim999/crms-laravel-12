/**
 * Line outline overlay for the Verify step.
 *
 * Draws every line's polygon in one SVG laid over the page canvas. The SVG's
 * viewBox is the page's own pixel size and it is stretched to the canvas'
 * displayed size, so outlines stay on the handwriting at every zoom level
 * without any per-zoom arithmetic. Strokes use non-scaling-stroke so they stay
 * thin however far the reviewer zooms in.
 *
 * Colours follow the existing markers: orange for every line in the selected
 * person's row, green for the selected field, and red with a "Needs review"
 * label for lines flagged no_row or shared_cell.
 *
 * Also hosts manual correction: drag a polygon's points, draw a rectangle
 * that replaces the outline, or stretch the outline by the handles round its
 * box (the Align step's line editing after Detect).
 */

const SVG_NS = 'http://www.w3.org/2000/svg';

function svg(tag, attributes = {}) {
    const node = document.createElementNS(SVG_NS, tag);
    Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, String(value)));
    return node;
}

export function polygonPoints(polygon) {
    return polygon.map(([x, y]) => `${Math.round(x * 10) / 10},${Math.round(y * 10) / 10}`).join(' ');
}

export function polygonBounds(polygon) {
    const xs = polygon.map((point) => point[0]);
    const ys = polygon.map((point) => point[1]);
    return {
        left: Math.min(...xs),
        top: Math.min(...ys),
        right: Math.max(...xs),
        bottom: Math.max(...ys),
    };
}

export function rectanglePolygon(x0, y0, x1, y1) {
    const left = Math.min(x0, x1);
    const right = Math.max(x0, x1);
    const top = Math.min(y0, y1);
    const bottom = Math.max(y0, y1);
    return [[left, top], [right, top], [right, bottom], [left, bottom]];
}

// A traced outline keeps a point every few screen pixels; the server takes
// up to 2000, and this leaves room.
const MAX_DRAWN_POINTS = 1500;

/** The eight stretch handles round an outline's box, clockwise from top left. */
export const BOX_HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];

const HANDLE_CURSORS = { nw: 'nwse', se: 'nwse', ne: 'nesw', sw: 'nesw', n: 'ns', s: 'ns', e: 'ew', w: 'ew' };

/** Where a stretch handle sits on a box. */
export function handlePoint(bounds, handle) {
    const x = handle.includes('w') ? bounds.left : handle.includes('e') ? bounds.right : (bounds.left + bounds.right) / 2;
    const y = handle.includes('n') ? bounds.top : handle.includes('s') ? bounds.bottom : (bounds.top + bounds.bottom) / 2;
    return [x, y];
}

/**
 * A box with one handle dragged to a point: that handle's sides follow it,
 * the opposite sides stay, and the box never folds past `minSize`.
 */
export function dragBounds(bounds, handle, [x, y], minSize = 4) {
    const next = { ...bounds };
    if (handle.includes('w')) next.left = Math.min(x, bounds.right - minSize);
    if (handle.includes('e')) next.right = Math.max(x, bounds.left + minSize);
    if (handle.includes('n')) next.top = Math.min(y, bounds.bottom - minSize);
    if (handle.includes('s')) next.bottom = Math.max(y, bounds.top + minSize);
    return next;
}

/** An outline stretched so that its box `from` becomes `to`. */
export function scalePolygon(polygon, from, to) {
    const sx = (to.right - to.left) / Math.max(1e-6, from.right - from.left);
    const sy = (to.bottom - to.top) / Math.max(1e-6, from.bottom - from.top);
    return polygon.map(([x, y]) => [to.left + (x - from.left) * sx, to.top + (y - from.top) * sy]);
}

/**
 * An outline grown (positive `distance`) or shrunk (negative) by that many
 * pixels all round: every corner moves out along the bisector of its two
 * edges, far enough that both edges move by `distance`. Sharp corners are
 * capped at twice the distance so a spike cannot shoot off.
 */
export function offsetPolygon(polygon, distance) {
    const count = polygon.length;
    if (count < 3 || !distance) return polygon.map((point) => [...point]);

    let area = 0;
    polygon.forEach(([x, y], index) => {
        const [nx, ny] = polygon[(index + 1) % count];
        area += x * ny - nx * y;
    });
    // With y pointing down, a positive area runs clockwise on screen, and
    // each edge's outward normal is (dy, -dx).
    const outward = area > 0 ? 1 : -1;
    const normal = ([ax, ay], [bx, by]) => {
        const length = Math.hypot(bx - ax, by - ay);
        return length < 1e-9 ? [0, 0] : [(by - ay) / length, -(bx - ax) / length];
    };

    return polygon.map((point, index) => {
        const before = normal(polygon[(index - 1 + count) % count], point);
        const after = normal(point, polygon[(index + 1) % count]);
        let nx = before[0] + after[0];
        let ny = before[1] + after[1];
        const length = Math.hypot(nx, ny);
        if (length < 1e-9) {
            [nx, ny] = before;
        } else {
            nx /= length;
            ny /= length;
        }
        const reach = distance / Math.max(0.5, nx * before[0] + ny * before[1]);
        return [point[0] + outward * nx * reach, point[1] + outward * ny * reach];
    });
}

export class LineOverlay {
    /**
     * @param {object} options
     * @param {HTMLElement} options.container  Positioned element sized to the displayed canvas.
     * @param {(index: number) => void} [options.onSelect]
     * @param {boolean} [options.preview]  Read-only: shows outlines without taking the
     *        pointer, so field markers underneath stay draggable (the Align step).
     *        setInteractive(true) makes them clickable for line editing.
     * @param {() => void} [options.onBackground]  A click beside every outline.
     * @param {(polygon: number[][], final: boolean) => void} [options.onEdit]
     *        The outline being edited, live while dragging and once more
     *        (final) when the drag, a grow/shrink or a drawing ends.
     * @param {(mode: string) => void} [options.onModeChange]  E.g. back to
     *        'box' once a drawn outline is closed.
     * @param {(points: number) => void} [options.onDraftChange]  How many
     *        points the outline being drawn has; from three on, onEdit also
     *        gets the drawing (not final), for a live preview.
     */
    constructor({
        container, onSelect = null, preview = false, onBackground = null, onEdit = null, onModeChange = null,
        onDraftChange = null,
    }) {
        this.container = container;
        this.onSelect = onSelect;
        this.onBackground = onBackground;
        this.onEdit = onEdit;
        this.onModeChange = onModeChange;
        this.onDraftChange = onDraftChange;
        this._justDragged = false;
        this.width = 1;
        this.height = 1;
        this.lines = [];
        this.groups = [];
        this.editing = null;

        this.root = svg('svg', {
            class: preview ? 'line-overlay is-preview' : 'line-overlay',
            preserveAspectRatio: 'none',
            'aria-label': 'Handwritten line outlines',
        });
        this.guidesLayer = svg('g', { class: 'line-overlay__guides' });
        this.linesLayer = svg('g', { class: 'line-overlay__lines' });
        this.editLayer = svg('g', { class: 'line-overlay__edit' });
        this.root.append(this.guidesLayer, this.linesLayer, this.editLayer);
        this.container.appendChild(this.root);

        this.root.addEventListener('click', (event) => {
            // The click that ends a handle drag is not a choice of line.
            if (this._justDragged) {
                this._justDragged = false;
                return;
            }
            // Stretching or moving points leaves the other outlines clickable,
            // to move on; drawing and the rectangle take every click.
            if (this.editing && !['box', 'points'].includes(this.editing.mode)) return;
            const target = event.target instanceof Element ? event.target : null;
            if (target?.closest('.line-overlay__edit')) return;
            const group = target?.closest('.line-marker');
            if (group) this.onSelect?.(Number(group.dataset.index));
            else this.onBackground?.();
        });
        this.root.addEventListener('keydown', (event) => {
            if ((this.editing && this.editing.mode !== 'box') || !['Enter', ' '].includes(event.key)) return;
            const group = event.target instanceof Element ? event.target.closest('.line-marker') : null;
            if (!group) return;
            event.preventDefault();
            this.onSelect?.(Number(group.dataset.index));
        });
    }

    /**
     * @param {{width: number, height: number}} page  Page size in pixels.
     * @param {Array<{name: string, polygon: number[][], needsReview: boolean}>} lines
     */
    setLines(page, lines) {
        this.cancelEdit();
        this.width = Math.max(1, page.width);
        this.height = Math.max(1, page.height);
        this.root.setAttribute('viewBox', `0 0 ${this.width} ${this.height}`);
        this.lines = lines;
        this.linesLayer.replaceChildren();
        // Rebuilt before any line is drawn, so a re-render never paints onto
        // the previous render's detached elements.
        this.groups = [];
        lines.forEach((line, index) => {
            const group = svg('g', {
                class: 'line-marker',
                'data-index': index,
                tabindex: 0,
                role: 'button',
                'aria-label': `Compare ${line.name}`,
            });
            const title = svg('title');
            title.textContent = line.name;
            group.append(title, svg('polygon', { class: 'line-marker__shape' }));
            this.linesLayer.appendChild(group);
            this.groups.push(group);
            this._drawLine(index);
        });
    }

    /**
     * Dashed guide lines in page pixels, e.g. the ruled rows Detect fitted:
     * [[x0, y0, x1, y1], ...].
     */
    setGuides(segments) {
        this.guidesLayer.replaceChildren(...segments.map(([x1, y1, x2, y2]) => svg('line', {
            class: 'line-overlay__guide', x1, y1, x2, y2,
        })));
    }

    clear() {
        this.cancelEdit();
        this.lines = [];
        this.groups = [];
        this.linesLayer.replaceChildren();
        this.guidesLayer.replaceChildren();
    }

    setVisible(visible) {
        this.root.classList.toggle('d-none', !visible);
    }

    /** Preview outlines that take the pointer (line editing) or let it through. */
    setInteractive(interactive) {
        this.root.classList.toggle('is-interactive', interactive);
    }

    updateLine(index, line) {
        this.lines[index] = line;
        this._drawLine(index);
    }

    /** The row's lines turn orange; the current one green. */
    setSelection(indexes, currentIndex = null) {
        const selected = new Set(indexes);
        this.groups.forEach((group, index) => {
            group.classList.toggle('is-selected', selected.has(index));
            const current = index === currentIndex;
            group.classList.toggle('is-current', current);
            if (current) group.setAttribute('aria-current', 'true');
            else group.removeAttribute('aria-current');
        });
        // Bring the selection above neighbouring outlines.
        indexes.forEach((index) => this.groups[index] && this.linesLayer.appendChild(this.groups[index]));
        if (currentIndex !== null && this.groups[currentIndex]) {
            this.linesLayer.appendChild(this.groups[currentIndex]);
        }
    }

    setVerified(index, verified) {
        this.groups[index]?.classList.toggle('is-verified', verified);
    }

    /** Displayed-pixel bounds of some lines, relative to the container. */
    displayBounds(indexes) {
        const scaleX = this.container.clientWidth / this.width;
        const scaleY = this.container.clientHeight / this.height;
        const bounds = indexes
            .map((index) => this.lines[index])
            .filter(Boolean)
            .map((line) => polygonBounds(line.polygon));
        if (bounds.length === 0) return null;
        return {
            left: Math.min(...bounds.map((b) => b.left)) * scaleX,
            top: Math.min(...bounds.map((b) => b.top)) * scaleY,
            right: Math.max(...bounds.map((b) => b.right)) * scaleX,
            bottom: Math.max(...bounds.map((b) => b.bottom)) * scaleY,
        };
    }

    // ---------------------------------------------------------------- editing

    /**
     * Start correcting one line's outline. `mode` is 'points' (drag the
     * polygon's corners), 'rectangle' (drag a new box over the writing) or
     * 'box' (stretch the outline by the eight handles round its box).
     */
    beginEdit(index, mode = 'points') {
        const line = this.lines[index];
        if (!line) return;
        this.cancelEdit();
        this.editing = {
            index,
            mode,
            polygon: line.polygon.map((point) => [...point]),
            drag: null,
            draft: mode === 'draw' ? [] : null,
            strokes: [],
            activePoint: null,
            cursor: null,
        };
        this.root.classList.add('is-editing');
        this.groups[index]?.classList.add('is-editing');
        this._bindEditEvents();
        this._drawEdit();
    }

    /**
     * Switch how the edited outline is changed. 'draw' starts a new outline
     * from nothing; the old one stays until the new one is closed.
     */
    setEditMode(mode) {
        if (!this.editing) return;
        this.editing.mode = mode;
        this.editing.draft = mode === 'draw' ? [] : null;
        // Where each click or traced stroke began in the draft, for undo.
        this.editing.strokes = [];
        this.editing.activePoint = null;
        this.editing.cursor = null;
        this.editing.drag = null;
        this._drawEdit();
        this.onModeChange?.(mode);
        if (mode === 'draw') this._draftChanged();
    }

    /** True while a new outline is being drawn and has at least one point. */
    isDrawing() {
        return this.editing?.mode === 'draw' && (this.editing.draft?.length ?? 0) > 0;
    }

    /** Close the outline being drawn (Enter, double-click). Needs three points. */
    finishDrawing() {
        const editing = this.editing;
        if (editing?.mode !== 'draw') return false;
        // A double-click lands two clicks on one spot; keep one.
        const near = 2 * this._scale();
        const points = (editing.draft ?? []).filter((point, index, all) => index === 0
            || Math.hypot(point[0] - all[index - 1][0], point[1] - all[index - 1][1]) > near);
        if (points.length < 3) return false;
        // Points all in a row are a line, not an outline: nothing to crop.
        const bounds = polygonBounds(points);
        if (bounds.right - bounds.left < 4 || bounds.bottom - bounds.top < 4) return false;
        editing.polygon = points.slice(0, MAX_DRAWN_POINTS);
        this.setEditMode('box');
        this.onEdit?.(this.editedPolygon(), true);
        return true;
    }

    /**
     * Take back the last click or traced stroke of the outline being drawn.
     * Returns false when there is nothing left to take back.
     */
    undoDrawStep() {
        const editing = this.editing;
        if (editing?.mode !== 'draw' || !(editing.draft?.length > 0)) return false;
        const start = editing.strokes.pop() ?? editing.draft.length - 1;
        editing.draft.length = Math.max(0, start);
        this._drawEdit();
        this._draftChanged();
        return true;
    }

    /** Remove the corner last touched in Points mode (Delete). Keeps at least three. */
    deleteActivePoint() {
        const editing = this.editing;
        if (editing?.mode !== 'points' || editing.activePoint === null || editing.polygon.length <= 3) return false;
        editing.polygon.splice(editing.activePoint, 1);
        editing.activePoint = null;
        this._drawEdit();
        this.onEdit?.(this.editedPolygon(), true);
        return true;
    }

    /** The edited outline in page pixels, or null when not editing. */
    editedPolygon() {
        return this.editing ? this.editing.polygon.map((point) => [...point]) : null;
    }

    /** Grow (positive) or shrink (negative) the edited outline by some pixels. */
    growEdit(distance) {
        if (!this.editing) return;
        const grown = offsetPolygon(this.editing.polygon, distance)
            .map(([x, y]) => [Math.min(this.width, Math.max(0, x)), Math.min(this.height, Math.max(0, y))]);
        const bounds = polygonBounds(grown);
        // Shrinking stops before the outline collapses.
        if (bounds.right - bounds.left < 4 || bounds.bottom - bounds.top < 4) return;
        this.setEditedPolygon(grown);
    }

    /**
     * Replace the edited outline. Reported as a finished edit unless `notify`
     * is false (the caller saves it its own way, e.g. a reset).
     */
    setEditedPolygon(polygon, { notify = true } = {}) {
        if (!this.editing) return;
        this.editing.polygon = polygon.map((point) => [...point]);
        this._drawEdit();
        if (notify) this.onEdit?.(this.editedPolygon(), true);
    }

    cancelEdit() {
        if (!this.editing) return;
        this.groups[this.editing.index]?.classList.remove('is-editing');
        this.editing = null;
        this.root.classList.remove('is-editing');
        this.editLayer.replaceChildren();
        this._unbindEditEvents();
    }

    /** Re-size handles after a zoom so they stay a constant size on screen. */
    refresh() {
        if (this.editing) this._drawEdit();
    }

    // -------------------------------------------------------------- internals

    _drawLine(index) {
        const line = this.lines[index];
        const group = this.groups[index];
        if (!line || !group) return;

        group.classList.toggle('needs-review', Boolean(line.needsReview));
        group.classList.toggle('is-adjusted', Boolean(line.adjusted));
        group.classList.toggle('is-unsaved', Boolean(line.unsaved));
        group.querySelector('.line-marker__shape').setAttribute('points', polygonPoints(line.polygon));
        group.querySelector('.line-review-label')?.remove();
        group.querySelector('.line-adjusted-mark')?.remove();

        if (line.adjusted) {
            // A pencil at the outline's top right: this one was adjusted by hand.
            const bounds = polygonBounds(line.polygon);
            const size = Math.max(9, this.height / 90);
            const mark = svg('text', {
                class: 'line-adjusted-mark',
                x: bounds.right - size * 0.2,
                y: Math.max(size, bounds.top + size * 0.25),
                'font-size': size,
                'text-anchor': 'end',
                'aria-hidden': 'true',
            });
            mark.textContent = '✎';
            group.appendChild(mark);
        }

        if (line.needsReview) {
            const bounds = polygonBounds(line.polygon);
            const size = Math.max(9, this.height / 95);
            const label = svg('g', { class: 'line-review-label', 'aria-hidden': 'true' });
            const text = svg('text', {
                x: bounds.left + size * 0.35,
                y: Math.max(size, bounds.top - size * 0.35),
                'font-size': size,
            });
            text.textContent = 'Needs review';
            const background = svg('rect', {
                x: bounds.left,
                y: Math.max(0, bounds.top - size * 1.35),
                width: size * 6.4,
                height: size * 1.25,
                rx: size * 0.2,
            });
            label.append(background, text);
            group.appendChild(label);
        }
    }

    /** Tell the page how the drawing stands, and preview it from three points. */
    _draftChanged() {
        const draft = this.editing?.draft ?? [];
        this.onDraftChange?.(draft.length);
        if (draft.length >= 3) this.onEdit?.(draft.map((point) => [...point]), false);
    }

    _scale() {
        return this.container.clientWidth > 0 ? this.width / this.container.clientWidth : 1;
    }

    _pagePoint(event) {
        const matrix = this.root.getScreenCTM();
        if (!matrix) return [0, 0];
        const point = this.root.createSVGPoint();
        point.x = event.clientX;
        point.y = event.clientY;
        const local = point.matrixTransform(matrix.inverse());
        return [
            Math.min(this.width, Math.max(0, local.x)),
            Math.min(this.height, Math.max(0, local.y)),
        ];
    }

    _drawEdit() {
        const editing = this.editing;
        this.editLayer.replaceChildren();
        if (!editing) return;

        const scale = this._scale();
        this.root.dataset.editMode = editing.mode;
        this.editLayer.appendChild(svg('polygon', {
            // While a new outline is drawn, the old one shows faintly.
            class: editing.mode === 'draw' ? 'line-edit__shape is-replaced' : 'line-edit__shape',
            points: polygonPoints(editing.polygon),
        }));

        if (editing.mode === 'draw') {
            const draft = editing.draft ?? [];
            if (draft.length > 0) {
                const trail = editing.cursor && !editing.drag ? [...draft, editing.cursor] : draft;
                this.editLayer.appendChild(svg('polyline', { class: 'line-edit__draft', points: polygonPoints(trail) }));
                draft.forEach(([x, y], pointIndex) => {
                    // Freehand traces have many points; show only the first (to close on).
                    if (pointIndex > 0 && draft.length > 40) return;
                    this.editLayer.appendChild(svg('circle', {
                        class: pointIndex === 0 && draft.length >= 3 ? 'line-edit__vertex is-start' : 'line-edit__vertex',
                        cx: x,
                        cy: y,
                        r: (pointIndex === 0 ? 6 : 3.5) * scale,
                    }));
                });
            }
            return;
        }

        if (editing.mode === 'points') {
            // A small dot halfway along each edge: drag it to add a corner there.
            // Dense traced outlines already have corners everywhere.
            const count = editing.polygon.length;
            if (count <= 120) {
                editing.polygon.forEach(([x, y], pointIndex) => {
                    const [nx, ny] = editing.polygon[(pointIndex + 1) % count];
                    this.editLayer.appendChild(svg('circle', {
                        class: 'line-edit__midpoint',
                        cx: (x + nx) / 2,
                        cy: (y + ny) / 2,
                        r: 3.5 * scale,
                        'data-edge': pointIndex,
                    }));
                });
            }
            editing.polygon.forEach(([x, y], pointIndex) => {
                this.editLayer.appendChild(svg('circle', {
                    class: pointIndex === editing.activePoint ? 'line-edit__handle is-active' : 'line-edit__handle',
                    cx: x,
                    cy: y,
                    r: 5 * scale,
                    'data-point': pointIndex,
                }));
            });
        } else if (editing.mode === 'box') {
            const bounds = polygonBounds(editing.polygon);
            this.editLayer.appendChild(svg('rect', {
                class: 'line-edit__box',
                x: bounds.left,
                y: bounds.top,
                width: bounds.right - bounds.left,
                height: bounds.bottom - bounds.top,
            }));
            const half = 4.5 * scale;
            BOX_HANDLES.forEach((handle) => {
                const [x, y] = handlePoint(bounds, handle);
                const grip = svg('rect', {
                    class: 'line-edit__handle line-edit__grip',
                    x: x - half,
                    y: y - half,
                    width: half * 2,
                    height: half * 2,
                    'data-handle': handle,
                });
                grip.style.cursor = `${HANDLE_CURSORS[handle]}-resize`;
                this.editLayer.appendChild(grip);
            });
        }
    }

    _bindEditEvents() {
        this._onPointerDown = (event) => {
            const editing = this.editing;
            if (!editing || event.button !== 0 || event.ctrlKey) return;

            if (editing.mode === 'draw') {
                const point = this._pagePoint(event);
                const draft = editing.draft ?? (editing.draft = []);
                // Back on the first point closes the outline.
                if (draft.length >= 3 && Math.hypot(point[0] - draft[0][0], point[1] - draft[0][1]) <= 8 * this._scale()) {
                    event.preventDefault();
                    event.stopPropagation();
                    this._justDragged = true;
                    window.setTimeout(() => { this._justDragged = false; }, 0);
                    this.finishDrawing();
                    return;
                }
                editing.strokes.push(draft.length);
                draft.push(point);
                editing.drag = { kind: 'draw', start: point, freehand: false };
                this._draftChanged();
            } else if (editing.mode === 'points') {
                const target = event.target instanceof Element ? event.target : null;
                const midpoint = target?.closest('.line-edit__midpoint');
                const handle = target?.closest('.line-edit__handle');
                if (midpoint) {
                    // A new corner where the dot was, dragged from there.
                    const at = Number(midpoint.dataset.edge) + 1;
                    editing.polygon.splice(at, 0, this._pagePoint(event));
                    editing.drag = { kind: 'point', point: at };
                    editing.activePoint = at;
                } else if (handle) {
                    editing.drag = { kind: 'point', point: Number(handle.dataset.point) };
                    editing.activePoint = Number(handle.dataset.point);
                } else {
                    return;
                }
            } else if (editing.mode === 'box') {
                // Only a handle starts a stretch; a click elsewhere picks a line.
                const grip = event.target instanceof Element ? event.target.closest('.line-edit__grip') : null;
                if (!grip) return;
                editing.drag = {
                    kind: 'box',
                    handle: grip.dataset.handle,
                    bounds: polygonBounds(editing.polygon),
                    polygon: editing.polygon.map((point) => [...point]),
                };
            } else {
                editing.drag = { kind: 'rectangle', start: this._pagePoint(event) };
            }
            event.preventDefault();
            event.stopPropagation();
            this.root.setPointerCapture?.(event.pointerId);
        };
        this._onPointerMove = (event) => {
            const editing = this.editing;
            if (editing?.mode === 'draw') {
                const point = this._pagePoint(event);
                editing.cursor = point;
                const drag = editing.drag;
                if (drag?.kind === 'draw') {
                    event.preventDefault();
                    const scale = this._scale();
                    // Pressed and moving: tracing freehand, a point every few
                    // pixels. The jitter of an ordinary click is not a trace.
                    if (!drag.freehand && Math.hypot(point[0] - drag.start[0], point[1] - drag.start[1]) > 8 * scale) {
                        drag.freehand = true;
                    }
                    const last = editing.draft[editing.draft.length - 1];
                    if (drag.freehand && editing.draft.length < MAX_DRAWN_POINTS
                        && Math.hypot(point[0] - last[0], point[1] - last[1]) > 3 * scale) {
                        editing.draft.push(point);
                        this._draftChanged();
                    }
                }
                if ((editing.draft?.length ?? 0) > 0) this._drawEdit();
                return;
            }
            if (!editing?.drag) return;
            event.preventDefault();
            const [x, y] = this._pagePoint(event);
            if (editing.drag.kind === 'point') {
                editing.polygon[editing.drag.point] = [x, y];
            } else if (editing.drag.kind === 'box') {
                const { handle, bounds, polygon } = editing.drag;
                editing.polygon = scalePolygon(polygon, bounds, dragBounds(bounds, handle, [x, y]));
            } else {
                const [x0, y0] = editing.drag.start;
                editing.polygon = rectanglePolygon(x0, y0, x, y);
            }
            this._drawEdit();
            this.onEdit?.(this.editedPolygon(), false);
        };
        this._onPointerUp = (event) => {
            const editing = this.editing;
            if (!editing?.drag) return;
            if (editing.drag.kind === 'draw') {
                // Drawing only ends when asked: a trace that comes back round
                // to where the outline began (a lasso), a click on the first
                // point, Enter or a double-click. Lifting the hand anywhere
                // else just pauses, to carry on with clicks or another trace.
                const draft = editing.draft;
                const lasso = editing.drag.freehand && draft.length >= 8
                    && Math.hypot(draft[draft.length - 1][0] - draft[0][0], draft[draft.length - 1][1] - draft[0][1])
                        <= 14 * this._scale();
                editing.drag = null;
                this._justDragged = true;
                window.setTimeout(() => { this._justDragged = false; }, 0);
                if (this.root.hasPointerCapture?.(event.pointerId)) this.root.releasePointerCapture(event.pointerId);
                if (!(lasso && this.finishDrawing())) this._drawEdit();
                return;
            }
            // A click without a drag must not collapse the outline to a point.
            if (editing.drag.kind === 'rectangle') {
                const bounds = polygonBounds(editing.polygon);
                if (bounds.right - bounds.left < 3 || bounds.bottom - bounds.top < 3) {
                    editing.polygon = this.lines[editing.index].polygon.map((point) => [...point]);
                }
            }
            editing.drag = null;
            // The click that follows this pointerup comes in the same turn.
            this._justDragged = true;
            window.setTimeout(() => { this._justDragged = false; }, 0);
            if (this.root.hasPointerCapture?.(event.pointerId)) this.root.releasePointerCapture(event.pointerId);
            this._drawEdit();
            this.onEdit?.(this.editedPolygon(), true);
        };
        this._onDoubleClick = (event) => {
            const editing = this.editing;
            if (!editing) return;
            if (editing.mode === 'draw') {
                event.preventDefault();
                this.finishDrawing();
            } else if (editing.mode === 'points') {
                // Double-click a corner to remove it.
                const handle = event.target instanceof Element ? event.target.closest('.line-edit__handle') : null;
                if (!handle) return;
                event.preventDefault();
                editing.activePoint = Number(handle.dataset.point);
                this.deleteActivePoint();
            }
        };
        this.root.addEventListener('pointerdown', this._onPointerDown);
        this.root.addEventListener('pointermove', this._onPointerMove);
        this.root.addEventListener('pointerup', this._onPointerUp);
        this.root.addEventListener('pointercancel', this._onPointerUp);
        this.root.addEventListener('dblclick', this._onDoubleClick);
    }

    _unbindEditEvents() {
        if (!this._onPointerDown) return;
        this.root.removeEventListener('pointerdown', this._onPointerDown);
        this.root.removeEventListener('pointermove', this._onPointerMove);
        this.root.removeEventListener('pointerup', this._onPointerUp);
        this.root.removeEventListener('pointercancel', this._onPointerUp);
        this.root.removeEventListener('dblclick', this._onDoubleClick);
        this._onPointerDown = null;
    }
}
