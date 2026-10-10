/**
 * What the panels need to know about the templates of a page, worked out
 * from the manifest.
 */
import type { ManifestFile } from '../bridge/protocol';

/** A template, named the way the API names it. */
export type TemplateName = { path: string; origin: 'site' | 'package' };

/** Roles in the order a page is built: the layout, the page's own template, then its parts. */
const ORDER = ['layout', 'entry', 'listing', 'term', 'search', 'error', 'other', 'partial', 'block', 'component'];

/** The roles of a template that is a page's own: the one a collection or taxonomy is set to use. */
const PAGE_ROLES = ['entry', 'listing', 'term', 'search'];

export function sameTemplate(a: TemplateName | null, b: TemplateName | null): boolean {
    return a !== null && b !== null && a.path === b.path && a.origin === b.origin;
}

/**
 * The page's own template: the first one rendered that draws an entry, a
 * listing, a term or the search results. Failing that the first that is
 * no layout, and failing that the first.
 */
export function pageTemplate(files: ManifestFile[]): ManifestFile | null {
    return files.find((file) => PAGE_ROLES.includes(file.role)) ?? files.find((file) => file.role !== 'layout') ?? files[0] ?? null;
}

/** The templates of a page in the order the panel lists them; within a role, by path. */
export function inPanelOrder<T extends { role: string; path: string }>(files: T[]): T[] {
    const rank = (role: string) => {
        const at = ORDER.indexOf(role);

        return at === -1 ? ORDER.indexOf('other') : at;
    };

    return [...files].sort((a, b) => rank(a.role) - rank(b.role) || a.path.localeCompare(b.path));
}

/** "partials/header.blade.php" → "partials/header": what a person calls it. */
export function shortName(path: string): string {
    return path.replace(/\.blade\.php$/, '');
}

/** What a role is called on screen. */
export function roleLabel(role: string): string {
    return (
        {
            layout: 'Layout',
            entry: 'Page template',
            listing: 'Listing',
            term: 'Term page',
            search: 'Search',
            error: 'Error page',
            partial: 'Partial',
            block: 'Block',
            component: 'Component',
        }[role] ?? 'Template'
    );
}

/** Why a template can't be edited visually, in a few words; null when it can. */
export function limit(file: { origin: string; opens: boolean }): string | null {
    if (file.origin === 'package') {
        return 'Read-only';
    }

    return file.opens ? null : 'Code only';
}
