import { setDisclosureExpanded } from './disclosure-motion.js';

export const RECORD_SPLIT_MIN = 35;
export const RECORD_SPLIT_MAX = 75;
export const RECORD_SPLIT_DEFAULT = 58;
export const RECORD_SCAN_ZOOM_MIN = 1;
export const RECORD_SCAN_ZOOM_MAX = 3;
export const RECORD_SCAN_WHEEL_STEP = 0.1;

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

export function clampRecordScanZoom(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return RECORD_SCAN_ZOOM_MIN;

    return Math.min(
        RECORD_SCAN_ZOOM_MAX,
        Math.max(RECORD_SCAN_ZOOM_MIN, Math.round(numeric * 10) / 10),
    );
}

export function recordScanZoomFromWheel(current, deltaY) {
    if (!Number.isFinite(deltaY) || deltaY === 0) return clampRecordScanZoom(current);

    return clampRecordScanZoom(
        current + (deltaY < 0 ? RECORD_SCAN_WHEEL_STEP : -RECORD_SCAN_WHEEL_STEP),
    );
}

export function recordScanPanPosition(scrollLeft, scrollTop, movementX, movementY) {
    return {
        left: Math.max(0, scrollLeft - movementX),
        top: Math.max(0, scrollTop - movementY),
    };
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

    const setZoom = (nextZoom, focalEvent = null) => {
        const canKeepFocalPoint = viewport instanceof HTMLElement
            && stage instanceof HTMLElement
            && focalEvent !== null;
        let focalPoint = null;

        if (canKeepFocalPoint) {
            const bounds = viewport.getBoundingClientRect();
            const stageWidth = stage.offsetWidth || 1;
            const stageHeight = stage.offsetHeight || 1;
            const localX = focalEvent.clientX - bounds.left;
            const localY = focalEvent.clientY - bounds.top;
            focalPoint = {
                localX,
                localY,
                documentX: (viewport.scrollLeft + localX) / stageWidth,
                documentY: (viewport.scrollTop + localY) / stageHeight,
            };
        }

        const previousTransition = canKeepFocalPoint ? stage.style.transition : null;
        if (canKeepFocalPoint) stage.style.transition = 'none';

        zoom = clampRecordScanZoom(nextZoom);
        if (stage instanceof HTMLElement) stage.style.inlineSize = `${zoom * 100}%`;
        if (zoomLabel instanceof HTMLButtonElement) zoomLabel.textContent = `${Math.round(zoom * 100)}%`;

        if (focalPoint && viewport instanceof HTMLElement && stage instanceof HTMLElement) {
            viewport.scrollLeft = focalPoint.documentX * stage.offsetWidth - focalPoint.localX;
            viewport.scrollTop = focalPoint.documentY * stage.offsetHeight - focalPoint.localY;
            window.requestAnimationFrame(() => {
                stage.style.transition = previousTransition ?? '';
            });
        }
    };

    root.querySelector('[data-scan-zoom-out]')?.addEventListener('click', () => setZoom(zoom - 0.25));
    root.querySelector('[data-scan-zoom-in]')?.addEventListener('click', () => setZoom(zoom + 0.25));
    zoomLabel?.addEventListener('click', () => setZoom(1));
    viewport?.addEventListener('wheel', (event) => {
        if (!event.ctrlKey || event.deltaY === 0) return;

        event.preventDefault();
        setZoom(recordScanZoomFromWheel(zoom, event.deltaY), event);
    }, { passive: false });

    let panning = false;
    let panX = 0;
    let panY = 0;
    let panStartX = 0;
    let panStartY = 0;
    let suppressPanClick = false;

    viewport?.addEventListener('pointerdown', (event) => {
        if (!event.ctrlKey || event.button !== 0) return;

        event.preventDefault();
        panning = true;
        panX = event.clientX;
        panY = event.clientY;
        panStartX = event.clientX;
        panStartY = event.clientY;
        suppressPanClick = false;
        viewport.setPointerCapture?.(event.pointerId);
        viewport.classList.add('is-panning');
    });

    viewport?.addEventListener('pointermove', (event) => {
        if (!panning) return;

        event.preventDefault();
        const next = recordScanPanPosition(
            viewport.scrollLeft,
            viewport.scrollTop,
            event.clientX - panX,
            event.clientY - panY,
        );
        viewport.scrollLeft = next.left;
        viewport.scrollTop = next.top;
        panX = event.clientX;
        panY = event.clientY;
        if (Math.hypot(event.clientX - panStartX, event.clientY - panStartY) > 3) {
            suppressPanClick = true;
        }
    });

    const finishPan = (event) => {
        if (!panning) return;

        panning = false;
        viewport.classList.remove('is-panning');
        if (event.type === 'pointercancel') suppressPanClick = false;
        if (viewport.hasPointerCapture?.(event.pointerId)) {
            viewport.releasePointerCapture(event.pointerId);
        }
    };

    viewport?.addEventListener('pointerup', finishPan);
    viewport?.addEventListener('pointercancel', finishPan);
    viewport?.addEventListener('click', (event) => {
        if (!suppressPanClick) return;

        event.preventDefault();
        event.stopPropagation();
        suppressPanClick = false;
    }, true);

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
