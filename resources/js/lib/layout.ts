/**
 * How the editor is laid out for whoever is using it: which side is shown,
 * and which panels and sections are folded to their titles. Kept in the
 * browser, so the editor opens the way it was left.
 */
type Store = Pick<Storage, 'getItem' | 'setItem'>;

export type Layout = {
    /** Whether the panels left of the page are shown. */
    left: boolean;
    /** Whether the panels right of the page are shown. */
    right: boolean;
    /** The panels and sections that are folded to their titles, by name. */
    folded: string[];
};

/** Everything shown, nothing folded: how the editor is the first time. */
export const EVERYTHING: Layout = { left: true, right: true, folded: [] };

const KEY = 'sunrice-visual-editor:layout';

/** As many folded sections as are worth remembering. */
const MOST = 200;

export function readLayout(store: Store = window.localStorage): Layout {
    try {
        const kept: unknown = JSON.parse(store.getItem(KEY) ?? 'null');
        if (typeof kept !== 'object' || kept === null || Array.isArray(kept)) {
            return EVERYTHING;
        }
        const { left, right, folded } = kept as Record<string, unknown>;

        return {
            left: left !== false,
            right: right !== false,
            folded: Array.isArray(folded) ? [...new Set(folded.filter((name): name is string => typeof name === 'string' && name !== ''))].slice(-MOST) : [],
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

/** The layout with a panel or section folded if it was open, and open if it was folded; or as `open` says. */
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
