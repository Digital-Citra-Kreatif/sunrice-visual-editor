import { useContext, useEffect, useRef } from 'react';
import type { ManifestFile } from '../bridge/protocol';
import type { Styles } from '../lib/api';
import { STYLE_GROUPS, declared, hexOf, shown, targetRule, type StyleControl } from '../lib/styleFields';
import { appliesAt, mediaLabel, sameMedia } from '../lib/media';
import type { StyleEdit } from '../lib/styles';
import type { Width } from '../lib/widths';
import { ClassChoice, LookOnly, NoClass, Unlinked } from './CssPanel';
import { Field } from './SettingsPanel';

type Props = {
    /** The element's classes; null when Blade has a hand in them. */
    classes: string[] | null;
    /** The class whose rule the controls read and write. */
    chosen: string | null;
    onChoose(name: string): void;
    /** What the stylesheets say about it; null until it is known. */
    styles: Styles | null;
    failed: string | null;
    /** What the page has for each property, for the element as it is drawn; null when that is not known. */
    onPage: Record<string, string> | null;
    /** The @media condition the controls write under; null for the rule outside any, which is for every width. */
    media: string | null;
    /** The conditions there are to choose from, besides every width. */
    mediaChoices: string[];
    /** The width the page is being edited at; null when it is as wide as the room. */
    width: Width | null;
    onMedia(media: string | null): void;
    linked: string[] | null;
    layout: ManifestFile | null;
    linking: boolean;
    onEdit(edit: StyleEdit): void;
    onLink(stylesheet: string): void;
};

/**
 * A control for each of the declarations most often written, for the
 * rule of one class of the selected element. Each reads one declaration
 * of that rule and writes one: what is typed is CSS, as it would be
 * written in the file. An empty control shows, in grey, what the page has
 * for it from elsewhere. The CSS tab shows the same rule, and the others.
 */
export function StyleFields({ classes, chosen, onChoose, styles, failed, onPage, media, mediaChoices, width, onMedia, linked, layout, linking, onEdit, onLink }: Props) {
    if (classes === null || classes.length === 0 || chosen === null) {
        return <NoClass classes={classes} />;
    }
    const lookOnly = useContext(LookOnly);
    const known = styles !== null && styles.class === chosen ? styles : null;
    const at = known === null ? null : targetRule(known, media);
    const rule = known === null || at === null ? null : known.rules[at];
    const choices = media !== null && !mediaChoices.some((each) => sameMedia(each, media)) ? [...mediaChoices, media] : mediaChoices;
    /** Where the first thing set goes when there is no rule yet: the stylesheet the class's own rule is in, else the editor's. */
    const base = known === null ? null : targetRule(known);
    const writesTo = known === null ? null : media !== null && base !== null ? known.rules[base].file : (known.own?.path ?? null);

    return (
        <div className="sve-css sve-style">
            <ClassChoice classes={classes} chosen={chosen} onChoose={onChoose} />

            {known === null ? (
                <p className="sve-empty">{failed ?? 'Reading the stylesheets…'}</p>
            ) : (
                <>
                    {(choices.length > 0 || width !== null) && (
                        <label className="sve-style__for">
                            <span className="sve-style__label">For</span>
                            <select className="sve-input" value={media ?? ''} onChange={(event) => onMedia(event.target.value === '' ? null : event.target.value)} aria-label="The widths it is set for">
                                <option value="">Every width</option>
                                {choices.map((each) => (
                                    <option key={each} value={each}>
                                        {mediaLabel(each)}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    {width !== null && media !== null && !appliesAt(media, width.width) && <p className="sve-style__target sve-style__target--off">That does not apply at {width.width} pixels, the width being edited: what is set will not show here.</p>}
                    {width !== null && media === null && <p className="sve-style__target">Set for every width, though the page is shown at {width.width} pixels.</p>}

                    <p className="sve-style__target">
                        {rule !== null ? (
                            <>
                                Writes <code>{known.selector}</code>
                                {media !== null && (
                                    <>
                                        {' '}
                                        under <code>{mediaLabel(media)}</code>
                                    </>
                                )}{' '}
                                in <code>{rule.file}</code>, line {rule.line}.
                            </>
                        ) : writesTo !== null ? (
                            <>
                                <code>{known.selector}</code> has no rule {media === null ? 'yet' : <>under <code>{mediaLabel(media)}</code> yet</>}. The first thing set here writes one into <code>{writesTo}</code>.
                            </>
                        ) : (
                            <>
                                <code>{known.selector}</code> has no rule, and the site names no stylesheet to write one into.
                            </>
                        )}
                    </p>

                    {STYLE_GROUPS.map((group) => (
                        <fieldset key={group.title} className="sve-group sve-style__group">
                            <legend>{group.title}</legend>
                            {group.controls
                                .filter((control) => shown(control, rule, onPage))
                                .map((control) => (
                                    <Control
                                        key={control.property}
                                        control={control}
                                        group={group.title}
                                        value={declared(rule, control.property)}
                                        onPage={onPage?.[control.property] ?? null}
                                        disabled={lookOnly || (rule === null && writesTo === null)}
                                        onSet={(value) => onEdit({ kind: 'style', property: control.property, value, media })}
                                    />
                                ))}
                        </fieldset>
                    ))}

                    <Unlinked styles={known} linked={linked} layout={layout} linking={linking} onLink={onLink} />
                </>
            )}
        </div>
    );
}

type ControlProps = {
    control: StyleControl;
    group: string;
    /** What the rule says; null when it says nothing. */
    value: string | null;
    /** What the page has, from wherever. */
    onPage: string | null;
    disabled: boolean;
    onSet(value: string): void;
};

function Control({ control, group, value, onPage, disabled, onSet }: ControlProps) {
    const name = `${group}: ${control.label}`;
    // What the page has is shown where nothing is set here; not for what is short for many things at once.
    const elsewhere = control.quiet === true || onPage === null || onPage === '' ? null : onPage;

    return (
        <div className={`sve-style__row${value === null ? '' : ' sve-style__row--set'}`}>
            <span className="sve-style__label" title={control.property}>
                {control.label}
            </span>
            {control.kind === 'choice' ? (
                <select className="sve-input" value={value ?? ''} disabled={disabled} aria-label={name} onChange={(event) => onSet(event.target.value)}>
                    <option value="">{elsewhere === null ? '—' : `(${elsewhere})`}</option>
                    {/* A value written by hand that is none of the usual ones stays what it is. */}
                    {value !== null && !(control.options ?? []).includes(value) && <option value={value}>{value}</option>}
                    {(control.options ?? []).map((option) => (
                        <option key={option} value={option}>
                            {option}
                        </option>
                    ))}
                </select>
            ) : (
                <span className="sve-style__value">
                    <Field label={name} bare mono value={value ?? ''} placeholder={elsewhere ?? control.example ?? ''} disabled={disabled} onCommit={onSet} />
                    {/* A swatch of its own for each colour it starts from: it is the browser's control, and keeps what was picked. */}
                    {control.kind === 'colour' && <Swatch key={hexOf(value) ?? hexOf(elsewhere) ?? 'none'} name={name} colour={hexOf(value) ?? hexOf(elsewhere)} disabled={disabled} onPick={onSet} />}
                </span>
            )}
        </div>
    );
}

/**
 * A colour to pick. It hands the colour on when the picker is done with,
 * not for every shade passed on the way there: each would be a save.
 */
function Swatch({ name, colour, disabled, onPick }: { name: string; colour: string | null; disabled: boolean; onPick(colour: string): void }) {
    const input = useRef<HTMLInputElement>(null);
    const pick = useRef(onPick);
    pick.current = onPick;
    useEffect(() => {
        const element = input.current;
        if (element === null) {
            return;
        }
        // The browser's own "change": React's onChange is its "input".
        const changed = () => pick.current(element.value);
        element.addEventListener('change', changed);

        return () => element.removeEventListener('change', changed);
    }, []);

    return <input ref={input} type="color" className="sve-style__swatch" defaultValue={colour ?? '#000000'} disabled={disabled} aria-label={`Pick ${name}`} title="Pick a colour" />;
}
