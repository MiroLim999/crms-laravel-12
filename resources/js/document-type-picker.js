/**
 * The document type select on step 1 of a new document
 * (resources/views/scan/partials/document-step.blade.php).
 *
 * Every type in the list is a link to the workspace for it, and Bootstrap's
 * dropdown already opens, closes and moves through the list with the arrow
 * keys. This adds the rest:
 *  - a filter, shown once the list is long: typing narrows it, Down moves into
 *    it, and Enter opens the only type left;
 *  - while a type opens, the drop area fades, so the click is acknowledged;
 *  - with no type chosen yet, a click on the drop area opens the list, and a
 *    file dropped there is stopped, since the browser would otherwise open it
 *    in the tab.
 */

const picker = document.querySelector('[data-document-type-select]');

if (picker) {
    const toggle = picker.querySelector('[data-bs-toggle="dropdown"]');
    const filter = picker.querySelector('[data-document-type-filter]');
    const options = [...picker.querySelectorAll('[data-document-type-option]')];
    const noMatch = picker.querySelector('[data-document-type-empty]');
    const card = picker.closest('.document-upload-card');

    const dropdown = () => window.bootstrap?.Dropdown?.getOrCreateInstance(toggle);
    const openable = (option) => !option.classList.contains('disabled');
    const shown = () => options.filter((option) => !option.classList.contains('d-none'));

    const applyFilter = (text) => {
        const words = text.trim().toLowerCase().split(/\s+/).filter(Boolean);
        options.forEach((option) => {
            const label = option.textContent.toLowerCase();
            option.classList.toggle('d-none', !words.every((word) => label.includes(word)));
        });
        noMatch?.classList.toggle('d-none', shown().length > 0);
    };

    picker.addEventListener('shown.bs.dropdown', () => filter?.focus());

    picker.addEventListener('hidden.bs.dropdown', () => {
        if (filter && filter.value !== '') {
            filter.value = '';
            applyFilter('');
        }
    });

    filter?.addEventListener('input', () => applyFilter(filter.value));
    filter?.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            shown().find(openable)?.focus();
        } else if (event.key === 'Enter') {
            const candidates = shown().filter(openable);
            event.preventDefault();
            if (candidates.length === 1) candidates[0].click();
        }
    });

    options.filter(openable).forEach((option) => {
        option.addEventListener('click', (event) => {
            // A new tab or window leaves this page as it is.
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) return;
            card?.classList.add('is-switching');
        });
    });

    // Back to this page from the browser's cache: it is not switching any more.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) card?.classList.remove('is-switching');
    });

    const waiting = document.querySelector('[data-requires-type]');
    if (waiting) {
        const openList = (event) => {
            event.preventDefault();
            // Bootstrap closes open lists on a click that reaches the document.
            event.stopPropagation();
            dropdown()?.show();
        };

        waiting.addEventListener('click', openList);
        ['dragenter', 'dragover'].forEach((name) => {
            waiting.addEventListener(name, (event) => event.preventDefault());
        });
        waiting.addEventListener('drop', openList);
    }
}
