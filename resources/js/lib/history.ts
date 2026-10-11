/**
 * Undo and redo for one template, for as long as the editor is open: the
 * file as it was before each change, to save again.
 *
 * A step is a whole source, not a change to one: undoing writes the file
 * as it was, whatever kind of change made it otherwise. The history holds
 * for the version of the file it knows as the latest. A file that has
 * changed otherwise, in a code editor or by someone else, has moved on
 * without it: nothing of it is offered any more, so that an undo never
 * writes over a change it has not seen.
 */

/** A template as it was: its text, and what was selected in it. */
export type Step = { source: string; selected: string | null };

export type History = {
    /** What to go back to, oldest first. */
    past: Step[];
    /** What was gone back from, to go forward to again; the next first to be redone is last. */
    future: Step[];
    /** The version of the file after the last step this history knows of. */
    version: string;
};

/** How many steps back are kept for a template. */
export const LIMIT = 100;

/** The history, if it is still about the file as it is now. */
function current(history: History | undefined, version: string): History | null {
    return history !== undefined && history.version === version ? history : null;
}

export function canUndo(history: History | undefined, version: string): boolean {
    return (current(history, version)?.past.length ?? 0) > 0;
}

export function canRedo(history: History | undefined, version: string): boolean {
    return (current(history, version)?.future.length ?? 0) > 0;
}

/**
 * After a change: `before` is the file as it was, at `was`; `now` is its
 * version since. What had been undone can't be redone any more.
 */
export function recorded(history: History | undefined, before: Step, was: string, now: string): History {
    if (was === now) {
        // Nothing changed: the history stands, if there is one.
        return history ?? { past: [], future: [], version: now };
    }
    const past = [...(current(history, was)?.past ?? []), before];

    return { past: past.slice(Math.max(0, past.length - LIMIT)), future: [], version: now };
}

/** The step an undo goes back to; null when there is none. */
export function undoStep(history: History | undefined, version: string): Step | null {
    const known = current(history, version);

    return known === null ? null : (known.past[known.past.length - 1] ?? null);
}

/** The step a redo goes forward to; null when there is none. */
export function redoStep(history: History | undefined, version: string): Step | null {
    const known = current(history, version);

    return known === null ? null : (known.future[known.future.length - 1] ?? null);
}

/** After an undo was saved: `left` is the file as it was just before the undo, `now` its version since. */
export function undone(history: History, left: Step, now: string): History {
    return { past: history.past.slice(0, -1), future: [...history.future, left], version: now };
}

/** After a redo was saved. */
export function redone(history: History, left: Step, now: string): History {
    return { past: [...history.past, left], future: history.future.slice(0, -1), version: now };
}
