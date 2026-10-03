/**
 * The checks Verify runs on a value, from its template field's settings (set
 * in the Template Builder): a date, a number, or one of a list of choices.
 *
 * A check never blocks: registers hold odd values ("unknown", a date written
 * in words), so a value that does not fit is only pointed out to Staff.
 *
 * Pure functions, so they are unit-tested without a browser.
 */

const MONTHS = [
    ['jan', 'january'], ['feb', 'february'], ['mar', 'march'], ['apr', 'april'],
    ['may'], ['jun', 'june'], ['jul', 'july'], ['aug', 'august'],
    ['sep', 'sept', 'september'], ['oct', 'october'], ['nov', 'november'], ['dec', 'december'],
];
const MONTH_NAMES = new Set(MONTHS.flat());

const isDay = (token) => /^\d{1,2}$/.test(token) && Number(token) >= 1 && Number(token) <= 31;
const isYear = (token) => /^\d{4}$/.test(token) || /^'?\d{2}$/.test(token);

/**
 * Whether a value reads as a date, written the ways registers write them:
 * "Jan. 03, 1978", "3 January 1978", "01/03/1978", "1978-01-03".
 */
export function looksLikeDate(value) {
    const text = String(value ?? '').trim().toLowerCase();
    if (!text) return false;

    // All digits: day, month and year in some order.
    const numeric = text.match(/^(\d{1,4})\s*[/.\-\s]\s*(\d{1,2})\s*[/.\-\s]\s*(\d{1,4})$/);
    if (numeric) {
        const [, first, middle, last] = numeric;
        const yearFirst = first.length === 4;
        const [a, b] = yearFirst ? [middle, last] : [first, middle];
        const year = yearFirst ? first : last;
        return isYear(year) && Number(a) >= 1 && Number(b) >= 1
            && Math.min(Number(a), Number(b)) <= 12 && Math.max(Number(a), Number(b)) <= 31;
    }

    // With a month name: the other parts are a day and a year.
    const tokens = text.replace(/(\d)(st|nd|rd|th)\b/g, '$1').split(/[\s.,/\-]+/).filter(Boolean);
    const month = tokens.findIndex((token) => MONTH_NAMES.has(token));
    if (month === -1) return false;
    const rest = tokens.filter((_, index) => index !== month);
    return rest.length === 2 && rest.some(isDay) && rest.some((token) => /^\d{2,4}$/.test(token));
}

/** Whether a value reads as a number: digits, maybe grouped ("1,024") or "No. 12". */
export function looksLikeNumber(value) {
    const text = String(value ?? '').trim().replace(/^(no\.?|#)\s*/i, '');
    return /^\d[\d\s.,/-]*$/.test(text) && /\d$/.test(text);
}

const plain = (value) => String(value ?? '').trim().toLowerCase().replace(/[.\s]+$/, '').replace(/\s+/g, ' ');

/**
 * The choice a value stands for: the same word, or the start of exactly one
 * choice ("M" for Male, "Legit" for Legitimate). Null when none.
 */
export function matchingChoice(value, options) {
    const text = plain(value);
    if (!text) return null;
    const choices = (options ?? []).map((option) => String(option));
    const exact = choices.find((option) => plain(option) === text);
    if (exact) return exact;
    const starts = choices.filter((option) => plain(option).startsWith(text));
    return starts.length === 1 ? starts[0] : null;
}

/**
 * Why a value does not fit its field, for Staff, or null when it fits (or the
 * field is plain text, or the value is empty).
 *
 * @param {string} value
 * @param {{type?: string, options?: string[]}} [settings]
 */
export function valueProblem(value, settings = {}) {
    if (String(value ?? '').trim() === '') return null;

    switch (settings?.type) {
        case 'date':
            return looksLikeDate(value) ? null : 'This does not look like a date.';
        case 'number':
            return looksLikeNumber(value) ? null : 'This does not look like a number.';
        case 'choice': {
            const options = settings.options ?? [];
            if (options.length === 0 || matchingChoice(value, options)) return null;
            return `Expected one of: ${options.join(', ')}.`;
        }
        default:
            return null;
    }
}
