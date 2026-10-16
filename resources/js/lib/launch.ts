/**
 * "Edit with Visual Editor" on an entry's page in the admin: which page of
 * the site the editor opens on for it. Pure, so it can be tested without
 * the admin around it.
 */

/** What the admin tells every page: whether whoever is signed in may open the editor (VisualEditorServiceProvider). */
export type Shared = { visualEditor?: { open?: boolean } | null };

type Translation = { url?: string | null };

/** The props of Sunrice's entry page (Entries/Edit) that are read here. */
export type EntryProps = Shared & {
    entry?: { translations?: Record<string, Translation | null | undefined> } | null;
    mainLocale?: string;
    locales?: string[];
};

/** The page component of an entry being edited in Sunrice's admin. */
export const ENTRY_PAGE = 'Entries/Edit';

/** A public address as a path of this site, with its query; null for another site, or nothing usable. */
export function pathOn(url: string | null | undefined, origin: string): string | null {
    if (typeof url !== 'string' || url === '') {
        return null;
    }
    try {
        const parsed = new URL(url, origin);

        return parsed.origin === origin ? `${parsed.pathname}${parsed.search}` : null;
    } catch {
        return null;
    }
}

/**
 * The site path the editor opens on for an entry: the page the admin's
 * own "View page" link goes to, which follows the language being edited;
 * else the entry's address in the main language, or in any. Null when the
 * entry has no page of its own (a new entry, a collection without single
 * pages) or whoever is signed in may not open the editor.
 */
export function entryPath(component: string, props: EntryProps, origin: string, viewLink: string | null = null): string | null {
    if (component !== ENTRY_PAGE || props.visualEditor?.open !== true || props.entry === null || props.entry === undefined) {
        return null;
    }
    const linked = pathOn(viewLink, origin);
    if (linked !== null) {
        return linked;
    }
    const translations = props.entry.translations ?? {};
    const main = props.mainLocale ?? props.locales?.[0];
    const order = [...(main === undefined ? [] : [main]), ...Object.keys(translations).filter((locale) => locale !== main)];
    for (const locale of order) {
        const path = pathOn(translations[locale]?.url, origin);
        if (path !== null) {
            return path;
        }
    }

    return null;
}

/** The editor's address for a site path, under the admin's path. */
export function editorUrl(adminUrl: (path: string) => string, path: string): string {
    return `${adminUrl('visual-editor')}?${new URLSearchParams({ url: path }).toString()}`;
}
