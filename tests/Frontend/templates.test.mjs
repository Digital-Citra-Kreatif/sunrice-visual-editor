import assert from 'node:assert/strict';
import { test } from 'node:test';
import { inPanelOrder, limit, pageTemplate, roleLabel, sameTemplate, shortName } from '../../resources/js/lib/templates.ts';

const file = (path, role, more = {}) => ({ key: path, path, origin: 'site', view: 'x', role, usedBy: [], editable: true, copyTo: null, opens: true, ...more });

test('finds the page\'s own template among those that drew it', () => {
    // As a page with a layout is rendered: the page's template first, the layout last.
    const home = [file('show.blade.php', 'entry'), file('blocks/hero.blade.php', 'block'), file('layouts/app.blade.php', 'layout'), file('partials/header.blade.php', 'partial')];
    assert.equal(pageTemplate(home).path, 'show.blade.php');

    assert.equal(pageTemplate([file('layouts/app.blade.php', 'layout'), file('index.blade.php', 'listing')]).path, 'index.blade.php');
    assert.equal(pageTemplate([file('layouts/app.blade.php', 'layout'), file('search.blade.php', 'search')]).path, 'search.blade.php');
    assert.equal(pageTemplate([file('layouts/app.blade.php', 'layout'), file('taxonomies/show.blade.php', 'term')]).path, 'taxonomies/show.blade.php');
});

test('settles for what there is when no template is the page\'s own', () => {
    assert.equal(pageTemplate([file('layouts/app.blade.php', 'layout'), file('partials/header.blade.php', 'partial')]).path, 'partials/header.blade.php');
    assert.equal(pageTemplate([file('layouts/app.blade.php', 'layout')]).path, 'layouts/app.blade.php');
    assert.equal(pageTemplate([]), null);
});

test('lists templates the way a page is built: layout, page template, then its parts', () => {
    const files = [
        file('show.blade.php', 'entry'),
        file('blocks/text.blade.php', 'block'),
        file('blocks/hero.blade.php', 'block'),
        file('components/seo.blade.php', 'component'),
        file('layouts/app.blade.php', 'layout'),
        file('partials/menu.blade.php', 'partial'),
        file('partials/header.blade.php', 'partial'),
        file('notes.blade.php', 'other'),
        file('odd.blade.php', 'a role from the future'),
    ];

    assert.deepEqual(
        inPanelOrder(files).map((each) => each.path),
        ['layouts/app.blade.php', 'show.blade.php', 'notes.blade.php', 'odd.blade.php', 'partials/header.blade.php', 'partials/menu.blade.php', 'blocks/hero.blade.php', 'blocks/text.blade.php', 'components/seo.blade.php'],
    );
    // The list it was given is left as it was.
    assert.equal(files[0].path, 'show.blade.php');
});

test('tells two templates apart by path and by where they are from', () => {
    const site = { path: 'show.blade.php', origin: 'site' };

    assert.equal(sameTemplate(site, { path: 'show.blade.php', origin: 'site' }), true);
    assert.equal(sameTemplate(site, { path: 'show.blade.php', origin: 'package' }), false);
    assert.equal(sameTemplate(site, { path: 'index.blade.php', origin: 'site' }), false);
    assert.equal(sameTemplate(site, null), false);
    assert.equal(sameTemplate(null, null), false);
});

test('names things for people', () => {
    assert.equal(shortName('partials/header.blade.php'), 'partials/header');
    assert.equal(shortName('show.blade.php'), 'show');
    assert.equal(roleLabel('entry'), 'Page template');
    assert.equal(roleLabel('partial'), 'Partial');
    assert.equal(roleLabel('something new'), 'Template');
});

test('says in a word why a template can\'t be edited visually', () => {
    assert.equal(limit({ origin: 'site', opens: true }), null);
    assert.equal(limit({ origin: 'site', opens: false }), 'Code only');
    assert.equal(limit({ origin: 'package', opens: true }), 'Read-only');
    assert.equal(limit({ origin: 'package', opens: false }), 'Read-only');
});

test('a search finds templates by path, short name or role, whatever the case or the way a letter is written', async () => {
    const { matchesSearch } = await import('../../resources/js/lib/templates.ts');
    const hero = { path: 'blocks/hero.blade.php', role: 'block' };
    assert.equal(matchesSearch(hero, ''), true);
    assert.equal(matchesSearch(hero, '  '), true);
    assert.equal(matchesSearch(hero, 'HERO'), true);
    assert.equal(matchesSearch(hero, 'blocks/hero'), true);
    assert.equal(matchesSearch(hero, 'block'), true);
    assert.equal(matchesSearch({ path: 'partials/card.blade.php', role: 'partial' }, 'PARTIAL'), true);
    assert.equal(matchesSearch({ path: 'show.blade.php', role: 'entry' }, 'page template'), true);
    assert.equal(matchesSearch({ path: 'partials/café.blade.php', role: 'partial' }, 'café'), true);
    assert.equal(matchesSearch(hero, 'zzz'), false);
});

test('lists the templates again no more than once every two seconds', async () => {
    const { relistDelay, RELIST_GAP } = await import('../../resources/js/lib/templates.ts');
    assert.equal(RELIST_GAP, 2000);
    assert.equal(relistDelay(0, 10_000), 0);
    assert.equal(relistDelay(10_000, 10_500), 1500);
    assert.equal(relistDelay(10_000, 12_000), 0);
    // A clock that went back never waits longer than the gap.
    assert.equal(relistDelay(20_000, 10_000), 2000);
});

test('takes the template the server says to open, and nothing else', async () => {
    const { readStartTemplate } = await import('../../resources/js/lib/templates.ts');
    assert.deepEqual(readStartTemplate({ path: 'blocks/hero.blade.php', origin: 'site' }), { path: 'blocks/hero.blade.php', origin: 'site' });
    assert.deepEqual(readStartTemplate({ path: 'entry.blade.php', origin: 'package' }), { path: 'entry.blade.php', origin: 'package' });
    for (const sent of [undefined, null, 'blocks/hero.blade.php', { path: '' , origin: 'site' }, { path: 'x.blade.php', origin: 'elsewhere' }, { origin: 'site' }]) {
        assert.equal(readStartTemplate(sent), null);
    }
});
