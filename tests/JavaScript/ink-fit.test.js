import assert from 'node:assert/strict';
import test from 'node:test';
import {
    binarise,
    detectRuledLines,
    dilate,
    findBlobs,
    fitFields,
    preparePage,
    stretchContrast,
    toGrayscale,
    whitenNeighbourInk,
} from '../../resources/js/ink-fit.js';

const W = 600;
const H = 400;
const PAPER = 235;
const INK = 30;

const blankPage = (value = PAPER) => new Uint8Array(W * H).fill(value);

function fillRect(gray, x0, y0, x1, y1, value = INK) {
    for (let y = y0; y < y1; y++) {
        for (let x = x0; x < x1; x++) gray[y * W + x] = value;
    }
}

/**
 * A stand-in for a handwritten word: thin vertical strokes 3px wide with a 3px
 * gap, so it is joined up only by the blob smear, like real letters. Returns the
 * ink's bounding box.
 */
function word(gray, x, y, letters, height = 20) {
    for (let i = 0; i < letters; i++) fillRect(gray, x + i * 6, y, x + i * 6 + 3, y + height);

    return { x0: x, y0: y, x1: x + (letters - 1) * 6 + 3, y1: y + height };
}

/** Template box from pixel bounds, as the marker stores it. */
const anchor = (x0, y0, x1, y1) => ({ x: x0 / W, y: y0 / H, w: (x1 - x0) / W, h: (y1 - y0) / H });

const fit = (gray, anchors) => fitFields(preparePage(gray, W, H), anchors);

test('grayscale weights match luma and ignore alpha', () => {
    const rgba = new Uint8ClampedArray([255, 255, 255, 0, 0, 0, 0, 255, 255, 0, 0, 255]);
    const gray = toGrayscale(rgba, 3, 1);

    assert.equal(gray[0], 255);
    assert.equal(gray[1], 0);
    assert.equal(gray[2], 76);
});

test('contrast stretching lifts faded ink and leaves a flat page alone', () => {
    const faded = new Uint8Array(1000).fill(200);
    for (let i = 0; i < 100; i++) faded[i] = 150;

    const stretched = stretchContrast(faded);
    assert.equal(stretched[0], 0);
    assert.equal(stretched[500], 255);

    const flat = new Uint8Array(1000).fill(210);
    assert.equal(stretchContrast(flat), flat);
});

test('binarise finds ink under uneven lighting without flagging the paper', () => {
    const gray = new Uint8Array(W * H);
    for (let y = 0; y < H; y++) {
        for (let x = 0; x < W; x++) gray[y * W + x] = 170 + Math.round((x / W) * 80);
    }
    // A stroke 90 levels darker than the paper around it, at both ends of the ramp.
    fillRect(gray, 40, 100, 43, 130, 90);
    fillRect(gray, 540, 100, 543, 130, 170);

    const ink = binarise(gray, W, H);

    assert.equal(ink[110 * W + 41], 1);
    assert.equal(ink[110 * W + 541], 1);

    let stray = 0;
    for (let y = 0; y < H; y++) {
        for (let x = 0; x < W; x++) {
            const onStroke = (x >= 38 && x <= 45 || x >= 538 && x <= 545) && y >= 98 && y <= 132;
            if (ink[y * W + x] && !onStroke) stray++;
        }
    }
    assert.equal(stray, 0);
});

test('dilation grows a pixel by the requested radius on each axis', () => {
    const mask = new Uint8Array(21 * 21);
    mask[10 * 21 + 10] = 1;

    const grown = dilate(mask, 21, 21, 3, 1);
    const count = grown.reduce((sum, value) => sum + value, 0);

    assert.equal(count, 7 * 3);
    assert.equal(grown[10 * 21 + 13], 1);
    assert.equal(grown[10 * 21 + 14], 0);
    assert.equal(grown[9 * 21 + 10], 1);
    assert.equal(grown[8 * 21 + 10], 0);
});

test('ruled lines are detected but strokes, underlines and crossings are not', () => {
    const gray = blankPage();
    fillRect(gray, 0, 100, W, 102);       // long horizontal rule
    fillRect(gray, 300, 0, 302, H);       // long vertical rule
    fillRect(gray, 400, 200, 460, 202);   // short flourish, not a rule
    word(gray, 50, 90, 8, 24);            // strokes crossing the horizontal rule

    const ink = binarise(gray, W, H);
    const lines = detectRuledLines(ink, W, H, { minHorizontal: 140, minVertical: 50 });

    assert.equal(lines[100 * W + 200], 1, 'horizontal rule');
    assert.equal(lines[250 * W + 300], 1, 'vertical rule');
    assert.equal(lines[200 * W + 430], 0, 'short underline stays');
    assert.equal(lines[95 * W + 51], 0, 'stroke above the rule stays');
    assert.equal(lines[110 * W + 51], 0, 'stroke below the rule stays');
});

test('blobs join the letters of a word but not separate words, and drop specks', () => {
    const gray = blankPage();
    word(gray, 50, 100, 12);
    word(gray, 250, 100, 12);
    fillRect(gray, 450, 300, 451, 301);   // one-pixel speck

    const ink = binarise(gray, W, H);
    const blobs = findBlobs(ink, W, H, { smearX: 6, smearY: 2, minArea: 6, maxPageFraction: 0.25 });
    const valid = [...blobs.valid].reduce((sum, value) => sum + value, 0);

    assert.equal(valid, 2);
});

test('a word running past its box is fitted whole', () => {
    const gray = blankPage();
    const own = word(gray, 190, 125, 14);        // 190-274, box ends at 240
    const other = word(gray, 360, 125, 10);
    const result = fit(gray, [anchor(30, 120, 240, 160), anchor(330, 120, 570, 160)]);

    const [first, second] = result.fields;
    assert.equal(first.status, 'fitted');
    assert.ok(first.rect.x * W <= own.x0, 'starts at or before the first letter');
    assert.ok((first.rect.x + first.rect.w) * W >= own.x1, 'reaches the last letter');
    assert.equal(second.status, 'fitted');
    assert.ok(second.rect.x * W >= 330 - 20, 'the other field is not dragged across');
    assert.ok((second.rect.x + second.rect.w) * W <= other.x1 + 20);
});

test('a fit is tighter than a box that is mostly blank', () => {
    const gray = blankPage();
    const own = word(gray, 60, 130, 10);
    const result = fit(gray, [anchor(30, 110, 300, 170)]);

    const [field] = result.fields;
    assert.equal(field.status, 'fitted');
    assert.ok(field.rect.w * W < 120);
    assert.ok(field.rect.x * W <= own.x0);
});

test('a word that straddles two boxes makes both fall back to their template box', () => {
    const gray = blankPage();
    word(gray, 200, 130, 15);                    // 200-287, straddles the 240/250 border
    word(gray, 400, 300, 8);
    const result = fit(gray, [
        anchor(30, 120, 240, 160),
        anchor(250, 120, 560, 160),
        anchor(380, 290, 560, 330),
    ]);

    assert.equal(result.fields[0].status, 'ambiguous');
    assert.equal(result.fields[1].status, 'ambiguous');
    assert.equal(result.fields[0].rect, null);
    assert.equal(result.fields[2].status, 'fitted', 'unrelated fields are unaffected');
});

test('a box with no writing stays empty', () => {
    const gray = blankPage();
    word(gray, 60, 130, 8);
    const result = fit(gray, [anchor(30, 120, 240, 160), anchor(330, 120, 570, 160)]);

    assert.equal(result.fields[1].status, 'empty');
    assert.equal(result.fields[1].rect, null);
});

test('ruling inside a box does not stretch the fit across the page', () => {
    const gray = blankPage();
    const own = word(gray, 60, 125, 10);
    fillRect(gray, 0, 155, W, 157);              // rule running through the box
    fillRect(gray, 320, 0, 322, H);              // and a column border
    const result = fit(gray, [anchor(30, 120, 240, 160), anchor(330, 120, 570, 160)]);

    const [field] = result.fields;
    assert.equal(field.status, 'fitted');
    assert.ok((field.rect.x + field.rect.w) * W < own.x1 + 30);
});

test('a fit that balloons far past its box is handed back to the person', () => {
    const gray = blankPage();
    word(gray, 100, 130, 83);                    // ~500px of ink over a 150px box
    const result = fit(gray, [anchor(90, 120, 240, 160)]);

    assert.equal(result.fields[0].status, 'ambiguous');
});

test('a neighbour hanging into the gap of a fitted box is listed for masking', () => {
    const gray = blankPage();
    word(gray, 40, 125, 8);                      // 40-85
    word(gray, 200, 125, 8);                     // 200-245
    // Belongs to the box above; its tail dips 2px into this one, between the words.
    // Words closer than about one word gap (the text height) would join into one blob.
    const intruder = word(gray, 130, 100, 4, 22);
    const result = fit(gray, [anchor(30, 120, 300, 160), anchor(30, 80, 300, 120)]);

    const [lower, upper] = result.fields;
    assert.equal(lower.status, 'fitted');
    assert.equal(upper.status, 'fitted');
    assert.equal(lower.maskLabels.length, 1);

    // Paint the crop out at scale 1 and confirm only the intruder disappears.
    const crop = {
        x: Math.floor(lower.rect.x * W),
        y: Math.floor(lower.rect.y * H),
        w: Math.ceil(lower.rect.w * W),
        h: Math.ceil(lower.rect.h * H),
    };
    const rgba = new Uint8ClampedArray(crop.w * crop.h * 4);
    for (let j = 0; j < crop.h; j++) {
        for (let i = 0; i < crop.w; i++) {
            const value = gray[(crop.y + j) * W + crop.x + i];
            rgba.set([value, value, value, 255], (j * crop.w + i) * 4);
        }
    }
    const pixel = (x, y) => rgba[((y - crop.y) * crop.w + (x - crop.x)) * 4];

    assert.equal(pixel(131, 121), INK, 'intruder is dark before masking');
    assert.equal(pixel(41, 130), INK, 'own writing is dark before masking');

    const painted = whitenNeighbourInk(
        rgba, crop.w, crop.h, crop, { width: W, height: H },
        { width: result.width, height: result.height, labels: result.labels, labelCount: result.labelCount },
        lower.maskLabels,
    );

    assert.ok(painted > 0);
    assert.equal(pixel(131, 121), 255, 'intruder is erased');
    assert.equal(pixel(41, 130), INK, 'own writing is untouched');
    assert.equal(pixel(201, 130), INK, 'own writing is untouched');
    assert.ok(intruder.y1 > lower.rect.y * H, 'the intruder really was inside the crop');
});

test('masking maps a full-resolution crop onto a coarser label grid', () => {
    // A 4x4 label grid over an 8x8 page: label 1 covers the top-left grid cell.
    const labels = new Int32Array(16);
    labels[0] = 1;
    const rgba = new Uint8ClampedArray(8 * 8 * 4).fill(0);
    for (let p = 3; p < rgba.length; p += 4) rgba[p] = 255;

    const painted = whitenNeighbourInk(
        rgba, 8, 8, { x: 0, y: 0, w: 8, h: 8 }, { width: 8, height: 8 },
        { width: 4, height: 4, labels, labelCount: 1 }, [1],
    );

    assert.equal(painted, 4);
    assert.equal(rgba[0], 255);
    assert.equal(rgba[(1 * 8 + 1) * 4], 255);
    assert.equal(rgba[(2 * 8 + 2) * 4], 0);
});

test('fitting no boxes returns no fields', () => {
    const result = fit(blankPage(), []);

    assert.deepEqual(result.fields, []);
});
