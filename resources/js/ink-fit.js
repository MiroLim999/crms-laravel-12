/**
 * Ink-aware crop fitting.
 *
 * A template box says which field is being read and roughly where it is. On old
 * handwritten registers it makes a poor crop: writing drifts past the box edge,
 * so characters are clipped, and much of the box is blank paper. This module finds
 * the ink, groups it into word blobs, decides which field each blob belongs to, and
 * reports the rectangle around a field's own writing, plus that writing's outline
 * for the marker to draw.
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
    // How far the drawn outline reaches past the field's own ink, so the letters of
    // a word read as one shape rather than a row of separate blobs.
    inkOutlineMargin: 0.35,
    // Outline simplification, in analysis pixels. Only affects what is drawn.
    polygonTolerance: 1.2,
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
        analyses: new Map(),
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
    // Unsmeared, so each piece is a connected stroke group; findBlobs measures it.
    const pieces = findBlobs(writing, width, height, {
        smearX: 0,
        smearY: 0,
        minArea: 8,
        maxPageFraction: 1,
    });

    const samples = [];
    for (let label = 1; label <= pieces.count; label++) {
        if (!pieces.valid[label]) continue;

        const tall = pieces.y1[label] - pieces.y0[label];
        if (tall >= 4 && tall <= height * 0.2) samples.push([tall, pieces.area[label]]);
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

// ------------------------------------------------------------------ outlines

const RING_STEP = [[1, 0], [0, 1], [-1, 0], [0, -1]];
// For the edge leaving a corner in each direction, the pixel on its right and on
// its left, as offsets from that corner.
const RING_RIGHT = [[0, 0], [-1, 0], [-1, -1], [0, -1]];
const RING_LEFT = [[0, -1], [0, 0], [-1, 0], [-1, -1]];

/**
 * Trace the outlines of a binary mask.
 *
 * Walks the cracks between kept and dropped pixels, keeping the kept side on the
 * right, so every boundary edge is walked exactly once and each loop comes back
 * to where it started. Holes (the inside of an 'o') come back as their own loops,
 * wound the other way, which is what a stroked outline wants.
 *
 * @returns {Array<Array<[number, number]>>} Closed rings in mask pixel coordinates.
 */
export function traceRings(mask, width, height) {
    const at = (x, y) => (x < 0 || y < 0 || x >= width || y >= height ? 0 : mask[y * width + x]);
    const edgeValid = (cx, cy, direction) => {
        const right = RING_RIGHT[direction];
        const left = RING_LEFT[direction];

        return at(cx + right[0], cy + right[1]) === 1 && at(cx + left[0], cy + left[1]) === 0;
    };

    const stride = width + 1;
    const seen = new Uint8Array(stride * (height + 1) * 4);
    const rings = [];

    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            // The top edge of a kept pixel with nothing kept above it. Every ring,
            // outer or hole, has at least one of these.
            if (at(x, y) !== 1 || at(x, y - 1) !== 0) continue;
            if (seen[(y * stride + x) * 4]) continue;

            const ring = [];
            let cx = x;
            let cy = y;
            let direction = 0;

            while (true) {
                const key = (cy * stride + cx) * 4 + direction;
                if (seen[key]) break;

                seen[key] = 1;
                ring.push([cx, cy]);
                cx += RING_STEP[direction][0];
                cy += RING_STEP[direction][1];

                // Turning left first keeps pixels that meet only at a corner inside
                // one ring, matching the 8-connected blobs the mask is built from.
                let next = -1;
                for (const candidate of [(direction + 3) % 4, direction, (direction + 1) % 4]) {
                    if (edgeValid(cx, cy, candidate)) {
                        next = candidate;
                        break;
                    }
                }
                if (next < 0) break;
                direction = next;
            }

            if (ring.length >= 4) rings.push(ring);
        }
    }

    return rings;
}

/**
 * Drop the points of a closed ring that say nothing about its shape: first the
 * ones continuing a straight run, then Douglas-Peucker to the given tolerance.
 */
export function simplifyRing(ring, tolerance) {
    const straight = [];
    for (let i = 0; i < ring.length; i++) {
        const previous = ring[(i - 1 + ring.length) % ring.length];
        const next = ring[(i + 1) % ring.length];
        const cross = (ring[i][0] - previous[0]) * (next[1] - ring[i][1])
            - (ring[i][1] - previous[1]) * (next[0] - ring[i][0]);
        if (cross !== 0) straight.push(ring[i]);
    }
    if (straight.length <= 4 || tolerance <= 0) return straight;

    // Douglas-Peucker over the ring opened at its first point.
    const closed = [...straight, straight[0]];
    const keep = new Uint8Array(closed.length);
    keep[0] = 1;
    keep[closed.length - 1] = 1;
    const stack = [[0, closed.length - 1]];

    while (stack.length > 0) {
        const [from, to] = stack.pop();
        if (to - from < 2) continue;

        const [ax, ay] = closed[from];
        const [bx, by] = closed[to];
        const dx = bx - ax;
        const dy = by - ay;
        const span = Math.hypot(dx, dy);
        let worst = 0;
        let worstIndex = -1;

        for (let i = from + 1; i < to; i++) {
            const [px, py] = closed[i];
            const distance = span === 0
                ? Math.hypot(px - ax, py - ay)
                : Math.abs(dy * px - dx * py + bx * ay - by * ax) / span;
            if (distance > worst) {
                worst = distance;
                worstIndex = i;
            }
        }

        if (worst <= tolerance || worstIndex < 0) continue;

        keep[worstIndex] = 1;
        stack.push([from, worstIndex], [worstIndex, to]);
    }

    const simplified = [];
    for (let i = 0; i < closed.length - 1; i++) if (keep[i]) simplified.push(closed[i]);

    return simplified.length >= 3 ? simplified : straight;
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

const emptyField = (status, reason = null) => ({
    status, reason, rect: null, polygons: [],
});

/**
 * The part of fitting that depends on the page and the settings but not on where
 * the boxes are: ruling removal, writing height, and the labelled blobs.
 *
 * It is the bulk of the work and it produces the page-sized label grid that every
 * fit keeps alive for painting out neighbours. Doing it once per page (per rounded
 * box height, which is all the boxes contribute) means a fit of three boxes costs
 * the same as a fit of three hundred, and repeated fits share one grid instead of
 * each pinning its own.
 */
function analysePage(page, boxHeight, config) {
    const key = JSON.stringify([
        Math.round(boxHeight),
        config.lineMinHorizontal, config.lineMinVertical, config.lineMaxGap,
        config.lineMinDensity, config.lineSkewTolerance,
        config.smearX, config.smearY, config.minBlobArea, config.maxBlobPageFraction,
    ]);
    page.analyses ??= new Map();

    const cached = page.analyses.get(key);
    if (cached) return cached;

    const { width, height } = page;

    // The boxes over-state the writing height (a register box is a whole cell), so
    // they only set a deliberately strict line length. Real ruling is page-long and
    // clears it easily; the writing height is measured once the ruling is gone.
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

    const analysis = {
        writing,
        textHeight,
        blobs: findBlobs(writing, width, height, {
            smearX: Math.max(1, Math.round((config.smearX * textHeight) / 2)),
            smearY: Math.max(1, Math.round((config.smearY * textHeight) / 2)),
            minArea: Math.max(4, Math.round(config.minBlobArea * textHeight * textHeight)),
            maxPageFraction: config.maxBlobPageFraction,
        }),
    };

    // A page holds a few analyses at most (box height rarely changes). Keep it that
    // way: each one carries a page-sized label grid.
    if (page.analyses.size >= 3) page.analyses.delete(page.analyses.keys().next().value);
    page.analyses.set(key, analysis);

    return analysis;
}

/**
 * Decide, for every template box, which ink is its own.
 *
 * Each blob is scored by the share of its ink that lies inside each box. A blob
 * with a real share in exactly one box belongs to that box, even when most of it
 * hangs outside (a word running past the cell edge). A blob with a real share in
 * two boxes is contested. A field that owns something is cropped to that ink plus
 * padding, and carries the outline of that ink for the marker to draw.
 *
 * The crop is the rectangle, not the outline. Whitening the paper outside the
 * writing was measured against the project's own TrOCR model and read slightly
 * worse than leaving the crop alone, so the outline is shown, not applied.
 *
 * An 'ambiguous' field keeps its own box, for one of two reasons: its writing
 * touches a neighbour's ('touching'), or the ink it would own is far bigger than
 * the box ('oversized': a stain, a stray mark, a long flourish).
 *
 * @param {{width: number, height: number, ink: Uint8Array}} page  From preparePage().
 * @param {Array<{x: number, y: number, w: number, h: number}>} anchors  Template boxes, as fractions.
 * @returns {{
 *   width: number, height: number, textHeight: number,
 *   labels: Int32Array, labelCount: number, owners: Int32Array,
 *   fields: Array<{
 *     status: 'fitted'|'ambiguous'|'empty',
 *     reason: 'touching'|'oversized'|null,
 *     rect: {x: number, y: number, w: number, h: number}|null,
 *     polygons: Array<Array<[number, number]>>
 *   }>
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

    const boxHeight = clamp(median(anchors.map((anchor) => anchor.h * height)), 6, height * 0.2);
    const { writing, textHeight, blobs } = analysePage(page, boxHeight, config);
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

        const box = union[owner];
        box.x0 = Math.min(box.x0, blobs.x0[label]);
        box.y0 = Math.min(box.y0, blobs.y0[label]);
        box.x1 = Math.max(box.x1, blobs.x1[label]);
        box.y1 = Math.max(box.y1, blobs.y1[label]);
        box.owned++;
    }

    const fields = rects.map((rect, index) => {
        if (strongLabels[index].some((label) => owners[label] === OWNER_CONTESTED)) {
            return emptyField('ambiguous', 'touching');
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
            return emptyField('ambiguous', 'oversized');
        }

        // The shape of this field's own writing, grown by a margin so the letters of
        // a word join into one outline. It is what the crop is measured from, and
        // what the marker draws; the crop itself stays the rectangle around it.
        const margin = Math.max(1, Math.round(config.inkOutlineMargin * textHeight));
        const own = new Uint8Array(fittedW * fittedH);
        for (let y = fitted.y0; y < fitted.y1; y++) {
            for (let x = fitted.x0; x < fitted.x1; x++) {
                const i = y * width + x;
                if (writing[i] && owners[labels[i]] === index) {
                    own[(y - fitted.y0) * fittedW + (x - fitted.x0)] = 1;
                }
            }
        }

        const keep = dilate(own, fittedW, fittedH, margin, margin);
        const polygons = traceRings(keep, fittedW, fittedH)
            .map((ring) => simplifyRing(ring, config.polygonTolerance))
            .filter((ring) => ring.length >= 3)
            .map((ring) => ring.map(([px, py]) => [
                (fitted.x0 + px) / width,
                (fitted.y0 + py) / height,
            ]));

        return {
            status: 'fitted',
            reason: null,
            rect: {
                x: fitted.x0 / width,
                y: fitted.y0 / height,
                w: fittedW / width,
                h: fittedH / height,
            },
            polygons,
        };
    });

    return { width, height, textHeight, labels, labelCount: count, owners, fields };
}

// ----------------------------------------------------------------- masking
