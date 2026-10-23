import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { extname, join } from 'node:path';
import test from 'node:test';

const root = new URL('../../', import.meta.url);
const searchableExtensions = new Set(['.js', '.mjs', '.php', '.ts']);
const iconPattern = /\bbx-[a-z0-9-]+\b/g;

function filesUnder(relativeDirectory) {
    const directory = new URL(`${relativeDirectory}/`, root);
    const files = [];

    for (const name of readdirSync(directory)) {
        const path = new URL(name, directory);

        if (statSync(path).isDirectory()) files.push(...filesUnder(`${relativeDirectory}/${name}`));
        else if (searchableExtensions.has(extname(name))) files.push(path);
    }

    return files;
}

test('every referenced Boxicon is present in the generated stylesheet', () => {
    const used = new Set(['bx-copy']);

    ['app', 'resources/js', 'resources/views'].flatMap(filesUnder).forEach((file) => {
        readFileSync(file, 'utf8').match(iconPattern)?.forEach((name) => used.add(name));
    });

    const stylesheet = readFileSync(new URL('resources/fonts/iconify/iconify.css', root), 'utf8');
    const defined = new Set(
        [...stylesheet.matchAll(/^\.(bx-[a-z0-9-]+)\s*\{/gm)].map((match) => match[1]),
    );
    const missing = [...used].filter((name) => !defined.has(name)).sort();

    assert.deepEqual(missing, [], `Missing generated icon rules: ${missing.join(', ')}`);
});
