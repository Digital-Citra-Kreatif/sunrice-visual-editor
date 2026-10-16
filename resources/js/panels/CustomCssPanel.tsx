import { useEffect, useState } from 'react';
import { ApiError, readCustomCss, saveStylesheet, type CustomCss, type Loaded } from '../lib/api';
import { PlainCode, RichCode, useCodeScript } from './CodePanel';

type Props = {
    /** Whether whoever is editing may change stylesheets: without it the CSS is shown, not changed. */
    mayEdit: boolean;
    /** Where the code editor's script is fetched from; null for the plain text field alone. */
    editorScript: string | null;
    /** Whether there is CSS typed and not saved. */
    onUnsaved(unsaved: boolean): void;
    /** The file was written: the page is drawn again with it. */
    onSaved(path: string): void;
};

type Trouble = { conflict: boolean; message: string };

const message = (error: unknown) => (error instanceof Error ? error.message : 'Something went wrong.');

/**
 * The site's custom CSS as one text, beside the page. It is a file of the
 * theme (public/sunrice-theme/custom.css) that every page of the site
 * links by itself, through the layout's `@stack('head')`: no template is
 * changed for it. Saving writes the file and draws the page again.
 */
export function CustomCssPanel({ mayEdit, editorScript, onUnsaved, onSaved }: Props) {
    const script = useCodeScript(editorScript);
    const [file, setFile] = useState<Loaded<CustomCss>>({ state: 'idle' });
    const [readings, setReadings] = useState(0);
    const [draft, setDraft] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [trouble, setTrouble] = useState<Trouble | null>(null);

    useEffect(() => {
        let stale = false;
        setFile((was) => (was.state === 'ready' ? was : { state: 'loading' }));
        readCustomCss()
            .then((data) => !stale && setFile({ state: 'ready', data }))
            .catch((error: unknown) => !stale && setFile({ state: 'failed', error: message(error) }));

        return () => {
            stale = true;
        };
    }, [readings]);

    const changed = draft !== null;
    useEffect(() => onUnsaved(changed), [changed]);

    if (file.state !== 'ready') {
        return <p className="sve-empty sve-code__none">{file.state === 'failed' ? file.error : 'Loading…'}</p>;
    }
    const css = file.data;
    if (css.source === null) {
        return <p className="sve-empty sve-code__none">{css.path} is not UTF-8 text and can’t be shown here. Save it as UTF-8 in a code editor first.</p>;
    }
    const saved = css.source;
    const text = draft ?? saved;
    const readOnly = !mayEdit;
    const typed = (now: string) => setDraft(now === saved ? null : now);

    const save = () => {
        if (!changed || saving || readOnly) {
            return;
        }
        setSaving(true);
        setTrouble(null);
        saveStylesheet(css.path, css.version, text, null)
            .then((written) => {
                setFile({ state: 'ready', data: { ...css, source: text, version: written.file.version, exists: true } });
                setDraft(null);
                onSaved(css.path);
            })
            .catch((error: unknown) => setTrouble({ conflict: error instanceof ApiError && error.reason === 'conflict', message: message(error) }))
            .finally(() => setSaving(false));
    };

    return (
        <section className="sve-code sve-code--beside sve-custom-css" aria-label="Custom CSS">
            <header className="sve-code__bar">
                <span className="sve-code__name" title={css.url}>
                    sunrice-theme/{css.path}
                </span>
                {readOnly ? <span className="sve-tag">Read-only</span> : changed && <span className="sve-tag sve-tag--changed">Not saved</span>}
                <span className="sve-code__space" />
                {!readOnly && (
                    <>
                        <button type="button" className="sve-button sve-button--quiet" disabled={!changed || saving} onClick={() => setDraft(null)}>
                            Revert
                        </button>
                        <button type="button" className="sve-button" disabled={!changed || saving} onClick={save} title="Ctrl+S or ⌘S">
                            {saving ? 'Saving…' : 'Save'}
                        </button>
                    </>
                )}
            </header>

            <p className="sve-custom-css__note">
                {css.injected
                    ? `Every page of the site links this file through its layout’s @stack('${css.stack}'). Save to see it on the page.`
                    : `Pages don’t link this file by themselves here (custom_css.inject is off): link ${css.url} from your layout.`}
            </p>

            {trouble !== null && (
                <div className="sve-code__trouble" role="alert">
                    <p>
                        <strong>Not saved. </strong>
                        {trouble.message}
                    </p>
                    {trouble.conflict && (
                        <button
                            type="button"
                            className="sve-button sve-button--quiet"
                            onClick={() => {
                                setDraft(null);
                                setTrouble(null);
                                setReadings((count) => count + 1);
                            }}
                        >
                            Load the file as it is now
                        </button>
                    )}
                </div>
            )}

            {script === 'waiting' ? (
                <div className="sve-code__scroll" aria-busy="true" />
            ) : script === 'none' ? (
                <PlainCode text={text} readOnly={readOnly} label="Custom CSS" onText={typed} onSave={save} />
            ) : (
                <RichCode
                    script={script}
                    name={`theme:${css.path}`}
                    text={text}
                    saved={saved}
                    readOnly={readOnly}
                    label="Custom CSS"
                    onText={typed}
                    onSave={save}
                    kept={[`theme:${css.path}`]}
                    goTo={null}
                    onCursor={() => {}}
                    put={null}
                    fields={null}
                />
            )}
        </section>
    );
}
