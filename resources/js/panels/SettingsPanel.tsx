import { useEffect, useId, useState, type KeyboardEvent, type ReactNode } from 'react';
import type { Operation, TemplateData, TreeNode } from '../lib/api';
import { WRAPPERS, type Action } from '../lib/move';
import { argumentsOf, attribute, attributesOf, classesOf, headOf, joinInclude, joinLoop, sourceOf, splitInclude, splitLoop } from '../lib/settings';
import { kindOf } from '../lib/tree';
import { nodeTitle } from './LayersPanel';
import { Panel } from './Panel';

type Props = {
    template: TemplateData;
    node: TreeNode;
    /** A change is on its way to the server: nothing else can be sent until it is back. */
    busy: boolean;
    /** Why nothing can be changed right now, when that is so. */
    locked: string | null;
    /** Goes up when a change is refused: the controls then show the file again, not what was typed. */
    refusals: number;
    /** What can be done to the node as a whole. */
    can: Record<Action | 'wrap', boolean>;
    onApply(operations: Operation[]): void;
    onAct(action: Action): void;
    onDeselect(): void;
};

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
export function SettingsPanel({ template, node, busy, locked, refusals, can, onApply, onAct, onDeselect }: Props) {
    const kind = kindOf(node);
    const off = busy || locked !== null || !template.editable;
    const send: Send = (operation, to = node) => onApply([{ ...operation, node: to.id } as Operation]);

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

            {/* A new node, a new version of this one, or a change that was refused starts every control afresh. */}
            <div className="sve-settings" key={`${template.version}:${node.id}:${refusals}`}>
                {node.can.attributes && <ElementSettings node={node} off={off} send={send} onApply={onApply} />}
                {node.type === 'text' && (
                    <Field label="Text" value={typeof node.text === 'string' ? node.text : ''} multiline disabled={off} onCommit={(text) => send({ type: 'setText', text })} hint="Typed as text: markup or Blade here is shown, not run." />
                )}
                {(kind === 'field' || kind === 'label' || kind === 'expression') && <EchoSettings node={node} off={off} send={send} />}
                {(node.type === 'block' || node.type === 'branch') && <BlockSettings node={node} off={off} send={send} />}
                {node.type === 'atom' && node.atom === 'directive' && node.kind !== 'code' && typeof node.stray !== 'string' && <DirectiveSettings node={node} off={off} send={send} />}

                <SourceSettings template={template} node={node} off={off} send={send} always={kind === 'code'} />
            </div>
        </Panel>
    );
}

/** An operation without its node, which the panel fills in. */
type Change = Operation extends infer Each ? (Each extends Operation ? Omit<Each, 'node'> : never) : never;

type Send = (operation: Change, to?: TreeNode) => void;

function ElementSettings({ node, off, send, onApply }: { node: TreeNode; off: boolean; send: Send; onApply(operations: Operation[]): void }) {
    const classes = classesOf(node);
    const id = attribute(node, 'id');
    const others = attributesOf(node).filter((each) => each.code || !(each.name === 'class' && classes !== null) && !(each.name === 'id' && !each.dynamic));
    const [adding, setAdding] = useState({ name: '', value: '' });

    return (
        <>
            {node.type === 'element' && <Field label="Tag" value={node.name} mono disabled={off} onCommit={(tag) => send({ type: 'setTag', tag })} />}
            {(id === null || !id.dynamic) && <Field label="ID" value={id?.value ?? ''} mono disabled={off} onCommit={(value) => send({ type: 'setId', id: value })} />}

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

            <Group label="Attributes">
                {others.length === 0 && <p className="sve-empty">None besides the above.</p>}
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

function EchoSettings({ node, off, send }: { node: TreeNode; off: boolean; send: Send }) {
    const raw = node.atom === 'raw_echo';
    const hint = useId();

    return (
        <>
            <Field label="Prints" value={typeof node.expression === 'string' ? node.expression : ''} mono multiline disabled={off} onCommit={(expression) => send({ type: 'setExpression', expression })} hint="One PHP expression, such as $entry->title." />
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

function BlockSettings({ node, off, send }: { node: TreeNode; off: boolean; send: Send }) {
    const head = headOf(node);
    const args = argumentsOf(head);
    if (head === null || args === null) {
        return null;
    }
    const loop = ['foreach', 'forelse'].includes(head.name) ? splitLoop(args) : null;
    if (loop !== null) {
        return (
            <>
                <Field label="Loop over" value={loop.source} mono multiline disabled={off} onCommit={(source) => send({ type: 'setArguments', arguments: joinLoop(source, loop.item) }, head)} hint="What to go through: $entries, $block->images." />
                <Field label="Each one as" value={loop.item} mono disabled={off} onCommit={(item) => send({ type: 'setArguments', arguments: joinLoop(loop.source, item) }, head)} hint="The name it has inside the loop: $entry." />
            </>
        );
    }
    const label = kindOf(node) === 'condition' || ['if', 'elseif', 'unless', 'isset', 'empty'].includes(head.name) ? (head.name === 'case' ? 'When it is' : 'Shown when') : 'Between the brackets';

    return <Field label={label} value={args} mono multiline disabled={off} onCommit={(value) => send({ type: 'setArguments', arguments: value }, head)} />;
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
function Field({ label, value, onCommit, disabled = false, mono = false, multiline = false, tall = false, bare = false, placeholder, hint }: FieldProps) {
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
