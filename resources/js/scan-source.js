/**
 * The scanned file a record comes from, in the scan workspace.
 *
 * A register book usually arrives as one PDF with a page per register page.
 * Each page is read and saved as its own record. The record keeps that page
 * alone, cut out of the PDF here in the browser with its original scan quality
 * (pdf-lib copies the page's own image, nothing is redrawn), so the server is
 * never sent the whole book. It also keeps the file's name, the page number and
 * the file's fingerprint (its SHA-256), which is how the workspace tells which
 * pages of that exact file are already saved, even when two files share a name.
 *
 * The batch upload builds on the same helpers: one queue item per page.
 */

/** The largest PDF the workspace opens: 1 GB. Only one page of it is ever uploaded. */
export const MAX_PDF_BYTES = 1024 * 1024 * 1024;

/** The largest image the workspace opens. */
export const MAX_SCAN_BYTES = 20 * 1024 * 1024;

/**
 * The largest file the server takes as a scan (App\Support\Limits::MAX_SCAN_KB):
 * a PDF up to this size may be sent whole, or a page cut out of it; past it a
 * page goes as an image of that page (pdfPageFile).
 */
export const MAX_SENT_PDF_BYTES = 200 * 1024 * 1024;

export function isPdfFile(file) {
    return file?.type === 'application/pdf' || /\.pdf$/i.test(String(file?.name ?? ''));
}

// ---------------------------------------------------------------- fingerprint

/**
 * The file's SHA-256, as 64 lowercase hex digits. The browser's own digest is
 * used where it exists; it does not on a page opened over plain http from
 * another computer (an office server reached by its address), so the same
 * digest is worked out here in that case. Both give the same fingerprint.
 */
export async function fileFingerprint(file) {
    const subtle = globalThis.crypto?.subtle;
    // A large file (up to 1 GB) is read a piece at a time, never all at once.
    if (subtle && globalThis.isSecureContext !== false && file.size <= 256 * 1024 * 1024) {
        try {
            return toHex(new Uint8Array(await subtle.digest('SHA-256', await file.arrayBuffer())));
        } catch {
            // Fall through to the digest below.
        }
    }

    const hash = new Sha256();
    const chunk = 4 * 1024 * 1024;
    for (let start = 0; start < file.size; start += chunk) {
        hash.update(new Uint8Array(await file.slice(start, start + chunk).arrayBuffer()));
    }
    return hash.hex();
}

/** SHA-256 of some bytes, worked out without the browser's digest (see above). */
export function sha256Hex(bytes) {
    const hash = new Sha256();
    hash.update(bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes));
    return hash.hex();
}

function toHex(bytes) {
    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

const ROUND = new Uint32Array([
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
]);

/** SHA-256 (FIPS 180-4), fed in pieces so a large file is never held twice. */
class Sha256 {
    constructor() {
        this.state = new Uint32Array([
            0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
        ]);
        this.block = new Uint8Array(64);
        this.blockLength = 0;
        this.length = 0;
        this.words = new Uint32Array(64);
    }

    update(bytes) {
        let offset = 0;
        this.length += bytes.length;

        if (this.blockLength > 0) {
            const take = Math.min(64 - this.blockLength, bytes.length);
            this.block.set(bytes.subarray(0, take), this.blockLength);
            this.blockLength += take;
            offset = take;
            if (this.blockLength < 64) return;
            this.compress(this.block, 0);
            this.blockLength = 0;
        }

        for (; offset + 64 <= bytes.length; offset += 64) this.compress(bytes, offset);

        if (offset < bytes.length) {
            this.block.set(bytes.subarray(offset), 0);
            this.blockLength = bytes.length - offset;
        }
    }

    hex() {
        // The padding: a 1 bit, zeros, then the length in bits as 64 bits.
        const bits = this.length * 8;
        const tail = new Uint8Array(this.blockLength < 56 ? 64 - this.blockLength : 128 - this.blockLength);
        tail[0] = 0x80;
        const view = new DataView(tail.buffer);
        view.setUint32(tail.length - 8, Math.floor(bits / 0x100000000));
        view.setUint32(tail.length - 4, bits >>> 0);
        this.update(tail);

        return Array.from(this.state, (word) => word.toString(16).padStart(8, '0')).join('');
    }

    compress(bytes, offset) {
        const w = this.words;
        for (let i = 0; i < 16; i += 1) {
            const at = offset + i * 4;
            w[i] = (bytes[at] << 24) | (bytes[at + 1] << 16) | (bytes[at + 2] << 8) | bytes[at + 3];
        }
        for (let i = 16; i < 64; i += 1) {
            const a = w[i - 15];
            const b = w[i - 2];
            const s0 = ((a >>> 7) | (a << 25)) ^ ((a >>> 18) | (a << 14)) ^ (a >>> 3);
            const s1 = ((b >>> 17) | (b << 15)) ^ ((b >>> 19) | (b << 13)) ^ (b >>> 10);
            w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
        }

        let [a, b, c, d, e, f, g, h] = this.state;
        for (let i = 0; i < 64; i += 1) {
            const s1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
            const choice = (e & f) ^ (~e & g);
            const t1 = (h + s1 + choice + ROUND[i] + w[i]) | 0;
            const s0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
            const majority = (a & b) ^ (a & c) ^ (b & c);
            const t2 = (s0 + majority) | 0;
            h = g;
            g = f;
            f = e;
            e = (d + t1) | 0;
            d = c;
            c = b;
            b = a;
            a = (t1 + t2) | 0;
        }

        const s = this.state;
        s[0] += a; s[1] += b; s[2] += c; s[3] += d;
        s[4] += e; s[5] += f; s[6] += g; s[7] += h;
    }
}

// ---------------------------------------------------------------- one page

// A PDF is read once, then every page cut from it reuses that reading.
const readPdfs = new WeakMap();

/**
 * Page `pageNumber` (from 1) of a PDF as a PDF of its own, ready to be saved
 * as one record's scan. pdf-lib is loaded the first time this is needed.
 */
export async function pdfPageFile(file, pageNumber) {
    // Too large to cut a page out of (that reads the whole PDF into memory) or to
    // send: the page goes as an image of it, drawn reading only what it needs.
    if (file.size > MAX_SENT_PDF_BYTES) {
        const { pdfPageImageFile } = await import('./pdf-source.js');
        return pdfPageImageFile(file, pageNumber, pageFileName(file.name, pageNumber).replace(/\.pdf$/i, '.jpg'));
    }
    const { PDFDocument } = await import('pdf-lib');

    let reading = readPdfs.get(file);
    if (!reading) {
        reading = file.arrayBuffer().then((bytes) => PDFDocument.load(bytes, { updateMetadata: false }));
        readPdfs.set(file, reading);
        reading.catch(() => readPdfs.delete(file));
    }
    const source = await reading;

    const count = source.getPageCount();
    if (!Number.isInteger(pageNumber) || pageNumber < 1 || pageNumber > count) {
        throw new RangeError(`This PDF has no page ${pageNumber}: it has ${count}.`);
    }

    const single = await PDFDocument.create({ updateMetadata: false });
    const [page] = await single.copyPages(source, [pageNumber - 1]);
    single.addPage(page);
    const bytes = await single.save();

    return new File([bytes], pageFileName(file.name, pageNumber), { type: 'application/pdf' });
}

/** "BIRTHS-1995.pdf", page 3 -> "BIRTHS-1995 (page 3).pdf" */
export function pageFileName(name, pageNumber) {
    const base = String(name ?? '').replace(/\.pdf$/i, '') || 'scan';
    return `${base} (page ${pageNumber}).pdf`;
}

// ---------------------------------------------------------------- which page

/**
 * The page to open a file at: the first one not saved yet, or page 1 when
 * every page is (or none is). `saved` holds the saved page numbers.
 */
export function firstUnsavedPage(pageCount, saved) {
    for (let page = 1; page <= pageCount; page += 1) {
        if (!saved.has(page)) return page;
    }
    return 1;
}

/**
 * The page to go on with after `current` was saved: the next one not saved
 * yet, else the first not saved before it, else null (every page is saved).
 */
export function nextUnsavedPage(current, pageCount, saved) {
    for (let page = current + 1; page <= pageCount; page += 1) {
        if (!saved.has(page)) return page;
    }
    for (let page = 1; page < current; page += 1) {
        if (!saved.has(page)) return page;
    }
    return null;
}

/**
 * The first page of the record a page of a file is saved in, the same in
 * single and batch mode: a page is its own record, unless the layout has a
 * page 2 (`pairs`), when pages go two at a time like an open book (1–2,
 * 3–4) and a lone last page is a record of its own.
 */
export function recordLeadPage(pageNumber, pageCount, pairs) {
    const page = Math.max(1, Math.trunc(Number(pageNumber)) || 1);
    if (!pairs || !(pageCount > 1)) return page;
    return page % 2 === 1 ? page : page - 1;
}

/** Page numbers as short runs: [1, 2, 3, 5, 7, 8] -> "1–3, 5 and 7–8". */
export function pageRanges(pages) {
    const runs = [];
    [...pages].sort((a, b) => a - b).forEach((page) => {
        const run = runs[runs.length - 1];
        if (run && page === run[1] + 1) run[1] = page;
        else if (!run || page !== run[1]) runs.push([page, page]);
    });

    const parts = runs.map(([first, last]) => (first === last ? String(first) : `${first}–${last}`));
    return parts.length > 1 ? `${parts.slice(0, -1).join(', ')} and ${parts[parts.length - 1]}` : (parts[0] ?? '');
}

// ---------------------------------------------------------------- batch pages

/*
 * A batch is a queue of pages (one item each). What can be done with a page
 * is decided by its own status, not by one phase for the whole batch, so a
 * page can be aligned or read again while others are already in Verify.
 *
 *   pending    not aligned and queued yet (or sent back to Align)
 *   saving     being sent to the queue
 *   saved      queued, not read yet ("Queued")
 *   scanning   being read ("Reading")
 *   ready      read, waiting to be verified ("Scanned")
 *   verifying  open in Verify or Review & Export
 *   imported   saved as a record ("Saved")
 *   rejected   left out of the batch ("Rejected", or "Skipped")
 *   failed     could not be read or queued (item.error says why)
 */

/** Statuses of a page that is finished with: saved as a record, or left out. */
export const PAGE_DONE = Object.freeze(['imported', 'rejected']);

/** Statuses of a page that has been read. */
export const PAGE_READ = Object.freeze(['ready', 'verifying']);

export function pageDone(item) {
    return PAGE_DONE.includes(item?.status);
}

/** Whether a page may be opened in Align: not queued yet, queued but not read, or failed. */
export function pageCanAlign(item) {
    return ['pending', 'saved', 'failed'].includes(item?.status);
}

/**
 * One record's state, from its pages: `done` (every page saved or left out),
 * `blocked` (a page failed), `ready` (every page read, so it can be verified)
 * or `waiting` (a page is not read yet).
 */
export function recordState(members) {
    const pages = Array.from(members ?? []);
    if (pages.every(pageDone)) return 'done';
    if (pages.some((item) => item.status === 'failed')) return 'blocked';
    if (pages.every((item) => pageDone(item) || PAGE_READ.includes(item.status))) return 'ready';
    return 'waiting';
}

/** Each record's state (see recordState), by its key: records are `{ key, members }`. */
export function recordReadiness(records) {
    return new Map(Array.from(records ?? [], (record) => [record.key, recordState(record.members)]));
}

/**
 * The phase the batch bar shows, worked out from the pages: `aligning`
 * while a page still has to be aligned or queued (or waits for Scan all),
 * `reading` while pages are read and none waits for Align, `verifying`
 * while read records wait, `done` once every page is saved or left out,
 * and `idle` for an empty queue.
 */
export function batchSummaryPhase(items) {
    const pages = Array.from(items ?? []);
    if (pages.length === 0) return 'idle';
    const any = (...statuses) => pages.some((item) => statuses.includes(item.status));
    if (any('pending', 'saving')) return 'aligning';
    if (any('scanning')) return 'reading';
    if (any('saved')) return 'aligning';
    if (any(...PAGE_READ)) return 'verifying';
    if (any('failed')) return 'aligning';
    return 'done';
}

/** "hash#page": one page of one exact file, or null when the file has no fingerprint. */
export function pageKey(item) {
    return item?.sourceHash ? `${item.sourceHash}#${item.pageNo ?? 1}` : null;
}

/**
 * The queue with pages added to it: they go after the pages already queued,
 * in the order given, so the page being worked on stays where it is. A page
 * the queue has already (same file fingerprint and page) is not added again,
 * unless the user asked for it (`addedAnyway`, from the left-out window) or the
 * duplicate file check is off (`allowCopies`).
 */
export function appendToQueue(items, added, { allowCopies = false } = {}) {
    const queue = Array.from(items ?? []);
    const seen = new Set(queue.map(pageKey).filter(Boolean));
    for (const item of added ?? []) {
        const key = pageKey(item);
        if (key !== null && seen.has(key) && !item.addedAnyway && !allowCopies) continue;
        if (key !== null) seen.add(key);
        queue.push(item);
    }
    return queue;
}

/** Whether a page needs the user: it failed (to be re-aligned, queued again, or removed). */
export function pageNeedsAttention(item) {
    return item?.status === 'failed';
}

/** The queue panel's filters: which pages each one shows. */
export const QUEUE_FILTERS = Object.freeze({
    all: () => true,
    attention: pageNeedsAttention,
    pending: (item) => item?.status === 'pending',
    saved: (item) => item?.status === 'imported',
});

/**
 * The queue's totals and filter counts: `total` pages; `queued` (sent to the
 * queue: queued, being read, read, or done); `read` (read, being verified,
 * saved, or left out after reading); `saved` (saved as records); and one
 * count per filter (see QUEUE_FILTERS), plus one per status in `byStatus`.
 */
export function queueCounts(items) {
    const pages = Array.from(items ?? []);
    const count = (test) => pages.filter(test).length;
    const byStatus = {};
    pages.forEach((item) => {
        byStatus[item.status] = (byStatus[item.status] ?? 0) + 1;
    });
    return {
        total: pages.length,
        queued: count((item) => ['saved', 'scanning', 'ready', 'verifying', 'imported', 'rejected'].includes(item.status)),
        read: count((item) => ['ready', 'verifying', 'imported'].includes(item.status) || (item.status === 'rejected' && !item.skipped)),
        saved: count((item) => item.status === 'imported'),
        failed: count((item) => item.status === 'failed'),
        filters: Object.fromEntries(Object.entries(QUEUE_FILTERS).map(([name, test]) => [name, count(test)])),
        byStatus,
    };
}

// ---------------------------------------------------------------- resume a batch

/** How a page's status kept on the server comes back (one being saved is not queued). */
const RESTORED_STATUS = {
    pending: 'pending',
    saving: 'pending',
    saved: 'saved',
    scanning: 'scanning',
    ready: 'ready',
    verifying: 'ready',
    imported: 'imported',
    rejected: 'rejected',
    failed: 'failed',
};

/**
 * A page that needs its original file again (to be aligned): not queued yet, or
 * failed. Not a page sent back by Re-align that is drawn from the copy the server
 * keeps (`serverCopy`).
 */
export function pageNeedsFile(item) {
    return !item?.file && !item?.serverCopy && ['pending', 'failed'].includes(item?.status);
}

/**
 * The workspace's queue, rebuilt from a batch kept on the server (as
 * documents.batches.show sends it). Every page comes back without its file:
 * pages queued already need none, and the others wait until their file is
 * picked again (matchFilesToBatch). A page the server no longer has comes
 * back failed. Each record's draft comes back on its first page. With
 * `layoutChanged`, pages not queued yet drop their markers, so they start on
 * the layout published now.
 *
 * @returns {{ key: string, items: object[] }}
 */
export function rebuildBatchState(serverBatch, { layoutChanged = false } = {}) {
    const drafts = new Map((serverBatch?.drafts ?? []).map((entry) => [entry.recordKey, entry.draft ?? {}]));
    const pages = [...(serverBatch?.pages ?? [])].sort((a, b) => (a.position ?? 0) - (b.position ?? 0));

    const items = pages.map((page) => {
        const flags = page.flags ?? {};
        const pageNo = Number(page.pageNo) || 1;
        const pageTotal = Number(page.pageTotal) || 1;
        const sourceName = String(page.sourceName ?? 'scan');
        let status = RESTORED_STATUS[page.status] ?? 'pending';
        let error = page.error ?? null;
        // Queued or read, but the server no longer has the page: it is aligned and queued again.
        if (['saved', 'scanning', 'ready'].includes(status) && !page.documentPageId) {
            status = 'failed';
            error = 'This page is no longer on the server. Re-align it and queue it again.';
        }
        const alignment = page.alignment ?? null;
        const keepAlignment = Boolean(alignment?.boxes) && !(layoutChanged && ['pending', 'failed'].includes(status));

        return {
            key: page.key,
            file: null,
            name: pageTotal > 1 ? pageFileName(sourceName, pageNo) : sourceName,
            group: String(page.recordKey ?? page.key).replace(/#\d+$/, ''),
            record: page.recordKey ?? page.key,
            pageNo,
            pageTotal,
            sourceName,
            sourceHash: page.sourceHash ?? null,
            status,
            pageId: page.documentPageId ?? null,
            page: page.documentPageId && page.statusUrl ? { id: page.documentPageId, statusUrl: page.statusUrl } : null,
            workspaceScanPath: page.workspaceScanPath ?? null,
            scanKind: flags.scanKind ?? 'full',
            geometry: alignment?.geometry ?? null,
            recordUrl: page.recordUrl ?? flags.recordUrl ?? null,
            recordId: page.recordId ?? flags.recordId ?? null,
            error,
            boxes: keepAlignment ? alignment.boxes : null,
            rows: keepAlignment ? (alignment.rows ?? []) : null,
            tilts: keepAlignment ? (alignment.tilts ?? null) : null,
            autoFields: keepAlignment && Array.isArray(alignment.autoFields) ? new Map(alignment.autoFields) : null,
            detected: null,
            skipped: Boolean(flags.skipped),
            existingAccepted: Boolean(flags.existingAccepted),
            realign: Boolean(flags.realign) && status === 'pending',
            // Sent back by Re-align without its file: drawn from the server's copy.
            serverCopy: Boolean(flags.serverCopy) && status === 'pending',
            addedAnyway: Boolean(flags.addedAnyway),
            // Step 1's book and page, as they were entered.
            ...(flags.bookNo ? { bookNo: String(flags.bookNo) } : {}),
            ...(flags.pageNoRef ? { pageNoRef: String(flags.pageNoRef) } : {}),
            // Save & next pressed on it (Done): saved with the rest by Save & finish
            // batch, with the answers given then ({ allowMissing, allowDuplicate }).
            approved: ['ready', 'verifying'].includes(status) && flags.approved && typeof flags.approved === 'object'
                ? { allowMissing: Boolean(flags.approved.allowMissing), allowDuplicate: Boolean(flags.approved.allowDuplicate) }
                : null,
            // Being read when the batch was left: the workspace watches it again.
            sentToScan: status === 'scanning',
            sentAt: page.position ?? 0,
            verifyDraft: null,
            reviewExtras: null,
            exportTable: null,
        };
    });

    // Each record's draft and saved table, on its first page.
    const leads = new Map();
    items.forEach((item) => {
        const lead = leads.get(item.record);
        if (!lead || item.pageNo < lead.pageNo) leads.set(item.record, item);
    });
    leads.forEach((lead, recordKey) => {
        const draft = drafts.get(recordKey);
        if (!draft) return;
        if (Array.isArray(draft.verify)) {
            lead.verifyDraft = new Map(draft.verify.map((entry) => [entry.line, { value: String(entry.value ?? ''), verified: Boolean(entry.verified) }]));
        }
        if (Array.isArray(draft.review)) lead.reviewExtras = new Map(draft.review);
        // Left on Review & Export: the workspace opens it there again.
        lead.inReview = draft.step === 'review';
        if (Array.isArray(draft.exportTable)) lead.exportTable = draft.exportTable;
    });

    return { key: serverBatch?.key ?? null, items };
}

/**
 * Files picked again on resume, matched to the batch's pages by their
 * fingerprint, so a renamed file still matches. `files` are `{ file, hash }`.
 * Returns the file for each fingerprint the batch has (`matched`), the names
 * of picked files the batch has no page of (`unmatched`), and the files still
 * wanted: pages that need their file (pageNeedsFile) and got none
 * (`missing`, each `{ sourceName, sourceHash, pages }`).
 */
export function matchFilesToBatch(files, pages) {
    const known = new Set();
    const wanted = new Map();
    Array.from(pages ?? []).forEach((page) => {
        if (!page.sourceHash) return;
        known.add(page.sourceHash);
        if (!pageNeedsFile(page)) return;
        const entry = wanted.get(page.sourceHash) ?? { sourceName: page.sourceName ?? page.name, sourceHash: page.sourceHash, pages: 0 };
        entry.pages += 1;
        wanted.set(page.sourceHash, entry);
    });

    const matched = new Map();
    const unmatched = [];
    Array.from(files ?? []).forEach(({ file, hash }) => {
        if (hash && known.has(hash)) {
            if (!matched.has(hash)) matched.set(hash, file);
        } else {
            unmatched.push(file?.name ?? 'file');
        }
    });

    // Pages with no fingerprint cannot be matched: they stay wanted.
    const nameless = Array.from(pages ?? []).filter((page) => !page.sourceHash && pageNeedsFile(page));
    const missing = [...wanted.values()].filter((entry) => !matched.has(entry.sourceHash));
    if (nameless.length > 0) {
        missing.push({ sourceName: nameless[0].sourceName ?? nameless[0].name, sourceHash: null, pages: nameless.length });
    }

    return { matched, unmatched, missing };
}

// ---------------------------------------------------------------- queue order

/**
 * A file name as an original and how far a copy it is: "BOOK - Copy (2).pdf"
 * -> { base: "BOOK.pdf", rank: 2 }. Copies as Windows, macOS and browsers name
 * them ("- Copy", "- Copy (n)", " copy", " copy n", "Copy of …", " (n)");
 * an original has rank 0.
 */
export function copyOf(name) {
    const text = String(name ?? '');
    const dot = text.lastIndexOf('.');
    const ext = dot > 0 ? text.slice(dot) : '';
    let stem = dot > 0 ? text.slice(0, dot) : text;
    let rank = 0;
    const patterns = [
        [/\s+-\s+copy\s*\((\d+)\)$/i, (m) => Number(m[1])],
        [/\s+-\s+copy$/i, () => 1],
        [/\s+copy\s+(\d+)$/i, (m) => Number(m[1])],
        [/\s+copy$/i, () => 1],
        [/\s*\((\d+)\)$/, (m) => Number(m[1])],
    ];
    for (const [pattern, rankOf] of patterns) {
        const match = stem.match(pattern);
        if (match) {
            rank = rankOf(match);
            stem = stem.slice(0, match.index);
            break;
        }
    }
    const copyOfPrefix = stem.match(/^copy(?:\s*\((\d+)\))?\s+of\s+/i);
    if (rank === 0 && copyOfPrefix) {
        rank = Number(copyOfPrefix[1] ?? 1);
        stem = stem.slice(copyOfPrefix[0].length);
    }
    return { base: `${stem}${ext}`, rank };
}

/**
 * The order files are queued in: a folder's own order, "page-2" before
 * "page-10" (`collator` compares numbers as numbers), and an original before
 * its copies, so of identical files the one queued is the original.
 */
export function compareQueuePaths(a, b, collator) {
    const split = (path) => {
        const text = String(path ?? '');
        const slash = Math.max(text.lastIndexOf('/'), text.lastIndexOf('\\'));
        return { dir: text.slice(0, slash + 1), ...copyOf(text.slice(slash + 1)) };
    };
    const left = split(a);
    const right = split(b);
    return collator.compare(left.dir, right.dir)
        || collator.compare(left.base, right.base)
        || left.rank - right.rank
        || collator.compare(String(a ?? ''), String(b ?? ''));
}

// ---------------------------------------------------------------- files kept in this browser

const FILE_STORE = { db: 'crms-batch-files', store: 'files', maxAgeMs: 7 * 24 * 60 * 60 * 1000 };

function openFileStore() {
    return new Promise((resolve, reject) => {
        if (typeof indexedDB === 'undefined') {
            reject(new Error('No IndexedDB'));
            return;
        }
        const request = indexedDB.open(FILE_STORE.db, 1);
        request.onupgradeneeded = () => request.result.createObjectStore(FILE_STORE.store);
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

/** Run one request on the store; resolves to its result, or null if the browser will not keep files. */
async function fileStoreRun(mode, action) {
    try {
        const db = await openFileStore();
        return await new Promise((resolve) => {
            const tx = db.transaction(FILE_STORE.store, mode);
            const request = action(tx.objectStore(FILE_STORE.store));
            tx.oncomplete = () => { db.close(); resolve(request?.result ?? null); };
            tx.onerror = () => { db.close(); resolve(null); };
            tx.onabort = () => { db.close(); resolve(null); };
        });
    } catch {
        return null;
    }
}

/**
 * Keeps a picked file in this browser (never uploaded), by its fingerprint, so a
 * resumed batch needs no re-pick. Silent if the browser refuses (private window, full disk).
 */
export function rememberBatchFile(hash, file) {
    if (!hash || !file) return Promise.resolve(null);
    return fileStoreRun('readwrite', (store) => store.put({ file, savedAt: Date.now() }, hash));
}

/** The kept files for these fingerprints, as `{ file, hash }`; files older than a week are dropped. */
export async function recallBatchFiles(hashes) {
    const found = [];
    for (const hash of new Set(hashes.filter(Boolean))) {
        // eslint-disable-next-line no-await-in-loop
        const entry = await fileStoreRun('readonly', (store) => store.get(hash));
        if (entry?.file && Date.now() - (entry.savedAt ?? 0) < FILE_STORE.maxAgeMs) found.push({ file: entry.file, hash });
    }
    return found;
}

/** Lets go of kept files (a discarded or finished batch). */
export async function forgetBatchFiles(hashes) {
    for (const hash of new Set(hashes.filter(Boolean))) {
        // eslint-disable-next-line no-await-in-loop
        await fileStoreRun('readwrite', (store) => store.delete(hash));
    }
}
