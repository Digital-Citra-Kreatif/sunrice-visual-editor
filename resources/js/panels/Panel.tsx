import { createContext, useContext, useEffect, useId, useRef, type ReactNode } from 'react';

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

type SideProps = {
    side: 'left' | 'right';
    /** Beside the page, with room of its own. */
    shown?: boolean;
    /** Hidden, and over the page for now: the page keeps its width. */
    flown?: boolean;
    /** Give it its room back, to stay. */
    onKeep?(): void;
    /** Put it away again. */
    onAway?(): void;
    /** Minimize a docked side without unmounting its controls. */
    onMinimize?(): void;
    children: ReactNode;
};

/**
 * A column of panels on one side of the page. Hidden, it takes no room at
 * all; it can then be flown out over the page for as long as it is needed.
 */
export function Side({ side, shown = true, flown = false, onKeep, onAway, onMinimize, children }: SideProps) {
    const element = useRef<HTMLElement>(null);
    // Flown out, the keyboard goes with it: Tab walks its controls, Escape puts it away.
    useEffect(() => {
        if (flown) {
            element.current?.focus();
        }
    }, [flown]);

    return (
        <aside
            ref={element}
            className={`sve-side sve-side--${side}${flown ? ' sve-side--flown' : ''}`}
            hidden={!shown && !flown}
            tabIndex={flown ? -1 : undefined}
            aria-label={flown ? `The panels on the ${side}, over the page` : undefined}
            onKeyDown={(event) => {
                // What is inside has the key first: a field that takes Escape for itself says so.
                if (flown && event.key === 'Escape' && !event.defaultPrevented) {
                    event.stopPropagation();
                    onAway?.();
                }
            }}
        >
            {shown && !flown && (
                <div className="sve-side__header">
                    <strong>{side === 'left' ? 'Structure' : 'Inspector'}</strong>
                    <button type="button" className="sve-side__keep" onClick={onMinimize} aria-label={`Minimize ${side === 'left' ? 'Structure' : 'Inspector'}`} title="Hide this panel; reopen it from the toolbar or page edge">
                        <span aria-hidden="true">−</span> Minimize
                    </button>
                </div>
            )}
            {flown && (
                <div className="sve-side__flown">
                    <span className="sve-side__for-now">Temporary panel</span>
                    <button type="button" className="sve-side__keep" onClick={onKeep} title="Give these panels their room beside the page again">
                        Pin panel
                    </button>
                    <button type="button" className="sve-side__away" onClick={onAway} aria-label="Put the panels away" title="Put the panels away (Esc)">
                        ×
                    </button>
                </div>
            )}
            {children}
        </aside>
    );
}

