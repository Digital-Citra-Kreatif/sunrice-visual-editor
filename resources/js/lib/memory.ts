/**
 * The last page each template was seen on, kept in the browser, so opening
 * a template that isn't on the page in the frame can show one that uses it.
 */
type Store = Pick<Storage, 'getItem' | 'setItem'>;

const KEY = 'sunrice-visual-editor:pages';

/** As many templates as are worth remembering; the oldest go first. */
const MOST = 200;

function read(store: Store): Record<string, string> {
    try {
        const parsed: unknown = JSON.parse(store.getItem(KEY) ?? '{}');

        return typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed) ? (parsed as Record<string, string>) : {};
    } catch {
        return {};
    }
}

/** Notes that these templates drew this page. */
export function rememberPage(templates: string[], page: string, store: Store = window.localStorage): void {
    const pages = read(store);
    for (const template of templates) {
        // Deleted and added again: the newest are last.
        delete pages[template];
        pages[template] = page;
    }
    const names = Object.keys(pages);
    for (const stale of names.slice(0, Math.max(0, names.length - MOST))) {
        delete pages[stale];
    }
    try {
        store.setItem(KEY, JSON.stringify(pages));
    } catch {
        // A full or forbidden store: nothing is remembered, nothing else changes.
    }
}

/** The page a template was last seen on, if any. */
export function pageOf(template: string, store: Store = window.localStorage): string | null {
    const page = read(store)[template];

    return typeof page === 'string' && page.startsWith('/') ? page : null;
}
