/**
 * Ink-aware crop fitting.
 *
 * A template box says which field is being read and roughly where it is. On old
 * handwritten registers it makes a poor crop: writing drifts past the box edge
 * (characters are clipped) and neighbouring entries intrude (the model reads
 * someone else's ink). This module finds the ink, groups it into word blobs,
 * decides which field each blob belongs to, and reports the rectangle holding a
 * field's own writing together with the neighbour ink to paint out.
 *
 * Everything here is DOM-free and works on plain typed arrays, so it can be unit
 * tested in Node. FieldMarker supplies the pixels and applies the result.
 * Rectangles are fractions of the page (0-1), like the rest of the marker.
 *
 * Pipeline:
 *   1. stretchContrast + binarise   adaptive threshold, robust to stains and fade
 *   2. detectRuledLines             long thin runs are ruling, not handwriting
 *   3. findBlobs                    smear + label: one blob is roughly one word
 *   4. fitFields                    give each blob to one field, or to none
 *
 * Ownership is deliberately conservative. A blob that sits substantially inside
 * two fields (touching handwriting across a cell border) is "contested" and both
 * fields keep their template box. The marker never guesses where one entry stops
 * and the next begins.
 */

export const OWNER_NONE = -1;
export const OWNER_CONTESTED = -2;

/**
 * Tunables. Lengths are multiples of the "text height", the typical height of the
 * writing on the page (measured from the ink, not from the boxes), so the same
 * settings hold at any scan resolution.
 */
export const FIT_DEFAULTS = Object.freeze({
    sauvolaK: 0.2,
    windowDivisor: 36,
    lineMinHorizontal: 3.5,
    lineMinVertical: 1.25,
    lineMinDensity: 0.8,
    lineMaxGap: 2,
    lineSkewTolerance: 3,
    // Wide enough to join the words of one entry ("December 12," + "1958"), so a
    // whole phrase is judged together. Narrower and a stray word gets handed to
    // whichever box it happens to sit in, silently.
    smearX: 1.0,
    smearY: 0.1,
    minBlobArea: 0.004,
    maxBlobPageFraction: 0.25,
    // Share of a blob's ink inside a box for it to count as that box's writing,
    // and the share in a second box at which it is contested. Tuned against
    // synthetic registers: lower is safer, and flat between 0.10 and 0.20.
    spillShare: 0.2,
    contestShare: 0.15,
    padX: 0.3,
    padY: 0.2,
    maxGrowthHeight: 2.5,
    maxGrowthWidth: 3,
    maxGrowthArea: 3,
});

const clamp = (value, min, max) => Math.min(Math.max(value, min), Math.max(min, max));

function median(values) {
    if (values.length === 0) return 0;

    const sorted = [...values].sort((a, b) => a - b);
    const middle = Math.floor(sorted.length / 2);

    return sorted.length % 2 === 0
        ? (sorted[middle - 1] + sorted[middle]) / 2
        : sorted[middle];
}

// -------------------------------------------------------------- binarisation

/**
 * Integer luma from canvas RGBA. Alpha is ignored: the marker paints a white
 * backing under every page, so transparency never reaches here.
 */
export function toGrayscale(rgba, width, height) {
    const gray = new Uint8Array(width * height);

    for (let i = 0, p = 0; i < gray.length; i++, p += 4) {
        gray[i] = (rgba[p] * 77 + rgba[p + 1] * 150 + rgba[p + 2] * 29) >> 8;
    }

    return gray;
}

/**
 * Stretch the 2nd-98th percentile range to the full scale so faded ink and
 * yellowed paper behave like a clean scan. A page with almost no tonal range
 * (blank, or already normalised) is returned untouched.
 */
export function stretchContrast(gray, lowPercentile = 0.02, highPercentile = 0.98) {
    const histogram = new Uint32Array(256);
    for (let i = 0; i < gray.length; i++) histogram[gray[i]]++;

    const percentile = (fraction) => {
        const target = fraction * gray.length;
        let seen = 0;

        for (let level = 0; level < 256; level++) {
            seen += histogram[level];
            if (seen >= target) return level;
        }

        return 255;
    };

    const low = percentile(lowPercentile);
    const high = percentile(highPercentile);
    if (high - low < 24) return gray;

    const table = new Uint8Array(256);
    for (let level = 0; level < 256; level++) {
        table[level] = clamp(Math.round(((level - low) / (high - low)) * 255), 0, 255);
    }

    const out = new Uint8Array(gray.length);
    for (let i = 0; i < gray.length; i++) out[i] = table[gray[i]];

    return out;
}

/**
 * Sauvola adaptive threshold: a pixel is ink when it is darker than a local
 * mean-and-deviation threshold. Unlike a single global cut-off it survives
 * stains, foxing, and uneven lighting across a bound register.
 *
 * @returns {Uint8Array} 1 where there is ink, 0 elsewhere.
 */
export function binarise(gray, width, height, { windowSize = null, k = FIT_DEFAULTS.sauvolaK } = {}) {
    const size = windowSize
        ?? clamp(Math.round(Math.min(width, height) / FIT_DEFAULTS.windowDivisor), 15, 41);
    const radius = size >> 1;
    const stride = width + 1;
    const sum = new Uint32Array(stride * (height + 1));
    const squares = new Float64Array(stride * (height + 1));

    for (let y = 0; y < height; y++) {
        let rowSum = 0;
        let rowSquares = 0;

        for (let x = 0; x < width; x++) {
            const value = gray[y * width + x];
            rowSum += value;
            rowSquares += value * value;

            const cell = (y + 1) * stride + (x + 1);
            sum[cell] = sum[cell - stride] + rowSum;
            squares[cell] = squares[cell - stride] + rowSquares;
        }
    }

    const ink = new Uint8Array(width * height);

    for (let y = 0; y < height; y++) {
        const y0 = Math.max(0, y - radius);
        const y1 = Math.min(height, y + radius + 1);

        for (let x = 0; x < width; x++) {
            const x0 = Math.max(0, x - radius);
            const x1 = Math.min(width, x + radius + 1);
            const count = (x1 - x0) * (y1 - y0);

            const total = sum[y1 * stride + x1] - sum[y0 * stride + x1]
                - sum[y1 * stride + x0] + sum[y0 * stride + x0];
            const totalSquares = squares[y1 * stride + x1] - squares[y0 * stride + x1]
                - squares[y1 * stride + x0] + squares[y0 * stride + x0];

            const mean = total / count;
            const variance = totalSquares / count - mean * mean;
            const deviation = variance > 0 ? Math.sqrt(variance) : 0;
            const threshold = mean * (1 + k * (deviation / 128 - 1));

            if (gray[y * width + x] < threshold) ink[y * width + x] = 1;
        }
    }

    return ink;
}

/**
 * The page-level work that does not depend on where the boxes are, so it can be
 * done once per document and reused for every fit.
 */
export function preparePage(gray, width, height, options = {}) {
    return {
        width,
        height,
        ink: binarise(stretchContrast(gray), width, height, options),
    };
}

// ------------------------------------------------------------- morphology

/**
 * Grow a mask by `rx` pixels horizontally and `ry` vertically. Each pass is a
 * single sweep that tracks the nearest set pixel, so cost stays linear.
 */
export function dilate(mask, width, height, rx, ry) {
    const horizontal = new Uint8Array(mask.length);

    if (rx > 0) {
        for (let y = 0; y < height; y++) {
            const row = y * width;
            let last = -Infinity;

            for (let x = 0; x < width; x++) {
                if (mask[row + x]) last = x;
                if (x - last <= rx) horizontal[row + x] = 1;
            }

            let next = Infinity;
            for (let x = width - 1; x >= 0; x--) {
                if (mask[row + x]) next = x;
                if (next - x <= rx) horizontal[row + x] = 1;
            }
        }
    } else {
        horizontal.set(mask);
    }

    if (ry <= 0) return horizontal;

    const out = new Uint8Array(mask.length);
    const last = new Float64Array(width).fill(-Infinity);

    for (let y = 0; y < height; y++) {
        const row = y * width;

        for (let x = 0; x < width; x++) {
            if (horizontal[row + x]) last[x] = y;
            if (y - last[x] <= ry) out[row + x] = 1;
        }
    }

    const next = new Float64Array(width).fill(Infinity);

    for (let y = height - 1; y >= 0; y--) {
        const row = y * width;

        for (let x = 0; x < width; x++) {
            if (horizontal[row + x]) next[x] = y;
            if (next[x] - y <= ry) out[row + x] = 1;
        }
    }

    return out;
}

/**
 * Mark long, dense, straight runs of ink along one axis. `step` is the distance
 * between consecutive pixels of a run and `lineStep` the distance between runs,
 * so one routine serves both rows and columns. Tiny gaps are bridged so a ruled
 * line with a faded patch is still recognised as one line.
 */
function markRuns(ink, out, lineCount, length, lineStep, step, minLength, maxGap, minDensity) {
    for (let line = 0; line < lineCount; line++) {
        const base = line * lineStep;
        let start = -1;
        let last = -1;
        let inked = 0;

        for (let t = 0; t <= length; t++) {
            const on = t < length && ink[base + t * step] === 1;

            if (on) {
                if (start < 0) {
                    start = t;
                    inked = 0;
                }
                last = t;
                inked++;
                continue;
            }

            if (start < 0 || (t < length && t - last <= maxGap)) continue;

            const extent = last - start + 1;
            if (extent >= minLength && inked / extent >= minDensity) {
                for (let u = start; u <= last; u++) {
                    if (ink[base + u * step] === 1) out[base + u * step] = 1;
                }
            }
            start = -1;
        }
    }
}

/**
 * Pixels that belong to ruling (table borders, underlines) rather than writing.
 * Handwriting does not stay straight and dense for as long as a rule does, so a
 * length threshold separates them.
 *
 * A scanned rule is never perfectly level: a 0.3 degree skew moves it a whole
 * pixel every ~190px, which chops it into short runs. Runs are therefore measured
 * on a copy grown by `skewTolerance` across the rule, and the returned mask is
 * that band, so it also swallows the ragged fringe a thresholded rule leaves.
 *
 * @returns {Uint8Array} 1 on ruled-line pixels (and a thin band around them).
 */
export function detectRuledLines(ink, width, height, {
    minHorizontal,
    minVertical,
    maxGap = FIT_DEFAULTS.lineMaxGap,
    minDensity = FIT_DEFAULTS.lineMinDensity,
    skewTolerance = FIT_DEFAULTS.lineSkewTolerance,
}) {
    const lines = new Uint8Array(ink.length);

    const acrossRows = dilate(ink, width, height, 0, skewTolerance);
    markRuns(acrossRows, lines, height, width, width, 1, minHorizontal, maxGap, minDensity);

    const acrossColumns = dilate(ink, width, height, skewTolerance, 0);
    markRuns(acrossColumns, lines, width, height, 1, width, minVertical, maxGap, minDensity);

    return lines;
}

// -------------------------------------------------------------------- blobs

/**
 * Connected components of a mask, 8-connected, by iterative flood fill. The stack
 * never exceeds the pixel count because a pixel is labelled the moment it is
 * pushed.
 */
function labelComponents(mask, width, height) {
    const labels = new Int32Array(mask.length);
    const stack = new Int32Array(mask.length);
    let count = 0;

    for (let start = 0; start < mask.length; start++) {
        if (!mask[start] || labels[start]) continue;

        count++;
        labels[start] = count;
        let top = 0;
        stack[top++] = start;

        while (top > 0) {
            const pixel = stack[--top];
            const x = pixel % width;
            const y = (pixel - x) / width;

            for (let dy = -1; dy <= 1; dy++) {
                const ny = y + dy;
                if (ny < 0 || ny >= height) continue;

                for (let dx = -1; dx <= 1; dx++) {
                    const nx = x + dx;
                    if (nx < 0 || nx >= width) continue;

                    const neighbour = ny * width + nx;
                    if (mask[neighbour] && !labels[neighbour]) {
                        labels[neighbour] = count;
                        stack[top++] = neighbour;
                    }
                }
            }
        }
    }

    return { labels, count };
}

/**
 * Group ink into word-sized blobs.
 *
 * The ink is smeared before labelling so the letters of a word, an i-dot, and a
 * broken faded stroke join up, while the gap between words does not. Bounding
 * boxes and areas are then measured on the *unsmeared* ink, so they stay tight.
 * Labels cover the smeared regions, which never overlap between blobs.
 */
export function findBlobs(ink, width, height, { smearX, smearY, minArea, maxPageFraction }) {
    const { labels, count } = labelComponents(dilate(ink, width, height, smearX, smearY), width, height);

    const area = new Int32Array(count + 1);
    const x0 = new Int32Array(count + 1).fill(width);
    const y0 = new Int32Array(count + 1).fill(height);
    const x1 = new Int32Array(count + 1);
    const y1 = new Int32Array(count + 1);

    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            const i = y * width + x;
            if (!ink[i]) continue;

            const label = labels[i];
            area[label]++;
            if (x < x0[label]) x0[label] = x;
            if (y < y0[label]) y0[label] = y;
            if (x + 1 > x1[label]) x1[label] = x + 1;
            if (y + 1 > y1[label]) y1[label] = y + 1;
        }
    }

    // Specks are scanner dust; page-sized blobs are borders, gutter shadow, or
    // ruling that survived. Neither is handwriting to be owned or masked.
    const valid = new Uint8Array(count + 1);
    const pageArea = width * height;

    for (let label = 1; label <= count; label++) {
        const boxArea = (x1[label] - x0[label]) * (y1[label] - y0[label]);
        valid[label] = area[label] >= minArea && boxArea < maxPageFraction * pageArea ? 1 : 0;
    }

    return { labels, count, area, x0, y0, x1, y1, valid };
}

/**
 * How tall the writing on this page is, judged from the ink itself.
 *
 * The template boxes are no guide: on a register a box is a whole ruled cell, three
 * or four times taller than the handwriting inside it. The area-weighted median
 * height of the connected pieces of ink is steady against specks (little area) and
 * against a few tall flourishes, and needs no assumption about layout.
 *
 * @returns {number|null} Height in pixels, or null when there is too little ink to judge.
 */
export function estimateTextHeight(writing, width, height) {
    const { labels, count } = labelComponents(writing, width, height);
    const area = new Int32Array(count + 1);
    const top = new Int32Array(count + 1).fill(height);
    const bottom = new Int32Array(count + 1);

    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            const i = y * width + x;
            if (!writing[i]) continue;

            const label = labels[i];
            area[label]++;
            if (y < top[label]) top[label] = y;
            if (y + 1 > bottom[label]) bottom[label] = y + 1;
        }
    }

    const samples = [];
    for (let label = 1; label <= count; label++) {
        const tall = bottom[label] - top[label];
        if (area[label] >= 8 && tall >= 4 && tall <= height * 0.2) samples.push([tall, area[label]]);
    }
    if (samples.length < 5) return null;

    samples.sort((a, b) => a[0] - b[0]);
    const totalArea = samples.reduce((sum, [, weight]) => sum + weight, 0);
    let seen = 0;

    for (const [tall, weight] of samples) {
        seen += weight;
        if (seen >= totalArea / 2) return tall;
    }

    return samples[samples.length - 1][0];
}

// ---------------------------------------------------------------- fitting

function pixelRect(anchor, width, height) {
    const x0 = clamp(Math.floor(anchor.x * width), 0, width - 1);
    const y0 = clamp(Math.floor(anchor.y * height), 0, height - 1);

    return {
        x0,
        y0,
        x1: clamp(Math.ceil((anchor.x + anchor.w) * width), x0 + 1, width),
        y1: clamp(Math.ceil((anchor.y + anchor.h) * height), y0 + 1, height),
    };
}

const emptyField = (status) => ({ status, rect: null, maskLabels: [] });

/**
 * Decide, for every template box, which ink is its own.
 *
 * Each blob is scored by the share of its ink that lies inside each box. A blob
 * with a real share in exactly one box belongs to that box, even when most of it
 * hangs outside (a word running past the cell edge). A blob with a real share in
 * two boxes is contested. Fields that own something are cropped to that ink plus
 * padding, and the ink owned by *other* fields that falls inside the crop is
 * listed so it can be painted out.
 *
 * @param {{width: number, height: number, ink: Uint8Array}} page  From preparePage().
 * @param {Array<{x: number, y: number, w: number, h: number}>} anchors  Template boxes, as fractions.
 * @returns {{
 *   width: number, height: number, textHeight: number,
 *   labels: Int32Array, labelCount: number, owners: Int32Array,
 *   fields: Array<{status: 'fitted'|'ambiguous'|'empty', rect: {x: number, y: number, w: number, h: number}|null, maskLabels: number[]}>
 * }}
 */
export function fitFields(page, anchors, options = {}) {
    const config = { ...FIT_DEFAULTS, ...options };
    const { width, height } = page;

    if (anchors.length === 0) {
        return {
            width,
            height,
            textHeight: 0,
            labels: new Int32Array(0),
            labelCount: 0,
            owners: new Int32Array(1).fill(OWNER_NONE),
            fields: [],
        };
    }

    const rects = anchors.map((anchor) => pixelRect(anchor, width, height));

    // The boxes over-state the writing height (a register box is a whole cell), so
    // they only set a deliberately strict line length. Real ruling is page-long and
    // clears it easily; the writing height is measured once the ruling is gone.
    const boxHeight = clamp(median(anchors.map((anchor) => anchor.h * height)), 6, height * 0.2);
    const ruling = detectRuledLines(page.ink, width, height, {
        minHorizontal: Math.max(24, Math.round(config.lineMinHorizontal * boxHeight)),
        minVertical: Math.max(16, Math.round(config.lineMinVertical * boxHeight)),
        maxGap: config.lineMaxGap,
        minDensity: config.lineMinDensity,
        skewTolerance: config.lineSkewTolerance,
    });
    const writing = new Uint8Array(page.ink.length);
    for (let i = 0; i < writing.length; i++) writing[i] = page.ink[i] && !ruling[i] ? 1 : 0;

    const measured = estimateTextHeight(writing, width, height);
    const textHeight = measured === null
        ? boxHeight
        : clamp(measured, Math.max(6, boxHeight * 0.12), boxHeight);

    const blobs = findBlobs(writing, width, height, {
        smearX: Math.max(1, Math.round((config.smearX * textHeight) / 2)),
        smearY: Math.max(1, Math.round((config.smearY * textHeight) / 2)),
        minArea: Math.max(4, Math.round(config.minBlobArea * textHeight * textHeight)),
        maxPageFraction: config.maxBlobPageFraction,
    });
    const { labels, count, valid } = blobs;

    // Score every (box, blob) pair by the share of the blob's ink inside the box.
    // Boxes barely overlap, so the sweep over all boxes is close to one page pass.
    const bestShare = new Float32Array(count + 1);
    const secondShare = new Float32Array(count + 1);
    const bestAnchor = new Int32Array(count + 1).fill(-1);
    const strongLabels = anchors.map(() => []);
    const tally = new Int32Array(count + 1);

    rects.forEach((rect, index) => {
        const touched = [];

        for (let y = rect.y0; y < rect.y1; y++) {
            for (let x = rect.x0; x < rect.x1; x++) {
                const i = y * width + x;
                if (!writing[i]) continue;

                const label = labels[i];
                if (tally[label]++ === 0) touched.push(label);
            }
        }

        for (const label of touched) {
            const share = tally[label] / blobs.area[label];
            tally[label] = 0;
            if (!valid[label]) continue;

            if (share > bestShare[label]) {
                secondShare[label] = bestShare[label];
                bestShare[label] = share;
                bestAnchor[label] = index;
            } else if (share > secondShare[label]) {
                secondShare[label] = share;
            }

            if (share >= config.contestShare) strongLabels[index].push(label);
        }
    });

    const owners = new Int32Array(count + 1).fill(OWNER_NONE);
    const ownedLabels = [];
    const union = anchors.map(() => ({
        x0: Infinity, y0: Infinity, x1: -Infinity, y1: -Infinity, owned: 0,
    }));

    for (let label = 1; label <= count; label++) {
        if (!valid[label] || bestAnchor[label] < 0 || bestShare[label] < config.spillShare) continue;

        if (secondShare[label] >= config.contestShare) {
            owners[label] = OWNER_CONTESTED;
            continue;
        }

        const owner = bestAnchor[label];
        owners[label] = owner;
        ownedLabels.push(label);

        const box = union[owner];
        box.x0 = Math.min(box.x0, blobs.x0[label]);
        box.y0 = Math.min(box.y0, blobs.y0[label]);
        box.x1 = Math.max(box.x1, blobs.x1[label]);
        box.y1 = Math.max(box.y1, blobs.y1[label]);
        box.owned++;
    }

    const fields = rects.map((rect, index) => {
        if (strongLabels[index].some((label) => owners[label] === OWNER_CONTESTED)) {
            return emptyField('ambiguous');
        }

        const ink = union[index];
        if (ink.owned === 0) return emptyField('empty');

        const padX = Math.max(2, Math.round(config.padX * textHeight));
        const padY = Math.max(2, Math.round(config.padY * textHeight));
        const fitted = {
            x0: Math.max(0, ink.x0 - padX),
            y0: Math.max(0, ink.y0 - padY),
            x1: Math.min(width, ink.x1 + padX),
            y1: Math.min(height, ink.y1 + padY),
        };

        // A fit that balloons far past its box has swallowed something that is not
        // this field's writing. Hand it back to the person instead of trusting it.
        const anchorW = rect.x1 - rect.x0;
        const anchorH = rect.y1 - rect.y0;
        const fittedW = fitted.x1 - fitted.x0;
        const fittedH = fitted.y1 - fitted.y0;
        if (fittedH > config.maxGrowthHeight * anchorH
            || fittedW > config.maxGrowthWidth * anchorW
            || fittedW * fittedH > config.maxGrowthArea * anchorW * anchorH) {
            return emptyField('ambiguous');
        }

        const maskLabels = ownedLabels.filter((label) => (
            owners[label] !== index
            && blobs.x0[label] < fitted.x1 && blobs.x1[label] > fitted.x0
            && blobs.y0[label] < fitted.y1 && blobs.y1[label] > fitted.y0
        ));

        return {
            status: 'fitted',
            rect: {
                x: fitted.x0 / width,
                y: fitted.y0 / height,
                w: fittedW / width,
                h: fittedH / height,
            },
            maskLabels,
        };
    });

    return { width, height, textHeight, labels, labelCount: count, owners, fields };
}

// ----------------------------------------------------------------- masking

/**
 * Paint the given blobs white inside a cropped RGBA image.
 *
 * The blob labels live at analysis resolution while the crop comes from the
 * full-resolution page, so each crop pixel is mapped back to the label grid.
 * Labels cover the smeared region around a blob, and two blobs' regions never
 * overlap, so painting a neighbour's label cannot touch this field's own ink.
 *
 * @param {Uint8ClampedArray} rgba  Crop pixels, modified in place.
 * @param {number} width            Crop width in pixels.
 * @param {number} height           Crop height in pixels.
 * @param {{x: number, y: number, w: number, h: number}} source  The crop's rectangle on the full page, in page pixels.
 * @param {{width: number, height: number}} page  Full-resolution page size.
 * @param {{width: number, height: number, labels: Int32Array, labelCount: number}} map  Label grid.
 * @param {number[]} maskLabels     Blob labels to erase.
 * @returns {number} Pixels painted.
 */
export function whitenNeighbourInk(rgba, width, height, source, page, map, maskLabels) {
    if (maskLabels.length === 0) return 0;

    const erase = new Uint8Array(map.labelCount + 1);
    maskLabels.forEach((label) => { erase[label] = 1; });

    const columns = new Int32Array(width);
    for (let i = 0; i < width; i++) {
        const pageX = source.x + ((i + 0.5) * source.w) / width;
        columns[i] = clamp(Math.floor((pageX * map.width) / page.width), 0, map.width - 1);
    }

    let painted = 0;

    for (let j = 0; j < height; j++) {
        const pageY = source.y + ((j + 0.5) * source.h) / height;
        const row = clamp(Math.floor((pageY * map.height) / page.height), 0, map.height - 1) * map.width;

        for (let i = 0; i < width; i++) {
            if (!erase[map.labels[row + columns[i]]]) continue;

            const p = (j * width + i) * 4;
            rgba[p] = 255;
            rgba[p + 1] = 255;
            rgba[p + 2] = 255;
            painted++;
        }
    }

    return painted;
}
