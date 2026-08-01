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
import pdfWorker from 'pdfjs-dist/build/pdf.worker.mjs?url';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorker;

const HANDLE_SIZE = 10;
const MIN_FRACTION = 0.01;

export class FieldMarker {
    /**
     * @param {object} options
     * @param {HTMLCanvasElement} options.canvas   Page render target.
     * @param {HTMLElement} options.overlay        Positioned container for the boxes.
     * @param {boolean} [options.readOnly]         Render boxes without interaction.
     * @param {(boxes: Array) => void} [options.onChange]
     */
    constructor({ canvas, overlay, readOnly = false, onChange = null }) {
        this.canvas = canvas;
        this.overlay = overlay;
        this.readOnly = readOnly;
        this.onChange = onChange;

        /** @type {Array<{name: string, x: number, y: number, w: number, h: number, el: HTMLElement|null}>} */
        this.boxes = [];
        this.pdfDoc = null;

        // Boxes are positioned in display pixels, so a resize has to reposition them.
        this._onResize = () => this.layout();
        window.addEventListener('resize', this._onResize);
    }

    destroy() {
        window.removeEventListener('resize', this._onResize);
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
        }

        this.layout();
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
     * @param {Array<{name: string, x: number, y: number, w: number, h: number}>} boxes
     */
    setBoxes(boxes) {
        this.overlay.innerHTML = '';
        this.boxes = boxes.map((box) => ({ ...box, el: null }));
        this.layout();
        this._emit();
    }

    addBox(name, fraction = { x: 0.3, y: 0.1, w: 0.35, h: 0.05 }) {
        this.boxes.push({ name, ...fraction, el: null });
        this.layout();
        this._emit();
    }

    removeBox(index) {
        const [removed] = this.boxes.splice(index, 1);
        removed?.el?.remove();
        this.layout();
        this._emit();
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
        return this.boxes.map(({ name, x, y, w, h }) => ({ name, x, y, w, h }));
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
        });
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

            this._makeInteractive(el, handle);
        }

        return el;
    }

    /**
     * Drag to move, corner handle to resize. Pointer events so it works with
     * touch and pen as well as mouse.
     */
    _makeInteractive(el, handle) {
        let mode = null;
        let startX = 0;
        let startY = 0;
        let origin = null;

        const begin = (event, nextMode) => {
            event.preventDefault();
            event.stopPropagation();
            mode = nextMode;
            startX = event.clientX;
            startY = event.clientY;
            const index = Number(el.dataset.index);
            origin = { ...this.boxes[index] };
            el.setPointerCapture(event.pointerId);
            el.classList.add('is-active');
        };

        const move = (event) => {
            if (!mode) return;

            const width = this.canvas.clientWidth;
            const height = this.canvas.clientHeight;
            const dx = (event.clientX - startX) / width;
            const dy = (event.clientY - startY) / height;

            const index = Number(el.dataset.index);
            const box = this.boxes[index];

            if (mode === 'move') {
                box.x = clamp(origin.x + dx, 0, 1 - origin.w);
                box.y = clamp(origin.y + dy, 0, 1 - origin.h);
            } else {
                box.w = clamp(origin.w + dx, MIN_FRACTION, 1 - origin.x);
                box.h = clamp(origin.h + dy, MIN_FRACTION, 1 - origin.y);
            }

            this.layout();
        };

        const end = (event) => {
            if (!mode) return;
            mode = null;
            el.releasePointerCapture(event.pointerId);
            el.classList.remove('is-active');
            this._emit();
        };

        el.addEventListener('pointerdown', (e) => {
            if (e.target === handle) return;
            begin(e, 'move');
        });
        handle.addEventListener('pointerdown', (e) => begin(e, 'resize'));
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
     * @returns {Array<{name: string, image: string, x: number, y: number, w: number, h: number}>}
     */
    crop() {
        return this.boxes.map((box) => ({
            name: box.name,
            image: this._cropBox(box),
            x: box.x,
            y: box.y,
            w: box.w,
            h: box.h,
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
        ctx.drawImage(this.canvas, sx, sy, sw, sh, 0, 0, out.width, out.height);

        return out.toDataURL('image/png');
    }

    _emit() {
        this.onChange?.(this.toJSON());
    }
}

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), Math.max(min, max));
}

export { HANDLE_SIZE };
