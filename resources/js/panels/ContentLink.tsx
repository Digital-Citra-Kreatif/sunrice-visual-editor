import { useEffect, useRef } from 'react';
import type { ShownEntry } from '../bridge/protocol';

type Props = {
    /** The entry the page in the frame is of; null when it is of none (a listing, a search), or not known yet. */
    entry: ShownEntry | null;
    /** Back from the entry's page in the admin: the page is drawn again, with whatever was saved there. */
    onBack(): void;
};

/**
 * "Edit content ↗" in the top bar: the CMS's own page for the entry the
 * page shows, in a tab of its own. The panel never lets a page of it be
 * put in a frame (X-Frame-Options: DENY), so it is opened beside the
 * editor. Its fields, its validation, its drafts and revisions are the
 * CMS's; when the editor's window is used again after it was opened, the
 * page is drawn again, with what was saved there.
 */
export function ContentLink({ entry: said, onBack }: Props) {
    // Only a page of this admin is linked here: what the page in the frame says is checked.
    const entry = said !== null && said.edit.startsWith(`${Sunrice.adminUrl()}/`) ? said : null;
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

    if (entry === null) {
        return (
            <span className="sve-bar__link sve-bar__link--off" aria-disabled="true" title="This page shows no single entry (a listing, a search or an error page): its content is edited in the admin, entry by entry.">
                Edit content
            </span>
        );
    }
    const title = entry.title === '' ? 'this entry' : entry.title;

    return (
        <a
            className="sve-bar__link"
            href={entry.edit}
            target="_blank"
            rel="noreferrer"
            title={`Edit ${title} in the admin, in a tab of its own. Back here, the page is drawn again; a published entry keeps what is saved as a draft until it is published.`}
            onClick={() => {
                away.current = true;
            }}
        >
            Edit content ↗
        </a>
    );
}
