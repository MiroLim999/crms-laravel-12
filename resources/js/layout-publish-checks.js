/**
 * What stops a layout from being published, checked in the builder before
 * it is sent: the same rules the server applies (DocumentTemplateController
 * ::publishProblems), so the admin sees them in the Publish window first.
 *
 * - a sample page to check the layout against;
 * - real names instead of "Column 3" or "Field 2";
 * - one column or field that holds the person's name (page 1 or page 2);
 * - on a two-page layout: no name on both pages, and the same number of rows
 *   on both (row N of each page is one person).
 */

/** A name the builder gave and nobody changed: "Column 3", "Field 2". */
export function isDefaultName(name) {
    return /^(column|field)\s*\d+$/i.test(String(name ?? '').trim());
}

/**
 * @param {{ hasSample: boolean, pages: Array<Array<{ name?: string, role?: string|null }>>, rows?: Array<number|null> }} layout
 *   `pages`: each page's markers (fields and columns); `rows`: each page's
 *   number of ruled rows (null without a table).
 * @returns {string[]} one sentence per problem, none when it can be published
 */
export function publishProblems({ hasSample, pages, rows }) {
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

    // A two-page register: row N of page 1 and row N of page 2 are one person.
    if (pages.length === 2) {
        const both = namesOnBothPages(pages[0], pages[1]);
        if (both.length > 0) {
            problems.push(`Give each page its own column names (on both pages: ${both.slice(0, 3).join(', ')}`
                + `${both.length > 3 ? ` and ${both.length - 3} more` : ''}).`);
        }
        const mismatch = rowMismatch(rows);
        if (mismatch) problems.push(mismatch);
    }

    return problems;
}

/**
 * Names used on both pages of a two-page layout (compared without case), in
 * page 1's order. Each page's columns join into one record per person, so a
 * name on both pages would be one field twice.
 *
 * @param {Array<{ name?: string }>} pageOne
 * @param {Array<{ name?: string }>} pageTwo
 * @returns {string[]}
 */
export function namesOnBothPages(pageOne, pageTwo) {
    const key = (box) => String(box.name ?? '').trim().toLocaleLowerCase();
    const second = new Set(pageTwo.map(key).filter(Boolean));
    const seen = new Set();
    return pageOne
        .filter((box) => {
            const name = key(box);
            if (!name || !second.has(name) || seen.has(name)) return false;
            seen.add(name);
            return true;
        })
        .map((box) => String(box.name).trim());
}

/**
 * Why the two pages' rows do not line up, or null. `rows` is each page's
 * number of ruled rows, null for a page without a table (nothing to match).
 *
 * @param {Array<number|null>|undefined} rows
 * @returns {string|null}
 */
export function rowMismatch(rows) {
    if (!Array.isArray(rows) || rows.length !== 2) return null;
    const [one, two] = rows;
    if (!Number.isInteger(one) || !Number.isInteger(two) || one < 1 || two < 1 || one === two) return null;
    return `Page 1 has ${one} ${one === 1 ? 'row' : 'rows'} and page 2 has ${two}. `
        + 'Row 1 of both pages is one person, so both pages need the same number of rows.';
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
