import type { ManifestFile } from '../bridge/protocol';
import type { TemplateInfo } from '../lib/api';
import { inPanelOrder, limit, roleLabel, sameTemplate, shortName, type TemplateName } from '../lib/templates';
import { Panel } from './Panel';

type Props = {
    /** The templates that drew the page in the frame; null while there is none to speak of. */
    files: ManifestFile[] | null;
    /** Why there is no list, when there isn't. */
    waiting: 'loading' | 'unknown' | null;
    /** Every template of the site and of Sunrice; null until they are loaded. */
    all: TemplateInfo[] | null;
    open: TemplateName | null;
    onOpen(template: TemplateName): void;
    /** The pointer went over a template's row, or left it. */
    onPoint(key: string | null): void;
};

/** The left panel: the templates that drew the page, then the rest. */
export function TemplatesPanel({ files, waiting, all, open, onOpen, onPoint }: Props) {
    const others = (all ?? []).filter((template) => !(files ?? []).some((file) => sameTemplate(file, template)));

    return (
        <Panel title="Templates on this page" side="left">
            {files === null ? (
                <p className="sve-empty">{waiting === 'unknown' ? 'This page was not drawn for the editor, so its templates can’t be listed. It may be an error page, or not one of the site’s own.' : 'Loading the page…'}</p>
            ) : files.length === 0 ? (
                <p className="sve-empty">No template of the site drew this page.</p>
            ) : (
                <ul className="sve-templates" onMouseLeave={() => onPoint(null)}>
                    {inPanelOrder(files).map((file) => (
                        <li key={file.key}>
                            <Row template={file} current={sameTemplate(open, file)} onOpen={onOpen} onPoint={() => onPoint(file.key)} />
                        </li>
                    ))}
                </ul>
            )}

            {others.length > 0 && (
                <details className="sve-others">
                    <summary>Other templates ({others.length})</summary>
                    <ul className="sve-templates">
                        {inPanelOrder(others).map((template) => (
                            <li key={`${template.origin}:${template.path}`}>
                                <Row template={template} current={sameTemplate(open, template)} onOpen={onOpen} />
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </Panel>
    );
}

type RowProps = {
    template: TemplateName & { role: string; opens: boolean };
    current: boolean;
    onOpen(template: TemplateName): void;
    onPoint?(): void;
};

function Row({ template, current, onOpen, onPoint }: RowProps) {
    const note = limit(template);

    return (
        <button
            type="button"
            className="sve-template"
            aria-current={current ? 'true' : undefined}
            title={template.path}
            onClick={() => onOpen({ path: template.path, origin: template.origin })}
            onMouseEnter={onPoint}
            onFocus={onPoint}
        >
            <span className="sve-template__name">{shortName(template.path)}</span>
            <span className="sve-template__role">{roleLabel(template.role)}</span>
            {note !== null && <span className="sve-tag">{note}</span>}
        </button>
    );
}
