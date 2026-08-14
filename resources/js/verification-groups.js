/**
 * Summarise a person's field checkboxes for the group-level control.
 *
 * @param {boolean[]} checkedStates
 * @returns {{checked: boolean, indeterminate: boolean, verified: number, total: number}}
 */
export function verificationGroupState(checkedStates) {
    const total = checkedStates.length;
    const verified = checkedStates.filter(Boolean).length;

    return {
        checked: total > 0 && verified === total,
        indeterminate: verified > 0 && verified < total,
        verified,
        total,
    };
}

/**
 * A field may only be bulk-verified when it contains a human-confirmable value.
 */
export function canVerifyValue(value) {
    return String(value ?? '').trim() !== '';
}
