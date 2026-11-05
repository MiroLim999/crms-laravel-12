import assert from 'node:assert/strict';
import { createHash, randomBytes } from 'node:crypto';
import test from 'node:test';

import { PDFDocument } from 'pdf-lib';

import {
    fileFingerprint,
    firstUnsavedPage,
    isPdfFile,
    nextUnsavedPage,
    pageFileName,
    pageRanges,
    pdfPageFile,
    sha256Hex,
} from '../../resources/js/scan-source.js';

const nodeSha256 = (bytes) => createHash('sha256').update(bytes).digest('hex');

test('the digest worked out without the browser matches SHA-256', () => {
    // FIPS 180-4 examples.
    assert.equal(sha256Hex(new Uint8Array()), 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
    assert.equal(sha256Hex(new TextEncoder().encode('abc')), 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');

    // Every length around the padding boundary, and across many blocks.
    for (const length of [1, 55, 56, 57, 63, 64, 65, 119, 120, 128, 1000, 70_000]) {
        const bytes = randomBytes(length);
        assert.equal(sha256Hex(bytes), nodeSha256(bytes), `length ${length}`);
    }
});

test('a file fingerprint is the same with or without the browser digest', async () => {
    const bytes = randomBytes(9 * 1024 * 1024 + 17);
    const file = new File([bytes], 'BIRTHS-1995.pdf', { type: 'application/pdf' });
    const expected = nodeSha256(bytes);

    assert.equal(await fileFingerprint(file), expected);

    // Over plain http from another computer the browser has no digest.
    const subtle = Object.getOwnPropertyDescriptor(globalThis.crypto, 'subtle')
        ?? Object.getOwnPropertyDescriptor(Object.getPrototypeOf(globalThis.crypto), 'subtle');
    Object.defineProperty(globalThis.crypto, 'subtle', { value: undefined, configurable: true });
    try {
        assert.equal(await fileFingerprint(file), expected);
    } finally {
        delete globalThis.crypto.subtle;
        if (!globalThis.crypto.subtle && subtle) Object.defineProperty(globalThis.crypto, 'subtle', subtle);
    }
});

test('PDFs are told from images by type or name', () => {
    assert.equal(isPdfFile({ type: 'application/pdf', name: 'scan' }), true);
    assert.equal(isPdfFile({ type: '', name: 'Book 2.PDF' }), true);
    assert.equal(isPdfFile({ type: 'image/png', name: 'page.png' }), false);
    assert.equal(isPdfFile(null), false);
});

test('one page is cut out of a PDF as a PDF of its own', async () => {
    const book = await PDFDocument.create();
    // Three pages of different sizes, so the right one can be recognised.
    [[600, 400], [700, 500], [800, 600]].forEach((size) => book.addPage(size));
    const file = new File([await book.save()], 'BIRTHS-1995.pdf', { type: 'application/pdf' });

    const second = await pdfPageFile(file, 2);
    assert.equal(second.name, 'BIRTHS-1995 (page 2).pdf');
    assert.equal(second.type, 'application/pdf');

    const page = await PDFDocument.load(await second.arrayBuffer());
    assert.equal(page.getPageCount(), 1);
    assert.deepEqual(page.getPage(0).getSize(), { width: 700, height: 500 });

    // The same file is read once; another page comes from that reading.
    const third = await PDFDocument.load(await (await pdfPageFile(file, 3)).arrayBuffer());
    assert.deepEqual(third.getPage(0).getSize(), { width: 800, height: 600 });

    // A page scanned sideways is kept turned upright.
    const turned = await PDFDocument.load(await (await pdfPageFile(file, 1, { rotation: 90 })).arrayBuffer());
    assert.equal(turned.getPage(0).getRotation().angle, 90);

    await assert.rejects(pdfPageFile(file, 4), RangeError);
    await assert.rejects(pdfPageFile(file, 0), RangeError);
});

test('page file names keep the book name', () => {
    assert.equal(pageFileName('Births Vol 2.pdf', 7), 'Births Vol 2 (page 7).pdf');
    assert.equal(pageFileName('', 1), 'scan (page 1).pdf');
});

test('a file opens at its first page not saved yet', () => {
    assert.equal(firstUnsavedPage(5, new Set()), 1);
    assert.equal(firstUnsavedPage(5, new Set([1, 2])), 3);
    assert.equal(firstUnsavedPage(5, new Set([1, 3])), 2);
    // Every page saved: back to the first.
    assert.equal(firstUnsavedPage(3, new Set([1, 2, 3])), 1);
});

test('after a save, the next page not saved yet comes next', () => {
    assert.equal(nextUnsavedPage(2, 5, new Set([1, 2])), 3);
    assert.equal(nextUnsavedPage(2, 5, new Set([1, 2, 3, 4])), 5);
    // Past the end: the pages skipped before it.
    assert.equal(nextUnsavedPage(5, 5, new Set([2, 3, 4, 5])), 1);
    assert.equal(nextUnsavedPage(3, 3, new Set([1, 2, 3])), null);
});

test('saved pages read as short runs', () => {
    assert.equal(pageRanges([]), '');
    assert.equal(pageRanges([4]), '4');
    assert.equal(pageRanges([3, 1, 2]), '1–3');
    assert.equal(pageRanges([1, 2, 3, 5, 7, 8]), '1–3, 5 and 7–8');
    assert.equal(pageRanges(new Set([2, 9])), '2 and 9');
});
