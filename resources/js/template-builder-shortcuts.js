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

// Inputs whose typing the browser can undo itself.
const nonTextInputTypes = new Set([
    'button', 'checkbox', 'color', 'file', 'hidden', 'image', 'radio', 'range', 'reset', 'submit',
]);

function hasOwnTextUndo(target) {
    if (!target || typeof target !== 'object') return false;

    const tag = String(target.tagName ?? '').toUpperCase();
    if (tag === 'TEXTAREA' || (tag === 'INPUT'
        && !nonTextInputTypes.has(String(target.type ?? 'text').toLowerCase()))) {
        // An empty box has nothing of its own to undo, so Ctrl+Z goes to the
        // layout: right after "Add field" the cursor sits in the emptied name box.
        return String(target.value ?? '') !== '';
    }

    if (target.isContentEditable === true) return true;
    if (typeof target.getAttribute !== 'function') return false;
    const contenteditable = target.getAttribute('contenteditable');

    return contenteditable !== null && contenteditable !== 'false';
}

/**
 * Ctrl+Z undoes the layout everywhere on the builder page (also right after a
 * click on a button or a checkbox, which keeps the focus), except in a text
 * box with text in it, where it undoes the typing as usual, and while a
 * dialog is open.
 */
export function layoutUndoIsBlocked(event, { modalOpen = false } = {}) {
    if (event.defaultPrevented || event.isComposing || modalOpen) return true;

    const path = typeof event.composedPath === 'function'
        ? event.composedPath()
        : [event.target];

    return path.some(hasOwnTextUndo);
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
