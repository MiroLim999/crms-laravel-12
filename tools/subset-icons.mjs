/**
 * Generate the Boxicons CSS subset CRMS actually uses.
 *
 * The immutable source comes from @iconify-json/bx. The generated stylesheet is
 * committed and loaded through Vite. Run this whenever an icon reference changes:
 *
 *   npm run subset-icons
 *
 * CI/read-only verification can use:
 *
 *   npm run check:icons
 */

import { icons as boxicons } from '@iconify-json/bx';
import { getIconsCSS } from '@iconify/utils';
import { extname, join } from 'node:path';
import { readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';

const SOURCE = '@iconify-json/bx';
const TARGET = 'resources/fonts/iconify/iconify.css';
const SEARCH_DIRS = ['resources/views', 'resources/js', 'app'];
const SEARCH_EXTS = new Set(['.php', '.js', '.mjs', '.ts']);
const ICON_RE = /\bbx-[a-z0-9-]+\b/g;
const EXTRAS = new Set([
    'bx-copy', // reserved for copy-to-clipboard controls
]);
const IGNORE = new Set();

function walk(directory) {
    const files = [];

    try {
        for (const name of readdirSync(directory)) {
            const path = join(directory, name);

            if (statSync(path).isDirectory()) files.push(...walk(path));
            else if (SEARCH_EXTS.has(extname(path))) files.push(path);
        }
    } catch {
        // An optional search directory being absent is not an error.
    }

    return files;
}

/** icon class -> files that reference it, used for actionable errors. */
const used = new Map();

for (const directory of SEARCH_DIRS) {
    for (const file of walk(directory)) {
        const source = readFileSync(file, 'utf8');

        for (const [name] of source.matchAll(ICON_RE)) {
            if (IGNORE.has(name)) continue;
            if (!used.has(name)) used.set(name, new Set());
            used.get(name).add(file);
        }
    }
}

for (const name of EXTRAS) {
    if (!used.has(name)) used.set(name, new Set(['EXTRAS in tools/subset-icons.mjs']));
}

const sourceNames = [
    ...Object.keys(boxicons.icons),
    ...Object.keys(boxicons.aliases ?? {}),
];
const available = new Set(sourceNames.map((name) => `bx-${name}`));
const unknown = [...used].filter(([name]) => !available.has(name));

if (unknown.length > 0) {
    console.error('\nERROR: these icon names do not exist in Boxicons:\n');

    for (const [name, files] of unknown.sort()) {
        const stem = name.replace(/^bx-/, '');
        const near = [...available]
            .filter((candidate) => candidate.includes(stem)
                || stem.includes(candidate.replace(/^bx-/, '')))
            .slice(0, 6);

        console.error(`  ${name}`);
        console.error(`      referenced by: ${[...files].join(', ')}`);
        console.error(`      did you mean:  ${near.join(', ') || '(nothing similar)'}`);
    }

    console.error('\nFix the icon name before regenerating the stylesheet.\n');
    process.exit(1);
}

const names = [...used.keys()].sort();
const iconNames = names.map((name) => name.replace(/^bx-/, ''));
const output = `${getIconsCSS(boxicons, iconNames, {
    iconSelector: '.{prefix}-{name}',
    commonSelector: '.{prefix}',
    overrideSelector: '.{prefix}-{name}',
    format: 'expanded',
}).trimEnd()}\n`;

if (process.argv.includes('--check')) {
    const current = readFileSync(TARGET, 'utf8');

    if (current !== output) {
        console.error(`ERROR: ${TARGET} is stale. Run npm run subset-icons.`);
        process.exit(1);
    }

    console.log(`${TARGET} is current (${names.length} icons).`);
    process.exit(0);
}

writeFileSync(TARGET, output, 'utf8');

console.log(`Icons kept: ${names.length}`);
console.log(names.join(', '));
console.log(`\nSource: ${SOURCE} (${available.size} icons)`);
console.log(`Written: ${TARGET} (${(output.length / 1024).toFixed(1)} KB)`);
