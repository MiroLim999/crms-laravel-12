/**
 * Light / dark / system colour mode.
 *
 * Light, Dark or System. The saved choice lives in localStorage ("crms.theme"). An inline script in the
 * layouts applies it before first paint; this module handles the picker in the
 * top bar, follows the operating system while "System" is chosen, and announces
 * every change with a `crms:themechange` event so canvases and charts can redraw.
 */

const STORAGE_KEY = 'crms.theme';
const MODES = ['light', 'dark', 'system'];
const ICONS = { light: 'bx-sun', dark: 'bx-moon', system: 'bx-desktop' };

const root = document.documentElement;
const systemQuery = window.matchMedia('(prefers-color-scheme: dark)');

function savedMode() {
    try {
        const mode = window.localStorage.getItem(STORAGE_KEY);

        // "oled" was an earlier separate mode; the black style is now plain Dark.
        if (mode === 'oled') return 'dark';

        return MODES.includes(mode) ? mode : 'light';
    } catch (error) {
        return 'light';
    }
}

function resolve(mode) {
    if (mode === 'system') return systemQuery.matches ? 'dark' : 'light';

    return mode;
}

function paintPicker(mode) {
    document.querySelectorAll('[data-theme-choice]').forEach((item) => {
        const active = item.dataset.themeChoice === mode;

        item.classList.toggle('active', active);
        item.setAttribute('aria-pressed', String(active));
    });

    document.querySelectorAll('[data-theme-current-icon]').forEach((icon) => {
        Object.values(ICONS).forEach((name) => icon.classList.remove(name));
        icon.classList.add(ICONS[mode]);
    });
}

function apply(mode) {
    const theme = resolve(mode);
    const changed = root.getAttribute('data-bs-theme') !== theme;

    root.setAttribute('data-bs-theme', theme);
    paintPicker(mode);

    if (changed) {
        window.dispatchEvent(new CustomEvent('crms:themechange', { detail: { theme, mode } }));
    }
}

document.addEventListener('click', (event) => {
    const item = event.target.closest('[data-theme-choice]');

    if (!item || !MODES.includes(item.dataset.themeChoice)) return;

    const mode = item.dataset.themeChoice;

    try {
        window.localStorage.setItem(STORAGE_KEY, mode);
    } catch (error) {
        // The choice still applies for this page view.
    }

    apply(mode);
});

systemQuery.addEventListener('change', () => {
    if (savedMode() === 'system') apply('system');
});

// Another tab changed the setting.
window.addEventListener('storage', (event) => {
    if (event.key === STORAGE_KEY) apply(savedMode());
});

apply(savedMode());
