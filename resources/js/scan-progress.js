/**
 * The pace of the scan progress window: where its ring is, and how long is left.
 *
 * A scan has steps the page can see start (waiting, finding lines, reading)
 * but not progress within. Each step gets a share of the ring and the time it
 * usually takes on this machine (PageRun::typicalSeconds). The ring covers
 * most of the share at a steady pace over that time, so it arrives as the step
 * ends; it used to rush ahead and then crawl, which felt stuck.
 */

/** The share of a step's span the ring covers, evenly, in the step's usual time. */
export const STEADY_SHARE = 0.9;

const DEFAULTS = { find: 20, read: 5, detect: 20 };

/**
 * The usual seconds of each step, from the server's numbers; a missing or
 * odd one gets its default. `wait` (a queued page being picked up) and
 * `reoutline` (outlining again from lines already found) are not recorded.
 *
 * @param {{find?: number, read?: number, detect?: number}|null|undefined} raw
 */
export function scanTimings(raw) {
    const seconds = (key) => {
        const value = Number(raw?.[key]);
        return Number.isFinite(value) && value > 0 ? Math.min(value, 600) : DEFAULTS[key];
    };
    return { wait: 1.5, reoutline: 4, find: seconds('find'), read: seconds('read'), detect: seconds('detect') };
}

/**
 * What is left of one page once it has been picked up: 'read' (its lines are
 * outlined already), 'reoutline' (outlined again from lines already found),
 * 'detect' (a Smoke test scan), or a full scan.
 *
 * @param {string} kind
 * @param {ReturnType<typeof scanTimings>} timings
 */
export function pageSeconds(kind, timings) {
    if (kind === 'read') return timings.read;
    if (kind === 'reoutline') return timings.reoutline + timings.read;
    if (kind === 'detect') return timings.detect;
    return timings.find + timings.read;
}

/**
 * Where the ring is `elapsed` seconds into a step that usually takes
 * `expected`: evenly up to STEADY_SHARE of the way from `from` to `ceiling`,
 * then ever closer to the ceiling without reaching it, so a step that runs
 * over still moves.
 */
export function phaseProgress(from, ceiling, elapsed, expected) {
    const span = ceiling - from;
    if (!(span > 0)) return from;
    const usual = Math.max(0.5, Number(expected) || 0);
    const time = Math.max(0, Number(elapsed) || 0);
    if (time <= usual) return from + span * STEADY_SHARE * (time / usual);
    return from + span * (STEADY_SHARE + (1 - STEADY_SHARE) * (1 - Math.exp(-(time - usual) / usual)));
}

/**
 * The time left, in words: `elapsed` seconds into a step that usually takes
 * `expected`, with `later` seconds of steps still to come. A step well past
 * its usual time has no honest number, and says so.
 */
export function timeLeftText(elapsed, expected, later = 0) {
    const over = elapsed - expected;
    if (over > Math.max(3, 0.25 * expected)) return 'Taking a little longer than usual';

    const left = Math.max(0, expected - elapsed) + Math.max(0, Number(later) || 0);
    if (left < 1.5) return 'Almost done';
    if (left >= 90) return `About ${Math.round(left / 60)} minutes left`;
    if (left >= 55) return 'About 1 minute left';
    // Counted in fives above 20: a number that ticks every second reads as a promise.
    const seconds = left > 20 ? Math.round(left / 5) * 5 : Math.ceil(left);
    return `About ${seconds} seconds left`;
}
