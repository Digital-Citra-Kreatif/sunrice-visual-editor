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
