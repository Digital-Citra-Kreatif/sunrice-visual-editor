import { useEffect, useId, useState, type KeyboardEvent, type ReactNode } from 'react';
import type { Operation, PickField, PickVariable, Site, TemplateData, TreeNode } from '../lib/api';
import { LIMITS, OPERATORS, condition, given, orders, readCondition, readWhere, takesValue, testLabel, testsFor, writeWhere, type Test, type Where } from '../lib/builders';
import { binding, boundTo, echoed } from '../lib/fields';
import { WRAPPERS, type Action } from '../lib/move';
import { argumentsOf, attribute, attributesOf, classesOf, headOf, joinInclude, joinLoop, sourceOf, splitInclude, splitLoop } from '../lib/settings';
import { kindOf } from '../lib/tree';
import { FieldChooser } from './FieldsPanel';
import { nodeTitle } from './LayersPanel';
import { Panel } from './Panel';

type Props = {
    template: TemplateData;
    node: TreeNode;
    /** What can be read at the node: the CMS's fields and variables. Null until it is known. */
    fields: PickVariable[] | null;
    /** The site's collections and taxonomies, for the controls that choose one. Null until it is known. */
    site: Site | null;
    /** A change is on its way to the server: nothing else can be sent until it is back. */
    busy: boolean;
    /** Why nothing can be changed right now, when that is so. */
    locked: string | null;
    /** Goes up when a change is refused: the controls then show the file again, not what was typed. */
    refusals: number;
    /** What can be done to the node as a whole. */
    can: Record<Action | 'wrap', boolean>;
    /** For an element: controls for the rule of one of its classes, and the rules of the theme's stylesheets for it. Null for what has no classes. */
    styling: { style: ReactNode; css: ReactNode } | null;
    /** Which is shown for an element: its settings, its style, or its CSS. */
    tab: SettingsTab;
    onTab(tab: SettingsTab): void;
    onApply(operations: Operation[]): void;
    onAct(action: Action): void;
    onDeselect(): void;
};

export type SettingsTab = 'settings' | 'style' | 'css';

const ACTIONS: { action: Action; label: string; title: string }[] = [
    { action: 'up', label: '↑', title: 'Move up, before what is above it (Alt+↑ in the layers)' },
    { action: 'down', label: '↓', title: 'Move down, after what is below it (Alt+↓ in the layers)' },
    { action: 'duplicate', label: 'Duplicate', title: 'Put a copy after it (Ctrl+D in the layers)' },
    { action: 'unwrap', label: 'Unwrap', title: 'Take it away and keep what is inside it' },
    { action: 'delete', label: 'Delete', title: 'Take it out of the template (Delete in the layers)' },
];

/** What each kind of node is called. */
const KINDS: Record<ReturnType<typeof kindOf>, string> = {
    element: 'Element',
    text: 'Text',
    field: 'Field',
    label: 'Interface text',
    expression: 'Expression',
    condition: 'Condition',
    loop: 'Loop',
    arm: 'Part of a block',
    include: 'Include',
    component: 'Component',
    directive: 'Directive',
    code: 'Code',
    comment: 'Comment',
    stray: 'Tag without its other half',
};

/**
 * The right panel when something is selected: what the node is, and a
 * control for each thing about it that can be changed. A control sends one
 * operation when it is left or Enter is pressed; the file is saved, the
 * page drawn again, and the node stays selected.
 */
export function SettingsPanel({ template, node, fields, site, busy, locked, refusals, can, styling, tab, onTab, onApply, onAct, onDeselect }: Props) {
    const kind = kindOf(node);
    const off = busy || locked !== null || !template.editable;
    const send: Send = (operation, to = node) => onApply([{ ...operation, node: to.id } as Operation]);
    /** Fills a control from a field: the text, what an echo prints, or an attribute's value. */
    const bind = (to: PickVariable | PickField, attribute: string | null = null, on: TreeNode = node) => {
        const operations = binding(on, to, attribute);
        if (operations.length > 0) {
            onApply(operations);
        }
    };
    // The tag whose attributes are changed: the element's own, or for a component (selected as
    // a whole, or by its one arm) the tag it opens with, which holds what the component is given.
    const head = headOf(node);
    const tag = node.can.attributes ? node : head !== null && head.can.attributes ? head : null;
    const component = tag !== null && typeof tag.stray !== 'string' && (tag.type === 'branch' || tag.atom === 'component');

    return (
        <Panel title="Settings" grow>
            <div className="sve-selection__head">
                <span className="sve-selection__kind">{KINDS[kind]}</span>
                <button type="button" className="sve-selection__clear" onClick={onDeselect} title="Select nothing">
                    Clear
                </button>
            </div>
            <strong className="sve-selection__title">{nodeTitle(node)}</strong>
            {typeof node.stray === 'string' && <p className="sve-note__reason">{node.stray}</p>}
            {locked !== null && <p className="sve-note sve-settings__locked">{locked}</p>}

            <div className="sve-actions" role="group" aria-label="Move, copy or delete it">
                {ACTIONS.map(({ action, label, title }) => (
                    <button key={action} type="button" className={`sve-action sve-action--${action}`} disabled={off || !can[action]} onClick={() => onAct(action)} title={title} aria-label={action === 'up' ? 'Move up' : action === 'down' ? 'Move down' : undefined}>
                        {label}
                    </button>
                ))}
                <select
                    className="sve-action sve-action--wrap"
                    value=""
                    disabled={off || !can.wrap}
                    onChange={(event) => event.target.value !== '' && send({ type: 'wrap', wrapper: event.target.value })}
                    aria-label="Wrap in"
                    title="Put it inside something new"
                >
                    <option value="">Wrap in…</option>
                    {WRAPPERS.map((each) => (
                        <option key={each.title} value={each.wrapper}>
                            {each.title}
                        </option>
                    ))}
                </select>
            </div>

            {styling !== null && (
                <div className="sve-places sve-settings__tabs" role="group" aria-label="What is shown of it">
                    <button type="button" className="sve-places__place" aria-pressed={tab === 'settings'} onClick={() => onTab('settings')} title="Its tag, classes and attributes">
                        Settings
                    </button>
                    <button type="button" className="sve-places__place" aria-pressed={tab === 'style'} onClick={() => onTab('style')} title="Layout, spacing, text and colours, for one of its classes">
                        Style
                    </button>
                    <button type="button" className="sve-places__place" aria-pressed={tab === 'css'} onClick={() => onTab('css')} title="The rules of the theme’s stylesheets for its classes">
                        CSS
                    </button>
                </div>
            )}

            {/* A new node, a new version of this one, or a change that was refused starts every control afresh. */}
            {(styling === null || tab === 'settings') && (
            <div className="sve-settings" key={`${template.version}:${node.id}:${refusals}`}>
                {tag !== null && component && tag.name === 'sunrice::entries' && <EntriesSettings tag={tag} site={site} off={off} onApply={onApply} />}
                {tag !== null && component && tag.name === 'sunrice::terms' && <TermsSettings tag={tag} site={site} off={off} onApply={onApply} />}
                {tag !== null && <ElementSettings node={tag} component={component} fields={fields} off={off} send={(operation) => send(operation, tag)} bind={(to, attribute) => bind(to, attribute, tag)} onApply={onApply} />}
                {node.type === 'text' && (
                    <>
                        <Field label="Text" value={typeof node.text === 'string' ? node.text : ''} multiline disabled={off} onCommit={(text) => send({ type: 'setText', text })} hint="Typed as text: markup or Blade here is shown, not run." />
                        <FieldChooser variables={fields} control="the text" disabled={off} onChoose={(to) => bind(to)} />
                    </>
                )}
                {(kind === 'field' || kind === 'label' || kind === 'expression') && <EchoSettings node={node} fields={fields} off={off} send={send} bind={bind} />}
                {(node.type === 'block' || node.type === 'branch') && <BlockSettings node={node} fields={fields} off={off} send={send} />}
                {node.type === 'atom' && node.atom === 'directive' && node.kind !== 'code' && typeof node.stray !== 'string' && <DirectiveSettings node={node} off={off} send={send} />}

                <SourceSettings template={template} node={node} off={off} send={send} always={kind === 'code'} />
            </div>
            )}
            {styling !== null && tab === 'style' && styling.style}
            {styling !== null && tab === 'css' && styling.css}
        </Panel>
    );
}

/** An operation without its node, which the panel fills in. */
type Change = Operation extends infer Each ? (Each extends Operation ? Omit<Each, 'node'> : never) : never;

type Send = (operation: Change, to?: TreeNode) => void;

type Bind = (to: PickVariable | PickField, attribute?: string | null) => void;

/** Says which of the CMS's fields a value is, when it is one: "$entry › Title". */
function Bound({ fields, expression }: { fields: PickVariable[] | null; expression: string | null }) {
    const found = boundTo(fields, expression);

    return found === null ? null : (
        <p className="sve-bound">
            <span className="sve-chip sve-chip--field" title={found.value.code}>
                {found.trail.join(' › ')}
            </span>
            <span className="sve-bound__what">{found.value.what}</span>
        </p>
    );
}

type ElementProps = {
    /** The node whose tag it is: an element, or the opening tag of a component. */
    node: TreeNode;
    /** A component: it has no tag to rename, and its id and classes are attributes like the rest. */
    component: boolean;
    fields: PickVariable[] | null;
    off: boolean;
    send: Send;
    bind: Bind;
    onApply(operations: Operation[]): void;
};

function ElementSettings({ node, component, fields, off, send, bind, onApply }: ElementProps) {
    const classes = component ? null : classesOf(node);
    const id = component ? null : attribute(node, 'id');
    const others = attributesOf(node).filter((each) => component || each.code || !(each.name === 'class' && classes !== null) && !(each.name === 'id' && !each.dynamic));
    const [adding, setAdding] = useState({ name: '', value: '' });

    return (
        <>
            {node.type === 'element' && <Field label="Tag" value={node.name} mono disabled={off} onCommit={(tag) => send({ type: 'setTag', tag })} />}
            {!component && (id === null || !id.dynamic) && <Field label="ID" value={id?.value ?? ''} mono disabled={off} onCommit={(value) => send({ type: 'setId', id: value })} />}

            {classes !== null && (
                <Group label="Classes">
                    <div className="sve-chips">
                        {classes.map((name) => (
                            <span className="sve-chip" key={name}>
                                {name}
                                <button type="button" className="sve-chip__remove" disabled={off} onClick={() => send({ type: 'removeClass', class: name })} aria-label={`Remove the class ${name}`} title="Remove">
                                    ×
                                </button>
                            </span>
                        ))}
                        <Adder
                            placeholder="Add a class"
                            disabled={off}
                            onAdd={(typed) => {
                                // Several at once, as they would be written: "card wide".
                                const names = typed.split(/[\s,]+/).filter((name) => name !== '' && !classes.includes(name));
                                if (names.length > 0) {
                                    onApply(names.map((name) => ({ type: 'addClass', node: node.id, class: name })));
                                }
                            }}
                        />
                    </div>
                </Group>
            )}

            <Group label={component ? 'What it is given' : 'Attributes'}>
                {others.length === 0 && <p className="sve-empty">{component ? 'Nothing.' : 'None besides the above.'}</p>}
                {others.map((each, index) =>
                    each.code ? (
                        <div className="sve-attribute" key={`code:${index}`}>
                            <code className="sve-attribute__code" title="Written in Blade: edit it in the code below">
                                {each.source}
                            </code>
                        </div>
                    ) : (
                        <div className="sve-attribute" key={each.name}>
                            <span className="sve-attribute__name" title={each.name}>
                                {each.name}
                            </span>
                            <Field
                                label={`Value of ${each.name}`}
                                bare
                                mono={each.dynamic}
                                value={each.value ?? ''}
                                placeholder={each.value === null ? 'no value' : ''}
                                disabled={off}
                                // What Blade prints stays Blade; plain text stays text.
                                onCommit={(value) => send({ type: 'setAttribute', name: each.name, value, blade: each.dynamic })}
                            />
                            <button type="button" className="sve-attribute__remove" disabled={off} onClick={() => send({ type: 'removeAttribute', name: each.name })} aria-label={`Remove the attribute ${each.name}`} title="Remove">
                                ×
                            </button>
                            {/* What a component is given is PHP when it is bound (:limit="3"): not something a field is echoed into. */}
                            {!component && (
                                <div className="sve-attribute__field">
                                    <Bound fields={fields} expression={echoed(each.value)} />
                                    <FieldChooser variables={fields} control={each.name} disabled={off} onChoose={(to) => bind(to, each.name)} />
                                </div>
                            )}
                        </div>
                    ),
                )}
                <form
                    className="sve-attribute sve-attribute--new"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (adding.name.trim() !== '') {
                            send({ type: 'setAttribute', name: adding.name.trim(), value: adding.value, blade: false });
                        }
                    }}
                >
                    <input className="sve-input sve-input--mono" value={adding.name} onChange={(event) => setAdding({ ...adding, name: event.target.value })} placeholder="name" aria-label="Name of a new attribute" disabled={off} spellCheck={false} />
                    <input className="sve-input" value={adding.value} onChange={(event) => setAdding({ ...adding, value: event.target.value })} placeholder="value" aria-label="Value of the new attribute" disabled={off} spellCheck={false} />
                    <button type="submit" className="sve-button sve-button--quiet" disabled={off || adding.name.trim() === ''}>
                        Add
                    </button>
                </form>
            </Group>
        </>
    );
}

function EchoSettings({ node, fields, off, send, bind }: { node: TreeNode; fields: PickVariable[] | null; off: boolean; send: Send; bind: Bind }) {
    const raw = node.atom === 'raw_echo';
    const hint = useId();
    const expression = typeof node.expression === 'string' ? node.expression : '';

    return (
        <>
            <Bound fields={fields} expression={expression} />
            <Field label="Prints" value={expression} mono multiline disabled={off} onCommit={(typed) => send({ type: 'setExpression', expression: typed })} hint="One PHP expression, such as $entry->title." />
            <FieldChooser variables={fields} control="what it prints" disabled={off} onChoose={(to) => bind(to)} />
            <div className="sve-field">
                <label className="sve-check">
                    <input type="checkbox" checked={raw} disabled={off} aria-describedby={`${hint}-hint`} onChange={(event) => send({ type: 'setEchoRaw', raw: event.target.checked })} />
                    Print as HTML
                </label>
                <small className="sve-field__hint" id={`${hint}-hint`}>
                    Off: what it prints is shown as text. On, only for content that is HTML and can be trusted, such as a rich text field.
                </small>
            </div>
        </>
    );
}

function BlockSettings({ node, fields, off, send }: { node: TreeNode; fields: PickVariable[] | null; off: boolean; send: Send }) {
    const head = headOf(node);
    const args = argumentsOf(head);
    if (head === null || args === null) {
        return null;
    }
    const loop = ['foreach', 'forelse'].includes(head.name) ? splitLoop(args) : null;
    if (loop !== null) {
        return (
            <>
                <Bound fields={fields} expression={loop.source} />
                <Field label="Loop over" value={loop.source} mono multiline disabled={off} onCommit={(source) => send({ type: 'setArguments', arguments: joinLoop(source, loop.item) }, head)} hint="What to go through: $entries, $block->images." />
                <FieldChooser
                    variables={fields}
                    control="what it loops over"
                    label="Loop over a field…"
                    offer={(found) => found.value.looped !== null}
                    disabled={off}
                    onChoose={(to) => to.looped !== null && send({ type: 'setArguments', arguments: joinLoop(to.looped, loop.item) }, head)}
                />
                <Field label="Each one as" value={loop.item} mono disabled={off} onCommit={(item) => send({ type: 'setArguments', arguments: joinLoop(loop.source, item) }, head)} hint="The name it has inside the loop: $entry." />
            </>
        );
    }
    const label = kindOf(node) === 'condition' || ['if', 'elseif', 'unless', 'isset', 'empty'].includes(head.name) ? (head.name === 'case' ? 'When it is' : 'Shown when') : 'Between the brackets';
    const written = <Field label={label} value={args} mono multiline disabled={off} onCommit={(value) => send({ type: 'setArguments', arguments: value }, head)} />;
    if (!['if', 'elseif'].includes(head.name)) {
        return written;
    }

    // A condition about one of the values at this place, built from choices. One written by hand stays as it is.
    const asked = readCondition(fields, args);
    const ask = (about: PickVariable | PickField, test: Test, typed = '') => send({ type: 'setArguments', arguments: condition(about, test, typed) }, head);

    return (
        <>
            {asked !== null && (
                <div className="sve-asked">
                    <span className="sve-chip sve-chip--field" title={asked.found.value.code}>
                        {asked.found.trail.join(' › ')}
                    </span>
                    <select className="sve-input sve-asked__test" value={asked.test} disabled={off} onChange={(event) => ask(asked.found.value, event.target.value as Test, asked.typed)} aria-label="What is asked of it">
                        {testsFor(asked.found.value).map((test) => (
                            <option key={test} value={test}>
                                {testLabel(test, asked.found.value)}
                            </option>
                        ))}
                    </select>
                    {takesValue(asked.test) && <Field label="Compared with" bare value={asked.typed} placeholder="a text or a number" disabled={off} onCommit={(typed) => ask(asked.found.value, asked.test, typed)} />}
                </div>
            )}
            <FieldChooser variables={fields} control="the condition" label={asked === null ? 'Ask about a field…' : 'Ask about another field…'} offer={() => true} disabled={off} onChoose={(to) => ask(to, 'set')} />
            {written}
        </>
    );
}

/** A select that is one of a component's attributes: choosing writes it, the first choice takes it away. */
function Choice({ label, value, options, disabled, hint, onChoose }: { label: string; value: string; options: { value: string; label: string }[]; disabled: boolean; hint?: string; onChoose(value: string): void }) {
    const id = useId();
    // What the tag says now is always among the choices, whatever it is.
    const all = options.some((option) => option.value === value) ? options : [...options, { value, label: value }];

    return (
        <div className="sve-field">
            <label className="sve-field__label" htmlFor={id}>
                {label}
            </label>
            <select id={id} className="sve-input" value={value} disabled={disabled} onChange={(event) => onChoose(event.target.value)} aria-describedby={hint === undefined ? undefined : `${id}-hint`}>
                {all.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            {hint !== undefined && (
                <small className="sve-field__hint" id={`${id}-hint`}>
                    {hint}
                </small>
            )}
        </div>
    );
}

/**
 * The operations that give a component something under a name, or take it
 * away: bound (`:limit="3"`, read as PHP) or plain (`collection="articles"`).
 * Whichever way it was written before, it is written one way after.
 */
function give(tag: TreeNode, name: string, value: string | null, bound: boolean): Operation[] {
    const [write, other] = bound ? [`:${name}`, name] : [name, `:${name}`];
    const has = (written: string) => attributesOf(tag).some((each) => !each.code && each.name === written);

    return [
        ...(has(other) ? [{ type: 'removeAttribute' as const, node: tag.id, name: other }] : []),
        ...(value === null ? (has(write) ? [{ type: 'removeAttribute' as const, node: tag.id, name: write }] : []) : [{ type: 'setAttribute' as const, node: tag.id, name: write, value, blade: bound }]),
    ];
}

/**
 * `<x-sunrice::entries>`: which entries it finds, chosen from lists. The
 * tag's attributes are what is written; they are listed as they are below.
 */
function EntriesSettings({ tag, site, off, onApply }: { tag: TreeNode; site: Site | null; off: boolean; onApply(operations: Operation[]): void }) {
    const attributes = attributesOf(tag);
    const collection = given(attributes, 'collection');
    const limit = given(attributes, 'limit');
    const paged = given(attributes, 'paginate');
    const perPage = given(attributes, 'per-page');
    const order = given(attributes, 'order-by');
    const where = given(attributes, 'where');
    const filters = where === null ? [] : where.bound ? readWhere(where.value) : null;
    const [adding, setAdding] = useState<Where | null>(null);

    const fields = site?.collections.find((each) => each.handle === collection?.value)?.fields ?? [];
    const apply = (operations: Operation[]) => operations.length > 0 && onApply(operations);
    const pages = paged !== null && paged.value !== 'false';
    const amounts = LIMITS.map((amount) => ({ value: String(amount), label: String(amount) }));
    // What entries can be filtered by: the collection's fields that hold one plain value.
    const filterable = fields.filter((field) => ['text', 'number', 'bool', 'date', 'value'].includes(field.kind));
    const writeFilters = (rows: Where[]) => apply(give(tag, 'where', writeWhere(rows), true));

    return (
        <Group label="Entries">
            {collection !== null && collection.bound ? (
                <p className="sve-field__hint">The collection is worked out when the page is drawn: {collection.value}</p>
            ) : (
                <Choice
                    label="From"
                    value={collection?.value ?? ''}
                    options={(site?.collections ?? []).map((each) => ({ value: each.handle, label: each.title }))}
                    disabled={off || site === null}
                    onChoose={(handle) => apply(give(tag, 'collection', handle, false))}
                />
            )}
            <Choice
                label="In the order"
                value={order?.value ?? ''}
                options={orders(fields)}
                disabled={off}
                onChoose={(value) => apply(give(tag, 'order-by', value === '' ? null : value, false))}
            />
            {pages ? (
                <Choice
                    label="On each page"
                    value={perPage?.value ?? ''}
                    options={[{ value: '', label: 'As many as the collection is set to' }, ...amounts]}
                    disabled={off}
                    onChoose={(value) => apply(give(tag, 'per-page', value === '' ? null : value, true))}
                />
            ) : (
                <Choice label="Show" value={limit?.value ?? ''} options={[{ value: '', label: 'All of them' }, ...amounts]} disabled={off} onChoose={(value) => apply(give(tag, 'limit', value === '' ? null : value, true))} />
            )}
            <div className="sve-field">
                <label className="sve-check">
                    <input
                        type="checkbox"
                        checked={pages}
                        disabled={off}
                        onChange={(event) => apply(event.target.checked ? [...give(tag, 'limit', null, true), ...give(tag, 'paginate', 'true', true)] : [...give(tag, 'paginate', null, true), ...give(tag, 'per-page', null, true)])}
                    />
                    In pages
                </label>
                <small className="sve-field__hint">On: a page of them at a time. Print {'{{ $component->entries->links() }}'} after the loop for the links to the other pages.</small>
            </div>

            <div className="sve-field">
                <span className="sve-field__label">Only those where</span>
                {filters === null ? (
                    <p className="sve-field__hint">The filter is written by hand: change it under “where” below.</p>
                ) : (
                    <>
                        {[...filters, ...(adding === null ? [] : [adding])].map((row, index) => {
                            const isNew = index === filters.length;
                            const change = (changed: Where) => {
                                if (isNew) {
                                    // Not written until it says what to compare with: an unfinished filter would find nothing.
                                    if (changed.value.trim() === '') {
                                        setAdding(changed);
                                    } else {
                                        setAdding(null);
                                        writeFilters([...filters, changed]);
                                    }
                                } else {
                                    writeFilters(filters.map((each, at) => (at === index ? changed : each)));
                                }
                            };

                            return (
                                <div className="sve-where" key={isNew ? 'new' : `${index}:${row.field}:${row.operator}:${row.value}`}>
                                    <select className="sve-input" value={row.field} disabled={off} onChange={(event) => change({ ...row, field: event.target.value })} aria-label={`Filter ${index + 1}: the field`}>
                                        {[...filterable.map((field) => ({ value: field.handle, label: field.label })), ...(filterable.some((field) => field.handle === row.field) ? [] : [{ value: row.field, label: row.field }])].map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                    <select className="sve-input" value={row.operator} disabled={off} onChange={(event) => change({ ...row, operator: event.target.value })} aria-label={`Filter ${index + 1}: how it is compared`}>
                                        {OPERATORS.map((each) => (
                                            <option key={each.operator} value={each.operator}>
                                                {each.label}
                                            </option>
                                        ))}
                                    </select>
                                    <Field label={`Filter ${index + 1}: compared with`} bare value={row.value} placeholder="a text or a number" disabled={off} onCommit={(value) => change({ ...row, value })} />
                                    <button type="button" className="sve-attribute__remove" disabled={off} onClick={() => (isNew ? setAdding(null) : writeFilters(filters.filter((_, at) => at !== index)))} aria-label={`Remove filter ${index + 1}`} title="Remove">
                                        ×
                                    </button>
                                </div>
                            );
                        })}
                        {adding === null && (
                            <button type="button" className="sve-bind__open" disabled={off || filterable.length === 0} onClick={() => setAdding({ field: filterable[0]?.handle ?? '', operator: '=', value: '' })}>
                                {filterable.length === 0 ? 'The collection has no field to filter by.' : 'Add a filter…'}
                            </button>
                        )}
                    </>
                )}
            </div>
        </Group>
    );
}

/** `<x-sunrice::terms>`: the taxonomy whose terms it lists. */
function TermsSettings({ tag, site, off, onApply }: { tag: TreeNode; site: Site | null; off: boolean; onApply(operations: Operation[]): void }) {
    const taxonomy = given(attributesOf(tag), 'taxonomy');
    if (taxonomy !== null && taxonomy.bound) {
        return null;
    }

    return (
        <Group label="Terms">
            <Choice label="Of" value={taxonomy?.value ?? ''} options={(site?.taxonomies ?? []).map((each) => ({ value: each.handle, label: each.title }))} disabled={off || site === null} onChoose={(handle) => onApply(give(tag, 'taxonomy', handle, false))} />
        </Group>
    );
}

function DirectiveSettings({ node, off, send }: { node: TreeNode; off: boolean; send: Send }) {
    const args = argumentsOf(node);
    if (args === null) {
        return null;
    }
    const include = ['include', 'includeif', 'includeisolated'].includes(node.name.toLowerCase()) ? splitInclude(args) : null;
    if (include !== null) {
        return (
            <>
                <Field label="View" value={include.view} mono disabled={off} onCommit={(view) => send({ type: 'setArguments', arguments: joinInclude(view, include.data) })} hint="The partial to draw here: sunrice.partials.card." />
                <Field label="Passes" value={include.data} mono multiline disabled={off} onCommit={(data) => send({ type: 'setArguments', arguments: joinInclude(include.view, data) })} hint="Data for it, as a PHP array: ['entry' => $entry]. May be empty." />
            </>
        );
    }

    return <Field label="Between the brackets" value={args} mono multiline disabled={off} onCommit={(value) => send({ type: 'setArguments', arguments: value })} />;
}

/** The node as it is written, for changing what no control above covers. */
function SourceSettings({ template, node, off, send, always }: { template: TemplateData; node: TreeNode; off: boolean; send: Send; always: boolean }) {
    const source = template.source === null ? '' : sourceOf(template.source, node);
    if (node.type === 'branch' || node.type === 'root') {
        return null;
    }
    const field = <Field label="As written" value={source} mono multiline tall disabled={off || !node.can.move} onCommit={(code) => send({ type: 'setCode', code })} hint={node.can.move ? 'Changing this rewrites the node. It has to be Blade that stands on its own.' : 'This stays as it is: edit it in the template’s code.'} />;

    return always ? (
        field
    ) : (
        <details className="sve-settings__source">
            <summary>As code</summary>
            {field}
        </details>
    );
}

function Group({ label, children }: { label: string; children: ReactNode }) {
    return (
        <fieldset className="sve-group">
            <legend>{label}</legend>
            {children}
        </fieldset>
    );
}

type FieldProps = {
    label: string;
    value: string;
    onCommit(value: string): void;
    disabled?: boolean;
    mono?: boolean;
    multiline?: boolean;
    tall?: boolean;
    /** No label shown: the field sits in a row that says what it is. */
    bare?: boolean;
    placeholder?: string;
    hint?: string;
};

/**
 * A text control that holds what is typed until it is left or Enter is
 * pressed, then hands it on if it changed. Escape puts back what was there.
 */
export function Field({ label, value, onCommit, disabled = false, mono = false, multiline = false, tall = false, bare = false, placeholder, hint }: FieldProps) {
    const [typed, setTyped] = useState(value);
    useEffect(() => setTyped(value), [value]);

    const commit = () => {
        if (typed !== value) {
            onCommit(typed);
        }
    };
    const keyDown = (event: KeyboardEvent<HTMLInputElement | HTMLTextAreaElement>) => {
        if (event.key === 'Escape') {
            setTyped(value);
        } else if (event.key === 'Enter' && (!multiline || event.metaKey || event.ctrlKey)) {
            event.preventDefault();
            event.currentTarget.blur();
        }
    };
    const id = useId();
    const shared = {
        id,
        className: `sve-input${mono ? ' sve-input--mono' : ''}${tall ? ' sve-input--tall' : ''}`,
        value: typed,
        disabled,
        placeholder,
        spellCheck: false,
        'aria-label': bare ? label : undefined,
        'aria-describedby': hint === undefined ? undefined : `${id}-hint`,
        onBlur: commit,
        onKeyDown: keyDown,
    };
    const control = multiline ? (
        <textarea {...shared} rows={Math.min(tall ? 12 : 6, Math.max(tall ? 4 : 1, typed.split('\n').length))} onChange={(event) => setTyped(event.target.value)} />
    ) : (
        <input {...shared} onChange={(event) => setTyped(event.target.value)} />
    );
    if (bare) {
        return control;
    }

    // The hint describes the control; it is not part of its name.
    return (
        <div className="sve-field">
            <label className="sve-field__label" htmlFor={id}>
                {label}
            </label>
            {control}
            {hint !== undefined && (
                <small className="sve-field__hint" id={`${id}-hint`}>
                    {hint}
                </small>
            )}
        </div>
    );
}

/** A small text control that adds what is typed on Enter and empties itself. */
function Adder({ placeholder, disabled, onAdd }: { placeholder: string; disabled: boolean; onAdd(value: string): void }) {
    const [typed, setTyped] = useState('');

    return (
        <input
            className="sve-input sve-input--mono sve-chips__add"
            value={typed}
            placeholder={placeholder}
            aria-label={placeholder}
            disabled={disabled}
            spellCheck={false}
            onChange={(event) => setTyped(event.target.value)}
            onKeyDown={(event) => {
                if (event.key === 'Enter' && typed.trim() !== '') {
                    event.preventDefault();
                    onAdd(typed);
                    setTyped('');
                } else if (event.key === 'Escape') {
                    setTyped('');
                }
            }}
        />
    );
}
