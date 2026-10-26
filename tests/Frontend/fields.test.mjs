// The field picker's own logic: finding, laying out, and writing a field into a control.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { binding, boundTo, echoed, everything, matchingFields, nameOf, originOf, printable, usual } from '../../resources/js/lib/fields.ts';

const value = (code, what, more = {}) => ({ code, what, kind: what, of: [], inserts: [], print: null, expression: null, asked: code, compared: code, looped: null, groups: [], ...more });
const text = (code, more = {}) => value(code, 'text', { print: `{{ ${code} }}`, expression: code, inserts: [{ title: 'Text', kind: 'print', blade: `{{ ${code} }}` }], ...more });
const field = (handle, label, of, note = null) => ({ handle, label, note, ...of });

const cover = field('cover', 'Cover', value("$entry->get('cover')", 'file', {
    inserts: [{ title: 'Image', kind: 'element', blade: '@if ($cover = …)' }, { title: 'Link to the file', kind: 'element', blade: '<a>' }],
    groups: [{ title: 'File', fields: [field('alt', 'Alt text', text("$entry->get('cover')?->alt")), field('url:large', 'URL, large', text("$entry->get('cover')?->url('large')"))] }],
}));
const variables = [
    { ...value('$step', 'row', { groups: [{ title: 'Fields', fields: [field('label', 'Label', text("$step['label']", { expression: "$step['label'] ?? ''", print: "{{ $step['label'] ?? '' }}" }))] }] }), title: 'Each of $steps', from: 'loop', sure: true },
    {
        ...value('$entry', 'entry', {
            of: ['Pages', 'Things'],
            groups: [
                { title: 'Every entry', fields: [field('title', 'Title', text('$entry->title'))] },
                { title: 'Things', fields: [field('summary', 'Summary', text("$entry->get('summary')"), 'Not in Pages'), cover, field('body', 'Body', value("$entry->get('body')", 'rich text', { print: "{!! $entry->get('body') !!}", expression: "$entry->get('body')" }))] },
            ],
        }),
        title: 'The entry the page shows',
        from: 'page',
        sure: true,
    },
    { ...value("cms_global('site')", 'global set', { groups: [{ title: 'Site', fields: [field('name', 'Name', text("cms_global('site')->name"))] }] }), title: 'Site', from: 'site', sure: true },
    { ...text('$locale'), title: 'The language of the page', from: 'site', sure: true },
];

test('says where each variable comes from, and what it is called', () => {
    assert.deepEqual(variables.map(originOf), ['here', 'template', 'site', 'site']);
    assert.deepEqual(variables.map(nameOf), ['$step', '$entry', 'Site', '$locale']);
});

test('lists everything with the way to it, in the order of the panel', () => {
    assert.deepEqual(everything(variables).map((found) => found.trail.join(' › ')), [
        '$step',
        '$step › Label',
        '$entry',
        '$entry › Title',
        '$entry › Summary',
        '$entry › Cover',
        '$entry › Cover › Alt text',
        '$entry › Cover › URL, large',
        '$entry › Body',
        'Site',
        'Site › Name',
        '$locale',
    ]);
    assert.equal(everything(variables).find((found) => found.trail.join() === '$entry,Summary').note, 'Not in Pages');
});

test('finds by any word of the way, the code or what it is', () => {
    const found = (typed) => matchingFields(variables, typed).map((each) => each.trail.join(' › '));

    assert.deepEqual(found('alt'), ['$entry › Cover › Alt text']);
    assert.deepEqual(found('cover url'), ['$entry › Cover › URL, large']);
    assert.deepEqual(found('COVER'), ['$entry › Cover', '$entry › Cover › Alt text', '$entry › Cover › URL, large']);
    assert.deepEqual(found('rich'), ['$entry › Body']);                    // what it is
    assert.deepEqual(found("get('summary')"), ['$entry › Summary']);        // its code
    assert.deepEqual(found('site name'), ['Site › Name']);
    assert.deepEqual(found('nothing like it'), []);
    assert.deepEqual(found('   '), [], 'nothing typed: the tree is shown, not a list');
});

test('knows what goes where a text goes, and what a click inserts', () => {
    assert.deepEqual(printable(variables).map((found) => found.trail.join(' › ')), ['$step › Label', '$entry › Title', '$entry › Summary', '$entry › Cover › Alt text', '$entry › Cover › URL, large', '$entry › Body', 'Site › Name', '$locale']);
    assert.equal(usual(cover).title, 'Image');
    assert.equal(usual(variables[1]), null, 'an entry is only read into');
});

test('says which field an expression reads', () => {
    assert.deepEqual(boundTo(variables, '$entry->title').trail, ['$entry', 'Title']);
    assert.deepEqual(boundTo(variables, "$entry->get( 'cover' )?->alt").trail, ['$entry', 'Cover', 'Alt text'], 'however it is spaced');
    assert.deepEqual(boundTo(variables, "$step['label'] ?? ''").trail, ['$step', 'Label'], 'as it is written where a text goes');
    assert.deepEqual(boundTo(variables, "$step['label']").trail, ['$step', 'Label'], 'or as it is read');
    assert.equal(boundTo(variables, '$entry->title . "!"'), null);
    // What a loop goes over is said the same way.
    const photos = { handle: 'photos', label: 'Photos', note: null, ...value("$entry->get('photos')", 'list of files', { looped: "$entry->get('photos') ?? []" }) };
    const withList = [{ ...variables[1], groups: [{ title: 'Things', fields: [photos] }] }];
    assert.deepEqual(boundTo(withList, "$entry->get('photos') ?? []").trail, ['$entry', 'Photos']);
    assert.equal(boundTo(variables, ''), null);
    assert.equal(boundTo(null, '$entry->title'), null);
});

test('reads the expression of an attribute that is one echo', () => {
    assert.equal(echoed('{{ $entry->url }}'), '$entry->url');
    assert.equal(echoed(' {{$entry->url}} '), '$entry->url');
    assert.equal(echoed('/articles/{{ $entry->slug }}'), null);
    assert.equal(echoed('{{ $a }} {{ $b }}'), null);
    assert.equal(echoed('plain'), null);
    assert.equal(echoed(null), null);
});

test('writes a field into a text, an echo and an attribute', () => {
    const title = everything(variables).find((found) => found.trail.join() === '$entry,Title').value;
    const body = everything(variables).find((found) => found.trail.join() === '$entry,Body').value;
    const label = everything(variables).find((found) => found.trail.join() === '$step,Label').value;

    // A text: emptied, and the echo put after it. The echo is the last, so it is what is selected then.
    assert.deepEqual(binding({ id: '40', type: 'text' }, title), [
        { type: 'setText', node: '40', text: '' },
        { type: 'insertAfter', node: '40', blade: '{{ $entry->title }}' },
    ]);
    // An echo: what it prints, and as HTML or not as the field wants.
    assert.deepEqual(binding({ id: '50', type: 'atom', atom: 'echo' }, label), [{ type: 'setExpression', node: '50', expression: "$step['label'] ?? ''" }]);
    assert.deepEqual(binding({ id: '50', type: 'atom', atom: 'echo' }, body), [
        { type: 'setExpression', node: '50', expression: "$entry->get('body')" },
        { type: 'setEchoRaw', node: '50', raw: true },
    ]);
    assert.deepEqual(binding({ id: '50', type: 'atom', atom: 'raw_echo' }, title), [
        { type: 'setExpression', node: '50', expression: '$entry->title' },
        { type: 'setEchoRaw', node: '50', raw: false },
    ]);
    // An attribute: always escaped.
    assert.deepEqual(binding({ id: '60', type: 'element' }, body, 'title'), [{ type: 'setAttribute', node: '60', name: 'title', value: "{{ $entry->get('body') }}", blade: true }]);
    // What is no text is written nowhere.
    assert.deepEqual(binding({ id: '40', type: 'text' }, cover), []);
});
