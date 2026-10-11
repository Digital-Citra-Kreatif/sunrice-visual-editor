import assert from 'node:assert/strict';
import { test } from 'node:test';
import { EVERYTHING, folding, isFolded, readLayout, shareOf, siding, writeLayout } from '../../resources/js/lib/layout.ts';

const store = (kept = {}) => ({ getItem: (key) => kept[key] ?? null, setItem: (key, value) => void (kept[key] = value) });

test('shows everything the first time', () => {
    assert.deepEqual(readLayout(store()), EVERYTHING);
    assert.deepEqual(EVERYTHING, { left: true, right: true, folded: [], mode: 'visual', share: 0.5 });
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
    assert.deepEqual(hidden, { ...EVERYTHING, right: false });
    assert.deepEqual(siding(hidden, 'right'), EVERYTHING);
    assert.equal(siding(hidden, 'right', false), hidden);
    assert.deepEqual(siding(hidden, 'left', false), { ...EVERYTHING, left: false, right: false });
});

test('remembers how the editor was left', () => {
    const kept = store();
    const left = { ...folding(siding(EVERYTHING, 'left'), 'group:style:Spacing'), mode: 'code', share: 0.35 };
    writeLayout(left, kept);
    assert.deepEqual(readLayout(kept), left);
});

test('makes do with whatever is in the store', () => {
    for (const nonsense of ['', 'not json', '[]', '"text"', '{"left":"no","folded":"all"}', '{"folded":[1,null,"",{}]}']) {
        assert.deepEqual(readLayout(store({ 'sunrice-visual-editor:layout': nonsense })), EVERYTHING, nonsense);
    }
    assert.deepEqual(readLayout(store({ 'sunrice-visual-editor:layout': '{"left":false,"folded":["a","a","b"]}' })), { ...EVERYTHING, left: false, folded: ['a', 'b'] });
    // A store that can't be read or written: everything shown, and nothing breaks.
    const forbidden = { getItem: () => { throw new Error('no'); }, setItem: () => { throw new Error('no'); } };
    assert.deepEqual(readLayout(forbidden), EVERYTHING);
    writeLayout(EVERYTHING, forbidden);
});

test('keeps the way of working and how the room is shared, within what is allowed', () => {
    const read = (kept) => readLayout(store({ 'sunrice-visual-editor:layout': JSON.stringify(kept) }));
    assert.equal(read({ mode: 'code' }).mode, 'code');
    assert.equal(read({ mode: 'something else' }).mode, 'visual');
    assert.equal(read({ share: 0.3 }).share, 0.3);
    // The page is never squeezed out, nor the code.
    assert.equal(shareOf(0.01), 0.2);
    assert.equal(shareOf(5), 0.8);
    assert.equal(shareOf('half'), 0.5);
    assert.equal(shareOf(Number.NaN), 0.5);
});
