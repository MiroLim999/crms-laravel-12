/**
 * Windows-style field selection for a FieldMarker overlay.
 *
 * Dragging an empty part of the document draws a rectangle and selects every
 * intersecting marker live. Direct marker dragging/resizing is left untouched.
 */
export function attachMarqueeSelection({ marker, overlay, marquee }) {
    let pointerId = null;
    let start = null;
    let baseIndexes = [];

    const point = (event) => {
        const bounds = overlay.getBoundingClientRect();

        return {
            x: Math.min(bounds.width, Math.max(0, event.clientX - bounds.left)),
            y: Math.min(bounds.height, Math.max(0, event.clientY - bounds.top)),
            width: bounds.width,
            height: bounds.height,
        };
    };

    const geometry = (current) => {
        if (!start) return null;

        const left = Math.min(start.x, current.x);
        const top = Math.min(start.y, current.y);
        const right = Math.max(start.x, current.x);
        const bottom = Math.max(start.y, current.y);

        return {
            left,
            top,
            right,
            bottom,
            width: right - left,
            height: bottom - top,
            overlayWidth: current.width,
            overlayHeight: current.height,
        };
    };

    const indexesInside = (area) => {
        if (!area || area.width <= 2 && area.height <= 2) return [];

        return marker.toJSON().flatMap((box, index) => {
            const boxLeft = box.x * area.overlayWidth;
            const boxTop = box.y * area.overlayHeight;
            const boxRight = (box.x + box.w) * area.overlayWidth;
            const boxBottom = (box.y + box.h) * area.overlayHeight;
            const intersects = boxRight >= area.left && boxLeft <= area.right
                && boxBottom >= area.top && boxTop <= area.bottom;

            return intersects ? [index] : [];
        });
    };

    const render = (current) => {
        const area = geometry(current);
        if (!area) return;

        marquee.style.left = `${area.left}px`;
        marquee.style.top = `${area.top}px`;
        marquee.style.width = `${area.width}px`;
        marquee.style.height = `${area.height}px`;
        marquee.classList.toggle('is-visible', area.width > 2 || area.height > 2);
        marker.selectIndexes([
            ...new Set([...baseIndexes, ...indexesInside(area)]),
        ], { source: 'marquee' });
    };

    const resetVisual = () => {
        marquee.classList.remove('is-visible');
        marquee.removeAttribute('style');
    };

    const cancel = (restoreSelection = false) => {
        if (restoreSelection) marker.selectIndexes(baseIndexes, { source: 'marquee' });

        if (pointerId !== null && overlay.hasPointerCapture(pointerId)) {
            overlay.releasePointerCapture(pointerId);
        }

        pointerId = null;
        start = null;
        baseIndexes = [];
        resetVisual();
    };

    const onPointerDown = (event) => {
        if (event.ctrlKey || event.target !== overlay || event.button !== 0 || !event.isPrimary) return;

        event.preventDefault();
        // FieldMarker also listens for an empty-overlay press to clear selection.
        // This gesture belongs to the marquee instead.
        event.stopImmediatePropagation();
        pointerId = event.pointerId;
        start = point(event);
        baseIndexes = event.shiftKey ? marker.selectedIndexes() : [];
        overlay.setPointerCapture(event.pointerId);
        render(start);
    };

    const onPointerMove = (event) => {
        if (event.pointerId !== pointerId || !start) return;
        event.preventDefault();
        render(point(event));
    };

    const onPointerUp = (event) => {
        if (event.pointerId !== pointerId || !start) return;

        const current = point(event);
        const area = geometry(current);
        const dragged = area && (area.width > 2 || area.height > 2);
        if (dragged) render(current);
        else marker.selectIndexes(baseIndexes, { source: 'marquee' });
        cancel(false);
    };

    const onPointerCancel = () => cancel(true);
    const onKeyDown = (event) => {
        if (event.key !== 'Escape' || pointerId === null) return;
        event.preventDefault();
        cancel(true);
    };

    overlay.addEventListener('pointerdown', onPointerDown, { capture: true });
    overlay.addEventListener('pointermove', onPointerMove);
    overlay.addEventListener('pointerup', onPointerUp);
    overlay.addEventListener('pointercancel', onPointerCancel);
    document.addEventListener('keydown', onKeyDown);

    return () => {
        cancel(true);
        overlay.removeEventListener('pointerdown', onPointerDown, { capture: true });
        overlay.removeEventListener('pointermove', onPointerMove);
        overlay.removeEventListener('pointerup', onPointerUp);
        overlay.removeEventListener('pointercancel', onPointerCancel);
        document.removeEventListener('keydown', onKeyDown);
    };
}
