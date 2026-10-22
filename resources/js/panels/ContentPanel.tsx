import { useEffect, useRef, useState } from 'react';
import type { ShownEntry } from '../bridge/protocol';
import { adminUrl } from '../lib/panel.ts';

type Props = {
    /** The entry the page in the frame is of; null when it is of none (a listing, a search). */
    entry: ShownEntry | null;
    /** The page is on its way: what it is of is not known yet. */
    waiting: boolean;
    /** Whether the tab is the one shown. */
    shown: boolean;
    /** Something was saved in the entry's page: the page is drawn again with it. */
    onSaved(title: string): void;
};

/** What the panel's page in the frame has of Inertia's router, as far as it is listened to here. */
type Router = { on(type: string, listener: (event: { detail: { visit?: { method?: string } } }) => void): () => void };

/** The panel's runtime in the frame, as far as its router goes. */
type InFrame = Window & { Sunrice?: { modules?: Record<string, { router?: Router } | undefined> } };

/**
 * The content of the entry the page shows, edited beside it: the CMS's own
 * page for the entry, in a frame. Its fields, its validation, its drafts
 * and revisions are the CMS's; the editor only listens for a save there,
 * and draws the page again when one is made.
 */
export function ContentPanel({ entry: said, waiting, shown, onSaved }: Props) {
    // Only a page of this admin is drawn here: what the page in the frame says is checked.
    const entry = said !== null && said.edit.startsWith(`${adminUrl()}/`) ? said : null;
    const holder = useRef<HTMLIFrameElement>(null);
    /** The entry whose page is in the frame: kept while the page in the editor is on its way, or shows none. */
    const [held, setHeld] = useState<ShownEntry | null>(entry);
    useEffect(() => {
        if (entry !== null && shown) {
            setHeld((was) => (was !== null && was.edit === entry.edit ? was : entry));
        }
    }, [entry?.edit, shown]);
    const calls = useRef({ onSaved, title: held?.title ?? '' });
    calls.current = { onSaved, title: held?.title ?? '' };

    /** Listens, in the admin's page in the frame, for a save: any visit that is not a GET and that succeeded. */
    const listen = () => {
        let router: Router | undefined;
        try {
            router = (holder.current?.contentWindow as InFrame | null)?.Sunrice?.modules?.['@inertiajs/react']?.router;
        } catch {
            return;
        }
        if (router === undefined || typeof router.on !== 'function') {
            return;
        }
        let method = 'get';
        router.on('start', (event) => {
            method = (event.detail.visit?.method ?? 'get').toLowerCase();
        });
        router.on('success', () => {
            if (method !== 'get') {
                calls.current.onSaved(calls.current.title);
            }
        });
    };

    if (held === null) {
        return <p className="sve-empty sve-code__none">{waiting ? 'Loading…' : 'This page shows no single entry (a listing, a search or an error page): its content is edited in the admin, entry by entry.'}</p>;
    }

    const changed = entry !== null && entry.edit !== held.edit;

    return (
        <section className="sve-content" aria-label={`Content of ${held.title}`}>
            <header className="sve-code__bar">
                <span className="sve-code__name" title={held.title}>
                    {held.title === '' ? 'Untitled' : held.title}
                </span>
                {changed && (
                    <button type="button" className="sve-button sve-button--quiet" onClick={() => setHeld(entry)} title="Show the content of the entry the page now shows">
                        Show {entry.title === '' ? 'this page' : entry.title}
                    </button>
                )}
                <span className="sve-code__space" />
                <a className="sve-bar__link" href={held.edit} target="_blank" rel="noreferrer" title="Open the entry in the admin">
                    Open ↗
                </a>
            </header>
            <p className="sve-custom-css__note">Saved here, the page is drawn again. A published entry keeps what is saved as a draft until it is published.</p>
            <iframe ref={holder} className="sve-content__frame" src={held.edit} title={`Content of ${held.title}`} onLoad={listen} />
        </section>
    );
}
