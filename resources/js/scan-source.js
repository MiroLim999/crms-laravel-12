/**
 * The scanned file a record comes from, in the Staff workspace.
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

/** The largest PDF the workspace opens. Only one page of it is ever uploaded. */
export const MAX_PDF_BYTES = 200 * 1024 * 1024;

/** The largest file the server keeps as a record's scan (an image, or one PDF page). */
export const MAX_SCAN_BYTES = 20 * 1024 * 1024;

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
    if (subtle && globalThis.isSecureContext !== false) {
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
