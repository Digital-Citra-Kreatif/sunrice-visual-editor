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
