const runningAnimations = new WeakMap();

function motionIsReduced() {
    return typeof window !== 'undefined'
        && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Animate an expandable region between its current height and its content
 * height. The hidden attribute is applied only after a closing animation so
 * the content never snaps out of the layout early.
 */
export function setDisclosureExpanded(region, expanded, duration = 220) {
    if (!(region instanceof HTMLElement)) return Promise.resolve();

    const previous = runningAnimations.get(region);
    if (!previous && region.hidden === !expanded) return Promise.resolve();

    previous?.cancel();

    const startHeight = region.hidden ? 0 : region.getBoundingClientRect().height;
    if (expanded) region.hidden = false;
    const endHeight = expanded ? region.scrollHeight : 0;

    if (motionIsReduced() || typeof region.animate !== 'function') {
        region.hidden = !expanded;
        region.style.removeProperty('height');
        region.style.removeProperty('overflow');
        region.style.removeProperty('opacity');
        return Promise.resolve();
    }

    region.style.height = `${startHeight}px`;
    region.style.overflow = 'hidden';

    const animation = region.animate(
        [
            { height: `${startHeight}px`, opacity: expanded ? 0.35 : 1 },
            { height: `${endHeight}px`, opacity: expanded ? 1 : 0.35 },
        ],
        { duration, easing: 'cubic-bezier(.2, .72, .2, 1)' },
    );
    runningAnimations.set(region, animation);

    return animation.finished
        .catch(() => undefined)
        .then(() => {
            if (runningAnimations.get(region) !== animation) return;

            runningAnimations.delete(region);
            region.hidden = !expanded;
            region.style.removeProperty('height');
            region.style.removeProperty('overflow');
            region.style.removeProperty('opacity');
        });
}
