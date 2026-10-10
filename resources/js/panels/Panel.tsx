import type { ReactNode } from 'react';

type Props = {
    title: string;
    side: 'left' | 'right';
    children: ReactNode;
};

/** A titled column beside the page. */
export function Panel({ title, side, children }: Props) {
    return (
        <aside className={`sve-panel sve-panel--${side}`} aria-label={title}>
            <h2 className="sve-panel__title">{title}</h2>
            <div className="sve-panel__body">{children}</div>
        </aside>
    );
}
