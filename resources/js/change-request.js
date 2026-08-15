import { setDisclosureExpanded } from './disclosure-motion.js';

export function normaliseChangeRequestValue(value) {
    if (value === null || value === undefined) return null;

    const normalised = String(value).trim();

    return normalised === '' ? null : normalised;
}

export function changeRequestValueChanged(current, proposed) {
    return normaliseChangeRequestValue(current) !== normaliseChangeRequestValue(proposed);
}

export function countChangedProposals(proposals) {
    return proposals.reduce(
        (count, proposal) => count + Number(changeRequestValueChanged(proposal.current, proposal.proposed)),
        0,
    );
}

function initChangeRequestForm(root) {
    const inputs = [...root.querySelectorAll('[data-change-input]')]
        .filter((input) => input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement);
    const count = root.querySelector('[data-change-count]');
    const countLabel = root.querySelector('[data-change-count-label]');
    const readyBadge = root.querySelector('[data-change-ready-badge]');
    const submit = root.querySelector('[data-change-submit]');

    const update = () => {
        inputs.forEach((input) => {
            input.closest('[data-change-row]')?.classList.toggle(
                'is-changed',
                changeRequestValueChanged(input.dataset.currentValue, input.value),
            );
        });

        root.querySelectorAll('[data-change-group]').forEach((group) => {
            const changed = group.querySelectorAll('[data-change-row].is-changed').length;
            const groupCount = group.querySelector('[data-change-group-count]');
            if (!groupCount) return;

            groupCount.textContent = `${changed} changed`;
            groupCount.classList.toggle('bg-label-secondary', changed === 0);
            groupCount.classList.toggle('bg-label-primary', changed > 0);
        });

        const changed = countChangedProposals(inputs.map((input) => ({
            current: input.dataset.currentValue,
            proposed: input.value,
        })));
        if (count) count.textContent = String(changed);
        if (countLabel) countLabel.textContent = changed === 0
            ? 'No values changed'
            : `${changed} value${changed === 1 ? '' : 's'} ready for review`;
        if (submit instanceof HTMLButtonElement) submit.disabled = changed === 0;
        if (readyBadge) {
            readyBadge.textContent = changed === 0
                ? 'No changes selected'
                : `${changed} change${changed === 1 ? '' : 's'} selected`;
            readyBadge.classList.toggle('bg-label-secondary', changed === 0);
            readyBadge.classList.toggle('bg-label-primary', changed > 0);
        }
    };

    inputs.forEach((input) => input.addEventListener('input', update));
    root.querySelector('[data-change-reset]')?.addEventListener('click', () => {
        inputs.forEach((input) => {
            input.value = input.dataset.currentValue ?? '';
        });
        update();
        inputs[0]?.focus();
    });

    root.querySelectorAll('[data-change-group]').forEach((group) => {
        const body = group.querySelector('.record-field-group__body');
        const summary = group.querySelector('.record-field-group__summary');
        if (!(group instanceof HTMLDetailsElement)
            || !(body instanceof HTMLElement)
            || !(summary instanceof HTMLElement)) return;

        body.hidden = !group.open;
        group.classList.toggle('is-expanded', group.open);
        summary.addEventListener('click', (event) => {
            event.preventDefault();
            const expanded = !group.classList.contains('is-expanded');

            if (expanded) {
                body.hidden = true;
                group.open = true;
            }
            group.classList.toggle('is-expanded', expanded);
            setDisclosureExpanded(body, expanded).then(() => {
                if (!expanded) group.open = false;
            });
        });
    });

    update();
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-change-request-form]').forEach(initChangeRequestForm);
}
