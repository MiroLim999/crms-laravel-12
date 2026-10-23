/**
 * Field marker.
 *
 * Renders a scanned certificate (image or PDF page) to a canvas, overlays
 * draggable and resizable field boxes, and crops each box to a PNG data URL for
 * the OCR service.
 *
 * Box coordinates are held as fractions of the page (0-1), matching how templates
 * are stored, so a marked layout survives any zoom level or scan resolution.
 *
 * Ported from the prototype's web/js/app.js. The crop path in particular is kept
 * faithful: cropping from the full-resolution canvas rather than the displayed
 * size is what keeps small handwriting legible to the model.
 */

import * as pdfjsLib from 'pdfjs-dist';
import { markerPersonMetadata } from './person-grouping.js';
import {
    canVerifyValue,
    verificationGroupState,
} from './verification-groups.js';

// The worker that parses PDFs is served by this app, not a CDN, so PDFs open
// without internet. tools/copy-pdf-worker.mjs copies it from pdfjs-dist into
// public/vendor/pdfjs before every build, so it always matches the installed
// version. It is copied rather than bundled, keeping the 2.2 MB file out of
// Vite's build.
pdfjsLib.GlobalWorkerOptions.workerSrc = '/vendor/pdfjs/pdf.worker.min.mjs';

const HANDLE_SIZE = 10;
const MIN_FRACTION = 0.01;
// Holding Shift while turning a marker snaps it to this many degrees.
const ROTATE_SNAP_DEGREES = 5;
// A turning arrow for the tilt knob. Inline, not from the subset icon font.
const ROTATE_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
    + '<path d="M19.5 12a7.5 7.5 0 1 1-2.2-5.3" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/>'
    + '<path d="M19.8 3.8v4.6h-4.6" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>'
    + '</svg>';

/**
 * Return the portable part of a marker box.
 *
 * Person metadata is optional because Staff may add ad-hoc fields while marking
 * a document. Keeping it beside the coordinates lets a template's explicit
 * validation groups survive moves, renames, undo, and cropping.
 */
function serialiseBox(box, withSettings = false) {
    return {
        name: box.name,
        x: box.x,
        y: box.y,
        w: box.w,
        h: box.h,
        ...markerPersonMetadata(box),
        ...markerColumnMetadata(box),
        ...markerAngleMetadata(box),
        ...(withSettings ? markerFieldSettings(box) : {}),
    };
}

const FIELD_ROLES = ['name', 'entry'];
const VALUE_TYPES = ['date', 'number', 'choice'];

/**
 * What a Template Builder field holds and how Staff check its value, present
 * only where it differs from the defaults (any value, text, required, no hint):
 * role 'name' or 'entry', type 'date', 'number' or 'choice' with its options,
 * a hint, and required: false.
 */
export function markerFieldSettings(box) {
    const settings = {};
    if (FIELD_ROLES.includes(box?.role)) settings.role = box.role;
    if (box?.required === false) settings.required = false;
    if (VALUE_TYPES.includes(box?.type)) settings.type = box.type;
    if (settings.type === 'choice' && Array.isArray(box.options)) {
        const options = box.options.map((option) => String(option).trim()).filter(Boolean);
        if (options.length > 0) settings.options = options;
    }
    const hint = typeof box?.hint === 'string' ? box.hint.trim() : '';
    if (hint) settings.hint = hint;
    return settings;
}

/**
 * A marker's tilt in degrees, clockwise as seen on screen, in (-180, 180].
 * Rounded to a tenth of a degree: finer than a hand can place it, and it keeps
 * saved layouts free of floating-point noise.
 */
export function normaliseAngle(degrees) {
    const value = Number(degrees);
    if (!Number.isFinite(value)) return 0;
    let angle = Math.round((((value % 360) + 540) % 360 - 180) * 10) / 10;
    if (angle === -180) angle = 180;
    return angle === 0 ? 0 : angle;
}

/**
 * The tilt of a marker, present only when it is turned, so upright layouts
 * serialise exactly as they did before markers could turn.
 *
 * Every marker, a ledger column too, turns on its own about its own centre.
 * For a ledger, the server takes the columns' typical tilt as the page's and
 * straightens the page by it before reading the rows.
 */
export function markerAngleMetadata(box) {
    const angle = normaliseAngle(box?.angle);
    return angle === 0 ? {} : { angle };
}

/** The point a marker turns about (its centre), in the units of width and height. */
export function markerPivot(box, width, height) {
    return { x: (box.x + box.w / 2) * width, y: (box.y + box.h / 2) * height };
}

/**
 * Magnetic edges: the shift that puts the nearest of some edges onto the
 * nearest line, if any is within reach (all in the same units), else 0.
 * Returns { shift, line } where line is the one snapped to (or null).
 */
export function magnetShift(edges, lines, reach) {
    let best = null;
    edges.forEach((edge) => {
        (lines ?? []).forEach((line) => {
            const shift = line - edge;
            if (Math.abs(shift) <= reach && (best === null || Math.abs(shift) < Math.abs(best.shift))) {
                best = { shift, line };
            }
        });
    });
    return best ?? { shift: 0, line: null };
}

/** Resize handles: four corners and four edge midpoints, by compass direction. */
export const RESIZE_HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];

/**
 * A box of size w x h resized by dragging one handle by (dx, dy), all in the
 * box's own upright frame. Only the sides the handle touches move ("n" the top,
 * "se" the right and bottom); the opposite sides stay put. A side is never
 * dragged past the point where the box would be smaller than the minimum.
 *
 * @returns {{left: number, top: number, right: number, bottom: number}} relative to the old top-left
 */
export function resizeRect(w, h, handle, dx, dy, minW, minH) {
    let left = 0;
    let top = 0;
    let right = w;
    let bottom = h;
    if (handle.includes('w')) left = Math.min(dx, w - minW);
    if (handle.includes('e')) right = Math.max(w + dx, minW);
    if (handle.includes('n')) top = Math.min(dy, h - minH);
    if (handle.includes('s')) bottom = Math.max(h + dy, minH);
    return { left, top, right, bottom };
}

/** (x, y) turned clockwise on screen by the given degrees (y points down). */
export function turnPoint(x, y, degrees) {
    const radians = degrees * Math.PI / 180;
    const cos = Math.cos(radians);
    const sin = Math.sin(radians);
    return { x: x * cos - y * sin, y: x * sin + y * cos };
}

/** A marker's four corners on a page of the given size, after its tilt. */
export function markerCorners(box, width, height) {
    const pivot = markerPivot(box, width, height);
    const halfWidth = box.w * width / 2;
    const halfHeight = box.h * height / 2;
    const angle = normaliseAngle(box.angle);
    return [[-1, -1], [1, -1], [1, 1], [-1, 1]].map(([sx, sy]) => {
        const turned = turnPoint(sx * halfWidth, sy * halfHeight, angle);
        return { x: pivot.x + turned.x, y: pivot.y + turned.y };
    });
}

/**
 * Whether a marker, tilt included, stays on the page. A turned marker's
 * corners reach past its upright box, so the box alone can be on the page
 * while a corner is off it. `tolerance` is a fraction of each side.
 */
export function markerInsidePage(box, width, height, tolerance = 0.005) {
    return markerCorners(box, width, height).every(({ x, y }) => (
        x >= -tolerance * width && x <= width * (1 + tolerance)
        && y >= -tolerance * height && y <= height * (1 + tolerance)
    ));
}

/**
 * A ledger column marker: aligned like any field, but read line by line inside
 * the template's ruled rows instead of cropped as one rectangle.
 */
export function markerColumnMetadata(box) {
    if (box?.kind !== 'column') return {};
    const columnIndex = Number(box.columnIndex);
    return Number.isInteger(columnIndex) && columnIndex >= 0
        ? { kind: 'column', columnIndex }
        : { kind: 'column' };
}

export function fieldMarkerPanPosition(scrollLeft, scrollTop, movementX, movementY) {
    return {
        left: Math.max(0, scrollLeft - movementX),
        top: Math.max(0, scrollTop - movementY),
    };
}

export class FieldMarker {
    /**
     * @param {object} options
     * @param {HTMLCanvasElement} options.canvas   Page render target.
     * @param {HTMLElement} options.overlay        Positioned container for the boxes.
     * @param {HTMLElement|null} [options.viewport] Scroll container used for zooming.
     * @param {boolean} [options.readOnly]         Render boxes without interaction.
     * @param {(boxes: Array) => void} [options.onChange]
     * @param {(indexes: number[], context: {source: string, activeIndex: number|null}) => void} [options.onSelectionChange]
     * @param {(zoom: number) => void} [options.onZoomChange]
     * @param {(boxes: Array) => void} [options.onDrag]  While markers are dragged, before onChange.
     * @param {boolean} [options.fieldSettings]  Keep markerFieldSettings() in toJSON() (Template Builder).
     */
    constructor({
        canvas,
        overlay,
        viewport = null,
        readOnly = false,
        onChange = null,
        onSelectionChange = null,
        onZoomChange = null,
        onDrag = null,
        fieldSettings = false,
    }) {
        this.canvas = canvas;
        this.overlay = overlay;
        this.viewport = viewport;
        this.readOnly = readOnly;
        this.onChange = onChange;
        this.onSelectionChange = onSelectionChange;
        this.onZoomChange = onZoomChange;
        this.onDrag = onDrag;
        this.fieldSettings = fieldSettings;

        /** @type {Array<{name: string, x: number, y: number, w: number, h: number, personGroup?: number, personFieldOrder?: number, el: HTMLElement|null}>} */
        this.boxes = [];
        this.selected = new Set();
        // Printed lines on the page (fractions) that marker edges snap to.
        this.snapLines = null;
        this.guides = { v: null, h: null };
        this.pdfDoc = null;
        this.pageMeasurement = null;
        this.zoom = 1;
        this.minZoom = 0.5;
        // Up to 500%: small handwriting on low-resolution scans needs a close
        // look when outlines are adjusted stroke by stroke.
        this.maxZoom = 5;

        this._panning = false;
        this._panX = 0;
        this._panY = 0;
        this._panStartX = 0;
        this._panStartY = 0;
        this._suppressPanClick = false;

        // Boxes are positioned in display pixels, so a resize has to reposition them.
        this._onResize = () => this.viewport ? this._applyZoom() : this.layout();
        this._onWheel = (event) => {
            if (!event.ctrlKey || !this.viewport) return;

            event.preventDefault();
            this.zoomBy(event.deltaY < 0 ? 0.1 : -0.1, event);
        };
        this._onOverlayPointerDown = (event) => {
            if (event.ctrlKey) return;
            if (event.target === this.overlay) this.clearSelection();
        };

        this._onViewportPointerDown = (event) => {
            if (!event.ctrlKey || event.button !== 0) return;

            event.preventDefault();
            this._panning = true;
            this._panX = event.clientX;
            this._panY = event.clientY;
            this._panStartX = event.clientX;
            this._panStartY = event.clientY;
            this._suppressPanClick = false;
            this.viewport?.setPointerCapture?.(event.pointerId);
            this.viewport?.classList.add('is-panning');
        };

        this._onViewportPointerMove = (event) => {
            if (!this._panning || !this.viewport) return;

            event.preventDefault();
            const next = fieldMarkerPanPosition(
                this.viewport.scrollLeft,
                this.viewport.scrollTop,
                event.clientX - this._panX,
                event.clientY - this._panY,
            );
            this.viewport.scrollLeft = next.left;
            this.viewport.scrollTop = next.top;
            this._panX = event.clientX;
            this._panY = event.clientY;
            if (Math.hypot(event.clientX - this._panStartX, event.clientY - this._panStartY) > 3) {
                this._suppressPanClick = true;
            }
        };

        this._onViewportPointerFinish = (event) => {
            if (!this._panning) return;

            this._panning = false;
            this.viewport?.classList.remove('is-panning');
            if (event.type === 'pointercancel') {
                this._suppressPanClick = false;
            }
            if (this.viewport?.hasPointerCapture?.(event.pointerId)) {
                this.viewport.releasePointerCapture(event.pointerId);
            }
        };

        this._onViewportClick = (event) => {
            if (!this._suppressPanClick) return;

            event.preventDefault();
            event.stopImmediatePropagation();
            this._suppressPanClick = false;
        };

        window.addEventListener('resize', this._onResize);
        if (this.viewport) {
            this.viewport.addEventListener('wheel', this._onWheel, { passive: false });
            this.viewport.addEventListener('pointerdown', this._onViewportPointerDown);
            this.viewport.addEventListener('pointermove', this._onViewportPointerMove);
            this.viewport.addEventListener('pointerup', this._onViewportPointerFinish);
            this.viewport.addEventListener('pointercancel', this._onViewportPointerFinish);
            this.viewport.addEventListener('click', this._onViewportClick, true);
        }
        this.overlay.addEventListener('pointerdown', this._onOverlayPointerDown);
    }

    destroy() {
        window.removeEventListener('resize', this._onResize);
        if (this.viewport) {
            this.viewport.removeEventListener('wheel', this._onWheel);
            this.viewport.removeEventListener('pointerdown', this._onViewportPointerDown);
            this.viewport.removeEventListener('pointermove', this._onViewportPointerMove);
            this.viewport.removeEventListener('pointerup', this._onViewportPointerFinish);
            this.viewport.removeEventListener('pointercancel', this._onViewportPointerFinish);
            this.viewport.removeEventListener('click', this._onViewportClick, true);
        }
        this.overlay.removeEventListener('pointerdown', this._onOverlayPointerDown);
    }

    // ------------------------------------------------------------------ loading

    /**
     * Render a File (image or PDF) onto the canvas.
     */
    async load(file) {
        const isPdf = file.type === 'application/pdf'
            || file.name.toLowerCase().endsWith('.pdf');

        if (isPdf) {
            const buffer = await file.arrayBuffer();
            this.pdfDoc = await pdfjsLib.getDocument({ data: buffer }).promise;
            await this.renderPdfPage(1);
        } else {
            await this.renderImage(file);
            this.pageMeasurement = await this._measureImage(file);
        }

        this.layout();

        return this.pageMeasurement;
    }

    /**
     * Render from a URL, used when revisiting an already-stored scan.
     */
    async loadFromUrl(url, isPdf = false) {
        if (isPdf) {
            this.pdfDoc = await pdfjsLib.getDocument({ url }).promise;
            await this.renderPdfPage(1);
        } else {
            await this._drawImage(url);
        }

        this.layout();
    }

    async renderPdfPage(pageNumber) {
        const page = await this.pdfDoc.getPage(pageNumber);

        // PDF viewport units at scale 1 are points (1/72 inch), so this is a
        // real physical page measurement rather than an estimate from pixels.
        const physicalViewport = page.getViewport({ scale: 1 });
        this.pageMeasurement = {
            kind: 'pdf',
            widthPx: Math.round(physicalViewport.width * 2),
            heightPx: Math.round(physicalViewport.height * 2),
            widthMm: physicalViewport.width * 25.4 / 72,
            heightMm: physicalViewport.height * 25.4 / 72,
            pageCount: this.pdfDoc.numPages,
            physicalSource: 'PDF page box',
        };

        // Render at 2x so the crops handed to the model keep their detail.
        const viewport = page.getViewport({ scale: 2 });
        this.canvas.width = viewport.width;
        this.canvas.height = viewport.height;

        const ctx = this.canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);

        await page.render({ canvasContext: ctx, viewport }).promise;
    }

    async renderImage(file) {
        const url = URL.createObjectURL(file);
        try {
            await this._drawImage(url);
        } finally {
            URL.revokeObjectURL(url);
        }
    }

    async _measureImage(file) {
        const measurement = {
            kind: 'image',
            widthPx: this.canvas.width,
            heightPx: this.canvas.height,
            widthMm: null,
            heightMm: null,
            pageCount: 1,
            physicalSource: null,
        };

        try {
            const density = this._readImageDensity(await file.arrayBuffer(), file);
            if (!density) return measurement;

            measurement.widthMm = this.canvas.width / density.xPixelsPerInch * 25.4;
            measurement.heightMm = this.canvas.height / density.yPixelsPerInch * 25.4;
            measurement.physicalSource = density.source;
        } catch {
            // Pixel dimensions remain exact even when optional metadata cannot
            // be read. Physical size must not be guessed from screen DPI.
        }

        return measurement;
    }

    _readImageDensity(buffer, file) {
        const view = new DataView(buffer);
        const name = file.name.toLowerCase();

        if ((file.type === 'image/png' || name.endsWith('.png')) && view.byteLength >= 33) {
            let offset = 8;
            while (offset + 12 <= view.byteLength) {
                const length = view.getUint32(offset);
                const type = String.fromCharCode(
                    view.getUint8(offset + 4), view.getUint8(offset + 5),
                    view.getUint8(offset + 6), view.getUint8(offset + 7),
                );
                if (type === 'pHYs' && length >= 9 && offset + 17 <= view.byteLength) {
                    const xPerMetre = view.getUint32(offset + 8);
                    const yPerMetre = view.getUint32(offset + 12);
                    const unitIsMetre = view.getUint8(offset + 16) === 1;
                    if (unitIsMetre && xPerMetre > 0 && yPerMetre > 0) {
                        return {
                            xPixelsPerInch: xPerMetre * 0.0254,
                            yPixelsPerInch: yPerMetre * 0.0254,
                            source: 'PNG density metadata',
                        };
                    }
                }
                offset += length + 12;
            }
        }

        if ((file.type === 'image/jpeg' || /\.jpe?g$/.test(name))
            && view.byteLength >= 18 && view.getUint16(0) === 0xffd8) {
            let offset = 2;
            while (offset + 4 <= view.byteLength) {
                if (view.getUint8(offset) !== 0xff) {
                    offset += 1;
                    continue;
                }

                const marker = view.getUint8(offset + 1);
                if (marker === 0xda || marker === 0xd9) break;
                const length = view.getUint16(offset + 2);
                const dataOffset = offset + 4;
                if (marker === 0xe0 && length >= 16 && dataOffset + 12 <= view.byteLength) {
                    const identifier = String.fromCharCode(...new Uint8Array(buffer, dataOffset, 5));
                    if (identifier === 'JFIF\0') {
                        const unit = view.getUint8(dataOffset + 7);
                        const xDensity = view.getUint16(dataOffset + 8);
                        const yDensity = view.getUint16(dataOffset + 10);
                        if (unit > 0 && xDensity > 0 && yDensity > 0) {
                            const multiplier = unit === 2 ? 2.54 : 1;
                            return {
                                xPixelsPerInch: xDensity * multiplier,
                                yPixelsPerInch: yDensity * multiplier,
                                source: 'JPEG density metadata',
                            };
                        }
                    }
                }
                if (length < 2) break;
                offset += length + 2;
            }
        }

        return null;
    }

    _drawImage(url) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => {
                this.canvas.width = img.naturalWidth;
                this.canvas.height = img.naturalHeight;

                const ctx = this.canvas.getContext('2d');
                // White backing: transparent PNGs would otherwise crop to black.
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
                ctx.drawImage(img, 0, 0);
                resolve();
            };
            img.onerror = () => reject(new Error('Could not load that image.'));
            img.src = url;
        });
    }

    // -------------------------------------------------------------------- boxes

    /**
     * @param {Array<{name: string, x: number, y: number, w: number, h: number, personGroup?: number, personFieldOrder?: number}>} boxes
     */
    setBoxes(boxes) {
        // Remove marker elements without destroying overlay-owned tools such as
        // Template Builder's Windows-style marquee selection rectangle.
        this.overlay.querySelectorAll(':scope > .field-box').forEach((element) => element.remove());
        this.boxes = boxes.map((box) => ({ ...box, el: null }));
        this.selected.clear();
        this.layout();
        this._emit();
        this._emitSelection();
    }

    addBox(name, fraction = { x: 0.3, y: 0.1, w: 0.35, h: 0.05 }) {
        const box = { name, ...fraction, el: null };
        this.boxes.push(box);
        this.selected = new Set([box]);
        this.layout();
        this._emit();
        this._emitSelection();
    }

    removeBox(index) {
        const [removed] = this.boxes.splice(index, 1);
        this.selected.delete(removed);
        removed?.el?.remove();
        this.layout();
        this._emit();
        this._emitSelection();
    }

    renameBox(index, name) {
        if (!this.boxes[index]) return;
        this.boxes[index].name = name;
        const label = this.boxes[index].el?.querySelector('.field-box-label');
        if (label) label.textContent = name;
        this._emit();
    }

    /**
     * Fractional coordinates, ready to persist or submit.
     */
    toJSON() {
        return this.boxes.map((box) => serialiseBox(box, this.fieldSettings));
    }

    /**
     * Change some markers' properties in place, as one change: `update` gets
     * each marker as toJSON() has it and returns the properties to set. Their
     * elements stay, so a field being typed into keeps its focus.
     */
    updateBoxes(indexes, update) {
        let changed = false;
        indexes.forEach((index) => {
            const box = this.boxes[index];
            if (!box) return;
            Object.assign(box, update(serialiseBox(box, this.fieldSettings), index));
            changed = true;
        });
        if (changed) this._emit();
    }

    selectedIndexes() {
        return this.boxes
            .map((box, index) => this.selected.has(box) ? index : null)
            .filter((index) => index !== null);
    }

    selectBox(index, { additive = false, toggle = false, source = 'api' } = {}) {
        const box = this.boxes[index];
        if (!box) return;

        if (toggle && this.selected.has(box)) {
            this.selected.delete(box);
        } else {
            if (!additive) this.selected.clear();
            this.selected.add(box);
        }

        this._emitSelection({ source, activeIndex: index });
    }

    /** Select several boxes in one render/update, used by area-selection tools. */
    selectIndexes(indexes, { additive = false, source = 'api' } = {}) {
        const next = additive ? new Set(this.selected) : new Set();
        let activeIndex = null;

        indexes.forEach((index) => {
            const box = this.boxes[index];
            if (!box) return;
            next.add(box);
            activeIndex = index;
        });

        this.selected = next;
        this._emitSelection({ source, activeIndex });
    }

    clearSelection() {
        if (this.selected.size === 0) return;
        this.selected.clear();
        this._emitSelection();
    }

    selectAll() {
        if (this.boxes.length === 0) return;
        this.selected = new Set(this.boxes);
        this._emitSelection();
    }

    removeSelected() {
        if (this.selected.size === 0) return;

        this.boxes = this.boxes.filter((box) => {
            if (!this.selected.has(box)) return true;
            box.el?.remove();
            return false;
        });

        this.selected.clear();
        this.layout();
        this._emit();
        this._emitSelection();
    }

    /**
     * Move the selected markers by (dx, dy) screen pixels, as the arrow keys
     * do, stopped at the page edges.
     */
    nudgeSelected(dx, dy) {
        const width = this.canvas.clientWidth;
        const height = this.canvas.clientHeight;
        if (this.selected.size === 0 || !width || !height) return;

        const boxes = [...this.selected];
        const fx = clamp(dx / width,
            Math.max(...boxes.map((box) => -box.x)), Math.min(...boxes.map((box) => 1 - box.x - box.w)));
        const fy = clamp(dy / height,
            Math.max(...boxes.map((box) => -box.y)), Math.min(...boxes.map((box) => 1 - box.y - box.h)));
        if (fx === 0 && fy === 0) return;

        boxes.forEach((box) => {
            box.x += fx;
            box.y += fy;
        });
        this.layout();
        this._emit();
    }

    // ----------------------------------------------------------- magnetic edges

    /**
     * The page's printed lines, as page fractions: { vertical: [x...],
     * horizontal: [y...] }. While a marker is moved or resized, an edge
     * within a few screen pixels of one snaps onto it (hold Alt to place
     * freely). null turns snapping off.
     */
    setSnapLines(lines) {
        this.snapLines = lines && (lines.vertical?.length || lines.horizontal?.length) ? lines : null;
        this._showGuides(null, null);
    }

    /** Thin lines over the page showing what an edge snapped to. */
    _showGuides(x, y) {
        const width = this.canvas.clientWidth;
        const height = this.canvas.clientHeight;
        [['v', x], ['h', y]].forEach(([axis, at]) => {
            let guide = this.guides[axis];
            if (at === null || at === undefined) {
                guide?.classList.add('d-none');
                return;
            }
            if (!guide || !guide.isConnected) {
                guide = document.createElement('div');
                guide.className = `marker-snap-guide is-${axis === 'v' ? 'vertical' : 'horizontal'}`;
                guide.setAttribute('aria-hidden', 'true');
                this.overlay.appendChild(guide);
                this.guides[axis] = guide;
            }
            guide.classList.remove('d-none');
            if (axis === 'v') guide.style.left = `${at * width}px`;
            else guide.style.top = `${at * height}px`;
        });
    }

    // ----------------------------------------------------------------- rotation

    /**
     * Turn each selected marker clockwise by the given degrees, each about
     * its own centre.
     */
    rotateSelected(degrees) {
        if (this.selected.size === 0 || !Number.isFinite(degrees) || degrees === 0) return;
        this._turnBoxes([...this.selected].map((box) => ({ box, angle: box.angle })), degrees);
        this.layout();
        this._emit();
    }

    /** Stand the selected markers upright again. */
    straightenSelected() {
        const turned = [...this.selected].filter((box) => normaliseAngle(box.angle) !== 0);
        if (turned.length === 0) return;
        turned.forEach((box) => { box.angle = 0; });
        this.layout();
        this._emit();
    }

    /** Apply one turn to a set of markers, from the angles they started at. */
    _turnBoxes(origins, degrees) {
        origins.forEach((origin) => {
            origin.box.angle = normaliseAngle((Number(origin.angle) || 0) + degrees);
        });
    }

    // --------------------------------------------------------------------- zoom

    resetZoom() {
        this.setZoom(1);
    }

    zoomBy(amount, focalEvent = null) {
        this.setZoom(this.zoom + amount, focalEvent);
    }

    setZoom(value, focalEvent = null) {
        if (!this.viewport) return;

        const next = clamp(value, this.minZoom, this.maxZoom);
        const viewportRect = this.viewport.getBoundingClientRect();
        const localX = focalEvent ? focalEvent.clientX - viewportRect.left : this.viewport.clientWidth / 2;
        const localY = focalEvent ? focalEvent.clientY - viewportRect.top : this.viewport.clientHeight / 2;
        const oldWidth = this.canvas.clientWidth || 1;
        const oldHeight = this.canvas.clientHeight || 1;
        const documentX = (this.viewport.scrollLeft + localX) / oldWidth;
        const documentY = (this.viewport.scrollTop + localY) / oldHeight;

        this.zoom = next;
        this._applyZoom();

        this.viewport.scrollLeft = documentX * this.canvas.clientWidth - localX;
        this.viewport.scrollTop = documentY * this.canvas.clientHeight - localY;
        this.onZoomChange?.(this.zoom);
    }

    _applyZoom() {
        if (!this.viewport || !this.canvas.width || !this.canvas.height) return;

        const available = Math.max(1, this.viewport.clientWidth - 2);
        const fitWidth = Math.min(this.canvas.width, available);
        const width = fitWidth * this.zoom;
        const height = width * (this.canvas.height / this.canvas.width);
        const stage = this.canvas.parentElement;

        this.canvas.style.maxWidth = 'none';
        this.canvas.style.width = `${width}px`;
        this.canvas.style.height = `${height}px`;
        stage.style.width = `${width}px`;
        stage.style.height = `${height}px`;
        this.layout();
    }

    /**
     * Position every box from its fractions. Called on load, resize, and any edit.
     */
    layout() {
        const width = this.canvas.clientWidth;
        const height = this.canvas.clientHeight;
        if (!width || !height) return;

        this.overlay.style.width = `${width}px`;
        this.overlay.style.height = `${height}px`;

        this.boxes.forEach((box, index) => {
            if (!box.el) {
                box.el = this._createElement(box, index);
                this.overlay.appendChild(box.el);
            }

            box.el.style.left = `${box.x * width}px`;
            box.el.style.top = `${box.y * height}px`;
            box.el.style.width = `${box.w * width}px`;
            box.el.style.height = `${box.h * height}px`;

            const angle = normaliseAngle(box.angle);
            if (angle === 0) {
                box.el.style.transform = '';
                box.el.style.transformOrigin = '';
            } else {
                const pivot = markerPivot(box, width, height);
                box.el.style.transformOrigin = `${pivot.x - box.x * width}px ${pivot.y - box.y * height}px`;
                box.el.style.transform = `rotate(${angle}deg)`;
            }
            box.el.classList.toggle('is-rotated', angle !== 0);
            box.el.dataset.index = String(index);
            box.el.classList.toggle('is-selected', this.selected.has(box));
        });
    }

    _createElement(box, index) {
        const el = document.createElement('div');
        el.className = box.kind === 'column' ? 'field-box is-column' : 'field-box';
        el.dataset.index = String(index);

        const label = document.createElement('span');
        label.className = 'field-box-label';
        label.textContent = box.name;
        el.appendChild(label);

        if (!this.readOnly) {
            // Every corner and every side can be dragged, so one side is fixed
            // without moving the others.
            const handles = RESIZE_HANDLES.map((direction) => {
                const handle = document.createElement('span');
                handle.className = `field-box-handle is-${direction}`;
                handle.dataset.handle = direction;
                el.appendChild(handle);
                return handle;
            });

            // Below the bottom edge, where the label above does not cover it.
            const rotator = document.createElement('span');
            rotator.className = 'field-box-rotate';
            rotator.title = 'Drag to tilt this marker. Shift snaps to 5°. Double-click to straighten.';
            rotator.setAttribute('aria-label', 'Tilt marker');
            rotator.innerHTML = ROTATE_ICON;
            el.appendChild(rotator);

            this._makeInteractive(el, handles, box, rotator);
        }

        return el;
    }

    /**
     * Drag to move, a corner or side handle to resize. Pointer events so it
     * works with touch and pen as well as mouse.
     */
    _makeInteractive(el, handles, box, rotator = null) {
        let mode = null;
        // Which handle a resize is using ('se' for the bottom-right corner).
        let direction = 'se';
        let startX = 0;
        let startY = 0;
        let origins = [];
        let pivot = null;
        let startAngle = 0;
        // A turn captures the pointer on the knob, so that a double-click on
        // the knob still reaches it (and straightens the marker).
        let captured = el;

        const begin = (event, nextMode) => {
            event.preventDefault();
            event.stopPropagation();
            // preventDefault also keeps focus where it was, often the "field
            // name" box just used to add this marker, and there the marker
            // shortcuts ([ ], Delete) are only typing. Working on a marker
            // takes focus off it.
            const focused = document.activeElement;
            if (focused instanceof HTMLElement && focused.matches('input, textarea, select')) focused.blur();

            const index = Number(el.dataset.index);

            if (nextMode === 'move') {
                if (event.shiftKey && this.selected.has(box)) {
                    this.selectBox(index, { additive: true, toggle: true, source: 'marker' });
                    return;
                }

                if (event.shiftKey) {
                    this.selectBox(index, { additive: true, source: 'marker' });
                } else if (!this.selected.has(box)) {
                    this.selectBox(index, { source: 'marker' });
                } else {
                    this._emitSelection({ source: 'marker', activeIndex: index });
                }
            } else if (!this.selected.has(box)) {
                this.selectBox(index, { source: 'marker' });
            } else {
                this._emitSelection({ source: 'marker', activeIndex: index });
            }

            mode = nextMode;
            startX = event.clientX;
            startY = event.clientY;
            // Moving and resizing operate on the full selection. Each marker
            // keeps its own origin and size, while receiving the same delta.
            origins = [...this.selected]
                .map((selected) => ({ box: selected, ...selected }));
            if (nextMode === 'rotate') {
                const bounds = this.overlay.getBoundingClientRect();
                const point = markerPivot(box, this.canvas.clientWidth, this.canvas.clientHeight);
                pivot = { x: bounds.left + point.x, y: bounds.top + point.y };
                startAngle = Math.atan2(event.clientY - pivot.y, event.clientX - pivot.x);
            }
            captured = nextMode === 'rotate' && rotator ? rotator : el;
            captured.setPointerCapture(event.pointerId);
            el.classList.add('is-active');
        };

        const move = (event) => {
            if (!mode) return;

            const width = this.canvas.clientWidth;
            const height = this.canvas.clientHeight;
            const dx = (event.clientX - startX) / width;
            const dy = (event.clientY - startY) / height;

            if (mode === 'rotate') {
                const current = Math.atan2(event.clientY - pivot.y, event.clientX - pivot.x);
                let degrees = (current - startAngle) * 180 / Math.PI;
                if (event.shiftKey) {
                    // Snap the grabbed marker itself; the rest keep their offsets.
                    const grabbed = origins.find((origin) => origin.box === box);
                    const from = Number(grabbed?.angle) || 0;
                    degrees = Math.round((from + degrees) / ROTATE_SNAP_DEGREES) * ROTATE_SNAP_DEGREES - from;
                }
                this._turnBoxes(origins, degrees);
            } else if (origins.some((origin) => normaliseAngle(origin.angle) !== 0)) {
                this._dragTurned(origins, mode === 'resize' ? direction : mode,
                    event.clientX - startX, event.clientY - startY, width, height);
            } else if (mode === 'move') {
                const minDx = Math.max(...origins.map((origin) => -origin.x));
                const maxDx = Math.min(...origins.map((origin) => 1 - origin.x - origin.w));
                const minDy = Math.max(...origins.map((origin) => -origin.y));
                const maxDy = Math.min(...origins.map((origin) => 1 - origin.y - origin.h));
                let boundedX = clamp(dx, minDx, maxDx);
                let boundedY = clamp(dy, minDy, maxDy);

                const grabbed = origins.find((origin) => origin.box === box);
                if (this.snapLines && grabbed && !event.altKey) {
                    const snapX = magnetShift(
                        [grabbed.x + boundedX, grabbed.x + grabbed.w + boundedX], this.snapLines.vertical, 8 / width,
                    );
                    const snapY = magnetShift(
                        [grabbed.y + boundedY, grabbed.y + grabbed.h + boundedY], this.snapLines.horizontal, 8 / height,
                    );
                    boundedX = clamp(boundedX + snapX.shift, minDx, maxDx);
                    boundedY = clamp(boundedY + snapY.shift, minDy, maxDy);
                    this._showGuides(snapX.line, snapY.line);
                } else {
                    this._showGuides(null, null);
                }

                origins.forEach((origin) => {
                    origin.box.x = origin.x + boundedX;
                    origin.box.y = origin.y + boundedY;
                });
            } else {
                const west = direction.includes('w');
                const east = direction.includes('e');
                const north = direction.includes('n');
                const south = direction.includes('s');
                let sx = east || west ? dx : 0;
                let sy = north || south ? dy : 0;

                // The dragged side of the grabbed marker snaps to a printed line.
                const grabbed = origins.find((origin) => origin.box === box);
                if (this.snapLines && grabbed && !event.altKey) {
                    const snapX = east || west
                        ? magnetShift([west ? grabbed.x + sx : grabbed.x + grabbed.w + sx], this.snapLines.vertical, 8 / width)
                        : { shift: 0, line: null };
                    const snapY = north || south
                        ? magnetShift([north ? grabbed.y + sy : grabbed.y + grabbed.h + sy], this.snapLines.horizontal, 8 / height)
                        : { shift: 0, line: null };
                    sx += snapX.shift;
                    sy += snapY.shift;
                    this._showGuides(snapX.line, snapY.line);
                } else {
                    this._showGuides(null, null);
                }

                origins.forEach((origin) => {
                    const rect = resizeRect(origin.w, origin.h, direction, sx, sy, MIN_FRACTION, MIN_FRACTION);
                    // The page's edges stop a side; the opposite side stays where it was.
                    const left = Math.max(0, origin.x + rect.left);
                    const top = Math.max(0, origin.y + rect.top);
                    const right = Math.min(1, origin.x + rect.right);
                    const bottom = Math.min(1, origin.y + rect.bottom);
                    origin.box.x = left;
                    origin.box.y = top;
                    origin.box.w = Math.max(MIN_FRACTION, right - left);
                    origin.box.h = Math.max(MIN_FRACTION, bottom - top);
                });
            }

            this.layout();
            this.onDrag?.(this.toJSON());
        };

        const end = (event) => {
            if (!mode) return;
            mode = null;
            if (captured.hasPointerCapture(event.pointerId)) captured.releasePointerCapture(event.pointerId);
            el.classList.remove('is-active');
            this._showGuides(null, null);
            this._emit();
        };

        el.addEventListener('pointerdown', (e) => {
            if (e.ctrlKey) return;
            if (handles.includes(e.target) || e.target === rotator) return;
            begin(e, 'move');
        });
        handles.forEach((handle) => {
            handle.addEventListener('pointerdown', (e) => {
                if (e.ctrlKey) return;
                direction = handle.dataset.handle || 'se';
                begin(e, 'resize');
            });
        });
        rotator?.addEventListener('pointerdown', (e) => {
            if (e.ctrlKey) return;
            begin(e, 'rotate');
        });
        rotator?.addEventListener('dblclick', (e) => {
            e.preventDefault();
            e.stopPropagation();
            this.straightenSelected();
        });
        el.addEventListener('pointermove', move);
        el.addEventListener('pointerup', end);
        el.addEventListener('pointercancel', end);
    }

    /**
     * Move or resize markers when any of them is turned. The pointer moves in
     * screen space; each marker's box is kept in its own upright frame.
     *
     * A marker turns about its centre, so a move is the same on screen and in
     * its box, and a resize grows it along its own sides while the sides the
     * handle does not touch stay put. `mode` is 'move' or a handle ('se', 'n',
     * ...); 'resize' means the bottom-right corner.
     */
    _dragTurned(origins, mode, pixelDx, pixelDy, width, height) {
        const handle = mode === 'resize' ? 'se' : mode;
        origins.forEach((origin) => {
            const angle = normaliseAngle(origin.angle);
            const target = origin.box;

            if (mode === 'move') {
                target.x = clamp(origin.x + pixelDx / width, 0, 1 - origin.w);
                target.y = clamp(origin.y + pixelDy / height, 0, 1 - origin.h);
                return;
            }

            // The drag in the marker's own upright frame, in pixels.
            const local = turnPoint(pixelDx, pixelDy, -angle);
            const ow = origin.w * width;
            const oh = origin.h * height;
            const rect = resizeRect(ow, oh, handle, local.x, local.y, MIN_FRACTION * width, MIN_FRACTION * height);
            const w = clamp((rect.right - rect.left) / width, MIN_FRACTION, 1);
            const h = clamp((rect.bottom - rect.top) / height, MIN_FRACTION, 1);
            // The untouched sides stay put: the centre moves by how far the new
            // box's centre is from the old one, turned into the page's frame.
            const shift = turnPoint((rect.left + rect.right - ow) / 2, (rect.top + rect.bottom - oh) / 2, angle);
            const cx = (origin.x + origin.w / 2) * width + shift.x;
            const cy = (origin.y + origin.h / 2) * height + shift.y;
            target.w = w;
            target.h = h;
            target.x = clamp(cx / width - w / 2, 0, 1 - w);
            target.y = clamp(cy / height - h / 2, 0, 1 - h);
        });
    }

    // ------------------------------------------------------------------ cropping

    /**
     * Crop every box to a PNG data URL.
     *
     * Crops come from the full-resolution canvas, not the on-screen size, so the
     * model sees the sharpest available pixels.
     *
     * @returns {Array<{name: string, image: string, x: number, y: number, w: number, h: number, personGroup?: number, personFieldOrder?: number}>}
     */
    crop() {
        return this.boxes.map((box) => ({
            ...serialiseBox(box),
            image: this._cropBox(box),
        }));
    }

    _cropBox(box) {
        const sx = box.x * this.canvas.width;
        const sy = box.y * this.canvas.height;
        const sw = Math.max(1, box.w * this.canvas.width);
        const sh = Math.max(1, box.h * this.canvas.height);

        const out = document.createElement('canvas');
        out.width = Math.round(sw);
        out.height = Math.round(sh);

        const ctx = out.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, out.width, out.height);

        const angle = normaliseAngle(box.angle);
        if (angle === 0) {
            ctx.drawImage(this.canvas, sx, sy, sw, sh, 0, 0, out.width, out.height);
        } else {
            // Turn the page back about the marker's pivot so the marker stands
            // upright, then take its box: the crop reads level.
            const pivot = markerPivot(box, this.canvas.width, this.canvas.height);
            ctx.translate(pivot.x - sx, pivot.y - sy);
            ctx.rotate(-angle * Math.PI / 180);
            ctx.translate(-pivot.x, -pivot.y);
            ctx.drawImage(this.canvas, 0, 0);
        }

        return out.toDataURL('image/png');
    }

    _emit() {
        this.onChange?.(this.toJSON());
    }

    _emitSelection({ source = 'api', activeIndex = null } = {}) {
        this.boxes.forEach((box) => box.el?.classList.toggle('is-selected', this.selected.has(box)));
        this.onSelectionChange?.(this.selectedIndexes(), { source, activeIndex });
    }
}

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), Math.max(min, max));
}

export {
    canVerifyValue,
    HANDLE_SIZE,
    markerPersonMetadata,
    ROTATE_SNAP_DEGREES,
    verificationGroupState,
};
