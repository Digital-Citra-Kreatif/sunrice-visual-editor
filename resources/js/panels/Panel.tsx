import type { ReactNode } from 'react';

/** What one tab of a side shows, with all the room under the tabs. Kept as it is while another tab is shown: a list scrolled stays scrolled. */
export function Pane({ label, shown, children }: { label: string; shown: boolean; children: ReactNode }) {
    return (
        <section className="sve-panel sve-panel--grow" aria-label={label} hidden={!shown}>
            <div className="sve-panel__body">{children}</div>
        </section>
    );
}

type SideProps = {
    side: 'left' | 'right';
    /** Beside the page, with room of its own; hidden, it takes none. */
    shown?: boolean;
    children: ReactNode;
};

/** A column of panels on one side of the page. */
export function Side({ side, shown = true, children }: SideProps) {
    return (
        <aside className={`sve-side sve-side--${side}`} hidden={!shown}>
            {children}
        </aside>
    );
}
