import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { ApiError, saveSource, type TemplateData } from '../lib/api';
import { highlight } from '../lib/highlight';

type Props = {
    template: TemplateData;
    /** The text as it is being edited; null when nothing has been changed. */
    draft: string | null;
    onDraft(draft: string | null): void;
    /** The file was written: here it is as it is now. */
    onSaved(template: TemplateData): void;
    /** Read the file again as it is on disk, dropping what was typed. */
    onReload(): void;
    onClose(): void;
};

type Trouble = { kind: 'syntax' | 'conflict' | 'other'; message: string };

/** What Tab puts in: the indentation the file itself uses, four spaces when it shows none. */
function indentOf(source: string): string {
    const match = /^(\t|[ ]{2,8})\S/m.exec(source);

    return match === null ? '    ' : match[1];
}

/**
 * The open template as code. This is where a template the tree can't hold
 * is edited, and where anything can be when a panel falls short.
 *
 * Saving sends the text as it is; the server refuses it when the file has
 * changed since it was read or when the text doesn't compile, and nothing
 * is written.
 */
export function CodePanel({ template, draft, onDraft, onSaved, onReload, onClose }: Props) {
    const area = useRef<HTMLTextAreaElement>(null);
    const [saving, setSaving] = useState(false);
    const [trouble, setTrouble] = useState<Trouble | null>(null);

    const original = template.source ?? '';
    const text = draft ?? original;
    const changed = draft !== null && draft !== original;
    const readOnly = !template.editable || template.source === null;
    const tokens = useMemo(() => highlight(text), [text]);
    const lines = useMemo(() => text.split('\n').length, [text]);

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

    const keyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            save();
        } else if (event.key === 'Tab' && !event.shiftKey && !event.altKey && !event.metaKey && !event.ctrlKey && !readOnly) {
            // Tab indents. Escape first, then Tab, moves on to the next control as usual.
            event.preventDefault();
            const field = event.currentTarget;
            // insertText keeps the browser's own undo; setRangeText is for where it is gone.
            if (!document.execCommand('insertText', false, indentOf(text))) {
                field.setRangeText(indentOf(text), field.selectionStart, field.selectionEnd, 'end');
                onDraft(field.value);
            }
        } else if (event.key === 'Escape') {
            event.currentTarget.blur();
        }
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
            ) : (
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
                            ref={area}
                            className="sve-code__field"
                            value={text}
                            readOnly={readOnly}
                            onChange={(event) => onDraft(event.target.value === original ? null : event.target.value)}
                            onKeyDown={keyDown}
                            spellCheck={false}
                            autoCapitalize="off"
                            autoComplete="off"
                            autoCorrect="off"
                            wrap="off"
                            aria-label={`Source of ${template.path}`}
                        />
                    </div>
                </div>
            )}
        </section>
    );
}
