import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import type { CodeEditor } from '../code/index';
import { indentOf } from '../code/text';
import { ApiError, saveSource, type TemplateData } from '../lib/api';
import { codeEditorScript, type CodeEditorScript } from '../lib/codeEditor';
import { highlight } from '../lib/highlight';

type Props = {
    template: TemplateData;
    /** The text as it is being edited; null when nothing has been changed. */
    draft: string | null;
    onDraft(draft: string | null): void;
    /** The file was written: here it is as it is now. */
    onSaved(template: TemplateData): void;
    /** Whether whoever is editing may write code: without it the code is shown, not changed. */
    mayCode: boolean;
    /** Read the file again as it is on disk, dropping what was typed. */
    onReload(): void;
    onClose(): void;
    /** Where the code editor's script is fetched from; null for the plain text field alone. */
    editorScript: string | null;
};

/** How long the code editor's script is waited for before the plain text field is shown in its place. */
const WAIT = 3000;

type Trouble = { kind: 'syntax' | 'conflict' | 'other'; message: string };

/**
 * The open template as code. This is where a template the tree can't hold
 * is edited, and where anything can be when a panel falls short.
 *
 * Saving sends the text as it is; the server refuses it when the file has
 * changed since it was read or when the text doesn't compile, and nothing
 * is written.
 */
export function CodePanel({ template, draft, onDraft, onSaved, mayCode, onReload, onClose, editorScript }: Props) {
    // The code editor is a script of its own. Until it has come the panel waits a moment, then shows
    // the plain text field, and keeps to it: nobody has the field changed under their hands.
    const [script, setScript] = useState<CodeEditorScript | 'waiting' | 'none'>(editorScript === null ? 'none' : 'waiting');
    useEffect(() => {
        if (editorScript === null) {
            return;
        }
        let over = false;
        const late = window.setTimeout(() => !over && setScript((now) => (now === 'waiting' ? 'none' : now)), WAIT);
        codeEditorScript(editorScript)
            .then((fetched) => !over && setScript((now) => (now === 'waiting' ? fetched : now)))
            .catch(() => !over && setScript((now) => (now === 'waiting' ? 'none' : now)));

        return () => {
            over = true;
            window.clearTimeout(late);
        };
    }, [editorScript]);
    const [saving, setSaving] = useState(false);
    const [trouble, setTrouble] = useState<Trouble | null>(null);

    const original = template.source ?? '';
    const text = draft ?? original;
    const changed = draft !== null && draft !== original;
    const readOnly = !template.editable || template.source === null || !mayCode;
    const typed = (now: string) => onDraft(now === original ? null : now);

    // Another template, or this one read again: what was wrong with the last save is no longer the matter.
    useEffect(() => setTrouble(null), [template.path, template.origin, template.version]);

    const save = () => {
        if (!changed || saving || readOnly) {
            return;
        }
        setSaving(true);
        setTrouble(null);
        saveSource(template.path, template.version, text)
            .then(onSaved)
            .catch((error: unknown) => {
                const kind = error instanceof ApiError && (error.reason === 'syntax' || error.reason === 'conflict') ? error.reason : 'other';
                setTrouble({ kind, message: error instanceof Error ? error.message : 'The template could not be saved.' });
            })
            .finally(() => setSaving(false));
    };

    return (
        <section className="sve-code" aria-label={`Code of ${template.path}`}>
            <header className="sve-code__bar">
                <strong className="sve-code__name">{template.path}</strong>
                {readOnly ? <span className="sve-tag">Read-only</span> : changed && <span className="sve-tag sve-tag--changed">Not saved</span>}
                <span className="sve-code__space" />
                {!readOnly && (
                    <>
                        <button type="button" className="sve-button sve-button--quiet" disabled={!changed || saving} onClick={() => onDraft(null)}>
                            Revert
                        </button>
                        <button type="button" className="sve-button" disabled={!changed || saving} onClick={save} title="Ctrl+S or ⌘S">
                            {saving ? 'Saving…' : 'Save'}
                        </button>
                    </>
                )}
                <button type="button" className="sve-code__close" onClick={onClose} aria-label="Close the code panel" title="Close">
                    ×
                </button>
            </header>

            {trouble !== null && (
                <div className="sve-code__trouble" role="alert">
                    <p>
                        {trouble.kind === 'syntax' && <strong>Not saved: this doesn’t compile. </strong>}
                        {trouble.kind === 'conflict' && <strong>Not saved. </strong>}
                        {trouble.message}
                    </p>
                    {trouble.kind === 'conflict' && (
                        <button type="button" className="sve-button sve-button--quiet" onClick={onReload}>
                            Load the file as it is now
                        </button>
                    )}
                </div>
            )}

            {template.source === null ? (
                <p className="sve-empty sve-code__none">This file is not UTF-8 text and can’t be shown here. Save it as UTF-8 in a code editor first.</p>
            ) : script === 'waiting' ? (
                <div className="sve-code__scroll" aria-busy="true" />
            ) : script === 'none' ? (
                <PlainCode text={text} readOnly={readOnly} label={`Source of ${template.path}`} onText={typed} onSave={save} />
            ) : (
                <RichCode key={`${template.origin}:${template.path}`} script={script} text={text} readOnly={readOnly} label={`Source of ${template.path}`} onText={typed} onSave={save} />
            )}
        </section>
    );
}

type CodeProps = {
    text: string;
    readOnly: boolean;
    label: string;
    onText(text: string): void;
    onSave(): void;
};

/** The code in the code editor (CodeMirror): search and replace, undo of its own, several cursors. */
function RichCode({ script, text, readOnly, label, onText, onSave }: CodeProps & { script: CodeEditorScript }) {
    const holder = useRef<HTMLDivElement>(null);
    const editor = useRef<CodeEditor | null>(null);
    // The editor is made once and calls whatever the panel's functions are by then.
    const calls = useRef({ onText, onSave });
    calls.current = { onText, onSave };
    /** The text as the editor last said it was: a text that differs from it came from outside (Revert, the file read again). */
    const said = useRef(text);

    useEffect(() => {
        if (holder.current === null) {
            return;
        }
        const made = script.mount(holder.current, {
            text: said.current,
            readOnly,
            indent: indentOf(said.current),
            label,
            onChange: (now) => {
                said.current = now;
                calls.current.onText(now);
            },
            onSave: () => calls.current.onSave(),
        });
        editor.current = made;

        return () => {
            made.destroy();
            editor.current = null;
        };
        // One editor for as long as the panel shows this template: its undo is its own.
    }, [script]);

    useEffect(() => {
        if (text !== said.current) {
            said.current = text;
            editor.current?.setText(text);
        }
    }, [text]);
    useEffect(() => editor.current?.setReadOnly(readOnly), [readOnly]);

    return <div ref={holder} className="sve-code__editor" />;
}

/** The code in a plain text field, coloured: what is shown when the code editor's script is not there. */
function PlainCode({ text, readOnly, label, onText, onSave }: CodeProps) {
    const tokens = useMemo(() => highlight(text), [text]);
    const lines = useMemo(() => text.split('\n').length, [text]);

    const keyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            onSave();
        } else if (event.key === 'Tab' && !event.shiftKey && !event.altKey && !event.metaKey && !event.ctrlKey && !readOnly) {
            // Tab indents. Escape first, then Tab, moves on to the next control as usual.
            event.preventDefault();
            const field = event.currentTarget;
            // insertText keeps the browser's own undo; setRangeText is for where it is gone.
            if (!document.execCommand('insertText', false, indentOf(text))) {
                field.setRangeText(indentOf(text), field.selectionStart, field.selectionEnd, 'end');
                onText(field.value);
            }
        } else if (event.key === 'Escape') {
            event.currentTarget.blur();
        }
    };

    return (
        <div className="sve-code__scroll">
            <div className="sve-code__lines" aria-hidden="true">
                {Array.from({ length: lines }, (_, line) => (
                    <span key={line}>{line + 1}</span>
                ))}
            </div>
            <div className="sve-code__text">
                {/* The colours lie under the text field, character for character; the field's own text is see-through. */}
                <pre className="sve-code__colours" aria-hidden="true">
                    {tokens.map((token, index) => (
                        <span key={index} className={token.kind === 'text' ? undefined : `sve-code--${token.kind}`}>
                            {token.text}
                        </span>
                    ))}
                    {'\n'}
                </pre>
                <textarea
                    className="sve-code__field"
                    value={text}
                    readOnly={readOnly}
                    onChange={(event) => onText(event.target.value)}
                    onKeyDown={keyDown}
                    spellCheck={false}
                    autoCapitalize="off"
                    autoComplete="off"
                    autoCorrect="off"
                    wrap="off"
                    aria-label={label}
                />
            </div>
        </div>
    );
}
