/**
 * What stops a layout from being published, checked in the builder before
 * it is sent: the same rules the server applies (DocumentTemplateController
 * ::publishProblems), so the admin sees them in the Publish window first.
 *
 * - a sample page to check the layout against;
 * - real names instead of "Column 3" or "Field 2";
 * - one column or field that holds the person's name (page 1 or page 2).
 */

/** A name the builder gave and nobody changed: "Column 3", "Field 2". */
export function isDefaultName(name) {
    return /^(column|field)\s*\d+$/i.test(String(name ?? '').trim());
}

/**
 * @param {{ hasSample: boolean, pages: Array<Array<{ name?: string, role?: string|null }>> }} layout
 *   `pages`: each page's markers (fields and columns).
 * @returns {string[]} one sentence per problem, none when it can be published
 */
export function publishProblems({ hasSample, pages }) {
    const problems = [];
    const boxes = pages.flat();

    if (!hasSample) {
        problems.push('Add a sample page: open a scan of this book with "Choose sample".');
    }

    const unnamed = [...new Set(boxes.map((box) => String(box.name ?? '').trim()).filter(isDefaultName))];
    if (unnamed.length > 0) {
        const shown = unnamed.slice(0, 4);
        const more = unnamed.length - shown.length;
        problems.push(`Give every column a real name (still named: ${shown.join(', ')}${more > 0 ? ` and ${more} more` : ''}).`);
    }

    if (!boxes.some((box) => box.role === 'name')) {
        problems.push('Mark the column or field that holds the person\'s name (Field settings → Holds → Name).');
    }

    return problems;
}

/**
 * Row lines still shown amber (guessed by Detect, not checked by hand), counted
 * over every page.
 *
 * @param {Array<{ ruledYs: number[], estimated: Iterable<string> }>} pages
 * @param {(y: number) => string} keyOf the key a row line is remembered by
 */
export function guessedRowCount(pages, keyOf) {
    return pages.reduce((total, page) => {
        const estimated = new Set(page.estimated ?? []);
        return total + page.ruledYs.filter((y) => estimated.has(keyOf(y))).length;
    }, 0);
}
