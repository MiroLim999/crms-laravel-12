{{--
    Review & Export's Shortcuts menu: the table's keys, Windows and Mac, in place
    of a help text over the table. Used by the scan workspace's step 4 and by a
    saved record's Review & Export page ($savedRecord: Save keeps an audit trail).
--}}
<div class="dropdown">
    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle marker-help-button"
            data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
            aria-label="Show the table's shortcuts and tips">
        <i class="icon-base bx bx-terminal icon-sm me-1" aria-hidden="true"></i>
        <span>Shortcuts</span>
    </button>
    <div class="dropdown-menu dropdown-menu-end marker-shortcuts-menu review-shortcuts-menu">
        <div class="marker-shortcuts-menu__title">Edit a cell</div>
        {{-- Mac keys beside the Windows ones: ⌘ Command for Ctrl. --}}
        <p class="marker-shortcuts-menu__note">On a Mac, <kbd>⌘</kbd> is Command and <kbd>Delete</kbd> is Backspace.</p>
        <div><span>Click, or type</span><small>Edit it (shows it on the scan)</small></div>
        <div><span><kbd>Enter</kbd> / <kbd>Tab</kbd></span><small>Keep, go down / right</small></div>
        <div><span><kbd>Esc</kbd></span><small>Cancel the edit</small></div>
        <div><span><kbd>↑</kbd> <kbd>↓</kbd> <kbd>←</kbd> <kbd>→</kbd></span><small>Move</small></div>

        <div class="marker-shortcuts-menu__title">Several cells of a column</div>
        <div><span>Drag up or down</span><small>Pick them</small></div>
        <div><span><kbd>Shift</kbd> + click / <kbd>↑</kbd> <kbd>↓</kbd></span><small>Pick more</small></div>
        <div><span>Type, then <kbd>Enter</kbd></span><small>Same value in all</small></div>
        <div><span><kbd>Del</kbd> / <kbd>Backspace</kbd></span><small>Empty them</small></div>

        <div class="marker-shortcuts-menu__title">Copy and paste</div>
        <div><span><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>C</kbd> / <kbd>X</kbd></span><small>Copy / cut the picked cells</small></div>
        <div><span><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>V</kbd></span><small>One value fills every picked cell; a list or Excel block fills down from the first</small></div>

        <div class="marker-shortcuts-menu__title">Undo</div>
        <div><span><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>Z</kbd></span><small>Undo the last change (or rename)</small></div>
        <div><span><kbd>Ctrl</kbd> + <kbd>Y</kbd> / <kbd>⌘</kbd> + <kbd>Shift</kbd> + <kbd>Z</kbd></span><small>Redo</small></div>

        <div class="marker-shortcuts-menu__title">Move a column</div>
        <div><span>Drag its header</span><small>Let go where the blue line shows (the table scrolls at its edges)</small></div>
        <div><span><kbd>Alt</kbd> / <kbd>⌥</kbd> + <kbd>Shift</kbd> + <kbd>←</kbd> <kbd>→</kbd></span><small>Move the picked cell's column one place</small></div>
        <div><span><kbd>Esc</kbd> while dragging</span><small>Put it back</small></div>

        <div class="marker-shortcuts-menu__title">Tips</div>
        <div><span>Double-click a header or row number</span><small>Rename it in the download</small></div>
        <div><span>Point at a header: ×</span><small>Remove the column (asks first; Removed columns brings it back)</small></div>
        <div><span>Red / yellow cell</span><small>Invalid / empty (the button above goes to the next)</small></div>
        @if (!empty($savedRecord))
            <div><span>Save changes</span><small>Each old and new value goes to the audit log</small></div>
        @endif
    </div>
</div>
