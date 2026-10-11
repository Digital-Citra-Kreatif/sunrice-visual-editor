/**
 * The script in a page rendered for the editor's frame. It tells the
 * editor what the pointer is over and what was clicked, and measures what
 * the editor asks it to. It draws nothing on the page, and changes one
 * thing about it: a stylesheet of the theme that the editor has changed is
 * fetched again, so the page shows the file as it is now.
 */
import {
    ATTRIBUTE,
    MANIFEST_ID,
    editorAddress,
    isSheet,
    markValue,
    parseMark,
    sheetAddress,
    shortcutOf,
    unwrap,
    visitorAddress,
    wrap,
    type FromPage,
    type Manifest,
    type Mark,
    type Measured,
    type Mode,
    type Rect,
    type Sheet,
    type Target,
    type ToPage,
    type Watch,
} from './protocol';

const editor = window.parent;
const selector = `[${ATTRIBUTE}]`;

let mode: Mode = 'select';
let watch: Watch = { marks: [], files: [] };
let hovered: Element | null = null;
let measuring = false;

function send(message: FromPage): void {
    editor.postMessage(wrap(message), window.location.origin);
}

function rectOf(element: Element): Rect {
    const box = element.getBoundingClientRect();

    return { x: box.x, y: box.y, width: box.width, height: box.height };
}

function markOf(element: Element): Mark | null {
    return parseMark(element.getAttribute(ATTRIBUTE));
}

/** The marked element an event happened in. */
function markedAt(node: EventTarget | null): Element | null {
    return node instanceof Element ? node.closest(selector) : null;
}

function targetOf(element: Element): Target | null {
    const mark = markOf(element);
    if (mark === null) {
        return null;
    }
    const path: Mark[] = [];
    for (let up: Element | null = element; up !== null; up = up.parentElement?.closest(selector) ?? null) {
        const above = markOf(up);
        if (above !== null) {
            path.push(above);
        }
    }

    return { mark, tag: element.tagName.toLowerCase(), rect: rectOf(element), path };
}

/** Every element on the page drawn from one node: one per copy of its template. */
function copiesOf(mark: Mark): Element[] {
    return Array.from(document.querySelectorAll(`[${ATTRIBUTE}="${markValue(mark)}"]`));
}

/** The outermost elements a template drew: its own, not inside another of its own. */
function regionsOf(file: string): Element[] {
    const own = `[${ATTRIBUTE}^="${file}:"]`;

    return Array.from(document.querySelectorAll(own)).filter((element) => element.parentElement?.closest(own) == null);
}

function measure(): Measured {
    return {
        watch,
        marks: watch.marks.map((mark) => copiesOf(mark).map(rectOf)),
        files: watch.files.map((file) => regionsOf(file).map(rectOf)),
    };
}

function report(): void {
    measuring = false;
    const root = document.documentElement;
    send({
        type: 'measured',
        measured: measure(),
        scroll: { x: window.scrollX, y: window.scrollY },
        page: { width: root.scrollWidth, height: root.scrollHeight },
    });
}

/** Once per frame at most, however often the page scrolls or changes. */
function reportSoon(): void {
    if (!measuring) {
        measuring = true;
        window.requestAnimationFrame(report);
    }
}

function manifest(): Manifest {
    try {
        const parsed = JSON.parse(document.getElementById(MANIFEST_ID)?.textContent ?? '') as Manifest;

        return Array.isArray(parsed.files) ? { files: parsed.files, ...(typeof parsed.status === 'number' ? { status: parsed.status } : {}) } : { files: [] };
    } catch {
        return { files: [] };
    }
}

// --- What the pointer is over --------------------------------------------------

document.addEventListener(
    'mouseover',
    (event) => {
        const element = markedAt(event.target);
        if (element !== hovered) {
            hovered = element;
            send({ type: 'hover', target: element === null ? null : targetOf(element) });
        }
    },
    true,
);

document.documentElement.addEventListener('mouseleave', () => {
    if (hovered !== null) {
        hovered = null;
        send({ type: 'hover', target: null });
    }
});

// --- Clicks --------------------------------------------------------------------

document.addEventListener(
    'click',
    (event) => {
        if (mode === 'select') {
            // The page stays where it is: a click picks an element.
            event.preventDefault();
            event.stopPropagation();
            const element = markedAt(event.target);
            const target = element === null ? null : targetOf(element);
            if (target !== null) {
                send({ type: 'select', target });
            }

            return;
        }

        // Browsing: a link to another page of the site opens it as an editor render.
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (link instanceof HTMLAnchorElement && !event.defaultPrevented && link.target !== '_blank') {
            const address = editorAddress(link.href, window.location.href);
            if (address !== null) {
                link.href = address;
            }
        }
    },
    true,
);

document.addEventListener(
    'submit',
    (event) => {
        if (mode === 'select') {
            event.preventDefault();
            event.stopPropagation();

            return;
        }
        // Browsing: a search or filter form leads to an editor render of its results.
        const form = event.target;
        if (form instanceof HTMLFormElement && form.method.toLowerCase() === 'get' && editorAddress(form.action, window.location.href) !== null) {
            if (form.querySelector('input[name="sve"]') === null) {
                const field = document.createElement('input');
                field.type = 'hidden';
                field.name = 'sve';
                field.value = '1';
                form.appendChild(field);
            }
        }
    },
    true,
);

// --- Keys ----------------------------------------------------------------------

// A click on the page gives the page the keyboard. Undo, redo and delete are the editor's
// while it is picking elements; when browsing, the keys are the page's own.
document.addEventListener(
    'keydown',
    (event) => {
        const shortcut = mode === 'select' ? shortcutOf(event) : null;
        if (shortcut !== null) {
            event.preventDefault();
            event.stopPropagation();
            send({ type: 'key', key: shortcut });
        }
    },
    true,
);

window.addEventListener('pagehide', () => send({ type: 'leaving', url: visitorAddress(window.location.href) }));

// --- Stylesheets ---------------------------------------------------------------

/** A link that is being replaced by a newer one of the same file: gone once that one has loaded. */
const OUTGOING = 'data-sve-outgoing';

/**
 * Shows each stylesheet of the theme the page links as the version it has
 * now. A browser may keep showing the copy it has of a file whose address
 * did not change; with the version in the address it can't. The new copy
 * is fetched beside the old one, which goes when the new one is there: the
 * page is never without its styles.
 */
function refreshSheets(sheets: Sheet[]): void {
    const linked = new Set<string>();
    for (const sheet of sheets) {
        for (const link of Array.from(document.querySelectorAll<HTMLLinkElement>(`link[rel~="stylesheet"][href]:not([${OUTGOING}])`))) {
            if (!isSheet(link.href, sheet, window.location.href)) {
                continue;
            }
            linked.add(sheet.url);
            const address = sheetAddress(link.href, sheet, window.location.href);
            if (address === null) {
                continue;
            }
            const fresh = link.cloneNode() as HTMLLinkElement;
            fresh.href = address;
            link.setAttribute(OUTGOING, '');
            fresh.addEventListener('load', () => {
                // Everything before it that was waiting to go: a link replaced twice in a row leaves two.
                for (let before = fresh.previousElementSibling; before !== null && before.hasAttribute(OUTGOING); before = fresh.previousElementSibling) {
                    before.remove();
                }
                reportSoon();
            });
            fresh.addEventListener('error', () => {
                // The file could not be fetched: the page keeps what it had.
                link.removeAttribute(OUTGOING);
                fresh.remove();
            });
            link.after(fresh);
        }
    }
    send({ type: 'styles', linked: [...linked] });
}

// --- What the editor asks ------------------------------------------------------

window.addEventListener('message', (event) => {
    if (event.source !== editor || event.origin !== window.location.origin) {
        return;
    }
    const message = unwrap<ToPage>(event.data);
    if (message === null) {
        return;
    }

    if (message.type === 'hello') {
        ready();
    } else if (message.type === 'mode') {
        mode = message.mode === 'browse' ? 'browse' : 'select';
    } else if (message.type === 'watch') {
        watch = { marks: message.watch.marks ?? [], files: message.watch.files ?? [] };
        reportSoon();
    } else if (message.type === 'styles') {
        refreshSheets(Array.isArray(message.sheets) ? message.sheets : []);
    } else if (message.type === 'reveal') {
        copiesOf(message.mark)[0]?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        reportSoon();
    }
});

// Whatever moves the boxes: scrolling (of anything, hence capture), resizing, late images and fonts.
window.addEventListener('scroll', reportSoon, true);
window.addEventListener('resize', reportSoon);
window.addEventListener('load', reportSoon);
if ('ResizeObserver' in window) {
    new ResizeObserver(reportSoon).observe(document.documentElement);
}

function ready(): void {
    send({ type: 'ready', url: visitorAddress(window.location.href), title: document.title, manifest: manifest(), mode });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ready);
} else {
    ready();
}
