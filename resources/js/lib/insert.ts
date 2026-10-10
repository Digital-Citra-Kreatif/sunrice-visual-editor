/**
 * Inserting into a template: where something can go relative to the
 * selected node, and which operation puts it there.
 */
import type { Insertable, Operation, TreeNode } from './api';
// With its extension: Node runs this file as it is for the tests.
import { pathTo, shownChildren } from './tree.ts';

export type Place = 'before' | 'inside' | 'after';

/** Where something can go by the selected node. `inside` is the node that takes it, or null. */
export type Places = { before: boolean; inside: string | null; after: boolean };

const NOWHERE: Places = { before: false, inside: null, after: false };

/**
 * The node that takes what is put inside a node: the node itself, or for a
 * block with one arm (a loop, a lone @if, a component with its end tag),
 * the arm. A block with several arms takes nothing itself: one of its arms
 * has to be chosen.
 */
export function holderOf(node: TreeNode): TreeNode | null {
    if (node.can.insert) {
        return node;
    }
    if (node.type !== 'block') {
        return null;
    }
    const arms = (node.children ?? []).filter((arm) => arm.type === 'branch' && !(node.name === 'switch' && arm.name === 'switch'));

    return arms.length === 1 && arms[0].can.insert ? arms[0] : null;
}

/**
 * Where something can be put by a node of the tree. With nothing selected,
 * only a template that has nothing in it yet takes something: anywhere else
 * the place has to be said.
 */
export function placesFor(tree: TreeNode | null, id: string | null): Places {
    if (tree === null) {
        return NOWHERE;
    }
    if (id === null) {
        return shownChildren(tree).length === 0 && tree.can.insert ? { before: false, inside: tree.id, after: false } : NOWHERE;
    }
    const path = pathTo(tree, id);
    const node = path[path.length - 1];
    const parent = path[path.length - 2];
    if (node === undefined) {
        return NOWHERE;
    }
    // Beside it: not for an arm of a block, which has its place, nor for the template itself.
    const beside = parent !== undefined && node.type !== 'branch' && parent.can.insert;

    return { before: beside, inside: holderOf(node)?.id ?? null, after: beside };
}

/**
 * Where the next thing most likely goes: into something that is still
 * empty, else after what is selected.
 */
export function likelyPlace(tree: TreeNode | null, id: string | null): Place | null {
    const places = placesFor(tree, id);
    const holder = places.inside === null ? null : (pathTo(tree, places.inside).pop() ?? null);
    if (holder !== null && (holder.children ?? []).length === 0) {
        return 'inside';
    }

    return places.after ? 'after' : places.inside !== null ? 'inside' : places.before ? 'before' : null;
}

/** The operation that puts a piece of Blade at a place by the selected node; null when it can't go there. */
export function insertion(tree: TreeNode | null, id: string | null, place: Place, blade: string): Operation | null {
    const places = placesFor(tree, id);
    if (place === 'inside') {
        return places.inside === null ? null : { type: 'append', node: places.inside, blade };
    }
    if (id === null || !places[place]) {
        return null;
    }

    return { type: place === 'before' ? 'insertBefore' : 'insertAfter', node: id, blade };
}

/**
 * What of the library matches what was typed: every word typed is in the
 * title, the hint, the keywords or the Blade itself. Nothing typed: all of it.
 */
export function matching(inserts: Insertable[], typed: string): Insertable[] {
    const words = typed.toLowerCase().split(/\s+/).filter((word) => word !== '');
    if (words.length === 0) {
        return inserts;
    }

    return inserts.filter((insert) => {
        const text = `${insert.title} ${insert.hint} ${insert.keywords} ${insert.blade}`.toLowerCase();

        return words.every((word) => text.includes(word));
    });
}

/** The library in its groups, in the order they first appear. */
export function grouped(inserts: Insertable[]): { group: string; inserts: Insertable[] }[] {
    const groups: { group: string; inserts: Insertable[] }[] = [];
    for (const insert of inserts) {
        const group = groups.find((candidate) => candidate.group === insert.group);
        if (group === undefined) {
            groups.push({ group: insert.group, inserts: [insert] });
        } else {
            group.inserts.push(insert);
        }
    }

    return groups;
}
