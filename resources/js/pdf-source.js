/**
 * Opening a PDF the user picked, however large (up to MAX_PDF_BYTES, 1 GB).
 *
 * A PDF up to PDF_IN_MEMORY_BYTES is read whole, as before. A larger one is
 * never held in memory at once: PDF.js asks for the byte ranges it needs (the
 * cross-reference table, then the objects of the page being drawn) and each is
 * read from the file on disk as it is asked for. So a 1 GB book opens at once
 * and costs about what the pages drawn cost.
 */
import * as pdfjsLib from 'pdfjs-dist';

pdfjsLib.GlobalWorkerOptions.workerSrc = '/vendor/pdfjs/pdf.worker.min.mjs';

/** Up to this size a PDF is read into memory whole (quicker for small files). */
export const PDF_IN_MEMORY_BYTES = 128 * 1024 * 1024;

/** The piece of a large PDF read at a time. */
const RANGE_CHUNK_BYTES = 1024 * 1024;

/** A PDF.js document for `file` (a File or Blob). Destroy it when done. */
export function openPdfFile(file) {
    if (file.size <= PDF_IN_MEMORY_BYTES) {
        return file.arrayBuffer().then((data) => pdfjsLib.getDocument({ data }).promise);
    }

    const transport = new pdfjsLib.PDFDataRangeTransport(file.size, null);
    transport.requestDataRange = (begin, end) => {
        file.slice(begin, end).arrayBuffer().then(
            (bytes) => transport.onDataRange(begin, new Uint8Array(bytes)),
            (error) => console.error(`Bytes ${begin}-${end} of the PDF could not be read:`, error),
        );
    };
    return pdfjsLib.getDocument({
        range: transport,
        length: file.size,
        rangeChunkSize: RANGE_CHUNK_BYTES,
        // Only what a page needs is read, never the whole file in the background.
        disableAutoFetch: true,
        disableStream: true,
    }).promise;
}

/** The longest side of a page drawn as the record's image, in pixels. */
const PAGE_IMAGE_MAX_SIDE = 7000;

/**
 * One page of a PDF as a JPEG image file, about 300 DPI (at most
 * PAGE_IMAGE_MAX_SIDE on its longest side): what a record keeps of a page of a
 * PDF too large to cut a page out of. `name` is the file's name.
 */
export async function pdfPageImageFile(file, pageNumber, name) {
    const doc = await openPdfFile(file);
    try {
        const page = await doc.getPage(pageNumber);
        const size = page.getViewport({ scale: 1 });
        const scale = Math.min(300 / 72, PAGE_IMAGE_MAX_SIDE / Math.max(size.width, size.height));
        const viewport = page.getViewport({ scale });
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(viewport.width);
        canvas.height = Math.round(viewport.height);
        const context = canvas.getContext('2d');
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        await page.render({ canvasContext: context, viewport }).promise;
        const blob = await new Promise((resolve, reject) => {
            canvas.toBlob((made) => (made ? resolve(made) : reject(new Error('The page could not be drawn as an image.'))), 'image/jpeg', 0.92);
        });
        canvas.width = 0;
        canvas.height = 0;
        return new File([blob], name, { type: 'image/jpeg' });
    } finally {
        doc.destroy();
    }
}
