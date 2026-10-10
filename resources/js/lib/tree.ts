/**
 * Reading a template's tree (TreeData on the server) for the Layers panel
 * and for showing a node on the page.
 */
import type { TreeNode } from './api';

/** A row of the Layers panel: a node, how deep it sits, and whether it has rows under it. */
export type Row = { node: TreeNode; depth: number; parent: boolean };

export function findNode(tree: TreeNode | null, id: string | null): TreeNode | null {
    if (tree === null || id === null) {
        return null;
    }
    if (tree.id === id) {
        return tree;
    }
    for (const child of tree.children ?? []) {
        const found = findNode(child, id);
        if (found !== null) {
            return found;
        }
    }

    return null;
}

/** The nodes from the root down to a node, itself last; empty when it isn't in the tree. */
export function pathTo(tree: TreeNode | null, id: string | null): TreeNode[] {
    if (tree === null || id === null) {
        return [];
    }
    if (tree.id === id) {
        return [tree];
    }
    for (const child of tree.children ?? []) {
        const below = pathTo(child, id);
        if (below.length > 0) {
            return [tree, ...below];
        }
    }

    return [];
}

/**
 * What the panel lists under a node. A block with one arm (a loop, a lone
 * @if) shows what the arm holds directly: the arm would only repeat the
 * block's own line. The part of a @switch before its first @case holds
 * nothing and is left out.
 */
export function shownChildren(node: TreeNode): TreeNode[] {
    const children = node.children ?? [];
    if (node.type !== 'block') {
        return children;
    }
    const arms = children.filter((arm) => !(node.name === 'switch' && arm.name === 'switch'));

    return arms.length === 1 && arms[0].type === 'branch' ? (arms[0].children ?? []) : arms;
}

/**
 * The rows of the panel, top to bottom, leaving out what is under a
 * collapsed node. The root itself is not a row.
 */
export function rows(tree: TreeNode | null, collapsed: ReadonlySet<string> = new Set()): Row[] {
    const list: Row[] = [];
    const walk = (nodes: TreeNode[], depth: number) => {
        for (const node of nodes) {
            const below = shownChildren(node);
            list.push({ node, depth, parent: below.length > 0 });
            if (below.length > 0 && !collapsed.has(node.id)) {
                walk(below, depth + 1);
            }
        }
    };
    if (tree !== null) {
        walk(shownChildren(tree), 0);
    }

    return list;
}

/** Whether a node has a box of its own on the page: its opening tag was marked for the editor. */
export function isMarked(node: TreeNode): boolean {
    return node.can.attributes;
}

/**
 * The elements that show a node on the page. An element shows itself. A
 * block, an arm or the template shows as the outermost elements inside it.
 * Text, an echo or a directive has no box of its own: it shows as the
 * element around it, and `inside` says so.
 */
export function boxesOf(tree: TreeNode | null, id: string | null): { ids: string[]; inside: boolean } {
    const path = pathTo(tree, id);
    const node = path[path.length - 1];
    if (node === undefined) {
        return { ids: [], inside: false };
    }
    if (isMarked(node)) {
        return { ids: [node.id], inside: false };
    }

    const outermost = (nodes: TreeNode[]): string[] => nodes.flatMap((child) => (isMarked(child) ? [child.id] : outermost(child.children ?? [])));
    const within = outermost(node.children ?? []);
    if (within.length > 0) {
        return { ids: within, inside: false };
    }

    const around = [...path].reverse().find((above) => above !== node && isMarked(above));

    return { ids: around === undefined ? [] : [around.id], inside: around !== undefined };
}

/**
 * The node to select for a click on an element of the page: the element
 * itself. Its id is in the tree unless the page is older than the tree.
 */
export function nodeForMark(tree: TreeNode | null, id: string): TreeNode | null {
    return findNode(tree, id);
}

/** What kind of thing a node is, in a word for the panel. */
export function kindOf(node: TreeNode): 'element' | 'text' | 'field' | 'label' | 'expression' | 'condition' | 'loop' | 'arm' | 'include' | 'component' | 'directive' | 'code' | 'comment' | 'stray' {
    if (typeof node.stray === 'string') {
        return 'stray';
    }
    switch (node.type) {
        case 'element':
            return 'element';
        case 'text':
            return 'text';
        case 'comment':
        case 'doctype':
            return 'comment';
        case 'branch':
            return 'arm';
        case 'block':
            if (node.kind === 'code') {
                return 'code';
            }
            if (node.atom === 'component') {
                return 'component';
            }

            return ['foreach', 'forelse', 'for', 'while'].includes(node.name) ? 'loop' : ['if', 'unless', 'isset', 'empty', 'switch', 'auth', 'guest', 'env', 'production', 'error'].includes(node.name) ? 'condition' : 'directive';
        default:
            if (node.kind === 'field' || node.kind === 'label' || node.kind === 'expression') {
                return node.kind;
            }
            if (node.kind === null) {
                return 'comment';
            }
            if (node.atom === 'component') {
                return 'component';
            }
            if (node.kind === 'code') {
                return 'code';
            }

            return node.name.toLowerCase().startsWith('include') || node.name === 'each' ? 'include' : 'directive';
    }
}
