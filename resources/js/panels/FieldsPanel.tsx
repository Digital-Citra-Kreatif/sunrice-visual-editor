import { useState } from 'react';
import type { FieldGroup, PickField, PickVariable } from '../lib/api';
import { ORIGINS, matchingFields, nameOf, originOf, printable, usual, type Found } from '../lib/fields';

type ListProps = {
    /** What can be read at the place; null until it is known. */
    variables: PickVariable[] | null;
    /** Why it could not be asked, when it couldn't. */
    failed: string | null;
    /** What was typed to find something. */
    typed: string;
    /** Nothing can be inserted right now. */
    off: boolean;
    onInsert(blade: string): void;
};

/**
 * The CMS's fields and variables at a place in the template, to click
 * into it: the variables there, each with what can be read from it. A
 * click inserts a value in the way that suits it (a text printed, an image
 * as an `<img>`, a list as a loop); "As…" lists the other ways. What is
 * only read into (an entry, a group) opens instead.
 */
export function FieldsList({ variables, failed, typed, off, onInsert }: ListProps) {
    /** What was opened or closed by hand, by its code: the rest is as it starts out. */
    const [toggled, setToggled] = useState<Record<string, boolean>>({});
    const toggle = (key: string, startsOpen: boolean) => setToggled((before) => ({ ...before, [key]: !(before[key] ?? startsOpen) }));

    if (variables === null) {
        return <p className="sve-empty">{failed ?? 'Looking at what is there…'}</p>;
    }

    if (typed.trim() !== '') {
        const found = matchingFields(variables, typed);

        return found.length === 0 ? (
            <p className="sve-empty">No field or variable here matches “{typed.trim()}”.</p>
        ) : (
            <ul className="sve-insertables sve-pick">
                {found.map((each) => (
                    <li key={each.key} className="sve-pick__row">
                        <Pick value={each.value} label={each.trail.join(' › ')} note={each.note} off={off} onInsert={onInsert} />
                    </li>
                ))}
            </ul>
        );
    }

    const rows = (groups: FieldGroup[], depth: number) =>
        groups.map((group) => (
            <div key={group.title} className="sve-pick__group" role="group" aria-label={group.title}>
                <h4 className="sve-pick__heading" style={{ paddingInlineStart: `${depth * 0.75 + 0.5}rem` }}>
                    {group.title}
                </h4>
                <ul className="sve-insertables">
                    {group.fields.map((field) => {
                        const open = toggled[field.code] ?? false;

                        return (
                            <li key={field.handle}>
                                <div className="sve-pick__row" style={{ paddingInlineStart: `${depth * 0.75}rem` }}>
                                    <Opener label={field.label} open={open} has={field.groups.length > 0} onToggle={() => toggle(field.code, false)} />
                                    <Pick value={field} label={field.label} note={field.note} off={off} onInsert={onInsert} onOpen={field.groups.length > 0 ? () => toggle(field.code, false) : undefined} />
                                </div>
                                {open && rows(field.groups, depth + 1)}
                            </li>
                        );
                    })}
                </ul>
            </div>
        ));

    return (
        <>
            {ORIGINS.map(({ origin, title }) => {
                const those = variables.filter((variable) => originOf(variable) === origin);

                return those.length === 0 ? null : (
                    <section key={origin} className="sve-insert__group sve-pick" aria-label={title}>
                        <h3 className="sve-insert__title">{title}</h3>
                        {those.map((variable) => {
                            // What the site has everywhere is many: closed until it is wanted.
                            const startsOpen = origin !== 'site';
                            const open = toggled[variable.code] ?? startsOpen;

                            return (
                                <div key={variable.code} className="sve-pick__variable">
                                    <div className="sve-pick__row">
                                        <Opener label={nameOf(variable)} open={open} has={variable.groups.length > 0} onToggle={() => toggle(variable.code, startsOpen)} />
                                        <Pick value={variable} label={nameOf(variable)} note={null} off={off} onInsert={onInsert} onOpen={variable.groups.length > 0 ? () => toggle(variable.code, startsOpen) : undefined} strong />
                                    </div>
                                    <p className="sve-pick__about">
                                        {variable.title}
                                        {variable.of.length > 0 && ` (${variable.of.join(', ')})`}
                                    </p>
                                    {open && rows(variable.groups, 1)}
                                </div>
                            );
                        })}
                    </section>
                );
            })}
        </>
    );
}

function Opener({ label, open, has, onToggle }: { label: string; open: boolean; has: boolean; onToggle(): void }) {
    return has ? (
        <button type="button" className="sve-pick__opener" aria-expanded={open} aria-label={`${open ? 'Close' : 'Open'} ${label}`} title={open ? 'Close' : 'Show what can be read from it'} onClick={onToggle}>
            <span aria-hidden="true">{open ? '▾' : '▸'}</span>
        </button>
    ) : (
        <span className="sve-pick__opener" aria-hidden="true" />
    );
}

type PickProps = {
    value: PickVariable | PickField;
    label: string;
    note: string | null;
    off: boolean;
    strong?: boolean;
    onInsert(blade: string): void;
    /** For what has nothing to insert as itself: a click opens it. */
    onOpen?: () => void;
};

/** One value: a click inserts it the usual way, "As…" another. */
function Pick({ value, label, note, off, strong = false, onInsert, onOpen }: PickProps) {
    const first = usual(value);

    return (
        <>
            <button
                type="button"
                className={`sve-insertable sve-pick__pick${strong ? ' sve-pick__pick--variable' : ''}`}
                disabled={first === null ? onOpen === undefined : off}
                onClick={() => (first === null ? onOpen?.() : onInsert(first.blade))}
                title={first === null ? 'Nothing to insert as itself: open it and pick from inside' : first.blade}
            >
                <span className="sve-insertable__title">{label}</span>
                <code className="sve-insertable__hint">{value.what}</code>
                {note !== null && <span className="sve-pick__note">{note}</span>}
            </button>
            {value.inserts.length > 1 && (
                <select
                    className="sve-pick__as"
                    value=""
                    disabled={off}
                    onChange={(event) => {
                        const chosen = value.inserts[Number(event.target.value)];
                        if (event.target.value !== '' && chosen !== undefined) {
                            onInsert(chosen.blade);
                        }
                    }}
                    aria-label={`Insert ${label} as`}
                    title="Insert it another way"
                >
                    <option value="">As…</option>
                    {value.inserts.map((insert, index) => (
                        <option key={insert.title} value={index}>
                            {insert.title}
                        </option>
                    ))}
                </select>
            )}
        </>
    );
}

type ChooserProps = {
    /** What can be read at the selected node; null until it is known. */
    variables: PickVariable[] | null;
    /** What the control is called, for the button's name: "Text", "href". */
    control: string;
    disabled: boolean;
    onChoose(value: PickVariable | PickField): void;
};

/**
 * "Use a field…" under a control of the Settings panel: a list of what
 * can be written where a text goes, to fill the control from. Opens in
 * place, under the control.
 */
export function FieldChooser({ variables, control, disabled, onChoose }: ChooserProps) {
    const [open, setOpen] = useState(false);
    const [typed, setTyped] = useState('');
    const all = variables === null ? [] : printable(variables);
    const words = typed.toLowerCase().split(/\s+/).filter((word) => word !== '');
    const shown: Found[] = all.filter((found) => words.every((word) => `${found.trail.join(' ')} ${found.value.code}`.toLowerCase().includes(word)));

    return (
        <div className="sve-bind">
            <button type="button" className="sve-bind__open" aria-expanded={open} disabled={disabled} onClick={() => setOpen((was) => !was)} aria-label={`Use a field for ${control}`} title="Fill it from one of the CMS’s fields or variables">
                {open ? 'Close' : 'Use a field…'}
            </button>
            {open && (
                <div className="sve-bind__list" role="group" aria-label={`Fields for ${control}`}>
                    <input className="sve-input" type="search" value={typed} onChange={(event) => setTyped(event.target.value)} placeholder="Find a field…" aria-label="Find a field" spellCheck={false} autoComplete="off" autoFocus />
                    {variables === null ? (
                        <p className="sve-empty">Looking at what is there…</p>
                    ) : shown.length === 0 ? (
                        <p className="sve-empty">{all.length === 0 ? 'Nothing here can be written as text.' : `Nothing matches “${typed.trim()}”.`}</p>
                    ) : (
                        <ul className="sve-insertables">
                            {shown.map((found) => (
                                <li key={found.key}>
                                    <button
                                        type="button"
                                        className="sve-insertable"
                                        disabled={disabled}
                                        onClick={() => {
                                            setOpen(false);
                                            onChoose(found.value);
                                        }}
                                        title={found.value.print ?? ''}
                                    >
                                        <span className="sve-insertable__title">{found.trail.join(' › ')}</span>
                                        <code className="sve-insertable__hint">{found.value.what}</code>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}
