/**
 * Undo history for the Template Builder.
 *
 * The markers, the person-row mode and a ledger's printed row lines are one
 * layout and are undone together: "Remove grid" followed by Ctrl+Z brings back
 * the columns and their row lines, not the columns alone.
 *
 * States are kept as JSON strings, so a step is an exact copy that later edits
 * cannot reach, and two states compare by value.
 */
export class LayoutHistory {
    /** @param {{limit?: number}} [options] */
    constructor({ limit = 100 } = {}) {
        this.limit = limit;
        this.steps = [];
        this.current = null;
    }

    /**
     * The layout as it is now. When it differs from the last one recorded,
     * that one becomes an undo step. Returns whether a step was added.
     */
    record(state) {
        const next = JSON.stringify(state);
        const changed = this.current !== null && next !== this.current;

        if (changed) {
            this.steps.push(this.current);
            if (this.steps.length > this.limit) this.steps.shift();
        }

        this.current = next;
        return changed;
    }

    /**
     * Take the layout as it now stands as the current one without adding a
     * step: after an undo has been applied, whatever the editor normalised
     * while restoring it is not a new change.
     */
    sync(state) {
        this.current = JSON.stringify(state);
    }

    /** The previous layout (a fresh copy), or null when there is none. */
    undo() {
        const previous = this.steps.pop();
        if (previous === undefined) return null;

        this.current = previous;
        return JSON.parse(previous);
    }

    get canUndo() {
        return this.steps.length > 0;
    }
}
