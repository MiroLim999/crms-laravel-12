import { setDisclosureExpanded } from './disclosure-motion.js';

export const RECORD_SPLIT_MIN = 35;
export const RECORD_SPLIT_MAX = 75;
export const RECORD_SPLIT_DEFAULT = 58;

export function clampRecordSplit(value) {
    if (value === null || value === undefined || value === '') return RECORD_SPLIT_DEFAULT;

    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return RECORD_SPLIT_DEFAULT;

    return Math.min(RECORD_SPLIT_MAX, Math.max(RECORD_SPLIT_MIN, Math.round(numeric)));
}

export function recordSplitFromPointer(clientX, left, width) {
    if (!Number.isFinite(width) || width <= 0) return RECORD_SPLIT_DEFAULT;

    return clampRecordSplit(((clientX - left) / width) * 100);
}

export function recordSplitFromKey(key, current, largeStep = false) {
    const step = largeStep ? 5 : 2;

    return {
        ArrowLeft: clampRecordSplit(current - step),
        ArrowRight: clampRecordSplit(current + step),
        Home: RECORD_SPLIT_MIN,
        End: RECORD_SPLIT_MAX,
    }[key] ?? null;
}

export function recordComparisonFrames(visible, stacked = false) {
    const offset = stacked ? 'translateY(-1rem)' : 'translateX(-1.5rem)';
    const hidden = { opacity: 0, transform: offset };
    const shown = { opacity: 1, transform: 'translate(0, 0)' };

    return visible ? [hidden, shown] : [shown, hidden];
}

function initRecordDetail(root) {
    const split = root.querySelector('[data-record-split]');
    const splitter = root.querySelector('[data-record-splitter]');
    const storageKey = 'crms.record-detail.scan-width';

    if (split instanceof HTMLElement && splitter instanceof HTMLElement) {
        let percentage = RECORD_SPLIT_DEFAULT;
        let dragging = false;

        try {
            percentage = clampRecordSplit(window.localStorage.getItem(storageKey));
        } catch {
            // Storage may be unavailable in private or locked-down browsers.
        }

        const updateSplit = (nextPercentage, persist = false) => {
            percentage = clampRecordSplit(nextPercentage);
            split.style.setProperty('--record-scan-width', `${percentage}%`);
            splitter.setAttribute('aria-valuenow', String(percentage));
            splitter.setAttribute(
                'aria-valuetext',
                `Original scan ${percentage}%, verified data ${100 - percentage}%`,
            );

            if (!persist) return;
            try {
                window.localStorage.setItem(storageKey, String(percentage));
            } catch {
                // The live resize still works when persistence is unavailable.
            }
        };

        const updateFromPointer = (event) => {
            const bounds = split.getBoundingClientRect();
            updateSplit(recordSplitFromPointer(event.clientX, bounds.left, bounds.width));
        };

        splitter.addEventListener('pointerdown', (event) => {
            if (event.button !== 0 || !window.matchMedia('(min-width: 1200px)').matches) return;

            event.preventDefault();
            dragging = true;
            splitter.setPointerCapture?.(event.pointerId);
            split.classList.add('is-resizing');
            updateFromPointer(event);
        });

        splitter.addEventListener('pointermove', (event) => {
            if (dragging) updateFromPointer(event);
        });

        const finishResize = (event) => {
            if (!dragging) return;

            dragging = false;
            split.classList.remove('is-resizing');
            if (splitter.hasPointerCapture?.(event.pointerId)) {
                splitter.releasePointerCapture(event.pointerId);
            }
            updateSplit(percentage, true);
        };

        splitter.addEventListener('pointerup', finishResize);
        splitter.addEventListener('pointercancel', finishResize);
        splitter.addEventListener('dblclick', () => updateSplit(RECORD_SPLIT_DEFAULT, true));
        splitter.addEventListener('keydown', (event) => {
            const nextPercentage = recordSplitFromKey(event.key, percentage, event.shiftKey);
            if (nextPercentage === null) return;

            event.preventDefault();
            updateSplit(nextPercentage, true);
        });

        updateSplit(percentage);
    }

    const originalToggle = root.querySelector('[data-original-toggle]');
    const originalToggleLabel = root.querySelector('[data-original-toggle-label]');
    const scanCard = root.querySelector('.record-scan-card');
    let comparisonVisible = false;
    let comparisonAnimation = null;
    let comparisonSequence = 0;

    const setOriginalComparison = (visible) => {
        if (!(split instanceof HTMLElement)) return;
        if (visible === comparisonVisible && !split.classList.contains('is-closing-comparison')) return;

        comparisonVisible = visible;
        const sequence = ++comparisonSequence;
        comparisonAnimation?.cancel();
        comparisonAnimation = null;

        originalToggle?.setAttribute('aria-pressed', String(visible));
        if (originalToggleLabel) {
            originalToggleLabel.textContent = visible ? 'Hide original' : 'Compare original';
        }

        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const canAnimate = scanCard instanceof HTMLElement
            && typeof scanCard.animate === 'function'
            && !reducedMotion;

        if (visible) {
            split.classList.remove('is-closing-comparison');
            split.classList.add('is-comparing');
            if (!canAnimate) return;

            comparisonAnimation = scanCard.animate(
                recordComparisonFrames(true, window.matchMedia('(max-width: 1199.98px)').matches),
                { duration: 260, easing: 'cubic-bezier(.2, .72, .2, 1)' },
            );
            comparisonAnimation.finished.catch(() => undefined).then(() => {
                if (comparisonSequence === sequence) comparisonAnimation = null;
            });
            return;
        }

        if (!split.classList.contains('is-comparing')) return;
        if (!canAnimate) {
            split.classList.remove('is-comparing', 'is-closing-comparison');
            return;
        }

        split.classList.add('is-closing-comparison');
        comparisonAnimation = scanCard.animate(
            recordComparisonFrames(false, window.matchMedia('(max-width: 1199.98px)').matches),
            { duration: 210, easing: 'cubic-bezier(.4, 0, 1, 1)' },
        );
        comparisonAnimation.finished.catch(() => undefined).then(() => {
            if (comparisonSequence !== sequence) return;

            comparisonAnimation = null;
            split.classList.remove('is-comparing', 'is-closing-comparison');
        });
    };

    originalToggle?.addEventListener('click', () => {
        setOriginalComparison(!comparisonVisible);
    });

    const setGroupExpanded = (group, expanded) => {
        const body = group.querySelector('.record-field-group__body');
        if (!(body instanceof HTMLElement)) return;

        if (expanded) {
            body.hidden = true;
            group.open = true;
        }
        group.classList.toggle('is-expanded', expanded);
        setDisclosureExpanded(body, expanded).then(() => {
            if (!expanded) group.open = false;
        });
    };

    root.querySelectorAll('.record-field-group').forEach((group) => {
        const body = group.querySelector('.record-field-group__body');
        const summary = group.querySelector('.record-field-group__summary');
        if (!(body instanceof HTMLElement) || !(summary instanceof HTMLElement)) return;

        group.classList.toggle('is-expanded', group.open);
        body.hidden = !group.open;
        summary.addEventListener('click', (event) => {
            event.preventDefault();
            setGroupExpanded(group, !group.classList.contains('is-expanded'));
        });
    });

    const viewport = root.querySelector('[data-scan-viewport]');
    const stage = root.querySelector('[data-scan-stage]');
    const zoomLabel = root.querySelector('[data-scan-zoom-reset]');
    let zoom = 1;

    const setZoom = (nextZoom) => {
        zoom = Math.min(3, Math.max(1, Math.round(nextZoom * 10) / 10));
        if (stage instanceof HTMLElement) stage.style.inlineSize = `${zoom * 100}%`;
        if (zoomLabel instanceof HTMLButtonElement) zoomLabel.textContent = `${Math.round(zoom * 100)}%`;
    };

    root.querySelector('[data-scan-zoom-out]')?.addEventListener('click', () => setZoom(zoom - 0.25));
    root.querySelector('[data-scan-zoom-in]')?.addEventListener('click', () => setZoom(zoom + 0.25));
    zoomLabel?.addEventListener('click', () => setZoom(1));

    const activateField = (fieldId, scrollRow = false) => {
        const row = root.querySelector(`[data-record-field="${fieldId}"]`);
        const marker = root.querySelector(`[data-scan-marker="${fieldId}"]`);
        if (!(row instanceof HTMLElement) || !(marker instanceof HTMLElement)) return;

        setOriginalComparison(true);
        root.querySelectorAll('.record-field-row.is-active, .record-scan-marker.is-active')
            .forEach((element) => element.classList.remove('is-active'));
        row.classList.add('is-active');
        marker.classList.add('is-active');
        const group = row.closest('.record-field-group');
        if (group instanceof HTMLDetailsElement && !group.classList.contains('is-expanded')) {
            setGroupExpanded(group, true);
        }
        setZoom(Math.max(zoom, 1.6));

        window.requestAnimationFrame(() => {
            if (viewport instanceof HTMLElement) {
                viewport.scrollTo({
                    left: Math.max(0, marker.offsetLeft + marker.offsetWidth / 2 - viewport.clientWidth / 2),
                    top: Math.max(0, marker.offsetTop + marker.offsetHeight / 2 - viewport.clientHeight / 2),
                    behavior: 'smooth',
                });
            }
            if (scrollRow) row.scrollIntoView({ block: 'center', behavior: 'smooth' });
        });
    };

    root.querySelectorAll('[data-record-field]').forEach((row) => {
        const activate = () => activateField(row.dataset.recordField);
        row.addEventListener('click', activate);
        row.addEventListener('keydown', (event) => {
            if (!['Enter', ' '].includes(event.key)) return;
            event.preventDefault();
            activate();
        });
    });

    root.querySelectorAll('[data-scan-marker]').forEach((marker) => {
        marker.addEventListener('click', () => activateField(marker.dataset.scanMarker, true));
    });
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-record-detail]').forEach(initRecordDetail);
}
