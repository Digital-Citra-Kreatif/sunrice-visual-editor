import assert from 'node:assert/strict';
import { test } from 'node:test';
import { grouped, holderOf, insertion, likelyPlace, matching, placesFor } from '../../resources/js/lib/insert.ts';
import { findNode } from '../../resources/js/lib/tree.ts';

/** A node as the server describes one, with only what these helpers read. */
const node = (id, type, more = {}, children = undefined) => ({
    id,
    type,
    kind: null,
    name: '',
    label: id,
    start: 0,
    end: 0,
    can: { move: true, delete: true, wrap: true, insert: false, attributes: type === 'element' },
    ...more,
    ...(children === undefined ? {} : { children }),
});
const holds = { can: { move: true, delete: true, wrap: true, insert: true, attributes: false } };
const element = (id, children = []) => node(id, 'element', { name: 'div', ...holds }, children);
const empty = (id) => node(id, 'element', { name: 'br' });
const text = (id) => node(id, 'text');
const block = (id, name, arms) => node(id, 'block', { name }, arms);
const arm = (id, name, children = []) => node(id, 'branch', { name, ...holds }, children);

/*
 * <section 0>
 *   <h1 10>text 14</h1>
 *   <div 20></div>
 *   <br 25>
 *   @if 30 … @endif                       one arm, empty
 *   @if 50 … <p 60></p> @else … @endif    two arms
 *   @foreach 90 … <li 100></li> @endforeach
 *   @switch 120 … @case 130 … @endswitch  the part before the first @case holds nothing
 *   <script 140>
 * </section>
 */
const tree = node('root', 'root', holds, [
    element('0', [
        element('10', [text('14')]),
        element('20'),
        empty('25'),
        block('30', 'if', [arm('b30', 'if')]),
        block('50', 'if', [arm('b50', 'if', [element('60')]), arm('b70', 'else')]),
        block('90', 'foreach', [arm('b90', 'foreach', [element('100')])]),
        block('120', 'switch', [node('b120', 'branch', { name: 'switch' }), arm('b130', 'case')]),
        node('140', 'element', { name: 'script' }, []),
    ]),
]);
const at = (id) => findNode(tree, id);

test('says which node takes what is put inside one', () => {
    assert.equal(holderOf(at('20')).id, '20');
    assert.equal(holderOf(at('25')), null, 'an element that holds nothing');
    assert.equal(holderOf(at('14')), null, 'text');
    assert.equal(holderOf(at('30')).id, 'b30', 'a block with one arm: the arm');
    assert.equal(holderOf(at('90')).id, 'b90');
    assert.equal(holderOf(at('50')), null, 'a block with two arms: one of them has to be chosen');
    assert.equal(holderOf(at('b70')).id, 'b70');
    assert.equal(holderOf(at('120')).id, 'b130', 'a @switch with one @case: the case, not the part before it');
    assert.equal(holderOf(at('140')), null, 'a <script> is not for the tree to fill');
});

test('says where something can go by a node', () => {
    assert.deepEqual(placesFor(tree, '10'), { before: true, inside: '10', after: true });
    assert.deepEqual(placesFor(tree, '25'), { before: true, inside: null, after: true });
    assert.deepEqual(placesFor(tree, '14'), { before: true, inside: null, after: true }, 'beside text, not into it');
    assert.deepEqual(placesFor(tree, '30'), { before: true, inside: 'b30', after: true });
    assert.deepEqual(placesFor(tree, '50'), { before: true, inside: null, after: true });
    assert.deepEqual(placesFor(tree, 'b70'), { before: false, inside: 'b70', after: false }, 'an arm has its place: only into it');
    assert.deepEqual(placesFor(tree, '0'), { before: true, inside: '0', after: true }, 'at the top of the template');
    assert.deepEqual(placesFor(tree, 'gone'), { before: false, inside: null, after: false });
    assert.deepEqual(placesFor(null, '10'), { before: false, inside: null, after: false });
});

test('takes the first thing of an empty template with nothing selected, and nothing else', () => {
    const blank = node('root', 'root', holds, []);

    assert.deepEqual(placesFor(blank, null), { before: false, inside: 'root', after: false });
    assert.equal(likelyPlace(blank, null), 'inside');
    assert.deepEqual(insertion(blank, null, 'inside', '<p></p>'), { type: 'append', node: 'root', blade: '<p></p>' });

    assert.deepEqual(placesFor(tree, null), { before: false, inside: null, after: false });
    assert.equal(likelyPlace(tree, null), null);
    assert.equal(insertion(tree, null, 'inside', '<p></p>'), null);
});

test('guesses where the next thing goes: into what is empty, else after', () => {
    assert.equal(likelyPlace(tree, '20'), 'inside', 'an empty element');
    assert.equal(likelyPlace(tree, '30'), 'inside', 'an empty block');
    assert.equal(likelyPlace(tree, '10'), 'after', 'an element with something in it');
    assert.equal(likelyPlace(tree, '90'), 'after', 'a loop with something in it');
    assert.equal(likelyPlace(tree, '25'), 'after');
    assert.equal(likelyPlace(tree, 'b50'), 'inside', 'an arm with something in it: there is nowhere else');
    assert.equal(likelyPlace(tree, 'b70'), 'inside');
});

test('writes the operation for a place', () => {
    assert.deepEqual(insertion(tree, '10', 'before', '<p></p>'), { type: 'insertBefore', node: '10', blade: '<p></p>' });
    assert.deepEqual(insertion(tree, '10', 'after', '<p></p>'), { type: 'insertAfter', node: '10', blade: '<p></p>' });
    assert.deepEqual(insertion(tree, '10', 'inside', '<p></p>'), { type: 'append', node: '10', blade: '<p></p>' });
    assert.deepEqual(insertion(tree, '90', 'inside', '<p></p>'), { type: 'append', node: 'b90', blade: '<p></p>' }, 'into the arm of the loop');
    assert.equal(insertion(tree, '25', 'inside', '<p></p>'), null);
    assert.equal(insertion(tree, 'b70', 'after', '<p></p>'), null);
});

const library = [
    { id: 'element.section', group: 'Elements', title: 'Section', hint: '<section>', blade: '<section></section>', keywords: 'area part block' },
    { id: 'element.list', group: 'Elements', title: 'List', hint: '<ul>', blade: '<ul>\n    <li>Item</li>\n</ul>', keywords: 'bullets items' },
    { id: 'blade.foreach', group: 'Blade', title: 'Loop', hint: '@foreach', blade: '@foreach ([] as $item)\n@endforeach', keywords: 'repeat each list every' },
    { id: 'partial.sunrice.partials.card', group: 'Partials', title: 'partials/card', hint: 'Needs $entry', blade: "@include('sunrice.partials.card')", keywords: 'include template' },
];
const ids = (inserts) => inserts.map((insert) => insert.id);

test('finds what was typed in the title, the hint, the keywords or the Blade', () => {
    assert.deepEqual(ids(matching(library, '')), ids(library));
    assert.deepEqual(ids(matching(library, '   ')), ids(library));
    assert.deepEqual(ids(matching(library, 'sect')), ['element.section']);
    assert.deepEqual(ids(matching(library, 'LIST')), ['element.list', 'blade.foreach'], 'in a title and in keywords, whatever the case');
    assert.deepEqual(ids(matching(library, '@foreach')), ['blade.foreach']);
    assert.deepEqual(ids(matching(library, 'card')), ['partial.sunrice.partials.card']);
    assert.deepEqual(ids(matching(library, '$entry')), ['partial.sunrice.partials.card']);
    assert.deepEqual(ids(matching(library, 'list each')), ['blade.foreach'], 'every word has to be there');
    assert.deepEqual(ids(matching(library, '<li>')), ['element.list'], 'in the Blade itself');
    assert.deepEqual(matching(library, 'nothing like it'), []);
});

test('keeps the groups in the order they come', () => {
    assert.deepEqual(
        grouped(library).map(({ group, inserts }) => [group, ids(inserts)]),
        [
            ['Elements', ['element.section', 'element.list']],
            ['Blade', ['blade.foreach']],
            ['Partials', ['partial.sunrice.partials.card']],
        ],
    );
    assert.deepEqual(grouped([]), []);
});
