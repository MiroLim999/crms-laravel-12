/**
 * Every Bootstrap dropdown opens with a short fade and slide (CSS), and closes the
 * same way: Bootstrap hides a menu at once, so the first hide is held back for the
 * length of the closing animation and then let through.
 */
const CLOSE_MS = 130;

function motionIsReduced() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}

document.addEventListener('hide.bs.dropdown', (event) => {
    const toggle = event.target;
    if (!(toggle instanceof HTMLElement) || motionIsReduced()) return;

    const menu = toggle.parentElement?.querySelector(':scope > .dropdown-menu.show');
    if (!(menu instanceof HTMLElement)) return;

    // The hide that follows the animation goes straight through.
    if (menu.dataset.closing === 'done') {
        delete menu.dataset.closing;
        menu.classList.remove('is-closing');
        return;
    }

    event.preventDefault();
    if (menu.dataset.closing === 'running') return;

    menu.dataset.closing = 'running';
    menu.classList.add('is-closing');
    window.setTimeout(() => {
        menu.dataset.closing = 'done';
        window.bootstrap?.Dropdown?.getInstance(toggle)?.hide();
    }, CLOSE_MS);
});
