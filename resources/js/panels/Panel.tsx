import type { ReactNode } from 'react';

type Props = {
    title: string;
    /** Takes the room the panels above it leave: shared evenly with the others that grow, or by this weight. */
    grow?: boolean | number;
    children: ReactNode;
};

/** A titled panel in a column beside the page. */
export function Panel({ title, grow = false, children }: Props) {
    return (
        <section className={`sve-panel${grow === false ? '' : ' sve-panel--grow'}`} style={typeof grow === 'number' ? { flexGrow: grow } : undefined} aria-label={title}>
            <h2 className="sve-panel__title">{title}</h2>
            <div className="sve-panel__body">{children}</div>
        </section>
    );
}

/** A column of panels on one side of the page. */
export function Side({ side, children }: { side: 'left' | 'right'; children: ReactNode }) {
    return <aside className={`sve-side sve-side--${side}`}>{children}</aside>;
}
