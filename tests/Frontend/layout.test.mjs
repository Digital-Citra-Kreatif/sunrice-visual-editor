import assert from 'node:assert/strict';
import { test } from 'node:test';
import { EVERYTHING, readLayout, shareOf, siding, writeLayout } from '../../resources/js/lib/layout.ts';

const store = (kept = {}) => ({ getItem: (key) => kept[key] ?? null, setItem: (key, value) => void (kept[key] = value) });

test('shows everything the first time', () => {
    assert.deepEqual(readLayout(store()), EVERYTHING);
    assert.deepEqual(EVERYTHING, { left: true, right: true, share: 0.4 });
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
    const left = { ...siding(EVERYTHING, 'left'), share: 0.55 };
    writeLayout(left, kept);
    assert.deepEqual(readLayout(kept), left);
});

test('keeps the code\'s width within bounds', () => {
    assert.equal(shareOf(0.05), 0.2);
    assert.equal(shareOf(0.95), 0.7);
    assert.equal(shareOf('wide'), EVERYTHING.share);
    assert.equal(shareOf(Number.NaN), EVERYTHING.share);
});

test('reads what it does not know as the first time', () => {
    assert.deepEqual(readLayout(store({ 'sunrice-visual-editor:layout:2': '[1, 2]' })), EVERYTHING);
    assert.deepEqual(readLayout(store({ 'sunrice-visual-editor:layout:2': '{' })), EVERYTHING);
    assert.deepEqual(readLayout({ getItem: () => { throw new Error('forbidden'); }, setItem: () => {} }), EVERYTHING);
    assert.deepEqual(readLayout(store({ 'sunrice-visual-editor:layout:2': '{"left":false,"folded":["a"],"share":9}' })), { ...EVERYTHING, left: false, share: 0.7 });
});
