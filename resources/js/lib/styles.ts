/**
 * The CSS panel's side of the theme's stylesheets: what was typed there
 * turned into changes the server makes (Css\Changes in PHP).
 */
import type { ManifestFile } from '../bridge/protocol';
import type { CssChange, StyleRule, Styles, ThemeFile } from './api';
import { sameMedia } from './media.ts';
import { declaredAt, targetRule } from './styleFields.ts';

/**
 * Something done in the panel, said by where it is in the list the panel
 * shows: the n-th rule, its n-th declaration. The places in the file are
 * looked up when the change is sent, since the change before it may have
 * moved them.
 */
export type StyleEdit =
    /** A declaration's property as it now reads. Emptied, the declaration goes. */
    | { kind: 'property'; rule: number; index: number; property: string }
    /** A declaration's value as it now reads. Emptied, the declaration goes. */
    | { kind: 'value'; rule: number; index: number; value: string }
    | { kind: 'remove'; rule: number; index: number }
    /** What was typed into the empty row of a rule: one declaration, or several as CSS writes them. */
    | { kind: 'add'; rule: number; property: string; value: string }
    /** A rule for the class itself, which has none yet: into the editor's own stylesheet. */
    | { kind: 'create'; property: string; value: string }
    /** A control of the Style tab as it now reads: what the class's own rule should say for a property. Emptied, it says nothing. */
    | { kind: 'style'; property: string; value: string; media?: string | null };

/**
 * "color: red; margin: 0" → the declarations it holds, as typed or pasted
 * into one field. A ";" or a ":" in a quoted text or between brackets is
 * part of a value.
 */
export function declarationsIn(text: string): [string, string][] {
    const found: [string, string][] = [];
    let depth = 0;
    let quote: string | null = null;
    let piece = '';
    const close = () => {
        const colon = colonIn(piece);
        const property = (colon === -1 ? piece : piece.slice(0, colon)).trim();
        const value = colon === -1 ? '' : piece.slice(colon + 1).trim();
        if (property !== '' || value !== '') {
            found.push([property, value]);
        }
        piece = '';
    };
    for (let at = 0; at < text.length; at++) {
        const char = text[at];
        if (quote !== null) {
            if (char === '\\') {
                piece += char + (text[++at] ?? '');

                continue;
            }
            quote = char === quote ? null : quote;
        } else if (char === '"' || char === "'") {
            quote = char;
        } else if (char === '(' || char === '[' || char === '{') {
            depth++;
        } else if (char === ')' || char === ']' || char === '}') {
            depth = Math.max(0, depth - 1);
        } else if (char === ';' && depth === 0) {
            close();

            continue;
        }
        piece += char;
    }
    close();

    return found;
}

/** Where the ":" between a property and its value is; -1 when there is none. */
function colonIn(piece: string): number {
    // The first one: a property's name holds none, and a value may (a time, an address).
    return piece.indexOf(':');
}

/**
 * What was typed into an empty row, as declarations: a property and a
 * value, or everything in the property's field when declarations were
 * pasted there ("color: red; margin: 0").
 */
export function typedDeclarations(property: string, value: string): [string, string][] {
    if (value.trim() === '' && property.includes(':')) {
        return declarationsIn(property).filter(([name, its]) => name !== '' && its !== '');
    }

    return property.trim() === '' || value.trim() === '' ? [] : [[property.trim(), value.trim().replace(/;\s*$/, '')]];
}

/** The stylesheet a rule is in, as the listing has it. */
export function fileOf(styles: Styles, rule: StyleRule): ThemeFile | null {
    return styles.files.find((file) => file.path === rule.file) ?? null;
}

/**
 * The changes an edit comes to, and the file they are for, as the
 * stylesheets are now. Null when there is nothing to change: the row
 * reads as the file does, or what it was about is no longer there.
 */
export function resolve(styles: Styles, edit: StyleEdit): { file: ThemeFile; changes: CssChange[] } | null {
    if (edit.kind === 'create') {
        const declarations = typedDeclarations(edit.property, edit.value);
        if (styles.own === null || styles.selector === null) {
            return null;
        }

        return { file: styles.own, changes: [{ type: 'rule', selector: styles.selector, ...(declarations.length > 0 ? { declarations } : {}) }] };
    }

    if (edit.kind === 'style') {
        const media = edit.media ?? null;
        const at = targetRule(styles, media);
        const value = edit.value.trim().replace(/;\s*$/, '');
        const target = at === null ? null : styles.rules[at];
        if (target === null || at === null) {
            // No rule yet: the first thing said makes it.
            return value === '' ? null : media === null ? resolve(styles, { kind: 'create', property: edit.property, value }) : createFor(styles, media, edit.property, value);
        }
        const index = declaredAt(target, edit.property);
        if (index === -1) {
            return value === '' ? null : resolve(styles, { kind: 'add', rule: at, property: edit.property, value });
        }

        return resolve(styles, { kind: 'value', rule: at, index, value });
    }

    const rule = styles.rules[edit.rule];
    const file = rule === undefined ? null : fileOf(styles, rule);
    if (rule === undefined || file === null) {
        return null;
    }
    if (edit.kind === 'add') {
        const changes: CssChange[] = typedDeclarations(edit.property, edit.value).map(([property, value]) => ({ type: 'add', rule: rule.id, property, value }));

        return changes.length === 0 ? null : { file, changes };
    }

    const declaration = rule.declarations[edit.index];
    if (declaration === undefined) {
        return null;
    }
    const typed = edit.kind === 'property' ? edit.property.trim() : edit.kind === 'value' ? edit.value.trim().replace(/;\s*$/, '') : '';
    if (typed === '') {
        return { file, changes: [{ type: 'remove', declaration: declaration.id }] };
    }
    if (edit.kind === 'property') {
        return typed === declaration.property ? null : { file, changes: [{ type: 'rename', declaration: declaration.id, property: typed }] };
    }

    return typed === declaration.value ? null : { file, changes: [{ type: 'set', declaration: declaration.id, value: typed }] };
}

/**
 * A new rule for the class at one width. It goes into the stylesheet the
 * class's own rule is in, after that rule, so that it counts over it: at
 * the end of a block for that width when one comes later in the file,
 * else in a new block at the file's end. A class without a rule of its
 * own gets it in the editor's stylesheet.
 */
function createFor(styles: Styles, media: string, property: string, value: string): { file: ThemeFile; changes: CssChange[] } | null {
    const base = targetRule(styles);
    const baseRule = base === null ? null : styles.rules[base];
    const file = baseRule === null ? styles.own : fileOf(styles, baseRule);
    if (file === null || styles.selector === null) {
        return null;
    }
    const declarations: [string, string][] = [[property, value]];
    const block = [...styles.media].reverse().find((each) => each.file === file.path && sameMedia(each.prelude, media) && (baseRule === null || each.id > baseRule.id));

    return { file, changes: [block === undefined ? { type: 'rule', selector: styles.selector, declarations, media } : { type: 'rule', selector: styles.selector, declarations, in: block.id }] };
}

/** The rules as the panel lists them: by file, each with its place in the whole list. */
export function byFile(styles: Styles): { file: ThemeFile; rules: { rule: StyleRule; at: number }[] }[] {
    const groups: { file: ThemeFile; rules: { rule: StyleRule; at: number }[] }[] = [];
    styles.rules.forEach((rule, at) => {
        const file = fileOf(styles, rule);
        if (file === null) {
            return;
        }
        let group = groups.find((each) => each.file.path === file.path);
        if (group === undefined) {
            group = { file, rules: [] };
            groups.push(group);
        }
        group.rules.push({ rule, at });
    });

    return groups;
}

/** Whether the class has a rule of its own: one whose selector is the class itself, outside any @media. */
export function hasOwnRule(styles: Styles): boolean {
    return styles.rules.some((rule) => rule.exact && rule.within.length === 0);
}

/** The stylesheets a page can be told of: those that are there. */
export function sheetsOf(styles: Styles | null): { url: string; version: string }[] {
    return (styles?.files ?? []).filter((file) => file.exists).map((file) => ({ url: file.url, version: file.version }));
}

/**
 * The layout a stylesheet can be linked from: the page's own, when it is
 * one of the site's templates and opens as a tree.
 */
export function layoutOf(files: ManifestFile[] | null): ManifestFile | null {
    return (files ?? []).find((file) => file.role === 'layout' && file.origin === 'site' && file.editable && file.opens) ?? null;
}

/**
 * A node's id in a template after text was put into the template before
 * it: ids are byte offsets, so every node after the place moves by what
 * was put in. `before` and `after` are the template's text then and now.
 */
export function shiftedId(id: string | null, before: string, after: string): string | null {
    if (id === null || !/^\d+$/.test(id)) {
        return id;
    }
    const was = new TextEncoder().encode(before);
    const now = new TextEncoder().encode(after);
    let same = 0;
    while (same < was.length && same < now.length && was[same] === now[same]) {
        same++;
    }

    return Number(id) >= same ? String(Number(id) + now.length - was.length) : id;
}
