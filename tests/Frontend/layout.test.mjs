import assert from 'node:assert/strict';
import { test } from 'node:test';
import { EVERYTHING, folding, isFolded, readLayout, siding, writeLayout } from '../../resources/js/lib/layout.ts';

const store = (kept = {}) => ({ getItem: (key) => kept[key] ?? null, setItem: (key, value) => void (kept[key] = value) });

test('shows everything the first time', () => {
    assert.deepEqual(readLayout(store()), EVERYTHING);
    assert.deepEqual(EVERYTHING, { left: true, right: true, folded: [] });
});

test('folds a panel and opens it again', () => {
    const folded = folding(EVERYTHING, 'panel:Layers');
    assert.ok(isFolded(folded, 'panel:Layers'));
    assert.ok(!isFolded(folded, 'panel:Settings'));
    assert.deepEqual(folding(folded, 'panel:Layers'), EVERYTHING);
});

test('opens or folds as it is told, and is the same layout when nothing changes', () => {
    const folded = folding(EVERYTHING, 'panel:Insert');
    assert.deepEqual(folding(folded, 'panel:Insert', true), EVERYTHING);
    assert.equal(folding(EVERYTHING, 'panel:Insert', true), EVERYTHING);
    assert.equal(folding(folded, 'panel:Insert', false), folded);
});

test('hides a side and shows it again', () => {
    const hidden = siding(EVERYTHING, 'right');
    assert.deepEqual(hidden, { left: true, right: false, folded: [] });
    assert.deepEqual(siding(hidden, 'right'), EVERYTHING);
    assert.equal(siding(hidden, 'right', false), hidden);
    assert.deepEqual(siding(hidden, 'left', false), { left: false, right: false, folded: [] });
});

test('remembers how the editor was left', () => {
    const kept = store();
    const left = folding(siding(EVERYTHING, 'left'), 'group:style:Spacing');
    writeLayout(left, kept);
    assert.deepEqual(readLayout(kept), left);
});

test('makes do with whatever is in the store', () => {
    for (const nonsense of ['', 'not json', '[]', '"text"', '{"left":"no","folded":"all"}', '{"folded":[1,null,"",{}]}']) {
        assert.deepEqual(readLayout(store({ 'sunrice-visual-editor:layout': nonsense })), EVERYTHING, nonsense);
    }
    assert.deepEqual(readLayout(store({ 'sunrice-visual-editor:layout': '{"left":false,"folded":["a","a","b"]}' })), { left: false, right: true, folded: ['a', 'b'] });
    // A store that can't be read or written: everything shown, and nothing breaks.
    const forbidden = { getItem: () => { throw new Error('no'); }, setItem: () => { throw new Error('no'); } };
    assert.deepEqual(readLayout(forbidden), EVERYTHING);
    writeLayout(EVERYTHING, forbidden);
});
