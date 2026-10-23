import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import test from 'node:test';

const viewsRoot = new URL('../../resources/views/', import.meta.url);
const scriptsRoot = new URL('../../resources/js/', import.meta.url);
const sneatButtonVariant = /^btn-(?:primary|secondary|success|danger|warning|info|light|dark|link|outline-(?:primary|secondary|success|danger|warning|info|light|dark)|label-(?:primary|secondary|success|danger|warning|info|light|dark))$/;

function bladeFiles(directory = viewsRoot) {
    return readdirSync(directory).flatMap((name) => {
        const path = new URL(name, directory);

        if (statSync(path).isDirectory()) return bladeFiles(new URL(`${name}/`, directory));
        return name.endsWith('.blade.php') ? [path] : [];
    });
}

function javascriptFiles(directory = scriptsRoot) {
    return readdirSync(directory).flatMap((name) => {
        const path = new URL(name, directory);

        if (statSync(path).isDirectory()) return javascriptFiles(new URL(`${name}/`, directory));
        return name.endsWith('.js') ? [path] : [];
    });
}

function lineNumber(source, offset) {
    return source.slice(0, offset).split('\n').length;
}

test('Blade buttons use a Sneat or Bootstrap control component', () => {
    const invalid = [];

    for (const file of bladeFiles()) {
        const source = readFileSync(file, 'utf8');

        for (const match of source.matchAll(/<button\b[^>]*>/gis)) {
            const classes = match[0].match(/\bclass\s*=\s*"([^"]*)"/is)?.[1] ?? '';
            const tokens = new Set(classes.split(/\s+/));
            const supported = tokens.has('btn')
                || tokens.has('btn-close')
                || tokens.has('dropdown-item')
                || tokens.has('accordion-button');

            if (!supported) {
                invalid.push(`${file.pathname}:${lineNumber(source, match.index)} (${classes || 'no class'})`);
            }
        }
    }

    assert.deepEqual(invalid, [], `Buttons without a Sneat/Bootstrap base class:\n${invalid.join('\n')}`);
});

test('Sneat action controls use exactly one supported visual variant', () => {
    const invalid = [];

    for (const file of bladeFiles()) {
        const source = readFileSync(file, 'utf8');

        for (const match of source.matchAll(/<(?:button|a|label)\b[^>]*>/gis)) {
            const classes = match[0].match(/\bclass\s*=\s*"([^"]*)"/is)?.[1] ?? '';
            const tokens = classes.split(/\s+/).filter(Boolean);
            if (!tokens.includes('btn')) continue;

            const variants = tokens.filter((token) => sneatButtonVariant.test(token));
            const isSneatBorderlessDropdown = tokens.includes('p-0')
                && tokens.includes('dropdown-toggle')
                && tokens.includes('hide-arrow');

            if (variants.length !== 1 && !(variants.length === 0 && isSneatBorderlessDropdown)) {
                invalid.push(`${file.pathname}:${lineNumber(source, match.index)} (${classes})`);
            }
        }
    }

    assert.deepEqual(invalid, [], `Buttons with missing or conflicting Sneat variants:\n${invalid.join('\n')}`);
});

test('JavaScript-generated Sneat buttons use one supported visual variant', () => {
    const invalid = [];

    for (const file of javascriptFiles()) {
        const source = readFileSync(file, 'utf8');

        for (const match of source.matchAll(/\.className\s*=\s*(['"])([^'"]*\bbtn\b[^'"]*)\1/g)) {
            const tokens = match[2].split(/\s+/).filter(Boolean);
            const variants = tokens.filter((token) => sneatButtonVariant.test(token));

            if (variants.length !== 1) {
                invalid.push(`${file.pathname}:${lineNumber(source, match.index)} (${match[2]})`);
            }
        }
    }

    assert.deepEqual(invalid, [], `Generated buttons with missing or conflicting Sneat variants:\n${invalid.join('\n')}`);
});

test('Modal dismissal actions use Sneat label-secondary buttons', () => {
    const invalid = [];

    for (const file of bladeFiles()) {
        const source = readFileSync(file, 'utf8');

        for (const match of source.matchAll(/<button\b[^>]*\bdata-bs-dismiss\s*=\s*"modal"[^>]*>/gis)) {
            const classes = match[0].match(/\bclass\s*=\s*"([^"]*)"/is)?.[1] ?? '';
            const tokens = new Set(classes.split(/\s+/));

            if (tokens.has('btn') && !tokens.has('btn-label-secondary')) {
                invalid.push(`${file.pathname}:${lineNumber(source, match.index)} (${classes})`);
            }
        }
    }

    assert.deepEqual(invalid, [], `Modal dismissal buttons without btn-label-secondary:\n${invalid.join('\n')}`);
});

test('Blade Boxicons use Sneat icon-base markup', () => {
    const invalid = [];

    for (const file of bladeFiles()) {
        const source = readFileSync(file, 'utf8');

        for (const match of source.matchAll(/<(?:i|span)\b[^>]*\bclass\s*=\s*"([^"]*\bbx(?:\s|-[^\s"]+)[^"]*)"[^>]*>/gis)) {
            const tokens = new Set(match[1].split(/\s+/));

            if (!tokens.has('icon-base') || !tokens.has('bx')) {
                invalid.push(`${file.pathname}:${lineNumber(source, match.index)} (${match[1]})`);
            }
        }
    }

    assert.deepEqual(invalid, [], `Boxicons without Sneat icon-base/bx classes:\n${invalid.join('\n')}`);
});
