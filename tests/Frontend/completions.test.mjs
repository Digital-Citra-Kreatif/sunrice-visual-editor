import assert from 'node:assert/strict';
import { test } from 'node:test';
import { indented, needsFieldCatalog, offersFor } from '../../resources/js/lib/completions.ts';

const field = (handle, label, expression, more = {}) => ({ handle, label, code: expression ?? handle, what: 'text', kind: 'text', of: [], inserts: [], print: expression === null ? null : `{{ ${expression} }}`, expression, asked: '', compared: '', looped: null, groups: [], note: null, ...more });
const variable = (code, fields, more = {}) => ({ code, title: `The ${code}`, from: 'page', sure: true, what: 'entry', kind: 'entry', of: [], inserts: [], print: null, expression: null, asked: '', compared: '', looped: null, groups: [{ title: 'Fields', fields }], ...more });

test('the catalog waits for typed field syntax or an explicit completion gesture', () => {
    for (const text of ['', '    ', '<h1>Title</h1>', 'plain words']) assert.equal(needsFieldCatalog(text), false);
    for (const text of ['{{ $block->', '$entry?->tit', "$values['pic", '{{ $ent', '$block']) assert.equal(needsFieldCatalog(text), true, text);
    assert.equal(needsFieldCatalog('    ', true), true);
});

const cover = field('cover', 'Cover', null, { what: 'file', groups: [{ title: 'Every file', fields: [field('alt', 'Alt text', "$entry->get('cover')?->alt")] }] });
const entry = variable('$entry', [field('title', 'Title', '$entry->title'), field('summary', 'Summary', "$entry->get('summary')"), field('body', 'Body', "$entry->get('body')", { print: "{!! $entry->get('body') !!}", what: 'rich text' }), cover]);
const site = variable("cms_global('site')", [field('name', 'Name', "cms_global('site')->name")], { from: 'site' });
const photo = variable('$photo', [field('alt', 'Alt text', '$photo->alt')], { from: 'loop', expression: null });
const locale = variable('$locale', [], { from: 'site', expression: '$locale', print: '{{ $locale }}', what: 'text', groups: [] });
const all = [photo, entry, locale, site];

const labels = (found) => found?.offers.map((offer) => offer.label) ?? null;

test('offers what a variable has after "->", written whole and guarded', () => {
    const found = offersFor('    <h1>{{ $entry->', all);
    assert.deepEqual(labels(found), ['Title', 'Summary', 'Body', 'Cover › Alt text']);
    // In place of the variable and what was typed after it.
    assert.equal('    <h1>{{ $entry->'.slice(found.from), '$entry->');
    assert.equal(found.offers.find((offer) => offer.label === 'Cover › Alt text').insert, "$entry->get('cover')?->alt");
    assert.equal(found.offers[0].detail, 'text');
});

test('narrows by what is typed after it, by handle or by name', () => {
    assert.deepEqual(labels(offersFor('{{ $entry->sum', all)), ['Summary']);
    assert.deepEqual(labels(offersFor('{{ $entry->alt', all)), ['Cover › Alt text']);
    assert.deepEqual(labels(offersFor("{{ $entry->get('cov", all)), null);
    assert.deepEqual(labels(offersFor("@if ($entry['tit", all)), ['Title']);
    assert.equal(offersFor('{{ $entry->nothinglikeit', all), null);
});

test('knows a value that is no plain variable, and not the end of a longer name', () => {
    const found = offersFor("<title>{{ cms_global('site')->", all);
    assert.deepEqual(labels(found), ['Name']);
    assert.equal(found.offers[0].insert, "cms_global('site')->name");
    assert.equal(offersFor('{{ $myentry->', all), null);
    assert.equal(offersFor('{{ $entries->', all), null);
});

test('offers what can be printed after the braces of an echo, the place and the template before the site', () => {
    const found = offersFor('<p>{{ ', all);
    assert.deepEqual(labels(found), ['$photo › Alt text', '$entry › Title', '$entry › Summary', '$entry › Cover › Alt text', '$locale', "cms_global('site') › Name"]);
    assert.equal(found.from, '<p>{{ '.length);
    // Rich text only where HTML is printed.
    assert.ok(labels(offersFor('<div>{!! ', all)).includes('$entry › Body'));
    assert.deepEqual(labels(offersFor('<p>{{ $ph', all)), ['$photo › Alt text']);
    assert.equal('<p>{{ $ph'.slice(offersFor('<p>{{ $ph', all).from), '$ph');
});

test('offers the variables there when a name is begun', () => {
    assert.deepEqual(labels(offersFor('@if ($', all)), ['$photo', '$entry', '$locale']);
    assert.deepEqual(labels(offersFor('@foreach ($en', all)), ['$entry']);
    assert.equal('@foreach ($en'.slice(offersFor('@foreach ($en', all).from), '$en');
    // A name that is whole already, and text with nothing begun: nothing to offer.
    assert.equal(offersFor('@if ($entry', all), null);
    assert.equal(offersFor('<p>plain text', all), null);
    assert.equal(offersFor('', all), null);
    assert.equal(offersFor('{{ $entry->', []), null);
});

test('indents what is put in as the line it lands on is', () => {
    assert.equal(indented('@foreach ($a as $b)\n    {{ $b }}\n@endforeach', '        '), '@foreach ($a as $b)\n            {{ $b }}\n        @endforeach');
    assert.equal(indented('{{ $x }}', '    <p>'), '{{ $x }}');
    assert.equal(indented('a\n\nb', '\t<p>'), 'a\n\n\tb');
    assert.equal(indented('a\nb\n', ''), 'a\nb\n');
});

const image = (code) => [
    { title: 'Image', kind: 'element', blade: `@if (${code})\n    <img src="{{ ${code}->url('large') }}">\n@endif` },
    { title: 'Link to the file', kind: 'element', blade: `<a href="{{ ${code}->url() }}">…</a>` },
];
const picture = field('picture', 'Picture', null, { what: 'file', inserts: image("$values['picture']"), note: 'Not in Pages' });
const summary = field('summary', 'Summary', "$values['summary'] ?? ''", { inserts: [{ title: 'Text', kind: 'print', blade: "{{ $values['summary'] ?? '' }}" }] });
const values = variable('$values', [summary, picture]);
const site2 = variable("cms_global('site')", [field('name', 'Name', "cms_global('site')['name']", { inserts: [{ title: 'Text', kind: 'print', blade: "{{ cms_global('site')['name'] }}" }] })], { from: 'site', title: 'Site' });

test('asked for, offers everything at the place as Blade of its own, each way it can be written', () => {
    const found = offersFor('    ', [site2, values], true);
    assert.deepEqual(labels(found), ['$values › Summary', '$values › Picture', '$values › Picture (link to the file)', 'Site › Name']);
    assert.equal(found.from, 4);
    assert.equal(found.offers[1].insert, image("$values['picture']")[0].blade);
    assert.equal(found.offers[1].detail, 'file · Not in Pages · image');
    assert.equal(found.offers[2].insert, image("$values['picture']")[1].blade);
});

test('asked for after a word, offers what has it, in place of the word', () => {
    const found = offersFor('<p>pic', [site2, values], true);
    assert.deepEqual(labels(found), ['$values › Picture', '$values › Picture (link to the file)']);
    assert.equal('<p>pic'.slice(found.from), 'pic');
    assert.equal(offersFor('<p>nothinglikeit', [site2, values], true), null);
});

test('says how the usual way writes a value, unless that is what the value is', () => {
    assert.equal(offersFor('', [values], true).offers[0].detail, 'text');
});

test('offers nothing unasked where no variable is begun, and says which collections lack a field', () => {
    assert.equal(offersFor('    ', [site2, values]), null);
    assert.equal(offersFor("{{ $values['", [values]).offers.find((offer) => offer.label === 'Picture'), undefined);
    const wide = variable('$entry', [field('picture', 'Picture', "$entry->picture", { note: 'Not in Pages' })]);
    assert.equal(offersFor('{{ $entry->', [wide]).offers[0].detail, 'text · Not in Pages');
});
