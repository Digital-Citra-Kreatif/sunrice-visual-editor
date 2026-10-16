/**
 * How the editor is laid out for whoever is using it: whether the tree on
 * the left and the code on the right are shown, how wide the code is, and
 * which panels are folded to their titles. Kept in the browser, so the
 * editor opens the way it was left.
 */
type Store = Pick<Storage, 'getItem' | 'setItem'>;

export type Layout = {
    /** Whether the tree left of the page is shown. */
    left: boolean;
    /** Whether the code right of the page is shown. */
    right: boolean;
    /** The panels that are folded to their titles, by name. */
    folded: string[];
    /** How much of the editor's width the code takes. */
    share: number;
};

/** Everything shown, nothing folded: how the editor is the first time. */
export const EVERYTHING: Layout = { left: true, right: true, folded: [], share: 0.4 };

/** The least and the most of the width the code may take: the page is never squeezed out, nor the code. */
export const SHARES = [0.2, 0.7] as const;

/** A share of the width as it may be: a number, and within what is allowed. */
export function shareOf(wanted: unknown): number {
    return typeof wanted === 'number' && Number.isFinite(wanted) ? Math.min(SHARES[1], Math.max(SHARES[0], wanted)) : EVERYTHING.share;
}

const KEY = 'sunrice-visual-editor:layout:2';

/** As many folded panels as are worth remembering. */
const MOST = 50;

export function readLayout(store: Store = window.localStorage): Layout {
    try {
        const kept: unknown = JSON.parse(store.getItem(KEY) ?? 'null');
        if (typeof kept !== 'object' || kept === null || Array.isArray(kept)) {
            return EVERYTHING;
        }
        const { left, right, folded, share } = kept as Record<string, unknown>;

        return {
            left: left !== false,
            right: right !== false,
            folded: Array.isArray(folded) ? [...new Set(folded.filter((name): name is string => typeof name === 'string' && name !== ''))].slice(-MOST) : [],
            share: shareOf(share),
        };
    } catch {
        return EVERYTHING;
    }
}

export function writeLayout(layout: Layout, store: Store = window.localStorage): void {
    try {
        store.setItem(KEY, JSON.stringify(layout));
    } catch {
        // A full or forbidden store: the layout lasts as long as the page does.
    }
}

export function isFolded(layout: Layout, name: string): boolean {
    return layout.folded.includes(name);
}

/** The layout with a panel folded if it was open, and open if it was folded; or as `open` says. */
export function folding(layout: Layout, name: string, open?: boolean): Layout {
    const folded = layout.folded.filter((each) => each !== name);
    const fold = open === undefined ? !isFolded(layout, name) : !open;

    return fold === isFolded(layout, name) ? layout : { ...layout, folded: fold ? [...folded, name].slice(-MOST) : folded };
}

/** The layout with a side shown if it was hidden, and hidden if it was shown; or as `shown` says. */
export function siding(layout: Layout, side: 'left' | 'right', shown?: boolean): Layout {
    const now = shown ?? !layout[side];

    return now === layout[side] ? layout : { ...layout, [side]: now };
}
