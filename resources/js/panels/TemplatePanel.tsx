import type { ManifestFile } from '../bridge/protocol';
import type { TemplateData } from '../lib/api';
import { roleLabel, sameTemplate, shortName, type TemplateName } from '../lib/templates';
import { Panel } from './Panel';

export type Loaded<T> = { state: 'idle' } | { state: 'loading' } | { state: 'ready'; data: T } | { state: 'failed'; error: string };

type Props = {
    open: TemplateName | null;
    template: Loaded<TemplateData>;
    /** The page's own template, which the trail starts from. */
    page: ManifestFile | null;
    /** Whether the open template drew part of the page in the frame. */
    onPage: boolean;
    /** A page the open template was last seen on, when it isn't on this one. */
    seenOn: string | null;
    copying: boolean;
    /** Whether the code panel is showing. */
    coding: boolean;
    /** Whether the code panel holds changes that are not saved. */
    unsaved: boolean;
    onCode(): void;
    onOpen(template: TemplateName): void;
    onShowPage(path: string): void;
    onCopy(path: string): void;
};

/** The right panel: the template that is open. */
export function TemplatePanel({ open, template, page, onPage, seenOn, copying, coding, unsaved, onCode, onOpen, onShowPage, onCopy }: Props) {
    return (
        <Panel title="Template" side="right">
            {open === null ? (
                <p className="sve-empty">No template is open. Click a part of the page, or a template in the list.</p>
            ) : (
                <>
                    <nav className="sve-trail" aria-label="Open template">
                        {page !== null && !sameTemplate(page, open) && (
                            <>
                                <button type="button" className="sve-trail__back" onClick={() => onOpen({ path: page.path, origin: page.origin })} title={`Back to ${page.path}`}>
                                    <span aria-hidden="true">←</span> {shortName(page.path)}
                                </button>
                                <span className="sve-trail__sep" aria-hidden="true">
                                    /
                                </span>
                            </>
                        )}
                        <strong className="sve-trail__here">{shortName(open.path)}</strong>
                    </nav>

                    {template.state === 'loading' && <p className="sve-empty">Opening…</p>}
                    {template.state === 'failed' && (
                        <p className="sve-note sve-note--problem" role="alert">
                            {template.error}
                        </p>
                    )}
                    {template.state === 'ready' && (
                        <>
                            <About template={template.data} onPage={onPage} seenOn={seenOn} copying={copying} onShowPage={onShowPage} onCopy={onCopy} />
                            <button type="button" className="sve-button sve-button--quiet sve-button--wide" aria-pressed={coding} onClick={onCode}>
                                {coding ? 'Hide the code' : template.data.editable ? 'Edit as code' : 'View the code'}
                                {unsaved && <span className="sve-dot" title="Changes not saved" />}
                            </button>
                        </>
                    )}
                </>
            )}
        </Panel>
    );
}

type AboutProps = Pick<Props, 'onPage' | 'seenOn' | 'copying' | 'onShowPage' | 'onCopy'> & { template: TemplateData };

function About({ template, onPage, seenOn, copying, onShowPage, onCopy }: AboutProps) {
    return (
        <>
            <dl className="sve-facts">
                <dt>File</dt>
                <dd>
                    <code>{template.path}</code>
                </dd>
                <dt>Kind</dt>
                <dd>{roleLabel(template.role)}</dd>
                {template.usedBy.length > 0 && (
                    <>
                        <dt>Draws</dt>
                        <dd>{template.usedBy.map((use) => `${use.handle} (${use.role})`).join(', ')}</dd>
                    </>
                )}
            </dl>

            {!onPage && (
                <div className="sve-note">
                    <p>This template is not part of the page shown.</p>
                    {seenOn !== null && (
                        <button type="button" className="sve-button sve-button--quiet" onClick={() => onShowPage(seenOn)}>
                            Show {seenOn}
                        </button>
                    )}
                </div>
            )}

            {template.origin === 'package' && (
                <div className="sve-note">
                    <p>This is one of Sunrice’s own views. It is shown as it is and can’t be changed here.</p>
                    {template.copyTo !== null ? (
                        <>
                            <p>
                                A copy in the site, at <code>{template.copyTo}</code>, is used in its place and can be edited.
                            </p>
                            <button type="button" className="sve-button" disabled={copying} onClick={() => onCopy(template.path)}>
                                {copying ? 'Copying…' : 'Copy to site'}
                            </button>
                        </>
                    ) : (
                        <p>Sunrice looks for it nowhere in the site, so a copy would not be used.</p>
                    )}
                </div>
            )}

            {template.origin === 'site' && !template.opens && (
                <div className="sve-note">
                    <p>This template can only be edited as code.</p>
                    {template.reason !== null && <p className="sve-note__reason">{template.reason}</p>}
                </div>
            )}

            {template.strays > 0 && (
                <p className="sve-note">
                    {template.strays === 1 ? 'One tag here has' : `${template.strays} tags here have`} its other half somewhere else. {template.strays === 1 ? 'It stays' : 'They stay'} as written; the rest
                    is edited as usual.
                </p>
            )}
        </>
    );
}
