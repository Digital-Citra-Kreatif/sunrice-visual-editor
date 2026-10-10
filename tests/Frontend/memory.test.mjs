import assert from 'node:assert/strict';
import { test } from 'node:test';
import { pageOf, rememberPage } from '../../resources/js/lib/memory.ts';

/** A stand-in for the browser's localStorage. */
const store = (initial = {}) => {
    const items = { ...initial };

    return { items, getItem: (key) => items[key] ?? null, setItem: (key, value) => { items[key] = value; } };
};

test('remembers the last page each template drew', () => {
    const kept = store();

    rememberPage(['site:show.blade.php', 'site:partials/header.blade.php'], '/', kept);
    rememberPage(['site:index.blade.php', 'site:partials/header.blade.php'], '/articles?page=2', kept);

    assert.equal(pageOf('site:show.blade.php', kept), '/');
    assert.equal(pageOf('site:index.blade.php', kept), '/articles?page=2');
    assert.equal(pageOf('site:partials/header.blade.php', kept), '/articles?page=2');
    assert.equal(pageOf('site:blocks/hero.blade.php', kept), null);
});

test('forgets the templates it heard of longest ago', () => {
    const kept = store();
    for (let n = 0; n < 205; n++) {
        rememberPage([`site:t${n}.blade.php`], `/p${n}`, kept);
    }
    // Seen again: one of the newest now.
    rememberPage(['site:t5.blade.php'], '/again', kept);
    rememberPage(['site:t205.blade.php'], '/p205', kept);

    assert.equal(pageOf('site:t0.blade.php', kept), null);
    assert.equal(pageOf('site:t4.blade.php', kept), null);
    assert.equal(pageOf('site:t5.blade.php', kept), '/again');
    assert.equal(pageOf('site:t204.blade.php', kept), '/p204');
    assert.equal(Object.keys(JSON.parse(Object.values(kept.items)[0])).length, 200);
});

test('makes do with a store that is broken, full or not its own', () => {
    assert.equal(pageOf('site:show.blade.php', store({ 'sunrice-visual-editor:pages': 'not json' })), null);
    assert.equal(pageOf('site:show.blade.php', store({ 'sunrice-visual-editor:pages': '["a list"]' })), null);
    // Something that is no path of the site is not a page to go to.
    assert.equal(pageOf('site:show.blade.php', store({ 'sunrice-visual-editor:pages': '{"site:show.blade.php":"https://example.com/"}' })), null);
    assert.equal(pageOf('site:show.blade.php', store({ 'sunrice-visual-editor:pages': '{"site:show.blade.php":7}' })), null);

    const full = { getItem: () => null, setItem: () => { throw new Error('QuotaExceededError'); } };
    assert.doesNotThrow(() => rememberPage(['site:show.blade.php'], '/', full));
});
