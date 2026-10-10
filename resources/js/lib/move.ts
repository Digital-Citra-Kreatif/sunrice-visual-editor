/**
 * Moving, copying, wrapping and deleting the selected node: what can be
 * done to it, where a dragged node may be dropped, and the operations.
 */
import type { Operation, TreeNode } from './api';
// With their extension: Node runs these files as they are for the tests.
import { placesFor } from './insert.ts';
import { pathTo, shownChildren } from './tree.ts';

/** Where a node goes: before or after a node, or into one as its last child. */
export type Drop = { target: string; position: 'before' | 'inside' | 'after' };

export type Action = 'up' | 'down' | 'duplicate' | 'unwrap' | 'delete';

/** What a node can be wrapped in. Each is written empty; the node goes inside. */
export const WRAPPERS: { title: string; wrapper: string }[] = [
    { title: 'Container', wrapper: '<div></div>' },
    { title: 'Section', wrapper: '<section></section>' },
    { title: 'Link', wrapper: '<a href="#"></a>' },
    { title: 'Condition', wrapper: '@if (true)\n@endif' },
];

/** The nodes beside a node, in the order of the file, and where it is among them. */
function beside(tree: TreeNode | null, id: string): { siblings: TreeNode[]; at: number } | null {
    const path = pathTo(tree, id);
    const parent = path[path.length - 2];
    if (parent === undefined) {
        return null;
    }
    const siblings = parent.children ?? [];
    const at = siblings.findIndex((sibling) => sibling.id === id);

    return at === -1 ? null : { siblings, at };
}

/** The @break that ends an arm of a @switch: it stays last, and nothing goes after it. */
const endsArm = (node: TreeNode | undefined) => node !== undefined && node.type === 'atom' && node.name === 'break' && !node.can.move;

/**
 * One step up or down among the nodes beside it: before the one above,
 * after the one below. Null at either end, and for what can't be moved.
 */
export function step(tree: TreeNode | null, id: string, direction: 'up' | 'down'): Drop | null {
    const place = beside(tree, id);
    if (place === null || !place.siblings[place.at].can.move) {
        return null;
    }
    const other = place.siblings[place.at + (direction === 'up' ? -1 : 1)];
    if (other === undefined || other.type === 'branch' || (direction === 'down' && endsArm(other))) {
        return null;
    }

    return { target: other.id, position: direction === 'up' ? 'before' : 'after' };
}

/** What can be done to a node, by what the tree says about it. */
export function actionsFor(tree: TreeNode | null, id: string | null): Record<Action, boolean> & { wrap: boolean } {
    const path = pathTo(tree, id);
    const node = path[path.length - 1];
    if (node === undefined || id === null) {
        return { up: false, down: false, duplicate: false, unwrap: false, delete: false, wrap: false };
    }
    // Something to keep: an element's children, or what the only arm of a block holds.
    const inside = shownChildren(node);
    const unwrap = node.can.move && (node.type === 'element' || node.type === 'block') && inside.length > 0 && inside.every((child) => child.type !== 'branch');

    return {
        up: step(tree, id, 'up') !== null,
        down: step(tree, id, 'down') !== null,
        duplicate: node.can.move,
        unwrap,
        // Text is not picked up like a node: deleting it empties it.
        delete: node.can.delete || (node.type === 'text' && typeof node.text === 'string'),
        wrap: node.can.wrap,
    };
}

/** The operation for an action on a node; null when it can't be done. */
export function actionOn(tree: TreeNode | null, id: string, action: Action): Operation | null {
    if (!actionsFor(tree, id)[action]) {
        return null;
    }
    const node = pathTo(tree, id).pop();
    switch (action) {
        case 'up':
        case 'down': {
            const drop = step(tree, id, action);

            return drop === null ? null : { type: 'move', node: id, ...drop };
        }
        case 'duplicate':
            return { type: 'duplicate', node: id };
        case 'unwrap':
            return { type: 'unwrap', node: id };
        case 'delete':
            return node?.type === 'text' ? { type: 'setText', node: id, text: '' } : { type: 'remove', node: id };
    }
}

/**
 * Where a dragged node would go, for the pointer at a share of the height
 * of a row (0 at its top, 1 at its bottom): the top of a row is before it,
 * the bottom after it, and the middle into it, where it takes things.
 * Null where it can't go: onto itself, into something inside itself, or
 * beside or into what takes nothing there.
 */
export function dropAt(tree: TreeNode | null, dragged: string, over: string, share: number): Drop | null {
    const moving = pathTo(tree, dragged).pop();
    if (moving === undefined || !moving.can.move || pathTo(tree, over).some((node) => node.id === dragged)) {
        return null;
    }
    const places = placesFor(tree, over);
    const overNode = pathTo(tree, over).pop();
    const before: Drop | null = places.before ? { target: over, position: 'before' } : null;
    const after: Drop | null = places.after && !endsArm(overNode) ? { target: over, position: 'after' } : null;
    const inside: Drop | null = places.inside === null ? null : { target: places.inside, position: 'inside' };

    if (inside === null) {
        return share < 0.5 ? (before ?? after) : (after ?? before);
    }
    if (share < 0.25) {
        return before ?? inside;
    }

    return share > 0.75 ? (after ?? inside) : inside;
}

/** The move for a drop. */
export function moveTo(dragged: string, drop: Drop): Operation {
    return { type: 'move', node: dragged, target: drop.target, position: drop.position };
}
