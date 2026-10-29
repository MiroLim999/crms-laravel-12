/**
 * Record search keyboard shortcut and empty-search check, on every page.
 *
 * "/" puts the cursor in the record search: the top bar's field, or on the
 * Records Archive, the archive's own Search field. Both carry
 * data-record-search. The key is left alone while someone is typing, when a
 * dialog is open, and on pages without a visible search field.
 *
 * The top bar's form (data-record-search-form) does not submit when empty,
 * so pressing Enter or the magnifier with nothing typed puts the cursor in
 * the field instead of opening the whole archive.
 */

const EDITABLE = 'input, textarea, select, [contenteditable]:not([contenteditable="false"])';

function visibleSearchField() {
    return [...document.querySelectorAll('[data-record-search]')]
        .find((field) => !field.disabled && field.getClientRects().length > 0);
}

document.addEventListener('keydown', (event) => {
    if (event.key !== '/' || event.defaultPrevented || event.isComposing
        || event.ctrlKey || event.metaKey || event.altKey) {
        return;
    }

    if (event.target instanceof Element && event.target.closest(EDITABLE)) return;

    // An open dialog keeps the focus, so the key is left for it.
    if (document.querySelector('.modal.show, .offcanvas.show')) return;

    const field = visibleSearchField();
    if (!field) return;

    event.preventDefault();
    field.focus();
    field.select();
});

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-record-search-form]')) return;

    const field = form.querySelector('[data-record-search]');
    if (field && field.value.trim() === '') {
        event.preventDefault();
        field.focus();
    }
});
