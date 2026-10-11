import { useState, type FocusEvent, type FormEvent } from 'react';
import type { ManifestFile } from '../bridge/protocol';
import type { StyleRule, Styles, ThemeFile } from '../lib/api';
import { byFile, hasOwnRule, typedDeclarations, type StyleEdit } from '../lib/styles';
import { Field } from './SettingsPanel';

type Props = {
    /** The element's classes; null when Blade has a hand in them. */
    classes: string[] | null;
    /** The class whose rules are shown. */
    chosen: string | null;
    onChoose(name: string): void;
    /** What the stylesheets say about it; null until it is known. */
    styles: Styles | null;
    /** Why that could not be asked, when it couldn't. */
    failed: string | null;
    /** The addresses of the stylesheets the page links; null until the page has said. */
    linked: string[] | null;
    /** The layout a stylesheet can be linked from, when the page has one that can be changed. */
    layout: ManifestFile | null;
    /** A link is being added to the layout. */
    linking: boolean;
    /** Which stylesheets have a change to undo, or one to do again. */
    can(path: string): { undo: boolean; redo: boolean };
    onEdit(edit: StyleEdit): void;
    onTravel(path: string, direction: 'undo' | 'redo'): void;
    onLink(stylesheet: string): void;
};

/**
 * The rules of the theme's stylesheets for one class of the selected
 * element: each declaration a row, changed where it stands in its file. A
 * class without a rule gets one in the editor's own stylesheet.
 */
export function CssRules({ classes, chosen, onChoose, styles, failed, linked, layout, linking, can, onEdit, onTravel, onLink }: Props) {
    if (classes === null || classes.length === 0 || chosen === null) {
        return <NoClass classes={classes} />;
    }

    const known = styles !== null && styles.class === chosen ? styles : null;
    const groups = known === null ? [] : byFile(known);
    const own = known?.own ?? null;
    const isLinked = (file: ThemeFile) => linked === null || linked.includes(file.url);

    return (
        <div className="sve-css">
            <ClassChoice classes={classes} chosen={chosen} onChoose={onChoose} />

            {known === null ? (
                <p className="sve-empty">{failed ?? 'Reading the stylesheets…'}</p>
            ) : (
                <>
                    {known.files.length === 0 && <p className="sve-empty sve-css__none">The theme has no stylesheet yet (public/sunrice-theme). A site that builds its CSS elsewhere, with Vite or Tailwind, is styled by its classes alone.</p>}

                    {groups.map(({ file, rules }) => (
                        <section key={file.path} className="sve-css__file" aria-label={file.path}>
                            <FileHead file={file} linked={isLinked(file)} can={can(file.path)} onTravel={onTravel} />
                            {rules
                                .filter(({ rule }) => rule.exact)
                                .map(({ rule, at }) => (
                                    <RuleCard key={at} rule={rule} at={at} onEdit={onEdit} />
                                ))}
                            {rules.some(({ rule }) => !rule.exact) && (
                                <details className="sve-css__others">
                                    <summary>
                                        {rules.filter(({ rule }) => !rule.exact).length} more that mention <code>{known.selector}</code>
                                    </summary>
                                    {rules
                                        .filter(({ rule }) => !rule.exact)
                                        .map(({ rule, at }) => (
                                            <RuleCard key={at} rule={rule} at={at} onEdit={onEdit} />
                                        ))}
                                </details>
                            )}
                        </section>
                    ))}
                    {known.more > 0 && <p className="sve-empty">And {known.more} more rules, not listed.</p>}

                    {!hasOwnRule(known) && own !== null && (
                        <section className="sve-css__file" aria-label={`A new rule in ${own.path}`}>
                            {/* Shown with its own head only when it has no rules listed above. */}
                            {!groups.some((group) => group.file.path === own.path) && <FileHead file={own} linked can={can(own.path)} onTravel={onTravel} />}
                            <div className="sve-rule sve-rule--new" role="group" aria-label={`A new rule for ${known.selector ?? ''}`}>
                                <p className="sve-rule__head">
                                    <code className="sve-rule__selector">{known.selector}</code>
                                    <span className="sve-rule__line">no rule yet</span>
                                </p>
                                <NewRow label={`${known.selector ?? ''} in ${own.path}`} onAdd={(property, value) => onEdit({ kind: 'create', property, value })} />
                                <p className="sve-rule__hint">
                                    The first declaration writes the rule into <code>{own.path}</code>.
                                </p>
                            </div>
                        </section>
                    )}

                    <Unlinked styles={known} linked={linked} layout={layout} linking={linking} onLink={onLink} />
                </>
            )}
        </div>
    );
}

/** Why there are no rules to show: the element has no class, or Blade writes its classes. */
export function NoClass({ classes }: { classes: string[] | null }) {
    return classes === null ? (
        <p className="sve-empty sve-css__none">This element’s classes are written in Blade, so there is no one class to style it by. Its class is under Settings.</p>
    ) : (
        <p className="sve-empty sve-css__none">This element has no class. Give it one under Settings, and its rule can be written here.</p>
    );
}

/** The element's classes, to choose the one whose rule is shown. */
export function ClassChoice({ classes, chosen, onChoose }: { classes: string[]; chosen: string; onChoose(name: string): void }) {
    return (
        <div className="sve-css__classes" role="group" aria-label="The class whose rules are shown">
            {classes.map((name) => (
                <button key={name} type="button" className="sve-css__class" aria-pressed={name === chosen} onClick={() => onChoose(name)}>
                    .{name}
                </button>
            ))}
        </div>
    );
}

type UnlinkedProps = { styles: Styles; linked: string[] | null; layout: ManifestFile | null; linking: boolean; onLink(stylesheet: string): void };

/** A stylesheet that is there and has rules for the class, which the page does not show: says so, and links it. */
export function Unlinked({ styles, linked, layout, linking, onLink }: UnlinkedProps) {
    if (linked === null) {
        return null;
    }

    return (
        <>
            {byFile(styles)
                .filter(({ file }) => file.exists && !linked.includes(file.url))
                .map(({ file }) => (
                    <div key={file.path} className="sve-note sve-css__unlinked">
                        <p>
                            This page does not link <code>{file.path}</code>, so its rules don’t show.
                        </p>
                        {layout === null ? (
                            <p>Link it from the layout’s &lt;head&gt;, in the layout’s code.</p>
                        ) : (
                            <button type="button" className="sve-button sve-button--quiet" disabled={linking} onClick={() => onLink(file.path)}>
                                Link it from {layout.path.replace(/\.blade\.php$/, '')}
                            </button>
                        )}
                    </div>
                ))}
        </>
    );
}

function FileHead({ file, linked, can, onTravel }: { file: ThemeFile; linked: boolean; can: { undo: boolean; redo: boolean }; onTravel(path: string, direction: 'undo' | 'redo'): void }) {
    return (
        <header className="sve-css__head">
            <h3 className="sve-css__path" title={`public/sunrice-theme/${file.path}`}>
                {file.path}
            </h3>
            {!linked && <span className="sve-tag">Not linked</span>}
            <span className="sve-css__history" role="group" aria-label={`Undo and redo in ${file.path}`}>
                <button type="button" className="sve-css__step" disabled={!can.undo} onClick={() => onTravel(file.path, 'undo')} title={`Undo the last change to ${file.path}`} aria-label={`Undo in ${file.path}`}>
                    ↶
                </button>
                <button type="button" className="sve-css__step" disabled={!can.redo} onClick={() => onTravel(file.path, 'redo')} title={`Redo what was undone in ${file.path}`} aria-label={`Redo in ${file.path}`}>
                    ↷
                </button>
            </span>
        </header>
    );
}

function RuleCard({ rule, at, onEdit }: { rule: StyleRule; at: number; onEdit(edit: StyleEdit): void }) {
    const name = `${rule.within.length > 0 ? `${rule.within.join(' ')} ` : ''}${rule.selector}`;

    return (
        <div className="sve-rule" role="group" aria-label={name}>
            {rule.within.map((around, index) => (
                <code key={index} className="sve-rule__within">
                    {around}
                </code>
            ))}
            <p className="sve-rule__head">
                <code className="sve-rule__selector">{rule.selector}</code>
                <span className="sve-rule__line">line {rule.line}</span>
            </p>
            {/* By their place in the rule, not their place in the file: the place in the file moves with every change before it. */}
            {rule.declarations.map((declaration, index) => (
                <div key={index} className="sve-declaration">
                    <Field label={`Property ${index + 1} of ${name}`} bare mono value={declaration.property} onCommit={(property) => onEdit({ kind: 'property', rule: at, index, property })} />
                    <Field label={`Value of ${declaration.property} in ${name}`} bare mono value={declaration.value} onCommit={(value) => onEdit({ kind: 'value', rule: at, index, value })} />
                    <button type="button" className="sve-attribute__remove" onClick={() => onEdit({ kind: 'remove', rule: at, index })} aria-label={`Remove ${declaration.property} from ${name}`} title="Remove">
                        ×
                    </button>
                </div>
            ))}
            <NewRow label={name} onAdd={(property, value) => onEdit({ kind: 'add', rule: at, property, value })} />
        </div>
    );
}

/**
 * The empty row at the end of a rule. Enter, or leaving the row, adds what
 * was typed when it is a whole declaration. Several can be pasted into the
 * first field as CSS writes them: "color: red; margin: 0".
 */
function NewRow({ label, onAdd }: { label: string; onAdd(property: string, value: string): void }) {
    const [property, setProperty] = useState('');
    const [value, setValue] = useState('');
    const add = () => {
        if (typedDeclarations(property, value).length > 0) {
            onAdd(property, value);
            setProperty('');
            setValue('');
        }
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        add();
    };
    const left = (event: FocusEvent<HTMLFormElement>) => {
        if (!(event.relatedTarget instanceof Node) || !event.currentTarget.contains(event.relatedTarget)) {
            add();
        }
    };

    return (
        <form className="sve-declaration sve-declaration--new" onSubmit={submit} onBlur={left}>
            <input className="sve-input sve-input--mono" value={property} onChange={(event) => setProperty(event.target.value)} placeholder="property" aria-label={`New property for ${label}`} spellCheck={false} autoComplete="off" autoCapitalize="off" />
            <input className="sve-input sve-input--mono" value={value} onChange={(event) => setValue(event.target.value)} placeholder="value" aria-label={`Value of the new property for ${label}`} spellCheck={false} autoComplete="off" autoCapitalize="off" />
            <button type="submit" className="sve-declaration__add" disabled={typedDeclarations(property, value).length === 0} aria-label={`Add it to ${label}`} title="Add (Enter)">
                +
            </button>
        </form>
    );
}
