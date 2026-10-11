/**
 * The editor's API (TemplateController): templates listed, read, changed
 * and copied. A refusal comes back as an ApiError with the server's reason.
 */
import type { ManifestFile } from '../bridge/protocol';

/** A template as the list has it. */
export type TemplateInfo = Omit<ManifestFile, 'key'> & {
    reason: string | null;
    strays: number;
};

/** A node of a template's tree (TreeData on the server). */
export type TreeNode = {
    id: string;
    type: 'root' | 'element' | 'block' | 'branch' | 'text' | 'atom' | 'comment' | 'doctype';
    kind: string | null;
    name: string;
    label: string;
    start: number;
    end: number;
    can: { move: boolean; delete: boolean; wrap: boolean; insert: boolean; attributes: boolean };
    children?: TreeNode[];
    [more: string]: unknown;
};

/** A template read in full. */
export type TemplateData = TemplateInfo & {
    /** Null for a file that isn't UTF-8 text. */
    source: string | null;
    version: string;
    /** Null when the template only opens as code. */
    tree: TreeNode | null;
};

/** One edit, as the server reads it (Operation in PHP): what to do, to which node, with what. */
export type Operation =
    | { type: 'setAttribute'; node: string; name: string; value: string | null; blade?: boolean }
    | { type: 'removeAttribute'; node: string; name: string }
    | { type: 'addClass' | 'removeClass'; node: string; class: string }
    | { type: 'setId'; node: string; id: string }
    | { type: 'setTag'; node: string; tag: string }
    | { type: 'setText'; node: string; text: string }
    | { type: 'setExpression'; node: string; expression: string }
    | { type: 'setEchoRaw'; node: string; raw: boolean }
    | { type: 'setArguments'; node: string; arguments: string | null }
    | { type: 'setCode'; node: string; code: string }
    | { type: 'insertBefore' | 'insertAfter' | 'append' | 'prepend'; node: string; blade: string }
    | { type: 'remove' | 'duplicate' | 'unwrap'; node: string }
    | { type: 'move'; node: string; target: string; position: 'before' | 'after' | 'inside' }
    | { type: 'wrap'; node: string; wrapper: string };

/** Something the editor offers to insert (Library on the server): a piece of Blade that stands on its own. */
export type Insertable = {
    id: string;
    /** Elements, Blade, Sunrice or Partials. */
    group: string;
    title: string;
    /** What it writes, in a few characters: "<section>", "@foreach", or what a partial needs. */
    hint: string;
    blade: string;
    /** More words to find it by. */
    keywords: string;
};

/** One way of putting a value into a template (Insert on the server). */
export type FieldInsert = { title: string; kind: 'print' | 'element' | 'structure'; blade: string };

/** Some of what can be read from a value, under one heading: "Every entry", "Articles". */
export type FieldGroup = { title: string; fields: PickField[] };

/** What the picker says of any value (Picker on the server). */
type Pickable = {
    /** The PHP that reads it: "$entry->get('cover')?->alt". */
    code: string;
    /** What it is, in a word or two: "text", "file", "list of entries". */
    what: string;
    kind: string;
    /** The collections, taxonomies or fieldsets it is of, by title. */
    of: string[];
    /** The ways it can be inserted, the usual one first. None for what is only read into. */
    inserts: FieldInsert[];
    /** As Blade where a text goes: "{{ … }}"; null for what is no text. */
    print: string | null;
    /** The same without the braces, for an echo or an attribute. */
    expression: string | null;
    /** As a condition that there is anything: "$entry->get('cover')", "$entry->terms->isNotEmpty()". */
    asked: string;
    /** As it is written to be compared with another value. */
    compared: string;
    /** As it is written to be gone over in a loop; null for what is no list. */
    looped: string | null;
    /** What can be read from it. */
    groups: FieldGroup[];
};

export type PickField = Pickable & {
    handle: string;
    label: string;
    /** "Not in Pages": a field only some of the collections have. */
    note: string | null;
};

/** Something a template can read at a place: "$entry", a loop's item, a global set. */
export type PickVariable = Pickable & {
    title: string;
    from: 'page' | 'included' | 'loop' | 'set' | 'component' | 'site';
    sure: boolean;
};

export type Refusal = 'forbidden' | 'not_found' | 'conflict' | 'rejected' | 'syntax' | 'invalid' | 'failed';

export class ApiError extends Error {
    /** Why, in a word the screen can act on. "failed" when the server didn't say. */
    readonly reason: Refusal;

    readonly status: number;

    /** With "conflict": the version the file has now. */
    readonly version: string | null;

    /** With "rejected": which operation of the list, counted from 0. */
    readonly operation: number | null;

    constructor(message: string, reason: Refusal, status: number, version: string | null = null, operation: number | null = null) {
        super(message);
        this.name = 'ApiError';
        this.reason = reason;
        this.status = status;
        this.version = version;
        this.operation = operation;
    }
}

function root(): string {
    return Sunrice.adminUrl('visual-editor/api');
}

/** "partials/header.blade.php" as part of an address: slashes kept, the rest escaped. */
export function pathInAddress(path: string): string {
    return path.split('/').map(encodeURIComponent).join('/');
}

function xsrfToken(): string {
    return decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

/** Turns an answer that isn't a success into the error the caller catches. */
export async function refusal(response: { status: number; json(): Promise<unknown> }): Promise<ApiError> {
    const body = (await response.json().catch(() => null)) as { reason?: unknown; message?: unknown; version?: unknown; operation?: unknown } | null;
    const known: Refusal[] = ['forbidden', 'not_found', 'conflict', 'rejected', 'syntax', 'invalid'];
    const reason = known.find((candidate) => candidate === body?.reason) ?? 'failed';
    const message =
        typeof body?.message === 'string' && body.message !== ''
            ? body.message
            : response.status === 401 || response.status === 419
              ? 'Your session has ended. Reload the page and sign in again.'
              : `The server answered ${response.status}.`;

    return new ApiError(message, reason, response.status, typeof body?.version === 'string' ? body.version : null, typeof body?.operation === 'number' ? body.operation : null);
}

async function request<T>(method: string, address: string, body?: unknown): Promise<T> {
    let response: Response;
    try {
        response = await fetch(`${root()}/${address}`, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(body === undefined ? {} : { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() }),
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
    } catch {
        throw new ApiError('The server could not be reached.', 'failed', 0);
    }
    if (!response.ok) {
        throw await refusal(response);
    }

    return (await response.json()) as T;
}

export function listTemplates(): Promise<TemplateInfo[]> {
    return request<{ templates: TemplateInfo[] }>('GET', 'templates').then((answer) => answer.templates);
}

/** What can be inserted into a template of this site. */
export function listInserts(): Promise<Insertable[]> {
    return request<{ inserts: Insertable[] }>('GET', 'inserts').then((answer) => answer.inserts);
}

export function readTemplate(path: string, origin: 'site' | 'package'): Promise<TemplateData> {
    return request<{ template: TemplateData }>('GET', `templates/${pathInAddress(path)}${origin === 'package' ? '?origin=package' : ''}`).then((answer) => answer.template);
}

/** "Copy to site": the new template of the site. */
export function copyToSite(path: string): Promise<TemplateData> {
    return request<{ template: TemplateData }>('POST', `templates/${pathInAddress(path)}/copy`, {}).then((answer) => answer.template);
}

/** Saves a template's source as written in the code panel: the template as it is then. */
export function saveSource(path: string, version: string, source: string): Promise<TemplateData> {
    return request<{ template: TemplateData }>('PUT', `templates/${pathInAddress(path)}/source`, { version, source }).then((answer) => answer.template);
}

/**
 * Applies operations to a template and saves it. Answers with the template
 * as it is then, and the id in it of the node to select: what the last
 * operation made or changed.
 */
export function applyOperations(path: string, version: string, operations: Operation[]): Promise<{ template: TemplateData; focus: string | null }> {
    return request<{ template: TemplateData; focus: string | null }>('POST', `templates/${pathInAddress(path)}/operations`, { version, operations });
}

/**
 * What can be read at a place in a template: at a node, or for something
 * going into it; with no node, for the template as a whole.
 */
export function listFields(path: string, version: string, node: string | null, inside: boolean): Promise<PickVariable[]> {
    const query = new URLSearchParams({ version, ...(node === null ? {} : { node }), ...(inside ? { inside: '1' } : {}) });

    return request<{ variables: PickVariable[] }>('GET', `templates/${pathInAddress(path)}/fields?${query.toString()}`).then((answer) => answer.variables);
}

/** A collection of the site, with the fields its entries have. */
export type SiteCollection = { handle: string; title: string; single: boolean; fields: { handle: string; label: string; what: string; kind: string }[] };

/** What the site's content is made of, for the controls that choose from it. */
export type Site = { collections: SiteCollection[]; taxonomies: { handle: string; title: string; archive: boolean }[] };

export function readSite(): Promise<Site> {
    return request<Site>('GET', 'site');
}

// --- The theme's stylesheets -----------------------------------------------------

/** A CSS file of the theme. `exists` is false for the editor's own before its first rule. */
export type ThemeFile = { path: string; url: string; version: string; exists: boolean; reason?: string | null };

/** One `property: value` of a rule; `id` is where it starts in the file. */
export type StyleDeclaration = { id: number; property: string; value: string };

/** A rule that styles a class, or mentions it. */
export type StyleRule = {
    file: string;
    /** Where it starts in the file. */
    id: number;
    selector: string;
    /** The at-rules it is inside, outermost first: "@media (max-width: 760px)". */
    within: string[];
    /** One of its selectors is the class itself; otherwise it only mentions it (".card h2"). */
    exact: boolean;
    line: number;
    declarations: StyleDeclaration[];
};

/** What the theme's stylesheets say about a class. */
export type Styles = {
    class: string | null;
    /** The class as CSS writes it: ".card", ".sm\:flex". */
    selector: string | null;
    files: ThemeFile[];
    rules: StyleRule[];
    /** How many more rules there are than are listed. */
    more: number;
    /** The @media blocks at the top of each stylesheet: `id` is where one starts in its file. */
    media: { file: string; id: number; prelude: string }[];
    /** Where a rule that does not exist yet is put; null when the site names no such file. */
    own: ThemeFile | null;
};

/** One change to a stylesheet, as the server reads it (Css\Changes in PHP). */
export type CssChange =
    | { type: 'set'; declaration: number; value: string }
    | { type: 'rename'; declaration: number; property: string }
    | { type: 'remove'; declaration: number }
    | { type: 'add'; rule: number; property: string; value: string }
    /** A new rule: at the end of the file, at the end of the block that starts at `in`, or in a new `media` block at the end of the file. */
    | { type: 'rule'; selector: string; declarations?: [string, string][]; in?: number; media?: string };

/** A stylesheet after it was written: its version, the file as it was before (null: too large to keep), and what the stylesheets say now. */
export type StylesSaved = { file: { path: string; version: string }; before: string | null; styles: Styles };

export function readStyles(ofClass: string | null): Promise<Styles> {
    return request<{ styles: Styles }>('GET', `styles${ofClass === null ? '' : `?${new URLSearchParams({ class: ofClass }).toString()}`}`).then((answer) => answer.styles);
}

/** Changes rules of a stylesheet and saves it. */
export function changeStyles(path: string, version: string, changes: CssChange[], ofClass: string | null): Promise<StylesSaved> {
    return request<StylesSaved>('POST', `styles/files/${pathInAddress(path)}/changes`, { version, changes, ...(ofClass === null ? {} : { class: ofClass }) });
}

/** Saves a stylesheet's whole text: how a change to it is undone, and done again. */
export function saveStylesheet(path: string, version: string, source: string, ofClass: string | null): Promise<StylesSaved> {
    return request<StylesSaved>('PUT', `styles/files/${pathInAddress(path)}/source`, { version, source, ...(ofClass === null ? {} : { class: ofClass }) });
}

/** Links a stylesheet of the theme from a layout: the layout as it is then, and its text before. */
export function linkStylesheet(template: string, version: string, stylesheet: string): Promise<{ template: TemplateData; before: string }> {
    return request<{ template: TemplateData; before: string }>('POST', 'styles/link', { template, version, stylesheet });
}
