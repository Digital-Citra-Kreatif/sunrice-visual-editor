/**
 * What the page in the editor's frame and the editor say to each other.
 *
 * The page is rendered with every element marked `data-sve="<file>:<id>"`:
 * the key of the template it came from (see the manifest) and the id of the
 * node in that template. The bridge script in the page reports what the
 * pointer is over and what was clicked; the editor draws the outlines and
 * tells the page what to keep measuring. Messages go by postMessage, same
 * origin only.
 */

/** In the address of a page rendered for the editor. */
export const PARAMETER = 'sve';

/** The attribute every element of an editor render carries. */
export const ATTRIBUTE = 'data-sve';

/** The element holding the manifest, as JSON. */
export const MANIFEST_ID = 'sve-manifest';

/** An element's origin: the key of its template and the node's id in it. */
export type Mark = { file: string; id: string };

/** A box in the frame's viewport, in CSS pixels. */
export type Rect = { x: number; y: number; width: number; height: number };

/** A template that took part in the page. */
export type ManifestFile = {
    key: string;
    path: string;
    origin: 'site' | 'package';
    view: string;
    role: string;
    usedBy: { role: string; subject: string; handle: string }[];
    editable: boolean;
    copyTo: string | null;
    opens: boolean;
};

/** `status`: what the server answered the page with. A page that failed to draw is still a page: 404, 500. */
export type Manifest = { files: ManifestFile[]; status?: number; /** Tailwind's browser build was added to the page: its CSS is a preview of what a build would make. */ preview?: boolean };

/** A marked element under the pointer or clicked. */
export type Target = {
    mark: Mark;
    tag: string;
    rect: Rect;
    /** The marked elements around it, innermost first, itself included. */
    path: Mark[];
};

/** What the editor asked the page to keep measuring. */
export type Watch = {
    /** Elements: every copy of each (a partial in a loop is drawn many times). */
    marks: Mark[];
    /** Templates: the outermost elements each one drew. */
    files: string[];
};

/**
 * The boxes of what is watched, in the order asked, with what was asked:
 * an answer may cross a new question on the way.
 */
export type Measured = {
    watch: Watch;
    marks: Rect[][];
    files: Rect[][];
};

export type Mode = 'select' | 'browse';

/** What the editor does from the keyboard, wherever the keyboard is. */
export type Shortcut = 'undo' | 'redo' | 'delete';

/**
 * The shortcut a key press means, if any: Ctrl/⌘+Z, Ctrl/⌘+Shift+Z or
 * Ctrl+Y, Delete or Backspace. Not while something is being typed into.
 */
export function shortcutOf(event: { key: string; ctrlKey: boolean; metaKey: boolean; shiftKey: boolean; altKey: boolean; target: unknown }): Shortcut | null {
    const target = event.target as { tagName?: unknown; isContentEditable?: unknown } | null;
    const tag = typeof target?.tagName === 'string' ? target.tagName.toLowerCase() : '';
    if (tag === 'input' || tag === 'textarea' || tag === 'select' || target?.isContentEditable === true || event.altKey) {
        return null;
    }
    const key = event.key.toLowerCase();
    if (event.ctrlKey || event.metaKey) {
        return key === 'z' ? (event.shiftKey ? 'redo' : 'undo') : key === 'y' && !event.shiftKey ? 'redo' : null;
    }

    return !event.shiftKey && (key === 'delete' || key === 'backspace') ? 'delete' : null;
}

/** From the page to the editor. */
export type FromPage =
    | { type: 'ready'; url: string; title: string; manifest: Manifest; mode: Mode }
    | { type: 'hover'; target: Target | null }
    | { type: 'select'; target: Target }
    | { type: 'measured'; measured: Measured; scroll: { x: number; y: number }; page: { width: number; height: number } }
    /** A key the editor acts on was pressed while the page had the keyboard. */
    | { type: 'key'; key: Shortcut }
    /** Which of the stylesheets it was told of the page links, by the address each was given with. */
    | { type: 'styles'; linked: string[] }
    /** What the page has for the properties asked about, for the first copy of an element; `values` is null when the element is not on the page. */
    | { type: 'computed'; mark: Mark; values: Record<string, string> | null }
    | { type: 'leaving'; url: string };

/** From the editor to the page. */
export type ToPage =
    /** "Are you a page drawn for the editor?" Answered with `ready`, again. */
    | { type: 'hello' }
    | { type: 'mode'; mode: Mode }
    | { type: 'watch'; watch: Watch }
    | { type: 'reveal'; mark: Mark }
    /** The theme's stylesheets as they are now: the page shows each it links as that version, and says which it links. */
    | { type: 'styles'; sheets: Sheet[] }
    /** "What does this element have for these properties?" Answered with `computed`, and again whenever a stylesheet has been fetched anew. Null: nothing is asked any more. */
    | { type: 'computed'; ask: { mark: Mark; properties: string[] } | null };

/** A stylesheet of the theme: where pages link it from, and the version it has. */
export type Sheet = { url: string; version: string };

/** In the address of a stylesheet shown as one version of it. */
export const SHEET_VERSION = 'sve-v';

/**
 * The address a link to a stylesheet should have to show a version of it;
 * null when the link is to another file, or shows that version already.
 * The same file is the same path: what stands after "?" is the page's own
 * way of asking for it afresh.
 */
export function sheetAddress(href: string, sheet: Sheet, base: string): string | null {
    let at: URL;
    let wanted: URL;
    try {
        at = new URL(href, base);
        wanted = new URL(sheet.url, base);
    } catch {
        return null;
    }
    if (at.pathname !== wanted.pathname || at.searchParams.get(SHEET_VERSION) === sheet.version) {
        return null;
    }
    at.searchParams.set(SHEET_VERSION, sheet.version);

    return at.href;
}

/** Whether a link is to that stylesheet, whatever version of it. */
export function isSheet(href: string, sheet: Sheet, base: string): boolean {
    try {
        return new URL(href, base).pathname === new URL(sheet.url, base).pathname;
    } catch {
        return false;
    }
}

/** Every message is wrapped in this, so nobody else's is taken for one. */
export type Envelope<T> = { sve: 1; message: T };

export function wrap<T>(message: T): Envelope<T> {
    return { sve: 1, message };
}

export function unwrap<T>(data: unknown): T | null {
    if (typeof data !== 'object' || data === null) {
        return null;
    }
    const envelope = data as Partial<Envelope<T>>;
    if (envelope.sve !== 1 || typeof envelope.message !== 'object' || envelope.message === null) {
        return null;
    }

    return envelope.message;
}

/** "a1b2c3d4e5:412" → the mark; null for anything else. */
export function parseMark(value: string | null | undefined): Mark | null {
    const match = /^([0-9a-f]+):(\d+)$/.exec(value ?? '');

    return match ? { file: match[1], id: match[2] } : null;
}

export function markValue(mark: Mark): string {
    return `${mark.file}:${mark.id}`;
}

/**
 * The address of the same page as an editor render, when it is a page of
 * this site: `sve=1` added, everything else kept. Null for another site,
 * and for what isn't a page at all (mailto:, javascript:).
 */
export function editorAddress(href: string, base: string): string | null {
    let url: URL;
    let site: URL;
    try {
        site = new URL(base);
        url = new URL(href, site);
    } catch {
        return null;
    }
    if (url.origin !== site.origin || !/^https?:$/.test(url.protocol)) {
        return null;
    }
    url.searchParams.set(PARAMETER, '1');

    return url.href;
}

/** Whether an address is already one of an editor render. */
export function isEditorAddress(href: string): boolean {
    try {
        return new URL(href).searchParams.get(PARAMETER) === '1';
    } catch {
        return false;
    }
}

/** The address as a visitor has it: without `sve`. */
export function visitorAddress(href: string): string {
    const url = new URL(href);
    url.searchParams.delete(PARAMETER);

    return url.pathname + url.search + url.hash;
}
