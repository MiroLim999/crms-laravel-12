const interactiveTags = new Set(['A', 'BUTTON', 'INPUT', 'LABEL', 'SELECT', 'TEXTAREA']);
const interactiveRoles = new Set(['button', 'combobox', 'menuitem', 'option', 'textbox']);

function isInteractiveTarget(target) {
    if (!target || typeof target !== 'object') return false;

    if (target.isContentEditable === true
        || interactiveTags.has(String(target.tagName ?? '').toUpperCase())) {
        return true;
    }

    if (typeof target.getAttribute !== 'function') return false;

    const contenteditable = target.getAttribute('contenteditable');
    const role = String(target.getAttribute('role') ?? '').toLowerCase();

    return (contenteditable !== null && contenteditable !== 'false')
        || interactiveRoles.has(role);
}

export function builderShortcutIsBlocked(event, { modalOpen = false } = {}) {
    if (event.defaultPrevented || event.isComposing || modalOpen) return true;

    const path = typeof event.composedPath === 'function'
        ? event.composedPath()
        : [event.target];

    return path.some(isInteractiveTarget);
}

export function isSelectedFieldDeletionShortcut(event, selectedCount, options = {}) {
    return !builderShortcutIsBlocked(event, options)
        && !event.ctrlKey
        && !event.metaKey
        && !event.altKey
        && selectedCount > 0
        && (event.key === 'Backspace' || event.key === 'Delete');
}

export function handleSelectedFieldDeletion(event, marker, options = {}) {
    if (!isSelectedFieldDeletionShortcut(event, marker.selectedIndexes().length, options)) {
        return false;
    }

    event.preventDefault();
    marker.removeSelected();

    return true;
}
