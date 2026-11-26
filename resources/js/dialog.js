/**
 * CRMS's own question and message windows, in place of the browser's
 * confirm() and alert() ("127.0.0.1 says"): centred, with an icon, a title,
 * the message, and the buttons in the middle.
 *
 *   if (await confirmDialog({ title, message, confirmText })) { ... }
 *   await alertDialog({ title, message });
 *
 * Escape, the ×-less backdrop and Cancel all answer false.
 */

const TONES = {
    primary: { icon: 'bx-help-circle', button: 'btn-primary' },
    warning: { icon: 'bx-error', button: 'btn-warning' },
    danger: { icon: 'bx-trash', button: 'btn-danger' },
    info: { icon: 'bx-info-circle', button: 'btn-primary' },
};

/**
 * @param {object} options
 * @param {string} options.title
 * @param {string} [options.message] Line breaks are kept.
 * @param {string[]} [options.details] Shown as a short list under the message.
 * @param {string} [options.confirmText]
 * @param {string|null} [options.cancelText] null: only the one button (a message).
 * @param {'primary'|'warning'|'danger'|'info'} [options.tone]
 * @param {string} [options.icon] A bx-* icon in place of the tone's own.
 * @returns {Promise<boolean>}
 */
export function confirmDialog({
    title,
    message = '',
    details = [],
    confirmText = 'OK',
    cancelText = 'Cancel',
    tone = 'primary',
    icon = null,
} = {}) {
    const Modal = window.bootstrap?.Modal;
    const look = TONES[tone] ?? TONES.primary;
    if (!Modal) {
        // No Bootstrap on the page: the browser's own box, so nothing is skipped.
        const text = [title, message, ...details.map((line) => `• ${line}`)].filter(Boolean).join('\n\n');
        if (cancelText === null) {
            window.alert(text);
            return Promise.resolve(true);
        }
        return Promise.resolve(window.confirm(text));
    }

    const element = document.createElement('div');
    element.className = `modal fade crms-dialog crms-dialog--${tone in TONES ? tone : 'primary'}`;
    element.tabIndex = -1;
    element.setAttribute('aria-hidden', 'true');
    element.setAttribute('role', cancelText === null ? 'alertdialog' : 'dialog');
    element.innerHTML = `
        <div class="modal-dialog modal-dialog-centered crms-dialog__dialog">
            <div class="modal-content text-center">
                <div class="modal-body crms-dialog__body">
                    <span class="crms-dialog__icon" aria-hidden="true"><i class="icon-base bx"></i></span>
                    <h2 class="crms-dialog__title h5"></h2>
                    <p class="crms-dialog__message text-muted"></p>
                    <ul class="crms-dialog__details text-start"></ul>
                </div>
                <div class="modal-footer crms-dialog__footer">
                    <button type="button" class="btn btn-label-secondary" data-answer="no"></button>
                    <button type="button" class="btn" data-answer="yes"></button>
                </div>
            </div>
        </div>`;

    const id = `crmsDialog${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
    const titleEl = element.querySelector('.crms-dialog__title');
    const messageEl = element.querySelector('.crms-dialog__message');
    const detailsEl = element.querySelector('.crms-dialog__details');
    titleEl.id = `${id}Title`;
    titleEl.textContent = title;
    element.setAttribute('aria-labelledby', titleEl.id);
    if (message) {
        messageEl.id = `${id}Text`;
        messageEl.textContent = message;
        element.setAttribute('aria-describedby', messageEl.id);
    } else {
        messageEl.remove();
    }
    if (details.length > 0) {
        detailsEl.replaceChildren(...details.map((line) => {
            const item = document.createElement('li');
            item.textContent = line;
            return item;
        }));
    } else {
        detailsEl.remove();
    }
    element.querySelector('.crms-dialog__icon i').classList.add(icon ?? look.icon);

    const yes = element.querySelector('[data-answer="yes"]');
    const no = element.querySelector('[data-answer="no"]');
    yes.textContent = confirmText;
    yes.classList.add(look.button);
    if (cancelText === null) no.remove();
    else no.textContent = cancelText;

    document.body.append(element);
    const modal = new Modal(element);
    let answer = false;

    return new Promise((resolve) => {
        yes.addEventListener('click', () => {
            answer = true;
            modal.hide();
        });
        no?.addEventListener('click', () => modal.hide());
        element.addEventListener('shown.bs.modal', () => yes.focus());
        element.addEventListener('hidden.bs.modal', () => {
            modal.dispose();
            element.remove();
            resolve(answer);
        });
        modal.show();
        // Above any window already open (its backdrop too).
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops[backdrops.length - 1]?.classList.add('crms-dialog-backdrop');
    });
}

/** A message with one button. Resolves once it is closed. */
export function alertDialog({ title, message = '', details = [], confirmText = 'OK', tone = 'warning', icon = null } = {}) {
    return confirmDialog({ title, message, details, confirmText, cancelText: null, tone, icon }).then(() => undefined);
}

// For inline page scripts that do not import modules.
window.crmsDialog = { confirm: confirmDialog, alert: alertDialog };
