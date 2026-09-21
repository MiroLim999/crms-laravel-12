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
import { fitFields, preparePage, toGrayscale } from './ink-fit.js';
import { markerPersonMetadata } from './person-grouping.js';
import {
    canVerifyValue,
    verificationGroupState,
} from './verification-groups.js';

// Use the CDN-hosted worker instead of bundling the 2.2 MB parser file.
// The version must stay in sync with pdfjs-dist in package.json (currently 4.10.38).
// If you upgrade pdfjs-dist, update this URL too.
pdfjsLib.GlobalWorkerOptions.workerSrc =
    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.10.38/pdf.worker.min.mjs';

const HANDLE_SIZE = 10;
const MIN_FRACTION = 0.01;

// Ink analysis runs on a downscaled copy of the page. Coordinates are fractions,
// so the result applies unchanged to the full-resolution canvas that is cropped.
const ANALYSIS_MAX_SIDE = 2000;

const sameGeometry = (a, b) => a.x === b.x && a.y === b.y && a.w === b.w && a.h === b.h;
const rectOf = ({ x, y, w, h }) => ({ x, y, w, h });

function median(values) {
    if (values.length === 0) return 0;

    const sorted = [...values].sort((a, b) => a - b);
    const middle = Math.floor(sorted.length / 2);

    return sorted.length % 2 === 0
        ? (sorted[middle - 1] + sorted[middle]) / 2
        : sorted[middle];
}

const overlaps = (a, b) => a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;

/**
 * Return the portable part of a marker box.
 *
 * Person metadata is optional because Staff may add ad-hoc fields while marking
 * a document. Keeping it beside the coordinates lets a template's explicit
 * validation groups survive moves, renames, undo, and cropping.
 */
function serialiseBox(box) {
    return {
        name: box.name,
        x: box.x,
        y: box.y,
        w: box.w,
        h: box.h,
        ...markerPersonMetadata(box),
    };
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
     */
    constructor({
        canvas,
        overlay,
        viewport = null,
        readOnly = false,
        onChange = null,
        onSelectionChange = null,
        onZoomChange = null,
    }) {
        this.canvas = canvas;
        this.overlay = overlay;
        this.viewport = viewport;
        this.readOnly = readOnly;
        this.onChange = onChange;
        this.onSelectionChange = onSelectionChange;
        this.onZoomChange = onZoomChange;

        /**
         * `fit` is an optional refinement of the crop, produced by fitToInk(). It never
         * changes x/y/w/h: the box stays the person's anchor, and the fit is dropped
         * the moment that box is moved or resized.
         *
         * @type {Array<{name: string, x: number, y: number, w: number, h: number, personGroup?: number, personFieldOrder?: number, el: HTMLElement|null, fit: object|null, pinned: boolean}>}
         */
        this.boxes = [];
        this.selected = new Set();
        this.pdfDoc = null;
        this.pageMeasurement = null;
        this._inkPage = null;
        this._fitLayer = null;
        this._fitVersion = 0;
        this._renderedFitVersion = -1;
        this.zoom = 1;
        this.minZoom = 0.5;
        this.maxZoom = 3;

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
        this._forgetInk();

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

        // Forgotten again now the new page is on the canvas: a fit requested while the
        // file was still loading would otherwise have cached the previous page's ink.
        this._forgetInk();
        this.layout();

        return this.pageMeasurement;
    }

    /**
     * Render from a URL, used when revisiting an already-stored scan.
     */
    async loadFromUrl(url, isPdf = false) {
        this._forgetInk();

        if (isPdf) {
            this.pdfDoc = await pdfjsLib.getDocument({ url }).promise;
            await this.renderPdfPage(1);
        } else {
            await this._drawImage(url);
        }

        this._forgetInk();
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
        const previous = new Map(this.boxes.map((box) => [box.name, box]));

        // Remove marker elements without destroying overlay-owned tools such as
        // Template Builder's Windows-style marquee selection rectangle.
        this.overlay
            .querySelectorAll(':scope > .field-box')
            .forEach((element) => element.remove());
        // Every rectangle that is new, edited, or gone. A fit that sits near one of
        // these was decided against boxes that are no longer there.
        const changed = [];

        this.boxes = boxes.map((box) => {
            const next = { ...box, el: null, fit: null, pinned: false };

            // Pasting, undoing, and resetting rebuild every box. A fit describes the
            // ink around one exact box, so it survives only where that box is unchanged.
            const before = previous.get(box.name);
            if (before && sameGeometry(before, next)) {
                next.fit = before.fit ?? null;
                next.pinned = before.pinned ?? false;
            } else {
                changed.push(rectOf(next));
            }

            return next;
        });

        const current = new Map(this.boxes.map((box) => [box.name, box]));
        previous.forEach((before, name) => {
            const now = current.get(name);
            if (!now || !sameGeometry(before, now)) changed.push(rectOf(before));
        });

        this._refitAffected(changed);
        this.selected.clear();
        this.layout();
        this._emit();
        this._emitSelection();
    }

    addBox(name, fraction = { x: 0.3, y: 0.1, w: 0.35, h: 0.05 }) {
        const box = { name, ...fraction, el: null, fit: null, pinned: false };
        this.boxes.push(box);
        this.selected = new Set([box]);
        this._refitAffected([rectOf(box)]);
        this.layout();
        this._emit();
        this._emitSelection();
    }

    removeBox(index) {
        const [removed] = this.boxes.splice(index, 1);
        this.selected.delete(removed);
        removed?.el?.remove();
        if (removed) this._refitAffected([rectOf(removed)]);
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
        return this.boxes.map(serialiseBox);
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

        const removed = [];
        this.boxes = this.boxes.filter((box) => {
            if (!this.selected.has(box)) return true;
            box.el?.remove();
            removed.push(rectOf(box));
            return false;
        });

        this._refitAffected(removed);
        this.selected.clear();
        this.layout();
        this._emit();
        this._emitSelection();
    }

    // -------------------------------------------------------------- ink fitting

    /**
     * Refine crops to the handwriting.
     *
     * Each box is an anchor ("this field is roughly here"). Its crop becomes the
     * rectangle holding the writing it owns, and ink belonging to neighbouring
     * fields is painted out of the crop. See ink-fit.js for how ownership is decided.
     *
     * A box whose writing touches a neighbour's is left as it is and flagged
     * 'ambiguous'. Guessing where two entries meet is how a wrong name reaches the
     * registry, so that call stays with the person.
     *
     * Boxes the person adjusted by hand after a fit are skipped unless named in
     * `indexes`.
     *
     * 'ambiguous' has two causes, told apart by fitReason(): the writing touches a
     * neighbour's ('touching'), or the ink found is far bigger than the box
     * ('oversized').
     *
     * A fit stays true as the layout changes: moving, adding, or deleting a box
     * re-evaluates the fits nearby (see _refitAffected), so a crop never keeps a
     * "fitted" mark over a paint-out that no longer matches its neighbours.
     *
     * @param {{indexes?: number[]|null}} [options] Fit only these boxes; null fits every box.
     * @returns {{fitted: number, ambiguous: number, oversized: number, empty: number, skipped: number}|null}
     */
    fitToInk({ indexes = null } = {}) {
        if (this.readOnly || this.boxes.length === 0 || !this.canvas.width || !this.canvas.height) {
            return null;
        }

        const targets = indexes === null
            ? this.boxes.filter((box) => !box.pinned)
            : indexes.map((index) => this.boxes[index]).filter(Boolean);
        const skipped = indexes === null ? this.boxes.length - targets.length : 0;
        const summary = { ...this._assignFits(targets), skipped };

        this.layout();
        this._emit();

        return summary;
    }

    /**
     * Decide ownership for the whole page and record the outcome on `targets`.
     *
     * Ownership needs every box even when only some are being fitted: a neighbour's
     * writing is what tells a box where its own ends.
     *
     * @param {Array<object>} targets Boxes to (re)fit.
     */
    _assignFits(targets) {
        const summary = { fitted: 0, ambiguous: 0, oversized: 0, empty: 0 };
        if (targets.length === 0) return summary;

        this._inkPage ??= this._prepareInkPage();

        const result = fitFields(this._inkPage, this.boxes.map(rectOf));
        const indexOf = new Map(this.boxes.map((box, index) => [box, index]));

        targets.forEach((box) => {
            const field = result.fields[indexOf.get(box)];

            box.pinned = false;
            box.fit = {
                status: field.status,
                reason: field.reason,
                rect: field.rect,
                polygons: field.polygons,
            };
            summary[field.reason === 'oversized' ? 'oversized' : field.status]++;
        });

        this._fitVersion++;

        return summary;
    }

    /**
     * Re-evaluate the fits a layout change could have altered.
     *
     * A fit records which ink belonged to which neighbour, so it is only true while
     * those neighbours stay put. When a box is moved, added, or deleted, every fit
     * that sits near it, or that painted out ink it owned, is decided again against
     * the boxes as they now are. Left alone, such a box would keep its green mark
     * while its crop quietly took back the neighbour's handwriting.
     *
     * Only boxes that already have a fit are touched. Boxes the person has never
     * fitted, or adjusted by hand, stay as they are.
     *
     * @param {Array<{x: number, y: number, w: number, h: number}>} changed Old and new
     *        rectangles of every box that was moved, added, or removed.
     */
    _refitAffected(changed) {
        if (changed.length === 0 || this._inkPage === null) return;

        // Two fields compete for the same writing when they sit within about a line
        // of each other, which on a register is the usual case for neighbouring
        // cells that do not overlap at all. Widen what counts as "near" to match.
        const near = median(this.boxes.map((box) => box.h)) || 0.02;
        const reachOf = (rect) => ({
            x: rect.x - near,
            y: rect.y - near,
            w: rect.w + near * 2,
            h: rect.h + near * 2,
        });
        const widened = changed.map(reachOf);

        const affected = this.boxes.filter((box) => {
            if (!box.fit) return false;

            // A box near where something was, or now is, may have been given ink that
            // is no longer its own, or denied ink that now is.
            const reach = box.fit.rect ?? box;

            return widened.some((rect) => overlaps(rect, box) || overlaps(rect, reach));
        });

        this._assignFits(affected);
    }

    /** 'fitted', 'ambiguous', 'empty', or null when the box has not been fitted. */
    fitStatus(index) {
        return this.boxes[index]?.fit?.status ?? null;
    }

    /** Why a box is 'ambiguous': 'touching' or 'oversized'. Null otherwise. */
    fitReason(index) {
        return this.boxes[index]?.fit?.reason ?? null;
    }

    hasFits() {
        return this.boxes.some((box) => Boolean(box.fit));
    }

    clearFits() {
        if (!this.hasFits() && !this.boxes.some((box) => box.pinned)) return;

        this.boxes.forEach((box) => {
            box.fit = null;
            box.pinned = false;
        });
        this._fitVersion++;
        this.layout();
        this._emit();
    }

    /**
     * A box moved or resized after a fit is now the person's choice: drop the stale
     * fit, and remember not to override that choice on the next "fit all".
     */
    _overrideFit(box) {
        if (!box.fit) return;

        box.fit = null;
        box.pinned = true;
        this._fitVersion++;
    }

    _forgetInk() {
        this._inkPage = null;
        this.boxes.forEach((box) => {
            box.fit = null;
            box.pinned = false;
        });
        this._fitVersion++;
    }

    _prepareInkPage() {
        const { width, height } = this.canvas;
        const scale = Math.min(1, ANALYSIS_MAX_SIDE / Math.max(width, height));
        const analysisWidth = Math.max(1, Math.round(width * scale));
        const analysisHeight = Math.max(1, Math.round(height * scale));

        const scratch = document.createElement('canvas');
        scratch.width = analysisWidth;
        scratch.height = analysisHeight;

        const ctx = scratch.getContext('2d', { willReadFrequently: true });
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(this.canvas, 0, 0, analysisWidth, analysisHeight);

        const { data } = ctx.getImageData(0, 0, analysisWidth, analysisHeight);

        return preparePage(toGrayscale(data, analysisWidth, analysisHeight), analysisWidth, analysisHeight);
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
            box.el.dataset.index = String(index);
            box.el.classList.toggle('is-selected', this.selected.has(box));
            box.el.classList.toggle('is-fit-review', box.fit?.status === 'ambiguous');
        });

        this._renderFitOutlines();
    }

    /**
     * Draw each fit: the writing it found, and the rectangle it will read.
     *
     * The filled shape is the handwriting the fit gave to this field. The dashed
     * rectangle around it is the crop itself, because a crop has to be rectangular
     * and the image is sent exactly as scanned.
     *
     * One SVG for the whole overlay. Its viewBox is the unit square, so the shapes
     * are the same fractions the fits are stored in and never need rebuilding when
     * the page is zoomed or resized - only when a fit changes, which `_fitVersion`
     * tracks.
     */
    _renderFitOutlines() {
        if (this._renderedFitVersion === this._fitVersion) return;
        this._renderedFitVersion = this._fitVersion;

        const paths = [];
        this.boxes.forEach((box) => {
            const rect = box.fit?.status === 'fitted' ? box.fit.rect : null;
            if (!rect) return;

            paths.push(`<rect class="field-fit-crop" x="${rect.x.toFixed(5)}" y="${rect.y.toFixed(5)}"`
                + ` width="${rect.w.toFixed(5)}" height="${rect.h.toFixed(5)}"/>`);

            box.fit.polygons.forEach((ring) => {
                const points = ring.map(([x, y]) => `${x.toFixed(5)} ${y.toFixed(5)}`);
                paths.push(`<path class="field-fit-ink" d="M${points.join('L')}Z"/>`);
            });
        });

        if (paths.length === 0) {
            this._fitLayer?.remove();
            this._fitLayer = null;
            return;
        }

        if (!this._fitLayer) {
            this._fitLayer = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            this._fitLayer.setAttribute('class', 'field-fit-layer');
            this._fitLayer.setAttribute('viewBox', '0 0 1 1');
            // The overlay is not square, so the unit square has to stretch to it.
            this._fitLayer.setAttribute('preserveAspectRatio', 'none');
            this._fitLayer.setAttribute('aria-hidden', 'true');
            this.overlay.appendChild(this._fitLayer);
        }

        this._fitLayer.innerHTML = paths.join('');
    }

    _createElement(box, index) {
        const el = document.createElement('div');
        el.className = 'field-box';
        el.dataset.index = String(index);

        const label = document.createElement('span');
        label.className = 'field-box-label';
        label.textContent = box.name;
        el.appendChild(label);

        if (!this.readOnly) {
            const handle = document.createElement('span');
            handle.className = 'field-box-handle';
            el.appendChild(handle);

            this._makeInteractive(el, handle, box);
        }

        return el;
    }

    /**
     * Drag to move, corner handle to resize. Pointer events so it works with
     * touch and pen as well as mouse.
     */
    _makeInteractive(el, handle, box) {
        let mode = null;
        let startX = 0;
        let startY = 0;
        let origins = [];

        const begin = (event, nextMode) => {
            event.preventDefault();
            event.stopPropagation();

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
            el.setPointerCapture(event.pointerId);
            el.classList.add('is-active');
        };

        const move = (event) => {
            if (!mode) return;

            const width = this.canvas.clientWidth;
            const height = this.canvas.clientHeight;
            const dx = (event.clientX - startX) / width;
            const dy = (event.clientY - startY) / height;

            if (mode === 'move') {
                const minDx = Math.max(...origins.map((origin) => -origin.x));
                const maxDx = Math.min(...origins.map((origin) => 1 - origin.x - origin.w));
                const minDy = Math.max(...origins.map((origin) => -origin.y));
                const maxDy = Math.min(...origins.map((origin) => 1 - origin.y - origin.h));
                const boundedX = clamp(dx, minDx, maxDx);
                const boundedY = clamp(dy, minDy, maxDy);

                origins.forEach((origin) => {
                    origin.box.x = origin.x + boundedX;
                    origin.box.y = origin.y + boundedY;
                });
            } else {
                const minDw = Math.max(...origins.map((origin) => MIN_FRACTION - origin.w));
                const maxDw = Math.min(...origins.map((origin) => 1 - origin.x - origin.w));
                const minDh = Math.max(...origins.map((origin) => MIN_FRACTION - origin.h));
                const maxDh = Math.min(...origins.map((origin) => 1 - origin.y - origin.h));
                const boundedW = clamp(dx, minDw, maxDw);
                const boundedH = clamp(dy, minDh, maxDh);

                origins.forEach((origin) => {
                    origin.box.w = origin.w + boundedW;
                    origin.box.h = origin.h + boundedH;
                });
            }

            this.layout();
        };

        const end = (event) => {
            if (!mode) return;
            mode = null;
            el.releasePointerCapture(event.pointerId);
            el.classList.remove('is-active');

            // Where each moved box was and where it is now: neighbours' fits were
            // decided against the old position.
            const changed = [];
            origins.forEach((origin) => {
                if (sameGeometry(origin.box, origin)) return;

                changed.push(rectOf(origin), rectOf(origin.box));
                this._overrideFit(origin.box);
            });
            this._refitAffected(changed);
            this.layout();
            this._emit();
        };

        el.addEventListener('pointerdown', (e) => {
            if (e.ctrlKey) return;
            if (e.target === handle) return;
            begin(e, 'move');
        });
        handle.addEventListener('pointerdown', (e) => {
            if (e.ctrlKey) return;
            begin(e, 'resize');
        });
        el.addEventListener('pointermove', move);
        el.addEventListener('pointerup', end);
        el.addEventListener('pointercancel', end);
    }

    // ------------------------------------------------------------------ cropping

    /**
     * Crop every box to a PNG data URL.
     *
     * Crops come from the full-resolution canvas, not the on-screen size, so the
     * model sees the sharpest available pixels.
     *
     * x/y/w/h stay the person's box, which is what row grouping is judged on.
     * `region` is the rectangle actually read: the fitted crop, or the box itself
     * when it has not been fitted. That is what to store and highlight.
     *
     * @returns {Array<{name: string, image: string, x: number, y: number, w: number, h: number, region: {x: number, y: number, w: number, h: number}, fit: string|null, personGroup?: number, personFieldOrder?: number}>}
     */
    crop() {
        return this.boxes.map((box) => {
            const region = this._readRegion(box);

            return {
                ...serialiseBox(box),
                region,
                fit: box.fit?.status ?? null,
                image: this._cropRegion(box, region),
            };
        });
    }

    _readRegion(box) {
        const { x, y, w, h } = box.fit?.status === 'fitted' ? box.fit.rect : box;

        return { x, y, w, h };
    }

    _cropRegion(box, region) {
        // Snap outward to whole source pixels and copy 1:1. A fractional source
        // rectangle makes drawImage interpolate, which blurs thin strokes: exactly
        // the detail handwriting recognition depends on.
        const sx = clamp(Math.floor(region.x * this.canvas.width), 0, this.canvas.width - 1);
        const sy = clamp(Math.floor(region.y * this.canvas.height), 0, this.canvas.height - 1);
        const sw = clamp(Math.ceil((region.x + region.w) * this.canvas.width) - sx, 1, this.canvas.width - sx);
        const sh = clamp(Math.ceil((region.y + region.h) * this.canvas.height) - sy, 1, this.canvas.height - sy);

        const out = document.createElement('canvas');
        out.width = sw;
        out.height = sh;

        const ctx = out.getContext('2d', { willReadFrequently: true });
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, out.width, out.height);
        ctx.drawImage(this.canvas, sx, sy, sw, sh, 0, 0, sw, sh);

        // The crop is handed over exactly as scanned. Whitening the paper outside
        // the writing, so only the outlined ink remained, was measured against this
        // project's own TrOCR model and read slightly worse than this, so the
        // outline is drawn for the person and never burned into the image.
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
    verificationGroupState,
};
