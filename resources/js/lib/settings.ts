/**
 * Reading a node for the Settings panel, and writing what was typed there
 * back the way Blade wants it.
 */
import type { TreeNode } from './api';

/** An attribute of an opening tag, as the tree describes it. */
export type Attribute = {
    name: string;
    /** As written between the quotes; null for a bare name (`disabled`). */
    value: string | null;
    /** The value, or the name, is built by Blade. */
    dynamic: boolean;
    /** A directive or echo standing in the tag on its own: `@class([…])`, `{{ $attributes }}`. */
    code: boolean;
    source: string;
};

export function attributesOf(node: TreeNode): Attribute[] {
    return Array.isArray(node.attributes) ? (node.attributes as Attribute[]) : [];
}

/** An attribute written as one, by name; null when there is none or Blade produces it. */
export function attribute(node: TreeNode, name: string): Attribute | null {
    return attributesOf(node).find((each) => !each.code && each.name === name) ?? null;
}

/**
 * The classes of an element as separate words, when the class attribute
 * is plain text. Null when Blade has a hand in it: then it is edited as
 * written, like any other attribute.
 */
export function classesOf(node: TreeNode): string[] | null {
    const list = attribute(node, 'class');
    if (list === null) {
        return attributesOf(node).some((each) => each.code && each.name.toLowerCase() === '@class') ? null : [];
    }
    if (list.dynamic || list.value === null) {
        return list.dynamic ? null : [];
    }

    return list.value.split(/\s+/).filter((word) => word !== '');
}

/**
 * A node's own text in the file. The tree counts bytes and JavaScript
 * counts UTF-16 units, so the file is cut as bytes.
 */
export function sourceOf(source: string, node: Pick<TreeNode, 'start' | 'end'>): string {
    const bytes = new TextEncoder().encode(source);

    return new TextDecoder().decode(bytes.slice(node.start, node.end));
}

/** "$entries as $entry" → what is looped over and what each one is called; null when it doesn't read that way. */
export function splitLoop(args: string | null): { source: string; item: string } | null {
    // The last " as ": what is looped over may itself contain one.
    const match = /^([\s\S]+)\s+as\s+([\s\S]+?)$/.exec((args ?? '').trim());

    return match === null ? null : { source: match[1].trim(), item: match[2].trim() };
}

export function joinLoop(source: string, item: string): string {
    return `${source.trim()} as ${item.trim()}`;
}

/** "'sunrice.partials.card', ['entry' => $entry]" → the view and the data passed to it; null when the view is no plain name. */
export function splitInclude(args: string | null): { view: string; data: string } | null {
    const match = /^(['"])([^'"\\]+)\1\s*(?:,\s*([\s\S]*))?$/.exec((args ?? '').trim());

    return match === null ? null : { view: match[2], data: (match[3] ?? '').trim() };
}

export function joinInclude(view: string, data: string): string {
    const name = `'${view.trim().replace(/['\\]/g, '')}'`;

    return data.trim() === '' ? name : `${name}, ${data.trim()}`;
}

/** The arm of a block that carries the block's own condition or loop: its first. */
export function headOf(node: TreeNode): TreeNode | null {
    if (node.type === 'branch') {
        return node;
    }
    const first = node.type === 'block' ? node.children?.[0] : undefined;

    return first !== undefined && first.type === 'branch' ? first : null;
}

/** What is between a directive's brackets; null when it has none. */
export function argumentsOf(node: TreeNode | null): string | null {
    return node !== null && typeof node.arguments === 'string' ? node.arguments : null;
}
