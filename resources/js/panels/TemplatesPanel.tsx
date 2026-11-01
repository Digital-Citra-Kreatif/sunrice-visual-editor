import { useState } from 'react';
import type { ManifestFile } from '../bridge/protocol';
import type { TemplateInfo } from '../lib/api';
import { inPanelOrder, limit, matchesSearch, roleLabel, sameTemplate, shortName, type TemplateName } from '../lib/templates';

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

/** The Templates tab on the left: the templates that drew the page, then the rest. */
export function TemplatesPanel({ files, waiting, all, open, onOpen, onPoint }: Props) {
    const [query, setQuery] = useState('');
    /** Whether the other templates were opened by a click: a search opens them only while it lasts. */
    const [othersOpen, setOthersOpen] = useState(false);
    const searching = query.trim() !== '';
    const others = (all ?? []).filter((template) => !(files ?? []).some((file) => sameTemplate(file, template)));
    const shownFiles = files === null ? null : files.filter((file) => matchesSearch(file, query));
    const shownOthers = others.filter((template) => matchesSearch(template, query));
    const nothing = searching && (shownFiles ?? []).length === 0 && shownOthers.length === 0;

    return (
        <>
            <input
                type="search"
                className="sve-templates__search"
                aria-label="Find a template"
                placeholder="Find a template"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                onKeyDown={(event) => {
                    if (event.key === 'Escape' && query !== '') {
                        event.preventDefault();
                        event.stopPropagation();
                        setQuery('');
                    }
                }}
            />
            {nothing && (
                <p className="sve-empty sve-templates__none" role="status">
                    No templates match your search.
                    <button type="button" className="sve-button sve-button--quiet" onClick={() => setQuery('')}>
                        Clear
                    </button>
                </p>
            )}
            {!nothing && (!searching || (shownFiles ?? []).length > 0) && (
                <>
                    <h3 className="sve-templates__title">On this page</h3>
                    {shownFiles === null ? (
                        <p className="sve-empty">{waiting === 'unknown' ? 'This page was not drawn for the editor, so its templates can’t be listed. It may be an error page, or not one of the site’s own.' : 'Loading the page…'}</p>
                    ) : shownFiles.length === 0 ? (
                        <p className="sve-empty">No template of the site drew this page.</p>
                    ) : (
                        <ul className="sve-templates" onMouseLeave={() => onPoint(null)}>
                            {inPanelOrder(shownFiles).map((file) => (
                                <li key={file.key}>
                                    <Row template={file} current={sameTemplate(open, file)} onOpen={onOpen} onPoint={() => onPoint(file.key)} />
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            )}

            {shownOthers.length > 0 && (
                // Open while a search is typed: what it found is not hidden behind a click.
                <details className="sve-others" open={searching || othersOpen} onToggle={(event) => !searching && setOthersOpen(event.currentTarget.open)}>
                    {/* Kept open while searching, so not a stop for Tab: from the field, Tab goes to the first template found. */}
                    <summary tabIndex={searching ? -1 : undefined}>{searching ? `Other templates (${shownOthers.length} of ${others.length})` : `Other templates (${others.length})`}</summary>
                    <ul className="sve-templates">
                        {inPanelOrder(shownOthers).map((template) => (
                            <li key={`${template.origin}:${template.path}`}>
                                <Row template={template} current={sameTemplate(open, template)} onOpen={onOpen} />
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </>
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
