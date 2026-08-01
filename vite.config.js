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
