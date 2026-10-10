import { useEffect } from 'react';

/** Something to tell the person: a change that was refused, or one that was made elsewhere than they are looking. */
export type Notice = { kind: 'problem' | 'done'; text: string; at: number };

/** How long word of something done stays. A problem stays until it is dismissed or the next change goes through. */
const DONE_FOR = 4000;

type Props = {
    notice: Notice | null;
    onDismiss(): void;
};

/**
 * What the editor has to say, over the top of the page. The editor's page
 * has no admin layout around it, and with the layout went the admin's own
 * toasts: this is in their place.
 */
export function NoticeBar({ notice, onDismiss }: Props) {
    useEffect(() => {
        if (notice === null || notice.kind !== 'done') {
            return;
        }
        const timer = window.setTimeout(onDismiss, DONE_FOR);

        return () => window.clearTimeout(timer);
    }, [notice]);

    if (notice === null) {
        return null;
    }

    return (
        <div className={`sve-notice sve-notice--${notice.kind}`} role={notice.kind === 'problem' ? 'alert' : 'status'}>
            <span className="sve-notice__text">{notice.text}</span>
            <button type="button" className="sve-notice__dismiss" onClick={onDismiss} aria-label="Dismiss" title="Dismiss">
                <span aria-hidden="true">×</span>
            </button>
        </div>
    );
}
