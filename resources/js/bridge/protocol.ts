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

export type Manifest = { files: ManifestFile[] };

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

/** From the page to the editor. */
export type FromPage =
    | { type: 'ready'; url: string; title: string; manifest: Manifest; mode: Mode }
    | { type: 'hover'; target: Target | null }
    | { type: 'select'; target: Target }
    | { type: 'measured'; measured: Measured; scroll: { x: number; y: number }; page: { width: number; height: number } }
    | { type: 'leaving'; url: string };

/** From the editor to the page. */
export type ToPage =
    /** "Are you a page drawn for the editor?" Answered with `ready`, again. */
    | { type: 'hello' }
    | { type: 'mode'; mode: Mode }
    | { type: 'watch'; watch: Watch }
    | { type: 'reveal'; mark: Mark };

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
