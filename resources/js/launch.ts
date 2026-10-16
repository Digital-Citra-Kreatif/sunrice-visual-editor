/**
 * Puts an "Edit with Visual Editor" button on every entry's page in
 * Sunrice's admin, beside its own "View page" and "Publish", the way a
 * page builder does. A click opens the editor on that entry's page.
 *
 * Sunrice has no place for a package's button on its pages, so the button
 * is added to the page as it is drawn, and put back whenever the admin
 * draws the page again. The admin's own markup is only looked at: when it
 * isn't as expected, the button floats in a corner of the page instead.
 */
import { ENTRY_PAGE, editorUrl, entryPath, type EntryProps } from './lib/launch';

type Page = { component: string; props: EntryProps };

const ID = 'sve-launch';

/** The page the admin showed first: the data Inertia put in the document. */
function firstPage(): Page | null {
    try {
        const data = document.querySelector('script[data-page="app"]')?.textContent || document.getElementById('app')?.dataset.page;

        return data ? (JSON.parse(data) as Page) : null;
    } catch {
        return null;
    }
}

/** The group of buttons beside the entry's title: "View page", "Publish", "More actions". */
function actions(): HTMLElement | null {
    const title = document.querySelector('h1.sunrice-page-title');
    const row = title?.parentElement?.parentElement?.parentElement;
    const group = row?.lastElementChild;

    return group instanceof HTMLElement && group !== title?.parentElement?.parentElement ? group : null;
}

function button(): HTMLAnchorElement {
    const link = document.createElement('a');
    link.id = ID;
    link.className = 'sve-launch';
    link.innerHTML =
        '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><rect x="1.5" y="2.5" width="13" height="11" rx="1.5" fill="none" stroke="currentColor"/><rect x="2.5" y="3.5" width="3.5" height="9" rx="0.5" fill="currentColor"/><rect x="8.5" y="3.5" width="5" height="9" rx="0.5" fill="currentColor" opacity="0.5"/></svg><span>Edit with Visual Editor</span>';
    link.title = 'Open this page in the visual editor';
    link.addEventListener('click', (event) => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        event.preventDefault();
        // Through the admin's router: the entry's page asks first when it has changes that are not saved.
        Sunrice.inertia.router.visit(link.href);
    });

    return link;
}

let page: Page | null = null;
let watching: MutationObserver | null = null;

/** The button where it belongs on the page as it is now, going where it should; or none, where there is none to have. */
function place(): void {
    const group = actions();
    // The admin's own "View page" link, which follows the language being edited.
    const view = group?.querySelector<HTMLAnchorElement>('a[target="_blank"][href]') ?? null;
    const path = page === null ? null : entryPath(page.component, page.props, window.location.origin, view?.href ?? null);
    const had = document.getElementById(ID) as HTMLAnchorElement | null;
    if (path === null) {
        had?.remove();

        return;
    }
    const link = had ?? button();
    const href = editorUrl(Sunrice.adminUrl, path);
    if (link.getAttribute('href') !== href) {
        link.setAttribute('href', href);
    }
    if (group !== null) {
        link.classList.remove('sve-launch--floating');
        if (link.parentElement !== group || group.firstElementChild !== link) {
            group.prepend(link);
        }
    } else if (link.parentElement !== document.body) {
        link.classList.add('sve-launch--floating');
        document.body.append(link);
    }
}

function follow(next: Page | null): void {
    page = next;
    watching?.disconnect();
    watching = null;
    place();
    if (page?.component === ENTRY_PAGE && page.props.visualEditor?.open === true) {
        // The admin draws its page again as it is used (a language picked, a save): the button stays on it.
        watching = new MutationObserver(() => place());
        watching.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['href'] });
    }
}

export function installLaunch(): void {
    const router = Sunrice.inertia.router;
    // In a frame, the entry's page is the editor's own Content tab: it is in the editor already.
    if (typeof router.on !== 'function' || window.top !== window.self) {
        return;
    }
    router.on('navigate', (event) => follow(event.detail.page as Page));
    // The page the admin opened on may have been drawn before this script ran.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => page === null && follow(firstPage()), { once: true });
    } else {
        window.requestAnimationFrame(() => page === null && follow(firstPage()));
    }
}
