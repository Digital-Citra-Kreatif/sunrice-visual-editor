import assert from 'node:assert/strict';
import { test } from 'node:test';
import { indented, offersFor } from '../../resources/js/lib/completions.ts';

const field = (handle, label, expression, more = {}) => ({ handle, label, code: expression ?? handle, what: 'text', kind: 'text', of: [], inserts: [], print: expression === null ? null : `{{ ${expression} }}`, expression, asked: '', compared: '', looped: null, groups: [], note: null, ...more });
const variable = (code, fields, more = {}) => ({ code, title: `The ${code}`, from: 'page', sure: true, what: 'entry', kind: 'entry', of: [], inserts: [], print: null, expression: null, asked: '', compared: '', looped: null, groups: [{ title: 'Fields', fields }], ...more });

const cover = field('cover', 'Cover', null, { what: 'file', groups: [{ title: 'Every file', fields: [field('alt', 'Alt text', "$entry->get('cover')?->alt")] }] });
const entry = variable('$entry', [field('title', 'Title', '$entry->title'), field('summary', 'Summary', "$entry->get('summary')"), field('body', 'Body', "$entry->get('body')", { print: "{!! $entry->get('body') !!}", what: 'rich text' }), cover]);
const site = variable("sunrice_global('site')", [field('name', 'Name', "sunrice_global('site')->name")], { from: 'site' });
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
    const found = offersFor("<title>{{ sunrice_global('site')->", all);
    assert.deepEqual(labels(found), ['Name']);
    assert.equal(found.offers[0].insert, "sunrice_global('site')->name");
    assert.equal(offersFor('{{ $myentry->', all), null);
    assert.equal(offersFor('{{ $entries->', all), null);
});

test('offers what can be printed after the braces of an echo, the place and the template before the site', () => {
    const found = offersFor('<p>{{ ', all);
    assert.deepEqual(labels(found), ['$photo › Alt text', '$entry › Title', '$entry › Summary', '$entry › Cover › Alt text', '$locale', "sunrice_global('site') › Name"]);
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
