import type { ReactNode } from 'react';

type Props = {
    title: string;
    /** Takes the room the panels above it leave. */
    grow?: boolean;
    children: ReactNode;
};

/** A titled panel in a column beside the page. */
export function Panel({ title, grow = false, children }: Props) {
    return (
        <section className={`sve-panel${grow ? ' sve-panel--grow' : ''}`} aria-label={title}>
            <h2 className="sve-panel__title">{title}</h2>
            <div className="sve-panel__body">{children}</div>
        </section>
    );
}

/** A column of panels on one side of the page. */
export function Side({ side, children }: { side: 'left' | 'right'; children: ReactNode }) {
    return <aside className={`sve-side sve-side--${side}`}>{children}</aside>;
}
