/**
 * Drawing the page again without it being seen to go: the new page is
 * loaded in a second frame, out of sight, and shown in place of the old
 * one when it is ready. This is when that is.
 *
 * A page drawn for the editor says `ready` once its document is read, and
 * the frame says `load` once its images and scripts are in too. The second
 * is the better moment, and can be long in coming (one slow file from
 * another site holds it back), so it is waited for only a short while
 * after the first. A page that says neither is given up on.
 */
export type Signal = 'ready' | 'load';

export type Redraw = {
    /** The new page said something. */
    heard(signal: Signal): void;
    /** It is no longer wanted: another change was made, or the frame was sent elsewhere. */
    cancel(): void;
};

type Options = {
    /** How long after `ready` to wait for `load`. */
    settle: number;
    /** How long to wait for anything at all. */
    giveUp: number;
    /** Runs something later; the answer calls it off. */
    later: (after: number, run: () => void) => () => void;
    /** The new page is ready to be shown. */
    show: () => void;
    /** Nothing came of it. */
    fail: () => void;
};

export function startRedraw({ settle, giveUp, later, show, fail }: Options): Redraw {
    let over = false;
    let settling: (() => void) | null = null;

    const end = (how: () => void) => {
        if (over) {
            return;
        }
        over = true;
        settling?.();
        stopWaiting();
        how();
    };
    const stopWaiting = later(giveUp, () => end(fail));

    return {
        heard(signal) {
            if (over) {
                return;
            }
            if (signal === 'load') {
                end(show);
            } else if (settling === null) {
                settling = later(settle, () => end(show));
            }
        },
        cancel() {
            end(() => {});
        },
    };
}
