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
                // Loaded only by the analytics page, which is the one screen that
                // charts anything. Keeping it out of app.js spares every other
                // page the download.
                'resources/js/sneat/apexcharts.js',
                // Own entry for the same reason: it pulls in PDF.js, and only the
                // scanning workspace and the template builder need it.
                'resources/js/field-marker.js',
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
                // SNEAT's SCSS does bare `@import "bootstrap/scss/..."`, which only
                // resolves if node_modules is on the Sass load path.
                loadPaths: ['node_modules'],
                quietDeps: true,
                silenceDeprecations: ['import', 'global-builtin', 'color-functions'],
            },
        },
    },
});
