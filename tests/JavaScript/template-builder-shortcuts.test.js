import assert from 'node:assert/strict';
import test from 'node:test';

import {
    builderShortcutIsBlocked,
    handleSelectedFieldDeletion,
    isSelectedFieldDeletionShortcut,
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

test('modals, composition, handled events, and command modifiers block deletion', () => {
    assert.equal(isSelectedFieldDeletionShortcut(keyboardEvent('Delete'), 1, { modalOpen: true }), false);
    assert.equal(isSelectedFieldDeletionShortcut(keyboardEvent('Delete', { isComposing: true }), 1), false);
    assert.equal(isSelectedFieldDeletionShortcut(keyboardEvent('Delete', { defaultPrevented: true }), 1), false);
    assert.equal(isSelectedFieldDeletionShortcut(keyboardEvent('Delete', { ctrlKey: true }), 1), false);
    assert.equal(isSelectedFieldDeletionShortcut(keyboardEvent('Backspace', { altKey: true }), 1), false);
});
