/**
 * The widths the page is shown at: as wide as there is room, at one
 * width, or at several side by side.
 */

/** A width to show the page at; `media` is the @media condition new rules for it go under when the site's CSS has none that fits. */
export type Width = { name: string; width: number; media: string | null };

/** How the page is shown. `at` is the width being edited: the one whose frame takes clicks. */
export type View = { kind: 'full' } | { kind: 'one'; at: number } | { kind: 'all'; at: number };

/** Space between frames side by side, and the height of the line that names each, in CSS pixels. */
export const GAP = 12;
export const HEAD = 28;

/** A frame as it is laid out. */
export type Slot = {
    /** Which of the widths it shows; null for the one that is as wide as the room. */
    at: number | null;
    name: string;
    /** How wide the page in it is, in the page's own pixels. */
    width: number;
    /** How wide it is shown. */
    box: number;
    /** The one being edited. */
    active: boolean;
};

export type Layout = {
    /** How much smaller than life the frames are drawn: 1 or less. */
    scale: number;
    /** How tall each frame's page is, in the page's own pixels. */
    height: number;
    /** How tall each frame is shown. */
    boxHeight: number;
    /** The frames, the one being edited first, then the others in the order of their widths. */
    slots: Slot[];
};

/** The width being edited; null when the page is as wide as the room. */
export function activeWidth(view: View, widths: Width[]): Width | null {
    return view.kind === 'full' ? null : (widths[view.at] ?? null);
}

/**
 * Where each frame goes in the room there is. Frames side by side are all
 * drawn at one scale, so that what is wider looks wider.
 */
export function layout(view: View, widths: Width[], room: { width: number; height: number }): Layout {
    const active = activeWidth(view, widths);
    if (view.kind === 'full' || active === null || room.width <= 0) {
        return { scale: 1, height: room.height, boxHeight: room.height, slots: [{ at: null, name: 'Full width', width: room.width, box: room.width, active: true }] };
    }
    const shown = view.kind === 'one' ? [view.at] : widths.map((_, at) => at);
    const total = shown.reduce((sum, at) => sum + widths[at].width, 0);
    const scale = Math.min(1, (room.width - GAP * (shown.length - 1)) / total);
    const boxHeight = Math.max(0, room.height - HEAD);
    const slot = (at: number): Slot => ({ at, name: widths[at].name, width: widths[at].width, box: Math.floor(widths[at].width * scale), active: at === view.at });

    return { scale, height: Math.ceil(boxHeight / scale), boxHeight, slots: [slot(view.at), ...shown.filter((at) => at !== view.at).map(slot)] };
}

/** The view as the value of a choice, and back: "full", "all", "one:2". */
export function viewValue(view: View): string {
    return view.kind === 'one' ? `one:${view.at}` : view.kind;
}

export function viewOf(value: string, widths: Width[], now: View): View {
    const at = now.kind === 'full' ? 0 : now.at;
    if (value === 'all' && widths.length > 0) {
        return { kind: 'all', at: Math.min(at, widths.length - 1) };
    }
    const one = /^one:(\d+)$/.exec(value);
    if (one !== null && Number(one[1]) < widths.length) {
        return { kind: 'one', at: Number(one[1]) };
    }

    return { kind: 'full' };
}

/** The widths the server sent, as far as they are widths. */
export function readWidths(sent: unknown): Width[] {
    if (!Array.isArray(sent)) {
        return [];
    }

    return sent.flatMap((each): Width[] => {
        const one = each as { name?: unknown; width?: unknown; media?: unknown } | null;

        return typeof one?.name === 'string' && typeof one.width === 'number' && one.width >= 200 && one.width <= 4000 ? [{ name: one.name, width: Math.round(one.width), media: typeof one.media === 'string' && one.media !== '' ? one.media : null }] : [];
    });
}
