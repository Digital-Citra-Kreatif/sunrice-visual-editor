import { useEffect, useRef, useState, type DragEvent, type KeyboardEvent } from 'react';
import type { TreeNode } from '../lib/api';
import { dropAt, type Action, type Drop } from '../lib/move';
import { kindOf, rows } from '../lib/tree';

type Props = {
    /** The open template's tree; null when there is none to show. */
    tree: TreeNode | null;
    /** What to say in place of the tree. */
    note: string | null;
    selected: string | null;
    /** The node under the pointer on the page. */
    lit: string | null;
    collapsed: ReadonlySet<string>;
    /** Nothing can be moved right now: a change is being saved, or the template is not one to change. */
    fixed: boolean;
    onSelect(id: string): void;
    onPoint(id: string | null): void;
    onToggle(id: string): void;
    /** A row was dragged onto another. */
    onMove(id: string, drop: Drop): void;
    /** A key asked for something to be done to a row. */
    onAct(id: string, action: Action): void;
};

/** In the data of a drag: the id of the node being moved. Only a drag that started here carries it. */
const DRAGGED = 'application/x-sve-node';

/** A few letters saying what a row is, before its label. */
const BADGES: Record<ReturnType<typeof kindOf>, string> = {
    element: '<>',
    text: 'T',
    field: '{ }',
    label: '{ }',
    expression: '{ }',
    condition: 'if',
    loop: 'for',
    arm: '›',
    include: 'inc',
    component: 'x-',
    directive: '@',
    code: 'php',
    comment: '//',
    stray: '!',
};

export function nodeTitle(node: TreeNode): string {
    if (node.type === 'text') {
        return `“${node.label}”`;
    }

    return node.label === '' ? node.name : node.label;
}

/**
 * The open template as a tree of layers: its elements, text, Blade blocks
 * and echoes, in the order of the file. Pointing at a row shows it on the
 * page; a click selects it.
 */
export function LayersPanel({ tree, note, selected, lit, collapsed, fixed, onSelect, onPoint, onToggle, onMove, onAct }: Props) {
    const list = useRef<HTMLUListElement>(null);
    const all = rows(tree, collapsed);
    /** The row being dragged, and where it would go if let go of now. */
    const [dragged, setDragged] = useState<string | null>(null);
    const [drop, setDrop] = useState<(Drop & { over: string }) | null>(null);

    const over = (event: DragEvent<HTMLLIElement>, id: string) => {
        if (dragged === null) {
            return;
        }
        const box = event.currentTarget.getBoundingClientRect();
        const next = dropAt(tree, dragged, id, box.height === 0 ? 0.5 : (event.clientY - box.top) / box.height);
        if (next !== null) {
            // Saying so is what lets the row be dropped on.
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
        }
        setDrop((before) => (next === null ? null : before !== null && before.over === id && before.target === next.target && before.position === next.position ? before : { ...next, over: id }));
    };

    // Where the line for "after" is drawn: under the last row of what the node holds, where it would
    // land, at the depth of the node itself.
    const afterAt = (() => {
        if (drop === null || drop.position !== 'after') {
            return null;
        }
        const from = all.findIndex((row) => row.node.id === drop.over);
        let last = from;
        while (from !== -1 && all[last + 1] !== undefined && all[last + 1].depth > all[from].depth) {
            last++;
        }

        return from === -1 ? null : { row: all[last].node.id, depth: all[from].depth };
    })();
    const indent = (depth: number) => `${0.25 + depth * 0.875}rem`;
    const dropClass = (id: string) =>
        drop === null ? '' : drop.position === 'after' ? (afterAt?.row === id ? ' sve-layer--drop-after' : '') : drop.over === id ? ` sve-layer--drop-${drop.position}` : '';

    const letGo = () => {
        setDragged(null);
        setDrop(null);
    };

    const key = (event: KeyboardEvent<HTMLButtonElement>, id: string) => {
        const action: Action | null =
            event.key === 'Delete' || event.key === 'Backspace'
                ? 'delete'
                : event.altKey && event.key === 'ArrowUp'
                  ? 'up'
                  : event.altKey && event.key === 'ArrowDown'
                    ? 'down'
                    : (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'd'
                      ? 'duplicate'
                      : null;
        if (action !== null && !fixed) {
            event.preventDefault();
            onAct(id, action);
        }
    };

    // Selected from the page: bring its row into view.
    useEffect(() => {
        if (selected !== null) {
            list.current?.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' });
        }
    }, [selected]);

    return (
        <>
            {tree === null || all.length === 0 ? (
                <p className="sve-empty">{note ?? 'This template has nothing in it yet.'}</p>
            ) : (
                <ul className="sve-layers" role="tree" aria-label="Layers" ref={list} onMouseLeave={() => onPoint(null)}>
                    {all.map(({ node, depth, parent }) => {
                        const kind = kindOf(node);

                        return (
                            <li
                                key={node.id}
                                role="treeitem"
                                aria-level={depth + 1}
                                aria-selected={node.id === selected}
                                aria-expanded={parent ? !collapsed.has(node.id) : undefined}
                                className={`sve-layer sve-layer--${kind}${node.id === lit ? ' sve-layer--lit' : ''}${node.id === dragged ? ' sve-layer--dragged' : ''}${dropClass(node.id)}`}
                                style={{ paddingInlineStart: indent(depth), ['--sve-drop-indent' as string]: indent(afterAt?.row === node.id ? afterAt.depth : depth) }}
                                onMouseEnter={() => onPoint(node.id)}
                                draggable={node.can.move && !fixed}
                                onDragStart={(event) => {
                                    event.dataTransfer.setData(DRAGGED, node.id);
                                    event.dataTransfer.effectAllowed = 'move';
                                    setDragged(node.id);
                                }}
                                onDragOver={(event) => over(event, node.id)}
                                onDragLeave={(event) => {
                                    // Leaving the row, not going from one part of it to another.
                                    if (!(event.relatedTarget instanceof Node) || !event.currentTarget.contains(event.relatedTarget)) {
                                        setDrop((before) => (before !== null && before.over === node.id ? null : before));
                                    }
                                }}
                                onDrop={(event) => {
                                    event.preventDefault();
                                    if (dragged !== null && drop !== null && drop.over === node.id) {
                                        onMove(dragged, { target: drop.target, position: drop.position });
                                    }
                                    letGo();
                                }}
                                onDragEnd={letGo}
                            >
                                {parent ? (
                                    <button type="button" className="sve-layer__toggle" onClick={() => onToggle(node.id)} aria-label={collapsed.has(node.id) ? 'Show what is inside' : 'Hide what is inside'} tabIndex={-1}>
                                        <span aria-hidden="true">{collapsed.has(node.id) ? '▸' : '▾'}</span>
                                    </button>
                                ) : (
                                    <span className="sve-layer__toggle" aria-hidden="true" />
                                )}
                                <button type="button" className="sve-layer__pick" onClick={() => onSelect(node.id)} onFocus={() => onPoint(node.id)} onKeyDown={(event) => key(event, node.id)} title={typeof node.stray === 'string' ? node.stray : undefined}>
                                    <span className="sve-layer__badge" aria-hidden="true">
                                        {BADGES[kind]}
                                    </span>
                                    <span className="sve-layer__label">{nodeTitle(node)}</span>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </>
    );
}
