import { useState } from 'react';
import type { Insertable, Operation, TreeNode } from '../lib/api';
import { grouped, insertion, likelyPlace, matching, placesFor, type Place } from '../lib/insert';
import { findNode } from '../lib/tree';
import { nodeTitle } from './LayersPanel';
import { Panel } from './Panel';

type Props = {
    /** What there is to insert; null until it is loaded. */
    inserts: Insertable[] | null;
    /** Why the list could not be loaded, when it couldn't. */
    failed: string | null;
    /** The open template's tree; null when it has none. */
    tree: TreeNode | null;
    /** The selected node's id; things go before, into or after it. */
    selected: string | null;
    /** Why nothing can be inserted into this template now, when nothing can. */
    locked: string | null;
    busy: boolean;
    /** Answers whether it was inserted. */
    onInsert(operation: Operation): Promise<boolean>;
    onClose(): void;
};

const PLACES: { place: Place; label: string }[] = [
    { place: 'before', label: 'Before' },
    { place: 'inside', label: 'Inside' },
    { place: 'after', label: 'After' },
];

/**
 * The left panel while inserting: where the next thing goes, by the
 * selected node, and a list to pick it from. What is inserted becomes the
 * selection, so a click after a click builds a structure.
 */
export function InsertPanel({ inserts, failed, tree, selected, locked, busy, onInsert, onClose }: Props) {
    const [typed, setTyped] = useState('');
    const [pasted, setPasted] = useState('');
    /** The place chosen by hand, for the node it was chosen by: a node of this tree, not one that has its id in the next. */
    const [chosen, setChosen] = useState<{ tree: TreeNode | null; by: string | null; place: Place } | null>(null);

    const places = placesFor(tree, selected);
    const can = (place: Place) => (place === 'inside' ? places.inside !== null : places[place]);
    const place = chosen !== null && chosen.tree === tree && chosen.by === selected && can(chosen.place) ? chosen.place : likelyPlace(tree, selected);
    const node = findNode(tree, selected);
    const off = busy || locked !== null || place === null;

    const insert = (blade: string, then?: () => void) => {
        const operation = place === null ? null : insertion(tree, selected, place, blade);
        if (operation !== null) {
            void onInsert(operation).then((inserted) => inserted && then?.());
        }
    };

    const found = matching(inserts ?? [], typed);

    return (
        <Panel title="Insert" grow={2}>
            <div className="sve-selection__head">
                <span className="sve-selection__kind">Where</span>
                <button type="button" className="sve-selection__clear" onClick={onClose}>
                    Done
                </button>
            </div>

            {locked !== null ? (
                <p className="sve-note">{locked}</p>
            ) : node === null ? (
                <p className="sve-insert__where">{places.inside !== null ? 'Into the template: it has nothing in it yet.' : 'Select what it goes beside or into: a layer below, or something on the page.'}</p>
            ) : (
                <>
                    <div className="sve-places" role="group" aria-label="Where it goes">
                        {PLACES.map((each) => (
                            <button key={each.place} type="button" className="sve-places__place" aria-pressed={place === each.place} disabled={!can(each.place)} onClick={() => setChosen({ tree, by: selected, place: each.place })}>
                                {each.label}
                            </button>
                        ))}
                    </div>
                    <p className="sve-insert__where">
                        {place === null ? 'Nothing can be put here.' : `${PLACES.find((each) => each.place === place)?.label} `}
                        {place !== null && <code>{nodeTitle(node)}</code>}
                    </p>
                </>
            )}

            <input className="sve-input sve-insert__find" type="search" value={typed} onChange={(event) => setTyped(event.target.value)} placeholder="Find…" aria-label="Find something to insert" spellCheck={false} autoComplete="off" />

            {inserts === null ? (
                <p className="sve-empty">{failed ?? 'Loading…'}</p>
            ) : found.length === 0 ? (
                <p className="sve-empty">Nothing matches “{typed.trim()}”. Any HTML or Blade can be pasted below.</p>
            ) : (
                grouped(found).map(({ group, inserts: entries }) => (
                    <section key={group} className="sve-insert__group" aria-label={group}>
                        <h3 className="sve-insert__title">{group}</h3>
                        <ul className="sve-insertables">
                            {entries.map((entry) => (
                                <li key={entry.id}>
                                    <button type="button" className="sve-insertable" disabled={off} onClick={() => insert(entry.blade)} title={entry.blade}>
                                        <span className="sve-insertable__title">{entry.title}</span>
                                        <code className="sve-insertable__hint">{entry.hint}</code>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))
            )}

            <details className="sve-paste">
                <summary>Paste HTML or Blade</summary>
                <textarea className="sve-input sve-input--mono" rows={6} value={pasted} onChange={(event) => setPasted(event.target.value)} aria-label="HTML or Blade to insert" spellCheck={false} placeholder={'<div class="card">\n    …\n</div>'} />
                <p className="sve-field__hint">It has to stand on its own: every tag and every block closed.</p>
                <button type="button" className="sve-button" disabled={off || pasted.trim() === ''} onClick={() => insert(pasted, () => setPasted(''))}>
                    Insert
                </button>
            </details>
        </Panel>
    );
}
