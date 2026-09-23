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
 * Also hosts manual correction: drag a polygon's points, or draw a rectangle
 * that replaces the outline.
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

export class LineOverlay {
    /**
     * @param {object} options
     * @param {HTMLElement} options.container  Positioned element sized to the displayed canvas.
     * @param {(index: number) => void} [options.onSelect]
     * @param {boolean} [options.preview]  Read-only: shows outlines without taking the
     *        pointer, so field markers underneath stay draggable (the Align step).
     */
    constructor({ container, onSelect = null, preview = false }) {
        this.container = container;
        this.onSelect = onSelect;
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
            if (this.editing) return;
            const group = event.target instanceof Element ? event.target.closest('.line-marker') : null;
            if (group) this.onSelect?.(Number(group.dataset.index));
        });
        this.root.addEventListener('keydown', (event) => {
            if (this.editing || !['Enter', ' '].includes(event.key)) return;
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
     * polygon's corners) or 'rectangle' (drag a new box over the writing).
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
        };
        this.root.classList.add('is-editing');
        this.groups[index]?.classList.add('is-editing');
        this._bindEditEvents();
        this._drawEdit();
    }

    setEditMode(mode) {
        if (!this.editing) return;
        this.editing.mode = mode;
        this._drawEdit();
    }

    /** The edited outline in page pixels, or null when not editing. */
    editedPolygon() {
        return this.editing ? this.editing.polygon.map((point) => [...point]) : null;
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
        group.querySelector('.line-marker__shape').setAttribute('points', polygonPoints(line.polygon));
        group.querySelector('.line-review-label')?.remove();

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
            class: 'line-edit__shape',
            points: polygonPoints(editing.polygon),
        }));

        if (editing.mode === 'points') {
            editing.polygon.forEach(([x, y], pointIndex) => {
                this.editLayer.appendChild(svg('circle', {
                    class: 'line-edit__handle',
                    cx: x,
                    cy: y,
                    r: 5 * scale,
                    'data-point': pointIndex,
                }));
            });
        }
    }

    _bindEditEvents() {
        this._onPointerDown = (event) => {
            const editing = this.editing;
            if (!editing || event.button !== 0 || event.ctrlKey) return;

            if (editing.mode === 'points') {
                const handle = event.target instanceof Element ? event.target.closest('.line-edit__handle') : null;
                if (!handle) return;
                editing.drag = { kind: 'point', point: Number(handle.dataset.point) };
            } else {
                editing.drag = { kind: 'rectangle', start: this._pagePoint(event) };
            }
            event.preventDefault();
            event.stopPropagation();
            this.root.setPointerCapture?.(event.pointerId);
        };
        this._onPointerMove = (event) => {
            const editing = this.editing;
            if (!editing?.drag) return;
            event.preventDefault();
            const [x, y] = this._pagePoint(event);
            if (editing.drag.kind === 'point') {
                editing.polygon[editing.drag.point] = [x, y];
            } else {
                const [x0, y0] = editing.drag.start;
                editing.polygon = rectanglePolygon(x0, y0, x, y);
            }
            this._drawEdit();
        };
        this._onPointerUp = (event) => {
            const editing = this.editing;
            if (!editing?.drag) return;
            // A click without a drag must not collapse the outline to a point.
            if (editing.drag.kind === 'rectangle') {
                const bounds = polygonBounds(editing.polygon);
                if (bounds.right - bounds.left < 3 || bounds.bottom - bounds.top < 3) {
                    editing.polygon = this.lines[editing.index].polygon.map((point) => [...point]);
                }
            }
            editing.drag = null;
            if (this.root.hasPointerCapture?.(event.pointerId)) this.root.releasePointerCapture(event.pointerId);
            this._drawEdit();
        };
        this.root.addEventListener('pointerdown', this._onPointerDown);
        this.root.addEventListener('pointermove', this._onPointerMove);
        this.root.addEventListener('pointerup', this._onPointerUp);
        this.root.addEventListener('pointercancel', this._onPointerUp);
    }

    _unbindEditEvents() {
        if (!this._onPointerDown) return;
        this.root.removeEventListener('pointerdown', this._onPointerDown);
        this.root.removeEventListener('pointermove', this._onPointerMove);
        this.root.removeEventListener('pointerup', this._onPointerUp);
        this.root.removeEventListener('pointercancel', this._onPointerUp);
        this._onPointerDown = null;
    }
}
