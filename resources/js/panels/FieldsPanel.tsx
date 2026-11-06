import { useEffect, useRef, useState, type ReactNode } from 'react';
import { readFieldCatalog, readFieldEditors, type CmsFieldEditor, type CmsFieldEditors, type FieldCatalog, type ReferenceGroup, type Loaded, type ReferenceVariable, type TemplateData } from '../lib/api';
import { cmsEditorHref, fieldExamples, fieldLabel, filterFields, templateFieldSources, type FieldReference } from '../lib/fields';
import type { ShownEntry } from '../bridge/protocol';

type Props = {
    active: boolean;
    template: TemplateData | null;
    node: string | null;
    entry: ShownEntry | null;
    unsaved: boolean;
    onBack(): void;
    onReload(): void;
};
type Reference = { key: string; catalog: FieldCatalog; editors: CmsFieldEditors };

/** Browse the CMS's types and Blade recipes; editing stays in its native forms. */
export function FieldsPanel({ active, template, node, entry, unsaved, onBack, onReload }: Props) {
    const [loaded, setLoaded] = useState<Loaded<Reference>>({ state: 'idle' });
    const [search, setSearch] = useState('');
    const [revision, setRevision] = useState(0);
    const [copied, setCopied] = useState('');
    /** The example last copied, which its button says. */
    const [copiedBlade, setCopiedBlade] = useState('');
    const [copyProblem, setCopyProblem] = useState('');
    const catalogs = useRef(new Map<string, FieldCatalog>());
    const editors = useRef(new Map<string, CmsFieldEditors>());
    const away = useRef(false);
    const back = useRef(onBack);
    back.current = onBack;
    const refresh = () => { catalogs.current.clear(); editors.current.clear(); setLoaded({ state: 'loading' }); setRevision((was) => was + 1); };
    const catalogKey = template === null ? '' : JSON.stringify([template.origin, template.path, template.version, node]);
    const entryHref = entry === null ? null : cmsEditorHref(entry.edit, Sunrice.adminUrl(), window.location.origin);
    const entryId = entryHref === null ? null : entry?.id ?? null;
    const locale = entryHref === null ? null : new URL(entryHref, window.location.href).searchParams.get('locale');
    const editorKey = JSON.stringify([entryId, locale]);
    const key = catalogKey + ':' + editorKey;

    useEffect(() => {
        if (!active || template === null) return;
        let stale = false;
        setLoaded({ state: 'loading' });
        setCopied(''); setCopiedBlade(''); setCopyProblem('');
        const catalog = catalogs.current.get(catalogKey);
        const links = editors.current.get(editorKey);
        Promise.all([catalog ? Promise.resolve(catalog) : readFieldCatalog(template, node), links ? Promise.resolve(links) : readFieldEditors(entryId, locale)])
            .then(([catalog, editorsForPage]) => {
                if (stale) return;
                catalogs.current.set(catalogKey, catalog);
                editors.current.set(editorKey, editorsForPage);
                for (const cache of [catalogs.current, editors.current]) {
                    while (cache.size > 8) {
                        const oldest = cache.keys().next().value;
                        if (oldest !== undefined) cache.delete(oldest);
                    }
                }
                setLoaded({ state: 'ready', data: { key, catalog, editors: editorsForPage } });
            })
            .catch((error: unknown) => !stale && setLoaded({ state: 'failed', error: error instanceof Error ? error.message : 'The fields could not be loaded.' }));
        return () => { stale = true; };
    }, [active, catalogKey, editorKey, revision]);

    useEffect(() => {
        const returned = () => {
            if (!away.current || document.visibilityState !== 'visible') return;
            away.current = false;
            catalogs.current.clear(); editors.current.clear();
            setLoaded({ state: 'loading' });
            setRevision((was) => was + 1);
            back.current();
        };
        window.addEventListener('focus', returned);
        document.addEventListener('visibilitychange', returned);
        return () => { window.removeEventListener('focus', returned); document.removeEventListener('visibilitychange', returned); };
    }, []);

    const copy = (blade: string, label: string) => {
        setCopyProblem('');
        if (!navigator.clipboard) { setCopyProblem('Select the Blade example and copy it from the page.'); return; }
        navigator.clipboard.writeText(blade).then(() => { setCopied(label); setCopiedBlade(blade); }).catch(() => setCopyProblem('Select the Blade example and copy it from the page.'));
    };
    const open = () => { away.current = true; };
    if (template === null) return <p className="sve-empty">Select a template or an element to see the CMS variables available there.</p>;
    const data = loaded.state === 'ready' && loaded.data.key === key ? loaded.data : null;
    const variables = filterFields(data?.catalog.variables ?? [], search);

    const sources = data === null ? [] : templateFieldSources(template).flatMap((key) => (data.editors.sources[key] ? [{ key, editor: data.editors.sources[key] }] : []));
    const manage = data?.editors.manage ?? [];
    const editing = data !== null && (Boolean(data.editors.entry) || sources.length > 0 || manage.length > 0);
    // One heading for each place a value comes from, in the order the catalog gives them.
    const sets = variables.reduce<{ from: ReferenceVariable['from']; variables: ReferenceVariable[] }[]>((all, variable) => {
        const last = all[all.length - 1];
        if (last !== undefined && last.from === variable.from) last.variables.push(variable);
        else all.push({ from: variable.from, variables: [variable] });
        return all;
    }, []);

    return <section className="sve-fields" aria-label="CMS fields and variables">
        <header className="sve-fields__head">
            <div>
                <h2>Fields &amp; variables</h2>
                <p className="sve-fields__lead">What <code>{template.path}</code> can read{node === null ? '' : ' at the selected layer'}. Open one for Blade to copy, or press <kbd>Ctrl</kbd>+<kbd>Space</kbd> in the code.</p>
            </div>
            <button type="button" className="sve-button sve-button--quiet" onClick={refresh}>Refresh fields</button>
        </header>
        {unsaved && <p className="sve-fields__note sve-fields__note--warn">This follows the saved template. Completions in the code also know variables added in what is not saved yet.</p>}
        {editing && <section className="sve-fields__cms" aria-label="Edit in the CMS">
            <h3>Edit in the CMS</h3>
            {data.editors.entry && <div className="sve-fields__source"><strong>This page: {data.editors.entry.title}</strong><EditorLinks editor={data.editors.entry} onOpen={open} /></div>}
            {sources.map(({ key, editor }) => <div className="sve-fields__source" key={key}><strong>{editor.title}</strong><EditorLinks editor={editor} onOpen={open} /></div>)}
            {manage.length > 0 && <div className="sve-fields__source"><strong>All field definitions</strong><div className="sve-fields__links" aria-label="CMS field definitions">{manage.map((link) => {
                const href = cmsEditorHref(link.url, Sunrice.adminUrl(), window.location.origin);
                return href ? <a key={href} href={href} target="_blank" rel="noreferrer" onClick={open}>{link.label}</a> : null;
            })}</div></div>}
            <p className="sve-fields__hint">They open in a tab of their own; the preview is drawn again when you come back. Entries follow the CMS’s draft and publish, globals and menus save at once.</p>
        </section>}
        <div className="sve-fields__find">
            <label className="sve-fields__search">Find a variable or field<input type="search" value={search} onChange={(event) => setSearch(event.currentTarget.value)} placeholder="Title, cover, $block, menu…" /></label>
        </div>
        <span className="sve-fields__status" role="status">{copied ? `Copied ${copied}.` : ''}</span>
        {copyProblem && <p className="sve-fields__note sve-fields__note--warn" role="alert">{copyProblem}</p>}
        {data === null && loaded.state !== 'failed' && <p className="sve-empty" role="status">Loading CMS fields…</p>}
        {loaded.state === 'failed' && <div role="alert"><p>{loaded.error}</p><button type="button" className="sve-button" onClick={() => { refresh(); onReload(); }}>Reload template and fields</button></div>}
        {data && variables.length === 0 && <p className="sve-empty">No fields match your search.</p>}
        {sets.map((set, index) => <section className="sve-fields__set" key={`${set.from}:${index}`} aria-label={FROM[set.from]}>
            <h3>{FROM[set.from]}</h3>
            {set.variables.map((variable) => <Variable key={variable.code} value={variable} editors={data?.editors.sources ?? {}} searching={search.trim() !== ''} copied={copiedBlade} onCopy={copy} onOpen={open} />)}
        </section>)}
    </section>;
}

/** Where a variable comes from, as a heading over those that come from there. */
const FROM: Record<ReferenceVariable['from'], string> = { page: 'This template', included: 'Passed to this template', loop: 'Inside this loop', set: 'Set above', component: 'Inside this component', site: 'Site-wide' };

function EditorLinks({ editor, onOpen }: { editor: CmsFieldEditor; onOpen(): void }) {
    const links = [...(editor.values ? [{ label: 'Open CMS content ↗', url: editor.values }] : []), ...editor.definitions];
    return <div className="sve-fields__links">{links.map((link) => {
        const href = cmsEditorHref(link.url, Sunrice.adminUrl(), window.location.origin);
        return href === null ? null : <a key={href} href={href} target="_blank" rel="noreferrer" onClick={onOpen}>{link.label}</a>;
    })}</div>;
}
type RowProps = { value: FieldReference; editors: CmsFieldEditors['sources']; searching: boolean; copied: string; onCopy(blade: string, label: string): void; onOpen(): void };
function Variable(props: RowProps & { value: ReferenceVariable }) {
    const match = /^cms_(menu|global)\('([\w-]+)'\)$/.exec(props.value.code);
    const menu = match ? props.editors[match[1] + ':' + match[2]] : undefined;
    // A global's or a menu's own links, in its card.
    return <div className="sve-fields__variable"><FieldRow {...props} footer={menu && <EditorLinks editor={menu} onOpen={props.onOpen} />} /></div>;
}
function FieldRow({ value, editors, searching, copied, onCopy, onOpen, footer }: RowProps & { footer?: ReactNode }) {
    const [expanded, setExpanded] = useState(false);
    const label = fieldLabel(value);
    const show = expanded || searching;
    return <div className="sve-fields__row">
        <button type="button" className="sve-fields__toggle" aria-expanded={show} onClick={() => setExpanded(!show)}><span className="sve-fields__name">{label}</span><small className="sve-fields__what">{value.what}</small><code>{value.code}</code></button>
        {footer && <div className="sve-fields__foot">{footer}</div>}
        {show && <div className="sve-fields__body">
            {'note' in value && value.note && <p className="sve-fields__note">{value.note}</p>}
            {'sure' in value && !value.sure && <p className="sve-fields__note">Inferred from the template; confirm that this variable is passed when the template is rendered.</p>}
            {fieldExamples(value).map((example) => <div className="sve-fields__example" key={example.blade}><div><strong>{example.title}</strong><button type="button" className="sve-button sve-button--quiet" aria-label={`Copy ${label}: ${example.title}`} onClick={() => onCopy(example.blade, label)}>{copied === example.blade ? 'Copied' : 'Copy Blade'}</button></div><pre><code>{example.blade}</code></pre></div>)}
            {value.groups.map((group, index) => <Group key={`${group.title}:${index}`} group={group} editors={editors} searching={searching} copied={copied} onCopy={onCopy} onOpen={onOpen} />)}
            {value.groups.length === 0 && fieldExamples(value).length === 0 && <p className="sve-fields__note">Read as <code>{value.code}</code>. This value has no printable Blade example.</p>}
        </div>}
    </div>;
}
function Group({ group, editors, searching, copied, onCopy, onOpen }: Omit<RowProps, 'value'> & { group: ReferenceGroup }) {
    const editor = group.source ? editors[group.source.subject + ':' + group.source.handle] : undefined;
    return <div className="sve-fields__group"><div className="sve-fields__group-head"><h4>{group.title}</h4>{editor && <EditorLinks editor={editor} onOpen={onOpen} />}</div>{group.fields.map((field) => <FieldRow key={field.code} value={field} editors={editors} searching={searching} copied={copied} onCopy={onCopy} onOpen={onOpen} />)}</div>;
}
