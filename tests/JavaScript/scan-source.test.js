import assert from 'node:assert/strict';
import { createHash, randomBytes } from 'node:crypto';
import test from 'node:test';

import { PDFDocument } from 'pdf-lib';

import {
    appendToQueue,
    batchSummaryPhase,
    fileFingerprint,
    firstUnsavedPage,
    isPdfFile,
    nextUnsavedPage,
    pageCanAlign,
    pageFileName,
    pageRanges,
    pdfPageFile,
    queueCounts,
    recordLeadPage,
    recordReadiness,
    recordState,
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

test('a page is its own record, or goes with its pair when the layout has a page 2', () => {
    // No page 2 in the layout: every page alone.
    assert.deepEqual([1, 2, 3, 4].map((page) => recordLeadPage(page, 4, false)), [1, 2, 3, 4]);
    // A page 2: 1–2 and 3–4, and a lone last page alone.
    assert.deepEqual([1, 2, 3, 4, 5].map((page) => recordLeadPage(page, 5, true)), [1, 1, 3, 3, 5]);
    // A file of one page has no pair.
    assert.equal(recordLeadPage(1, 1, true), 1);
});

// ---------------------------------------------------------------- batch pages

const page = (status, extra = {}) => ({ status, ...extra });
const records = (...groups) => groups.map((members, index) => ({ key: `r${index + 1}`, members }));

test('a page can be aligned while it is pending, queued or failed', () => {
    for (const status of ['pending', 'saved', 'failed']) assert.equal(pageCanAlign(page(status)), true, status);
    for (const status of ['saving', 'scanning', 'ready', 'verifying', 'imported', 'rejected']) {
        assert.equal(pageCanAlign(page(status)), false, status);
    }
    assert.equal(pageCanAlign(null), false);
});

test('a record is ready once all its pages are read, blocked by a failed page', () => {
    assert.equal(recordState([page('pending'), page('pending')]), 'waiting');
    assert.equal(recordState([page('ready'), page('scanning')]), 'waiting');
    assert.equal(recordState([page('ready'), page('verifying')]), 'ready');
    assert.equal(recordState([page('ready'), page('failed')]), 'blocked');
    assert.equal(recordState([page('imported'), page('imported')]), 'done');
    assert.equal(recordState([page('rejected')]), 'done');
    // Its partner removed, the page left is a record on its own.
    assert.equal(recordState([page('ready')]), 'ready');
});

test('record readiness covers every record by its key', () => {
    const mix = records(
        [page('ready'), page('ready')],
        [page('ready'), page('failed')],
        [page('saved')],
        [page('imported'), page('imported')],
    );
    assert.deepEqual([...recordReadiness(mix)], [['r1', 'ready'], ['r2', 'blocked'], ['r3', 'waiting'], ['r4', 'done']]);
    assert.equal(recordReadiness([]).size, 0);
});

test('a record blocked by a failed page is ready once that page is removed', () => {
    const lead = page('ready', { pageNo: 1 });
    const failed = page('failed', { pageNo: 2 });
    assert.equal(recordReadiness([{ key: 'pair', members: [lead, failed] }]).get('pair'), 'blocked');
    // Removing the failed page leaves its partner as a record on its own.
    assert.equal(recordReadiness([{ key: 'pair', members: [lead] }]).get('pair'), 'ready');
    // Queued again after a re-align, it waits to be read.
    assert.equal(recordReadiness([{ key: 'pair', members: [lead, page('saved')] }]).get('pair'), 'waiting');
});

test('the batch phase is worked out from its pages', () => {
    assert.equal(batchSummaryPhase([]), 'idle');
    // All pending, or some queued and some not: aligning.
    assert.equal(batchSummaryPhase([page('pending'), page('pending')]), 'aligning');
    assert.equal(batchSummaryPhase([page('saved'), page('pending')]), 'aligning');
    // Every page queued, waiting for Scan all.
    assert.equal(batchSummaryPhase([page('saved'), page('saved')]), 'aligning');
    // Scan all running.
    assert.equal(batchSummaryPhase([page('ready'), page('scanning')]), 'reading');
    // Some read: verifying, even with a failed page waiting.
    assert.equal(batchSummaryPhase([page('ready'), page('verifying'), page('imported')]), 'verifying');
    assert.equal(batchSummaryPhase([page('ready'), page('failed')]), 'verifying');
    // Only a failed page left: it has to be aligned again (or removed).
    assert.equal(batchSummaryPhase([page('imported'), page('failed')]), 'aligning');
    // A page sent back to Align while others are read.
    assert.equal(batchSummaryPhase([page('ready'), page('pending')]), 'aligning');
    // Everything saved or left out.
    assert.equal(batchSummaryPhase([page('imported'), page('rejected', { skipped: true })]), 'done');
});

test('added pages go after the queue, in their own order', () => {
    const queued = [
        { name: 'a1', sourceHash: 'aa', pageNo: 1 },
        { name: 'a2', sourceHash: 'aa', pageNo: 2 },
    ];
    const added = [
        { name: 'c1', sourceHash: 'cc', pageNo: 1 },
        { name: 'b1', sourceHash: 'bb', pageNo: 1 },
        { name: 'b2', sourceHash: 'bb', pageNo: 2 },
    ];
    const queue = appendToQueue(queued, added);
    assert.deepEqual(queue.map((item) => item.name), ['a1', 'a2', 'c1', 'b1', 'b2']);
    // The queue given is left as it was; the items are the same objects.
    assert.equal(queued.length, 2);
    assert.equal(queue[0], queued[0]);
});

test('a page already queued is not added twice', () => {
    const queued = [{ name: 'a1', sourceHash: 'aa', pageNo: 1 }, { name: 'a2', sourceHash: 'aa', pageNo: 2 }];
    // A copy of a queued file (same fingerprint), and one page given twice.
    const added = [
        { name: 'copy-a1', sourceHash: 'aa', pageNo: 1 },
        { name: 'copy-a3', sourceHash: 'aa', pageNo: 3 },
        { name: 'b1', sourceHash: 'bb', pageNo: 1 },
        { name: 'b1-again', sourceHash: 'bb', pageNo: 1 },
    ];
    assert.deepEqual(appendToQueue(queued, added).map((item) => item.name), ['a1', 'a2', 'copy-a3', 'b1']);
});

test('a copy asked for in the left-out window, or a file with no fingerprint, is added', () => {
    const queued = [{ name: 'a1', sourceHash: 'aa', pageNo: 1 }];
    const added = [
        { name: 'copy-a1', sourceHash: 'aa', pageNo: 1, addedAnyway: true },
        { name: 'unknown', sourceHash: null, pageNo: 1 },
        { name: 'unknown-too', sourceHash: null, pageNo: 1 },
    ];
    assert.deepEqual(appendToQueue(queued, added).map((item) => item.name), ['a1', 'copy-a1', 'unknown', 'unknown-too']);
    assert.deepEqual(appendToQueue(null, null), []);
});

test('queue counts cover every state', () => {
    const items = [
        page('pending'),
        page('pending', { alignCheck: true }),
        page('saving'),
        page('saved'),
        page('scanning'),
        page('ready'),
        page('verifying'),
        page('imported'),
        page('imported', { alignCheck: true }),
        page('rejected'),
        page('rejected', { skipped: true }),
        page('failed'),
    ];
    const counts = queueCounts(items);
    assert.equal(counts.total, 12);
    // Sent to the queue: queued, reading, read, verifying, saved, rejected.
    assert.equal(counts.queued, 8);
    // Read: scanned, verifying, saved, and a rejected match (a skipped page may not have been read).
    assert.equal(counts.read, 5);
    assert.equal(counts.saved, 2);
    assert.equal(counts.failed, 1);
    assert.deepEqual(counts.byStatus, {
        pending: 2, saving: 1, saved: 1, scanning: 1, ready: 1, verifying: 1, imported: 2, rejected: 2, failed: 1,
    });
    assert.deepEqual(counts.filters, { all: 12, attention: 2, pending: 2, saved: 2 });
});

test('needs attention: failed pages, and pages Snap could not fit that are not done', () => {
    const counts = queueCounts([
        page('failed'),
        page('saved', { alignCheck: true }),
        page('imported', { alignCheck: true }),
        page('ready'),
    ]);
    assert.equal(counts.filters.attention, 2);
    assert.equal(counts.alignCheck, 1);
    assert.deepEqual(queueCounts([]).filters, { all: 0, attention: 0, pending: 0, saved: 0 });
});
