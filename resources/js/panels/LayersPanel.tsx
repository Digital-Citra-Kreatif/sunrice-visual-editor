import { useEffect, useRef } from 'react';
import type { TreeNode } from '../lib/api';
import { kindOf, rows } from '../lib/tree';
import { Panel } from './Panel';

type Props = {
    /** The open template's tree; null when there is none to show. */
    tree: TreeNode | null;
    /** What to say in place of the tree. */
    note: string | null;
    selected: string | null;
    /** The node under the pointer on the page. */
    lit: string | null;
    collapsed: ReadonlySet<string>;
    onSelect(id: string): void;
    onPoint(id: string | null): void;
    onToggle(id: string): void;
};

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
export function LayersPanel({ tree, note, selected, lit, collapsed, onSelect, onPoint, onToggle }: Props) {
    const list = useRef<HTMLUListElement>(null);
    const all = rows(tree, collapsed);

    // Selected from the page: bring its row into view.
    useEffect(() => {
        if (selected !== null) {
            list.current?.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' });
        }
    }, [selected]);

    return (
        <Panel title="Layers" grow>
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
                                className={`sve-layer sve-layer--${kind}${node.id === lit ? ' sve-layer--lit' : ''}`}
                                style={{ paddingInlineStart: `${0.25 + depth * 0.875}rem` }}
                                onMouseEnter={() => onPoint(node.id)}
                            >
                                {parent ? (
                                    <button type="button" className="sve-layer__toggle" onClick={() => onToggle(node.id)} aria-label={collapsed.has(node.id) ? 'Show what is inside' : 'Hide what is inside'} tabIndex={-1}>
                                        <span aria-hidden="true">{collapsed.has(node.id) ? '▸' : '▾'}</span>
                                    </button>
                                ) : (
                                    <span className="sve-layer__toggle" aria-hidden="true" />
                                )}
                                <button type="button" className="sve-layer__pick" onClick={() => onSelect(node.id)} onFocus={() => onPoint(node.id)} title={typeof node.stray === 'string' ? node.stray : undefined}>
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
        </Panel>
    );
}
