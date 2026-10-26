import { useEffect, useRef, useState } from 'react';
import type { ShownEntry } from '../bridge/protocol';

type Props = {
    /** The entry the page in the frame is of; null when it is of none (a listing, a search). */
    entry: ShownEntry | null;
    /** The page is on its way: what it is of is not known yet. */
    waiting: boolean;
    /** Whether the tab is the one shown. */
    shown: boolean;
    /** Back from the entry's page in the admin: the page is drawn again, with whatever was saved there. */
    onBack(): void;
};

/**
 * The content of the entry the page shows: the CMS's own page for the
 * entry, in a tab of its own. The panel never lets a page of it be put in
 * a frame (X-Frame-Options: DENY), so it is opened beside the editor, not
 * in it. Its fields, its validation, its drafts and revisions are the
 * CMS's; when the editor's window is used again after it was opened, the
 * page is drawn again, with what was saved there.
 */
export function ContentPanel({ entry: said, waiting, shown, onBack }: Props) {
    // Only a page of this admin is linked here: what the page in the frame says is checked.
    const entry = said !== null && said.edit.startsWith(`${Sunrice.adminUrl()}/`) ? said : null;
    /** The entry linked: kept while the page in the editor is on its way, or shows none. */
    const [held, setHeld] = useState<ShownEntry | null>(entry);
    useEffect(() => {
        if (entry !== null && shown) {
            setHeld((was) => (was !== null && was.edit === entry.edit && was.title === entry.title ? was : entry));
        }
    }, [entry?.edit, entry?.title, shown]);
    /** Whether the entry's page was opened, until the editor is back in use. */
    const away = useRef(false);
    const calls = useRef(onBack);
    calls.current = onBack;

    useEffect(() => {
        const back = () => {
            if (away.current && document.visibilityState === 'visible') {
                away.current = false;
                calls.current();
            }
        };
        window.addEventListener('focus', back);
        document.addEventListener('visibilitychange', back);

        return () => {
            window.removeEventListener('focus', back);
            document.removeEventListener('visibilitychange', back);
        };
    }, []);

    if (held === null) {
        return <p className="sve-empty sve-code__none">{waiting ? 'Loading…' : 'This page shows no single entry (a listing, a search or an error page): its content is edited in the admin, entry by entry.'}</p>;
    }

    const changed = entry !== null && entry.edit !== held.edit;
    const title = held.title === '' ? 'Untitled' : held.title;

    return (
        <section className="sve-content" aria-label={`Content of ${title}`}>
            <header className="sve-code__bar">
                <span className="sve-code__name" title={held.title}>
                    {title}
                </span>
                {changed && (
                    <button type="button" className="sve-button sve-button--quiet" onClick={() => setHeld(entry)} title="Show the entry the page now shows">
                        Show {entry.title === '' ? 'this page' : entry.title}
                    </button>
                )}
            </header>
            <div className="sve-content__open">
                <p>The fields of {title} are edited on its page in the admin, which opens in a tab of its own.</p>
                <a
                    className="sve-button"
                    href={held.edit}
                    target="_blank"
                    rel="noreferrer"
                    onClick={() => {
                        away.current = true;
                    }}
                >
                    Edit {title} ↗
                </a>
                <p className="sve-custom-css__note">Back here, the page is drawn again. A published entry keeps what is saved as a draft until it is published.</p>
            </div>
        </section>
    );
}
