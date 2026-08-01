/**
 * Subset iconify.css to only the selectors actually used in CRMS.
 *
 * Reads icon names from Blade views, PHP, and JS files, pulls those selectors
 * from the full iconify.css, and overwrites it with the trimmed version.
 * Commit the result. Re-run whenever icons are added.
 *
 * Usage: node _subset-icons.mjs
 */

import { readFileSync, writeFileSync, readdirSync, statSync } from 'fs';
import { join, extname } from 'path';

// ─── collect icon names from source ──────────────────────────────────────────

const SEARCH_DIRS = ['resources/views', 'resources/js'];
const SEARCH_EXTS = new Set(['.blade.php', '.php', '.js', '.mjs', '.ts']);

function walk(dir) {
    const entries = [];
    try {
        for (const name of readdirSync(dir)) {
            const full = join(dir, name);
            const s = statSync(full);
            if (s.isDirectory()) entries.push(...walk(full));
            else if (SEARCH_EXTS.has(extname(full))) entries.push(full);
        }
    } catch { /* skip unreadable dirs */ }
    return entries;
}

const ICON_RE = /\bbx[s]?-[a-z0-9-]+\b/g;
const used = new Set();

for (const dir of SEARCH_DIRS) {
    for (const file of walk(dir)) {
        const text = readFileSync(file, 'utf8');
        for (const [m] of text.matchAll(ICON_RE)) {
            used.add(m);
        }
    }
}

// Static extras that are generated at runtime or easy to miss in a regex scan.
for (const name of [
    'bx-baby-carriage',   // DocumentType::Birth icon in PHP enum
    'bx-heart',           // DocumentType::Marriage icon
    'bx-home-smile',      // Dashboard sidebar item in Navigation.php
    'bx-chevron-left',    // Sidebar collapse toggle (layouts/partials/sidebar)
    'bx-menu',            // Navbar hamburger
    'bx-user',            // User accounts nav
    'bx-layout',          // Template builder nav
    'bx-power-off',       // Sign out dropdown
    'bx-cog',             // Account settings dropdown
    'bx-wrench',          // Empty-state icon on placeholder pages
    'bx-minus-circle',    // Dashboard capability list
    'bx-copy',            // Copy button (future-proof)
]) {
    used.add(name);
}

console.log(`Unique icons to keep: ${used.size}`);
console.log([...used].sort().join(', '));

// ─── parse CSS and subset ─────────────────────────────────────────────────────

const css = readFileSync('resources/fonts/iconify/iconify.css', 'utf8');

// Each rule is a single un-nested selector block: .class-name { ... }
const blocks = css.match(/\.[a-zA-Z][^{]*\{[^}]*\}/gs) ?? [];

const kept = blocks.filter((block) => {
    const head = block.trimStart();

    // The base display rule every icon depends on.
    if (/^\.bx\s*\{/.test(head)) return true;

    // SNEAT size modifiers: icon-base, icon-sm, icon-md, icon-lg, icon-xl.
    if (/^\.(icon-base|icon-sm|icon-md|icon-lg|icon-xl)\s*\{/.test(head)) return true;

    // Keep the block if any used icon name appears in its selector.
    return [...used].some((name) => head.includes('.' + name));
});

const output = kept.join('\n') + '\n';

const before = css.length;
const after = output.length;
const pct = (100 - (after / before * 100)).toFixed(1);

writeFileSync('resources/fonts/iconify/iconify.css', output, 'utf8');

console.log(`\nSubset written to resources/fonts/iconify/iconify.css`);
console.log(`Before: ${(before / 1024).toFixed(1)} KB`);
console.log(`After:  ${(after / 1024).toFixed(1)} KB  (${pct}% smaller)`);
console.log(`Kept ${kept.length} / ${blocks.length} blocks`);
