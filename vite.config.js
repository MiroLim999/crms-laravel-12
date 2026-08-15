import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import path from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/fonts/iconify/iconify.css',
                'resources/scss/app.scss',
                'resources/css/app.css',
                'resources/js/app.js',
                // Page-scoped archive split view and shared disclosure animation.
                'resources/js/record-detail.js',
                'resources/js/disclosure-motion.js',
                // Grouped correction editor with live no-op detection.
                'resources/js/change-request.js',
                // Auth-page decoration (centred card, corner shapes). Only the
                // guest layout loads it, so it stays out of the main bundle.
                'resources/scss/pages/page-auth.scss',
                // Consolidated dashboard analytics. Its ApexCharts dependency is
                // isolated from app.js so non-dashboard pages do not pay for it.
                'resources/js/dashboard-analytics.js',
                // Own entry: pulls in the PDF.js module tree. Only the scanning
                // workspace and the template builder use it.
                // NOTE: pdf.worker.mjs is now served from the CDN (see field-marker.js).
                //       Do not add `pdfjs-dist/build/pdf.worker.mjs?url` back here.
                'resources/js/field-marker.js',
                // Shared Windows-style drag selection used by both marker editors.
                'resources/js/marquee-selection.js',
                // Template Builder interaction shell. It shares FieldMarker with
                // Staff scanning but is only loaded on Super Admin layout pages.
                'resources/js/template-builder.js',
                // OCR workspace layout refinements. Loaded from the page's head
                // stack to avoid a flash of the uncomposed cards before JS runs.
                'resources/scss/pages/ocr-workspace.scss',
                // Own entry: the OCR workspace's chunked upload, drag-and-drop, and
                // job polling. Only that one page loads it.
                'resources/js/ocr-workspace.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],

    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources'),
        },
    },

    css: {
        preprocessorOptions: {
            scss: {
                // SNEAT's SCSS does bare `@import "bootstrap/scss/..."`, which
                // only resolves if node_modules is on the Sass load path.
                loadPaths: ['node_modules'],
                quietDeps: true,
                silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'if-function'],
            },
        },
    },

    build: {
        // Report chunks larger than this — keeps bundle growth visible.
        chunkSizeWarningLimit: 600,

        rollupOptions: {
            // field-marker.js is both a Vite entry and a module imported by the
            // inline template/scanning workspaces. Rollup normally removes exports
            // from application entries because it assumes nobody imports the built
            // file. Preserve its public exports so `import { FieldMarker } from
            // Vite::asset(...)` continues to work in production builds.
            preserveEntrySignatures: 'exports-only',
            output: {
                // Vendor code changes less often than app code, so split it into
                // its own chunk to get longer cache hits in production.
                manualChunks(id) {
                    // Bootstrap JS and Popper share a chunk because they're both
                    // loaded on every page and always version-locked together.
                    if (id.includes('node_modules/bootstrap')
                        || id.includes('node_modules/@popperjs')) {
                        return 'vendor-bootstrap';
                    }

                    // Perfect Scrollbar is small but loaded on every page via app.js.
                    if (id.includes('node_modules/perfect-scrollbar')) {
                        return 'vendor-scrollbar';
                    }

                    // PDF.js is large (~364 KB) and only the scanning workspace needs
                    // it. Bundling it with app.js would penalise every other page.
                    // It already has its own entry (field-marker.js), so this keeps
                    // the internal dependency out of the shared chunk.
                    if (id.includes('node_modules/pdfjs-dist')) {
                        return 'vendor-pdfjs';
                    }

                    // ApexCharts enters through the page-scoped dashboard and OCR
                    // analytics bundles, which keeps it out of app.js. Mark it
                    // explicitly so Rollup retains a separately cached vendor chunk.
                    if (id.includes('node_modules/apexcharts')) {
                        return 'vendor-apexcharts';
                    }
                },
            },
        },
    },
});
