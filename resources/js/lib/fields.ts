import type { ReferenceGroup, FieldInsert, ReferenceField, ReferenceVariable, TemplateData } from './api';

export type FieldReference = ReferenceField | ReferenceVariable;
export const fieldLabel = (value: FieldReference) => 'title' in value ? value.title : value.label;
const matches = (value: FieldReference, query: string) => [fieldLabel(value), value.code, value.what, ...value.of, ...('note' in value && value.note ? [value.note] : [])].join(' ').toLowerCase().includes(query);

/** Keep the parent context of matching fields, including nested groups and rows. */
export function filterFields(variables: ReferenceVariable[], search: string): ReferenceVariable[] {
    const query = search.trim().toLowerCase();
    if (query === '') return variables;
    const groups = (all: ReferenceGroup[]): ReferenceGroup[] => all.flatMap((group) => {
        if ([group.title, group.source?.subject, group.source?.handle].filter(Boolean).join(' ').toLowerCase().includes(query)) return [group];
        const fields = group.fields.flatMap((field) => {
            if (matches(field, query)) return [field];
            const children = groups(field.groups);
            return children.length ? [{ ...field, groups: children }] : [];
        });
        return fields.length ? [{ ...group, fields }] : [];
    });

    return variables.flatMap((variable) => {
        if (matches(variable, query)) return [variable];
        const children = groups(variable.groups);
        return children.length ? [{ ...variable, groups: children }] : [];
    });
}

export function fieldExamples(value: FieldReference): FieldInsert[] {
    const examples = value.inserts.length ? value.inserts : value.print ? [{ title: 'Print value', kind: 'print' as const, blade: value.print }] : [];
    return examples.filter((example, index) => examples.findIndex((other) => other.blade === example.blade) === index);
}

/** Only links within this panel are opened from a CMS editor action. */
export function cmsEditorHref(href: string | null, admin: string, origin: string): string | null {
    if (!href || /[\\\x00-\x1f\x7f]/.test(href)) return null;
    try {
        const url = new URL(href, origin);
        const prefix = '/' + admin.replace(/^\/+|\/+$/g, '');
        return url.origin === origin && (prefix === '/' || url.pathname.startsWith(prefix + '/')) ? url.pathname + url.search + url.hash : null;
    } catch {
        return null;
    }
}

/** Source editors remain reachable even before a blueprint has any fields. */
export function templateFieldSources(template: TemplateData): string[] {
    const sources = (template.usedBy ?? []).map((use) => `${use.role === 'listing' ? 'listing' : use.role === 'index' ? 'index' : use.subject}:${use.handle}`);
    if (template.role === 'block') {
        const handle = template.path.split('/').pop()?.replace(/\.blade\.php$/, '');
        if (handle) sources.push('fieldset:' + handle);
    }
    return [...new Set(sources)];
}
