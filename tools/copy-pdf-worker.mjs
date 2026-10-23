// Copies PDF.js's worker into public/, so PDFs open without internet.
//
// field-marker.js points PDF.js at /vendor/pdfjs/pdf.worker.min.mjs. The
// worker is copied rather than bundled, which keeps the 2.2 MB file out of
// Vite's build. package.json runs this before every `npm run build` and
// `npm run dev`, so the copy always matches the installed pdfjs-dist.
import { copyFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const source = resolve(root, 'node_modules/pdfjs-dist/build/pdf.worker.min.mjs');
const target = resolve(root, 'public/vendor/pdfjs/pdf.worker.min.mjs');

if (!existsSync(source)) {
    console.error(`The PDF.js worker is missing (${relative(root, source)}). Run npm install first.`);
    process.exit(1);
}

mkdirSync(dirname(target), { recursive: true });
copyFileSync(source, target);
console.log(`PDF.js worker copied to ${relative(root, target)}`);
