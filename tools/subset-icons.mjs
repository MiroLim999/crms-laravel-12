/**
 * Subset iconify.css down to the icons CRMS actually uses.
 *
 * Reads the pristine SNEAT vendor stylesheet, keeps only the rules the app
 * references, and writes the trimmed result to resources/fonts/iconify/iconify.css.
 * Commit the output. Re-run whenever an icon is added or removed.
 *
 *   npm run subset-icons
 *
 * IMPORTANT — this used to read and write the same file, which made it a one-way
 * ratchet: every run could only remove icons, never restore one. Adding an icon to
 * a view and re-running appeared to succeed while silently leaving the icon out,
 * and it rendered as a blank box. SOURCE is now the untouched vendor file and is
 * never written to, so the script is idempotent and adding an icon works.
 *
 * The script also fails loudly on an icon name Iconify does not have, which is the
 * check that would have caught `bx-inbox` and `bx-baby-carriage` - neither exists
 * in this Boxicons build, so both had been rendering as nothing for a while.
 */

import { readFileSync, writeFileSync, readdirSync, statSync } from 'fs';
import { join, extname } from 'path';

/** Never written to. The single source of truth for what an icon looks like. */
const SOURCE = 'sneat/resources/assets/vendor/fonts/iconify/iconify.css';

/** Generated. Loaded by the app through Vite. */
const TARGET = 'resources/fonts/iconify/iconify.css';

/*
 * Icon names live in Blade, in JS that builds class strings, and in PHP - the
 * DocumentType enum and Navigation both carry them, so `app/` has to be scanned as
 * well or those icons get dropped.
 */
const SEARCH_DIRS = ['resources/views', 'resources/js', 'app'];
const SEARCH_EXTS = new Set(['.php', '.js', '.mjs', '.ts']);

const ICON_RE = /\bbxs?-[a-z0-9-]+\b/g;

/**
 * Names that look like icons to the regex but are not, or are wanted even though
 * nothing references them yet. An escape hatch for the hard failure below.
 */
const EXTRAS = new Set([
    'bx-copy', // used by future copy-to-clipboard buttons
]);

const IGNORE = new Set();

// ─── collect the icon names the app references ───────────────────────────────

function walk(dir) {
    const files = [];
    try {
        for (const name of readdirSync(dir)) {
            const full = join(dir, name);
            if (statSync(full).isDirectory()) files.push(...walk(full));
            else if (SEARCH_EXTS.has(extname(full))) files.push(full);
        }
    } catch {
        /* an absent directory is not an error */
    }
    return files;
}

/** icon name -> the files that reference it, for a useful error message. */
const used = new Map();

for (const dir of SEARCH_DIRS) {
    for (const file of walk(dir)) {
        const text = readFileSync(file, 'utf8');
        for (const [name] of text.matchAll(ICON_RE)) {
            if (IGNORE.has(name)) continue;
            if (!used.has(name)) used.set(name, new Set());
            used.get(name).add(file);
        }
    }
}

for (const name of EXTRAS) {
    if (!used.has(name)) used.set(name, new Set(['(EXTRAS in tools/subset-icons.mjs)']));
}

// ─── parse the vendor stylesheet ─────────────────────────────────────────────

const source = readFileSync(SOURCE, 'utf8');

// Every rule is a single un-nested selector block: `.name { --svg: url(...) }`.
// There are no at-rules and no shared selectors, which is what makes a plain
// block filter safe here.
const blocks = source.match(/\.[a-zA-Z][^{]*\{[^}]*\}/gs) ?? [];

/** icon name -> its rule. */
const rules = new Map();
let baseRule = null;

for (const block of blocks) {
    const selector = block.slice(0, block.indexOf('{')).trim();

    // `.bx` carries the mask/sizing every icon depends on.
    if (selector === '.bx') {
        baseRule = block;
        continue;
    }

    const match = /^\.(bxs?-[a-z0-9-]+)$/.exec(selector);
    if (match) rules.set(match[1], block);
}

if (!baseRule) {
    console.error(`ERROR: the base '.bx' rule was not found in ${SOURCE}.`);
    process.exit(1);
}

// ─── refuse to generate a stylesheet with holes in it ────────────────────────

const unknown = [...used].filter(([name]) => !rules.has(name));

if (unknown.length) {
    console.error('\nERROR: these icon names do not exist in Iconify:\n');

    for (const [name, files] of unknown.sort()) {
        // Suggest the nearest real names, which is usually the solid `bxs-` variant.
        const stem = name.replace(/^bxs?-/, '');
        const near = [...rules.keys()]
            .filter((k) => k.includes(stem) || stem.includes(k.replace(/^bxs?-/, '')))
            .slice(0, 6);

        console.error(`  ${name}`);
        console.error(`      referenced by: ${[...files].join(', ')}`);
        console.error(`      did you mean:  ${near.join(', ') || '(nothing similar)'}`);
    }

    console.error(
        '\nFix the name, or add it to IGNORE in tools/subset-icons.mjs if it is a\n' +
        'false positive. Nothing was written - an icon with no rule renders as a\n' +
        'blank box, so this fails rather than shipping one.\n',
    );

    process.exit(1);
}

// ─── write the subset ────────────────────────────────────────────────────────

const names = [...used.keys()].sort();
const output = [baseRule, ...names.map((name) => rules.get(name))].join('\n') + '\n';

writeFileSync(TARGET, output, 'utf8');

const before = source.length;
const after = output.length;

console.log(`Icons kept: ${names.size ?? names.length}`);
console.log(names.join(', '));
console.log(`\nSource: ${SOURCE}  (${(before / 1024).toFixed(1)} KB, ${rules.size} icons)`);
console.log(`Written: ${TARGET}  (${(after / 1024).toFixed(1)} KB)`);
console.log(`Kept ${names.length} of ${rules.size} available icons.`);
