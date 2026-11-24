/**
 * The checks Verify runs on a value, from its template field's settings (set
 * in the Template Builder): a date, a number, or one of a list of choices.
 *
 * A check never blocks: registers hold odd values ("unknown", a date written
 * in words), so a value that does not fit is only pointed out, never refused.
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

/**
 * A date as YYYY-MM-DD, from the ways registers write it, or null when it is not
 * a whole, real date. As App\Support\RegistryFields::isoDate (keep them alike).
 *
 *   "5-14-79", "05/14/1979"     month first (as the registers here write it)
 *   "14-5-79"                   day first, when the first number cannot be a month
 *                               (or always, with `dayFirst`: "3-11-57" is 3 November)
 *   "1979-05-14"                year first
 *   "May 14, 1979", "14 May 79" a month's name, in any place
 *
 * A two-digit year is this century's up to this year ("24" is 2024), else the
 * last one's ("57" is 1957).
 */
export function toIsoDate(value, today = new Date(), { dayFirst = false } = {}) {
    const text = String(value ?? '').trim().toLowerCase();
    if (!text) return null;

    const fullYear = (token) => {
        const digits = String(token).replace(/^'/, '');
        if (/^\d{4}$/.test(digits)) return Number(digits);
        if (!/^\d{2}$/.test(digits)) return null;
        const short = Number(digits);
        const thisYear = today.getFullYear();
        const century = Math.floor(thisYear / 100) * 100;
        return century + short <= thisYear ? century + short : century - 100 + short;
    };
    const build = (year, month, day) => {
        if (!year || !(month >= 1 && month <= 12) || !(day >= 1)) return null;
        const last = new Date(Date.UTC(year, month, 0)).getUTCDate();
        if (day > last) return null;
        return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    };

    const numeric = text.match(/^('?\d{1,4})\s*[/.\-\s]\s*(\d{1,2})\s*[/.\-\s]\s*('?\d{1,4})$/);
    if (numeric) {
        const [, first, middle, last] = numeric;
        if (/^\d{4}$/.test(first)) return build(Number(first), Number(middle), Number(last));
        const year = fullYear(last);
        const [a, b] = [Number(first), Number(middle)];
        // As the register writes it; a number that cannot be a month decides anyway.
        if (dayFirst) return b > 12 ? build(year, a, b) : build(year, b, a);
        return a > 12 ? build(year, b, a) : build(year, a, b);
    }

    const tokens = text.replace(/(\d)(st|nd|rd|th)\b/g, '$1').split(/[\s.,/\-]+/).filter(Boolean);
    const monthAt = tokens.findIndex((token) => MONTH_NAMES.has(token));
    if (monthAt === -1) return null;
    const month = MONTHS.findIndex((names) => names.includes(tokens[monthAt])) + 1;
    const rest = tokens.filter((_, index) => index !== monthAt);
    if (rest.length !== 2 || !rest.every((token) => /^'?\d{1,4}$/.test(token))) return null;
    // The year is the four-digit one, else the one after the day.
    const yearAt = rest.findIndex((token) => /^\d{4}$/.test(token));
    const [dayToken, yearToken] = yearAt === 0 ? [rest[1], rest[0]] : yearAt === 1 ? [rest[0], rest[1]] : [rest[0], rest[1]];
    return build(fullYear(yearToken), month, Number(dayToken));
}

/**
 * How a set of dates (one column) is written: 'day' when one of them can only be
 * day first ("25-3-57"), 'month' when one can only be month first ("3-25-57"),
 * else null (each could be either).
 */
export function dateOrderOf(values) {
    let order = null;
    for (const value of values) {
        const match = String(value ?? '').trim().match(/^'?(\d{1,2})\s*[/.\-\s]\s*(\d{1,2})\s*[/.\-\s]\s*'?\d{2,4}$/);
        if (!match) continue;
        const [a, b] = [Number(match[1]), Number(match[2])];
        if (a > 12 && b <= 12) return 'day';
        if (b > 12 && a <= 12) order = 'month';
    }
    return order;
}

/**
 * The month most of a column's dates fall in, as { year, month }, or null when
 * fewer than two dates are whole or no month holds half of them. A register page
 * is written in order, so its dates keep close together.
 */
export function usualMonthOf(isoDates) {
    const months = isoDates.filter(Boolean).map((iso) => iso.slice(0, 7));
    if (months.length < 2) return null;
    const counts = new Map();
    months.forEach((month) => counts.set(month, (counts.get(month) ?? 0) + 1));
    const [best, count] = [...counts].sort((a, b) => b[1] - a[1])[0];
    if (count * 2 < months.length) return null;
    return { year: Number(best.slice(0, 4)), month: Number(best.slice(5, 7)) };
}

/**
 * A date the scan misread by one stray digit ("22-22-57" for 2-22-57, a "2"
 * read twice), repaired from the rest of its column: each way of taking one
 * digit out of it is tried, and the one whole date in the column's usual
 * month (`usual`, from usualMonthOf) is it. Null when none, or more than one, is.
 */
export function repairedDate(value, usual, { dayFirst = false, today = new Date() } = {}) {
    if (!usual) return null;
    const text = String(value ?? '').trim();
    if (!/^[\d'][\d'\s/.\-]*\d$/.test(text) || toIsoDate(text, today, { dayFirst })) return null;
    const found = new Set();
    for (let at = 0; at < text.length; at += 1) {
        if (!/\d/.test(text[at])) continue;
        const iso = toIsoDate(text.slice(0, at) + text.slice(at + 1), today, { dayFirst });
        if (iso && Number(iso.slice(0, 4)) === usual.year && Number(iso.slice(5, 7)) === usual.month) found.add(iso);
    }
    return found.size === 1 ? [...found][0] : null;
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
 * Why a value does not fit its field, for Verify, or null when it fits (or the
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
