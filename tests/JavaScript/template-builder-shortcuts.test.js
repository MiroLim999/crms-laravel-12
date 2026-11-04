import assert from 'node:assert/strict';
import test from 'node:test';

import {
    builderShortcutIsBlocked,
    handleSelectedFieldDeletion,
    isSelectedFieldDeletionShortcut,
    layoutUndoIsBlocked,
} from '../../resources/js/template-builder-shortcuts.js';

function keyboardEvent(key, options = {}) {
    const target = options.target ?? { tagName: 'DIV', isContentEditable: false };

    return {
        key,
        target,
        defaultPrevented: options.defaultPrevented ?? false,
        isComposing: options.isComposing ?? false,
        ctrlKey: options.ctrlKey ?? false,
        metaKey: options.metaKey ?? false,
        altKey: options.altKey ?? false,
        composedPath: options.path ? () => options.path : undefined,
        preventDefault() {
            this.defaultPrevented = true;
        },
    };
}

test('Backspace and Delete remove a non-empty builder selection', () => {
    for (const key of ['Backspace', 'Delete']) {
        const event = keyboardEvent(key);
        let removals = 0;
        const marker = {
            selectedIndexes: () => [0, 1],
            removeSelected: () => { removals += 1; },
        };

        assert.equal(handleSelectedFieldDeletion(event, marker), true);
        assert.equal(event.defaultPrevented, true);
        assert.equal(removals, 1);
    }
});

test('deletion shortcuts do nothing without selected fields or for another key', () => {
    assert.equal(isSelectedFieldDeletionShortcut(keyboardEvent('Delete'), 0), false);
    assert.equal(isSelectedFieldDeletionShortcut(keyboardEvent('Enter'), 2), false);
});

test('text-entry controls keep Backspace and Delete for editing', () => {
    for (const tagName of ['INPUT', 'TEXTAREA', 'SELECT']) {
        const event = keyboardEvent('Backspace', {
            target: { tagName, isContentEditable: false },
        });

        assert.equal(builderShortcutIsBlocked(event), true);
        assert.equal(isSelectedFieldDeletionShortcut(event, 1), false);
    }
});

test('interactive controls and contenteditable descendants are protected', () => {
    const editableParent = { tagName: 'DIV', isContentEditable: true };
    const child = { tagName: 'SPAN', isContentEditable: false };
    const contenteditableEvent = keyboardEvent('Delete', {
        target: child,
        path: [child, editableParent],
    });

    assert.equal(isSelectedFieldDeletionShortcut(contenteditableEvent, 1), false);
    assert.equal(isSelectedFieldDeletionShortcut(keyboardEvent('Delete', {
        target: { tagName: 'BUTTON', isContentEditable: false },
    }), 1), false);
    assert.equal(isSelectedFieldDeletionShortcut(keyboardEvent('Delete', {
        target: { tagName: 'A', isContentEditable: false },
    }), 1), false);
});

test('Ctrl+Z undoes the layout after a click on a button, checkbox or select', () => {
    for (const target of [
        { tagName: 'BUTTON' },
        { tagName: 'INPUT', type: 'checkbox', value: 'on' },
        { tagName: 'SELECT', value: 'auto' },
        { tagName: 'A' },
        { tagName: 'DIV', isContentEditable: false },
    ]) {
        assert.equal(layoutUndoIsBlocked(keyboardEvent('z', { ctrlKey: true, target })), false, target.tagName);
    }
});

test('Ctrl+Z undoes the layout from an empty text box (the Add field box after adding)', () => {
    const target = { tagName: 'INPUT', type: 'text', value: '' };

    assert.equal(layoutUndoIsBlocked(keyboardEvent('z', { ctrlKey: true, target })), false);
});

test('Ctrl+Z in a text box with text, or in contenteditable, undoes the typing', () => {
    assert.equal(layoutUndoIsBlocked(keyboardEvent('z', {
        ctrlKey: true, target: { tagName: 'INPUT', type: 'text', value: 'Column 1' },
    })), true);
    assert.equal(layoutUndoIsBlocked(keyboardEvent('z', {
        ctrlKey: true, target: { tagName: 'TEXTAREA', value: 'note' },
    })), true);
    const editableParent = { tagName: 'DIV', isContentEditable: true };
    const child = { tagName: 'SPAN', isContentEditable: false };
    assert.equal(layoutUndoIsBlocked(keyboardEvent('z', {
        ctrlKey: true, target: child, path: [child, editableParent],
    })), true);
});

test('Ctrl+Z waits while a dialog is open or the key was already handled', () => {
    assert.equal(layoutUndoIsBlocked(keyboardEvent('z', { ctrlKey: true }), { modalOpen: true }), true);
    assert.equal(layoutUndoIsBlocked(keyboardEvent('z', { ctrlKey: true, defaultPrevented: true })), true);
    assert.equal(layoutUndoIsBlocked(keyboardEvent('z', { ctrlKey: true, isComposing: true })), true);
});

test('modals, composition, handled events, and command modifiers block deletion', () => {
    assert.equal(isSelectedFieldDeletionShortcut(keyboardEvent('Delete'), 1, { modalOpen: true }), false);
    assert.equal(isSelectedFieldDeletionShortcut(keyboardEvent('Delete', { isComposing: true }), 1), false);
    assert.equal(isSelectedFieldDeletionShortcut(keyboardEvent('Delete', { defaultPrevented: true }), 1), false);
    assert.equal(isSelectedFieldDeletionShortcut(keyboardEvent('Delete', { ctrlKey: true }), 1), false);
    assert.equal(isSelectedFieldDeletionShortcut(keyboardEvent('Backspace', { altKey: true }), 1), false);
});
