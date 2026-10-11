import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import type { CodeEditor } from '../code/index';
import { byteOfIndex, indentOf, indexOfByte } from '../code/text';
import { ApiError, saveSource, type TemplateData } from '../lib/api';
import { codeEditorScript, type CodeEditorScript } from '../lib/codeEditor';
import { highlight } from '../lib/highlight';
import { sameTemplate, type TemplateName } from '../lib/templates';

type Props = {
    /** The template whose code is shown. While another is being read, it is still the one before. */
    template: TemplateData;
    /** The template that is open: the one whose tab is marked. */
    current: TemplateName | null;
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
    /** The templates opened since the editor was: each keeps its text as typed. The open one is among them. */
    tabs: CodeTab[];
    onOpenTab(name: TemplateName): void;
    onCloseTab(name: TemplateName): void;
    /** Whether the code is beside the page (Code mode) and not under it: it is then always there, with nothing to close. */
    beside: boolean;
    /** A place of the open template the cursor is asked to go to: an element clicked on the page, or a layer. */
    goTo: CodePlace | null;
    /** The cursor is somewhere else: where that is in the file as saved, in bytes as nodes are counted. */
    onCursor(byte: number): void;
};

export type CodeTab = TemplateName & { unsaved: boolean };

/** A place in a template's file as saved, in bytes. `asked` tells one asking from the next for the same place. */
export type CodePlace = TemplateName & { byte: number; asked: number; focus: boolean };

/** A template's path as a tab says it: without what every one of them ends with. */
const tabName = (path: string) => path.replace(/\.blade\.php$/, '');

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
export function CodePanel({ template, current: opened, draft, onDraft, onSaved, mayCode, onReload, onClose, editorScript, tabs, onOpenTab, onCloseTab, beside, goTo, onCursor }: Props) {
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
    // The open template's tab is in sight, however many there are.
    const openTab = useRef<HTMLSpanElement>(null);
    useEffect(() => openTab.current?.scrollIntoView({ block: 'nearest', inline: 'nearest' }), [template.path, template.origin, tabs.length]);

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
        <section className={`sve-code${beside ? ' sve-code--beside' : ''}`} aria-label={`Code of ${template.path}`}>
            <header className="sve-code__bar">
                <div className="sve-tabs" role="tablist" aria-label="Open templates">
                    {tabs.map((tab) => {
                        const current = sameTemplate(tab, opened ?? template);

                        return (
                            <span key={`${tab.origin}:${tab.path}`} className={`sve-tab${current ? ' sve-tab--open' : ''}`} ref={current ? openTab : undefined}>
                                <button type="button" role="tab" className="sve-tab__name" aria-selected={current} onClick={() => onOpenTab(tab)} title={tab.unsaved ? `${tab.path}: not saved` : tab.path}>
                                    {tabName(tab.path)}
                                    {tab.unsaved && <span className="sve-tab__dot" aria-label="not saved" />}
                                </button>
                                {tabs.length > 1 && (
                                    <button type="button" className="sve-tab__close" onClick={() => onCloseTab(tab)} aria-label={`Close ${tab.path}`} title="Close">
                                        ×
                                    </button>
                                )}
                            </span>
                        );
                    })}
                </div>
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
                {!beside && (
                    <button type="button" className="sve-code__close" onClick={onClose} aria-label="Close the code panel" title="Close">
                        ×
                    </button>
                )}
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
                <RichCode
                    script={script}
                    name={`${template.origin}:${template.path}`}
                    text={text}
                    saved={original}
                    readOnly={readOnly}
                    label="Source of the open template"
                    onText={typed}
                    onSave={save}
                    kept={tabs.map((tab) => `${tab.origin}:${tab.path}`)}
                    goTo={goTo !== null && sameTemplate(goTo, template) ? goTo : null}
                    onCursor={onCursor}
                />
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

type RichProps = CodeProps & {
    script: CodeEditorScript;
    /** What the text is kept under while another is shown: the template it is of. */
    name: string;
    /** The file as saved, which `text` is a change to. */
    saved: string;
    /** The names of the texts worth keeping: those of the open tabs. */
    kept: string[];
    goTo: CodePlace | null;
    onCursor(byte: number): void;
};

/**
 * The code in the code editor (CodeMirror): search and replace, undo of
 * its own, several cursors. One editor for as long as the panel is there;
 * it keeps each template's text as it was left while another is shown.
 */
function RichCode({ script, name, text, saved, readOnly, label, onText, onSave, kept, goTo, onCursor }: RichProps) {
    const holder = useRef<HTMLDivElement>(null);
    const editor = useRef<CodeEditor | null>(null);
    // The editor is made once and calls whatever the panel's functions are by then.
    const calls = useRef({ onText, onSave, onCursor, saved });
    calls.current = { onText, onSave, onCursor, saved };
    /** What the editor shows: under which name, and the text as the editor last said it was. */
    const shown = useRef<{ name: string | null; text: string; saved: string; readOnly: boolean }>({ name: null, text: '', saved: '', readOnly: true });
    const went = useRef(0);
    const before = useRef(kept);

    useEffect(() => {
        if (holder.current === null) {
            return;
        }
        const made = script.mount(holder.current, {
            label,
            onChange: (now) => {
                shown.current.text = now;
                calls.current.onText(now);
            },
            onSave: () => calls.current.onSave(),
            onCursor: (place) => calls.current.onCursor(byteOfIndex(calls.current.saved, place)),
        });
        editor.current = made;
        shown.current = { name: null, text: '', saved: '', readOnly: true };

        return () => {
            made.destroy();
            editor.current = null;
        };
    }, [script]);

    // Another template: its text as it was left. The same one: only what came from outside
    // the editor (Revert, the file read again, a save) is told to it.
    useEffect(() => {
        const now = editor.current;
        if (now === null) {
            return;
        }
        if (shown.current.name !== name) {
            now.show(name, { text, saved, readOnly });
        } else {
            if (text !== shown.current.text) {
                now.setText(text);
            }
            if (saved !== shown.current.saved) {
                now.setSaved(saved);
            }
            if (readOnly !== shown.current.readOnly) {
                now.setReadOnly(readOnly);
            }
        }
        shown.current = { name, text, saved, readOnly };
    }, [script, name, text, saved, readOnly]);

    // A tab that is closed takes its text with it.
    useEffect(() => {
        for (const gone of before.current.filter((each) => !kept.includes(each))) {
            editor.current?.forget(gone);
        }
        before.current = kept;
    }, [kept.join('\n')]);

    // The cursor goes where it was asked to, once: after the template it is asked for is the one shown.
    useEffect(() => {
        if (goTo !== null && goTo.asked !== went.current && editor.current !== null && shown.current.name === name) {
            went.current = goTo.asked;
            editor.current.goTo(indexOfByte(saved, goTo.byte), goTo.focus);
        }
    }, [script, name, saved, goTo?.asked]);

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
