import { createContext, useContext, useId, type ReactNode } from 'react';

/**
 * Which panels and sections are folded to their titles, and how one is
 * folded or opened. The editor keeps it (lib/layout.ts); without one,
 * everything is open and stays so.
 */
export const Folds = createContext<{ folded: ReadonlySet<string>; toggle(name: string): void }>({ folded: new Set(), toggle: () => {} });

/** Whether a panel or section is open, and what a click on its title does. */
export function useFold(name: string): [boolean, () => void] {
    const { folded, toggle } = useContext(Folds);

    return [!folded.has(name), () => toggle(name)];
}

/** A title that folds what is under it away and brings it back. */
function FoldButton({ open, onToggle, controls, label, children }: { open: boolean; onToggle(): void; controls: string; label?: string; children: ReactNode }) {
    return (
        <button type="button" className="sve-fold" aria-expanded={open} aria-controls={controls} aria-label={label} onClick={onToggle} title={open ? 'Fold away' : 'Show'}>
            <span className="sve-fold__mark" aria-hidden="true" />
            <span className="sve-fold__title">{children}</span>
        </button>
    );
}

type Props = {
    title: string;
    /** Takes the room the panels above it leave: shared evenly with the others that grow, or by this weight. */
    grow?: boolean | number;
    children: ReactNode;
};

/** A titled panel in a column beside the page. A click on its title folds it to the title, and opens it again. */
export function Panel({ title, grow = false, children }: Props) {
    const [open, toggle] = useFold(`panel:${title}`);
    const body = useId();

    return (
        <section
            className={`sve-panel${grow === false || !open ? '' : ' sve-panel--grow'}${open ? '' : ' sve-panel--folded'}`}
            style={typeof grow === 'number' && open ? { flexGrow: grow } : undefined}
            aria-label={title}
        >
            <h2 className="sve-panel__title">
                {/* Named as a panel: a button inside it may well have the panel's own name ("Settings", "Insert"). */}
                <FoldButton open={open} onToggle={toggle} controls={body} label={`${title} panel`}>
                    {title}
                </FoldButton>
            </h2>
            {/* Folded, what is in it stays as it is: a list scrolled, a field half typed. */}
            <div className="sve-panel__body" id={body} hidden={!open}>
                {children}
            </div>
        </section>
    );
}

/** A group of controls under a title of its own, inside a panel. It folds like a panel does. */
export function Group({ label, name, className, children }: { label: string; name?: string; className?: string; children: ReactNode }) {
    const [open, toggle] = useFold(`group:${name ?? label}`);
    const body = useId();

    return (
        <fieldset className={`sve-group${className === undefined ? '' : ` ${className}`}${open ? '' : ' sve-group--folded'}`}>
            <legend>
                <FoldButton open={open} onToggle={toggle} controls={body}>
                    {label}
                </FoldButton>
            </legend>
            <div className="sve-group__body" id={body} hidden={!open}>
                {children}
            </div>
        </fieldset>
    );
}

/** A titled part of a list, inside a panel: the kinds of thing to insert, where a field comes from. It folds like a panel does. */
export function Section({ title, name, className, children }: { title: string; name?: string; className?: string; children: ReactNode }) {
    const [open, toggle] = useFold(`section:${name ?? title}`);
    const body = useId();

    return (
        <section className={className} aria-label={title}>
            <h3 className="sve-insert__title">
                <FoldButton open={open} onToggle={toggle} controls={body}>
                    {title}
                </FoldButton>
            </h3>
            <div id={body} hidden={!open}>
                {children}
            </div>
        </section>
    );
}

/** A column of panels on one side of the page. */
export function Side({ side, shown = true, children }: { side: 'left' | 'right'; shown?: boolean; children: ReactNode }) {
    return (
        <aside className={`sve-side sve-side--${side}`} hidden={!shown}>
            {children}
        </aside>
    );
}
