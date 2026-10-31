import assert from 'node:assert/strict';
import { test } from 'node:test';
import { cmsEditorHref, fieldExamples, filterFields, templateFieldSources } from '../../resources/js/lib/fields.ts';

const field = (handle, what = 'text', groups = []) => ({ handle, label: handle, code: `$values['${handle}']`, what, kind: what, of: [], note: null, inserts: [], print: `{{ $values['${handle}'] ?? '' }}`, groups });
const variable = (groups) => ({ title: 'Page fields', code: '$values', what: 'array', of: ['Pages'], groups, from: 'page', inserts: [], print: null });

test('field search preserves nested context, types, and blueprint source metadata', () => {
    const source = { subject: 'collection', handle: 'pages' };
    const values = [variable([{ title: 'Pages', source, fields: [field('title'), field('group', 'group', [{ title: 'Nested', fields: [field('cover', 'image'), field('summary')] }])] }])];
    const found = filterFields(values, 'IMAGE');
    assert.equal(found.length, 1);
    assert.deepEqual(found[0].groups[0].source, source);
    assert.equal(found[0].groups[0].fields.length, 1);
    assert.equal(found[0].groups[0].fields[0].groups[0].fields[0].handle, 'cover');
    assert.deepEqual(filterFields(values, 'collection')[0].groups, values[0].groups);
    assert.equal(filterFields(values, 'Nested')[0].groups[0].fields[0].groups[0].fields.length, 2);
    assert.deepEqual(filterFields(values, 'nothing like this'), []);
    assert.equal(filterFields(values, '   '), values);
});

test('the field reference keeps the emitter recipes and removes duplicate examples', () => {
    const value = field('cover', 'image');
    value.inserts = [{ title: 'Image', kind: 'element', blade: '@if ($cover)\n<img src="{{ $cover->url() }}">\n@endif' }];
    value.inserts.push({ ...value.inserts[0], title: 'Same image' });
    assert.deepEqual(fieldExamples(value), [value.inserts[0]]);
    assert.deepEqual(fieldExamples(field('summary')), [{ title: 'Print value', kind: 'print', blade: "{{ $values['summary'] ?? '' }}" }]);
});

test('CMS editor links stay inside the active panel and preserve their locale', () => {
    const origin = 'https://example.test';
    assert.equal(cmsEditorHref('/control/cms/entries/4/edit?locale=id', '/control', origin), '/control/cms/entries/4/edit?locale=id');
    assert.equal(cmsEditorHref('https://example.test/control/cms/fieldsets/hero/edit', '/control', origin), '/control/cms/fieldsets/hero/edit');
    for (const href of ['https://evil.test/control/x', '//evil.test/control/x', '/control/../../outside', '/control\\evil', '/control/\nx', 'javascript:alert(1)']) assert.equal(cmsEditorHref(href, '/control', origin), null, href);
});

test('source editors stay discoverable for empty blueprints, listings and block fieldsets', () => {
    assert.deepEqual(templateFieldSources({ role: 'block', path: 'blocks/hero.blade.php', usedBy: [] }), ['fieldset:hero']);
    assert.deepEqual(templateFieldSources({ role: 'listing', path: 'collections/index.blade.php', usedBy: [{ role: 'listing', subject: 'collection', handle: 'articles' }] }), ['listing:articles']);
});
