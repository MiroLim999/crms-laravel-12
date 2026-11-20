import assert from 'node:assert/strict';
import { createHash, randomBytes } from 'node:crypto';
import test from 'node:test';

import { PDFDocument } from 'pdf-lib';

import {
    appendToQueue,
    batchSummaryPhase,
    compareQueuePaths,
    copyOf,
    fileFingerprint,
    firstUnsavedPage,
    isPdfFile,
    matchFilesToBatch,
    nextUnsavedPage,
    pageCanAlign,
    pageFileName,
    pageNeedsFile,
    pageRanges,
    pdfPageFile,
    queueCounts,
    rebuildBatchState,
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

test('with the duplicate file check off, copies and repeated pages are all added', () => {
    const queued = [{ name: 'a1', sourceHash: 'aa', pageNo: 1 }];
    const added = [
        { name: 'copy-a1', sourceHash: 'aa', pageNo: 1 },
        { name: 'copy-a1-again', sourceHash: 'aa', pageNo: 1 },
    ];
    assert.deepEqual(appendToQueue(queued, added, { allowCopies: true }).map((item) => item.name), ['a1', 'copy-a1', 'copy-a1-again']);
    // The default still leaves them out.
    assert.deepEqual(appendToQueue(queued, added).map((item) => item.name), ['a1']);
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
        page('pending'),
        page('saving'),
        page('saved'),
        page('scanning'),
        page('ready'),
        page('verifying'),
        page('imported'),
        page('imported'),
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
    assert.deepEqual(counts.filters, { all: 12, attention: 1, pending: 2, saved: 2 });
});

test('needs attention: failed pages', () => {
    const counts = queueCounts([
        page('failed'),
        page('saved'),
        page('imported'),
        page('ready'),
    ]);
    assert.equal(counts.filters.attention, 1);
    assert.deepEqual(queueCounts([]).filters, { all: 0, attention: 0, pending: 0, saved: 0 });
});

// ---------------------------------------------------------------- resume a batch

const HASH_A = 'a'.repeat(64);
const HASH_B = 'b'.repeat(64);

/** A batch as documents.batches.show sends it: a two-page file (a pair) and a one-page file. */
function serverBatch() {
    const page = (key, position, extra) => ({
        key,
        position,
        sourceName: 'BOOK-1995.pdf',
        sourceHash: HASH_A,
        pageNo: position + 1,
        pageTotal: 2,
        recordKey: 'g1#1',
        status: 'pending',
        documentPageId: null,
        statusUrl: null,
        workspaceScanPath: null,
        alignment: null,
        flags: {},
        error: null,
        recordId: null,
        recordUrl: null,
        ...extra,
    });
    return {
        key: 'batch_1_abc',
        version: 7,
        pages: [
            // Out of order on purpose: the queue follows `position`.
            page('p3', 2, {
                sourceName: 'cover.png', sourceHash: HASH_B, pageNo: 1, pageTotal: 1, recordKey: 'g2#1', status: 'imported',
                recordId: 12, recordUrl: '/records/12',
            }),
            page('p1', 0, {
                status: 'ready', documentPageId: 41, statusUrl: '/documents/pages/41', workspaceScanPath: 'documents/workspace/batch_1_abc/41-BOOK.pdf',
                alignment: { boxes: [{ name: 'Name', x: 0.1 }], rows: [0.2, 0.3], tilts: [0, 0], autoFields: [['name', { x: 0.1 }]] },
                flags: { scanKind: 'read' },
            }),
            page('p2', 1, { status: 'verifying', documentPageId: 42, statusUrl: '/documents/pages/42' }),
        ],
        drafts: [
            { recordKey: 'g1#1', draft: { verify: [{ line: 5, value: 'Juan', verified: true }], review: [['k', 'v']] } },
            { recordKey: 'g2#1', draft: { exportTable: [['Name'], ['Cover']] } },
        ],
    };
}

test('a batch kept on the server is rebuilt in queue order, with its states and drafts', () => {
    const { key, items } = rebuildBatchState(serverBatch());
    assert.equal(key, 'batch_1_abc');
    assert.deepEqual(items.map((item) => item.key), ['p1', 'p2', 'p3']);

    const [first, second, cover] = items;
    // Read pages need no file; their page on the server is asked for again.
    assert.equal(first.file, null);
    assert.equal(first.status, 'ready');
    assert.deepEqual(first.page, { id: 41, statusUrl: '/documents/pages/41' });
    assert.equal(first.workspaceScanPath, 'documents/workspace/batch_1_abc/41-BOOK.pdf');
    assert.equal(first.name, 'BOOK-1995 (page 1).pdf');
    assert.equal(first.group, 'g1');
    assert.equal(first.record, 'g1#1');
    assert.deepEqual(first.rows, [0.2, 0.3]);
    assert.equal(first.autoFields.get('name').x, 0.1);
    assert.equal(first.scanKind, 'read');
    // A record open in Verify comes back read, waiting to be verified.
    assert.equal(second.status, 'ready');
    // The draft comes back on the record's first page.
    assert.deepEqual([...first.verifyDraft], [[5, { value: 'Juan', verified: true }]]);
    assert.deepEqual([...first.reviewExtras], [['k', 'v']]);
    assert.equal(second.verifyDraft, null);
    // A saved record keeps its link and the table it was saved with (Download all).
    assert.equal(cover.status, 'imported');
    assert.equal(cover.recordUrl, '/records/12');
    assert.deepEqual(cover.exportTable, [['Name'], ['Cover']]);
});

test('a queued page the server no longer has comes back failed; one being read is watched again', () => {
    const batch = serverBatch();
    batch.pages[1].documentPageId = null;
    batch.pages[1].statusUrl = null;
    batch.pages[2].status = 'scanning';
    const { items } = rebuildBatchState(batch);
    assert.equal(items[0].status, 'failed');
    assert.match(items[0].error, /no longer on the server/);
    assert.equal(items[1].status, 'scanning');
    assert.equal(items[1].sentToScan, true);
});

test('after a layout change, pages not queued yet start on the current layout', () => {
    const batch = serverBatch();
    batch.pages.push({
        key: 'p4', position: 3, sourceName: 'loose.pdf', sourceHash: HASH_B, pageNo: 1, pageTotal: 1, recordKey: 'g3#1',
        status: 'pending', alignment: { boxes: [{ name: 'Old field' }], rows: [0.5] }, flags: {},
    });
    const kept = rebuildBatchState(batch).items.find((item) => item.key === 'p4');
    assert.deepEqual(kept.boxes, [{ name: 'Old field' }]);

    const changed = rebuildBatchState(batch, { layoutChanged: true }).items;
    assert.equal(changed.find((item) => item.key === 'p4').boxes, null);
    // A page queued already keeps the layout it was read with.
    assert.deepEqual(changed.find((item) => item.key === 'p1').rows, [0.2, 0.3]);
});

test('files picked again are matched by fingerprint, whatever their names', () => {
    const pages = [
        { sourceName: 'BOOK-1995.pdf', sourceHash: HASH_A, status: 'pending', file: null },
        { sourceName: 'BOOK-1995.pdf', sourceHash: HASH_A, status: 'failed', file: null },
        { sourceName: 'cover.png', sourceHash: HASH_B, status: 'imported', file: null },
        { sourceName: 'LEDGER.pdf', sourceHash: 'c'.repeat(64), status: 'pending', file: null },
    ];
    const renamed = { name: 'book (copy).pdf' };
    const extra = { name: 'holiday.jpg' };
    const result = matchFilesToBatch([{ file: renamed, hash: HASH_A }, { file: extra, hash: 'f'.repeat(64) }], pages);

    // The renamed file matches; the extra one is reported.
    assert.equal(result.matched.get(HASH_A), renamed);
    assert.deepEqual(result.unmatched, ['holiday.jpg']);
    // The file not picked leaves its page waiting.
    assert.deepEqual(result.missing, [{ sourceName: 'LEDGER.pdf', sourceHash: 'c'.repeat(64), pages: 1 }]);
    assert.equal(pageNeedsFile(pages[0]), true);
    assert.equal(pageNeedsFile(pages[2]), false);
    assert.equal(pageNeedsFile({ status: 'pending', file: renamed }), false);
});

test('a file of a page queued already matches without being wanted; nothing picked leaves all wanted', () => {
    const pages = [
        { sourceName: 'cover.png', sourceHash: HASH_B, status: 'ready', file: null },
        { sourceName: 'BOOK.pdf', sourceHash: HASH_A, status: 'pending', file: null },
        { sourceName: 'BOOK.pdf', sourceHash: HASH_A, status: 'pending', file: null },
    ];
    const cover = { name: 'cover.png' };
    const result = matchFilesToBatch([{ file: cover, hash: HASH_B }], pages);
    assert.equal(result.matched.get(HASH_B), cover);
    assert.deepEqual(result.unmatched, []);
    assert.deepEqual(result.missing, [{ sourceName: 'BOOK.pdf', sourceHash: HASH_A, pages: 2 }]);
    assert.deepEqual(matchFilesToBatch([], []).missing, []);
});

// ---------------------------------------------------------------- queue order

test('a copy is known by its name, as Windows, macOS and browsers name copies', () => {
    assert.deepEqual(copyOf('1.pdf'), { base: '1.pdf', rank: 0 });
    assert.deepEqual(copyOf('1 - Copy.pdf'), { base: '1.pdf', rank: 1 });
    assert.deepEqual(copyOf('1 - Copy (3).pdf'), { base: '1.pdf', rank: 3 });
    assert.deepEqual(copyOf('BOOK copy.pdf'), { base: 'BOOK.pdf', rank: 1 });
    assert.deepEqual(copyOf('BOOK copy 2.pdf'), { base: 'BOOK.pdf', rank: 2 });
    assert.deepEqual(copyOf('BOOK (1).pdf'), { base: 'BOOK.pdf', rank: 1 });
    assert.deepEqual(copyOf('Copy of BOOK.pdf'), { base: 'BOOK.pdf', rank: 1 });
    assert.deepEqual(copyOf('page-10.png'), { base: 'page-10.png', rank: 0 });
});

test('files are queued in natural order, each original before its copies', () => {
    const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });
    const paths = [
        'scans/1 - Copy (10).pdf', 'scans/1 - Copy (2).pdf', 'scans/1 - Copy.pdf', 'scans/1.pdf',
        'scans/page-10.png', 'scans/page-2.png', 'other/a.pdf',
    ];
    assert.deepEqual([...paths].sort((a, b) => compareQueuePaths(a, b, collator)), [
        'other/a.pdf',
        'scans/1.pdf', 'scans/1 - Copy.pdf', 'scans/1 - Copy (2).pdf', 'scans/1 - Copy (10).pdf',
        'scans/page-2.png', 'scans/page-10.png',
    ]);
});
