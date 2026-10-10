import assert from 'node:assert/strict';
import { test } from 'node:test';
import { boxesOf, findNode, kindOf, pathTo, rows, shownChildren } from '../../resources/js/lib/tree.ts';

/** A node as the server describes one, with only what these helpers read. */
const node = (id, type, more = {}, children = undefined) => ({
    id,
    type,
    kind: type === 'element' ? 'visual' : null,
    name: '',
    label: id,
    start: 0,
    end: 0,
    can: { move: true, delete: true, wrap: true, insert: false, attributes: type === 'element' },
    ...more,
    ...(children === undefined ? {} : { children }),
});
const element = (id, children = []) => node(id, 'element', { name: 'div' }, children);
const text = (id) => node(id, 'text', { kind: 'visual' });
const echo = (id) => node(id, 'atom', { kind: 'field', atom: 'echo' });
const block = (id, name, arms) => node(id, 'block', { kind: 'visual', name, atom: 'directive' }, arms);
const arm = (id, name, children = []) => node(id, 'branch', { name }, children);

/*
 * <section 0>
 *   <h1 10> {{ title 14 }} </h1>
 *   @if 30 … <p 40>yes 43</p> @else … <p 60>no</p> <p 70>really</p> @endif
 *   @foreach 90 … <li 100><a 104>link 107</a></li> @endforeach
 *   text 130
 * </section>
 * {{-- comment 150 --}}
 */
const tree = node('root', 'root', {}, [
    element('0', [
        element('10', [echo('14')]),
        block('30', 'if', [arm('b30', 'if', [element('40', [text('43')])]), arm('b50', 'else', [element('60', []), element('70', [])])]),
        block('90', 'foreach', [arm('b90', 'foreach', [element('100', [element('104', [text('107')])])])]),
        text('130'),
    ]),
    node('150', 'atom', { atom: 'comment' }),
]);

test('finds a node, and the way down to it', () => {
    assert.equal(findNode(tree, '104').id, '104');
    assert.equal(findNode(tree, 'b50').name, 'else');
    assert.equal(findNode(tree, 'root').type, 'root');
    assert.equal(findNode(tree, '999'), null);
    assert.equal(findNode(tree, null), null);
    assert.equal(findNode(null, '0'), null);

    assert.deepEqual(pathTo(tree, '107').map((each) => each.id), ['root', '0', '90', 'b90', '100', '104', '107']);
    assert.deepEqual(pathTo(tree, '999'), []);
});

test('lists a block with one arm without the arm, and one with several by its arms', () => {
    assert.deepEqual(shownChildren(findNode(tree, '90')).map((each) => each.id), ['100']);
    assert.deepEqual(shownChildren(findNode(tree, '30')).map((each) => each.id), ['b30', 'b50']);
    assert.deepEqual(shownChildren(findNode(tree, '0')).map((each) => each.id), ['10', '30', '90', '130']);

    // A @switch: the part before its first @case holds nothing and is left out.
    const choice = block('200', 'switch', [arm('b200', 'switch'), arm('b210', 'case', [element('220')]), arm('b230', 'default', [element('240')])]);
    assert.deepEqual(shownChildren(choice).map((each) => each.id), ['b210', 'b230']);
    // With one case left, that case is still shown: it is not the block's own line.
    const one = block('300', 'switch', [arm('b300', 'switch'), arm('b310', 'case', [element('320')])]);
    assert.deepEqual(shownChildren(one).map((each) => each.id), ['320']);
});

test('lays the tree out as rows, top to bottom', () => {
    assert.deepEqual(
        rows(tree).map((row) => `${row.depth}:${row.node.id}${row.parent ? '+' : ''}`),
        ['0:0+', '1:10+', '2:14', '1:30+', '2:b30+', '3:40+', '4:43', '2:b50+', '3:60', '3:70', '1:90+', '2:100+', '3:104+', '4:107', '1:130', '0:150'],
    );
    assert.deepEqual(rows(null), []);
});

test('leaves out what is under a collapsed row', () => {
    assert.deepEqual(
        rows(tree, new Set(['30', '100'])).map((row) => row.node.id),
        ['0', '10', '14', '30', '90', '100', '130', '150'],
    );
    assert.deepEqual(rows(tree, new Set(['0'])).map((row) => row.node.id), ['0', '150']);
});

test('shows an element on the page as itself', () => {
    assert.deepEqual(boxesOf(tree, '104'), { ids: ['104'], inside: false });
});

test('shows a block, an arm or the whole template as the outermost elements inside', () => {
    assert.deepEqual(boxesOf(tree, '30'), { ids: ['40', '60', '70'], inside: false });
    assert.deepEqual(boxesOf(tree, 'b50'), { ids: ['60', '70'], inside: false });
    assert.deepEqual(boxesOf(tree, '90'), { ids: ['100'], inside: false });
    assert.deepEqual(boxesOf(tree, 'root'), { ids: ['0'], inside: false });
});

test('shows text and echoes as the element around them', () => {
    assert.deepEqual(boxesOf(tree, '14'), { ids: ['10'], inside: true });
    assert.deepEqual(boxesOf(tree, '107'), { ids: ['104'], inside: true });
    assert.deepEqual(boxesOf(tree, '130'), { ids: ['0'], inside: true });
});

test('shows nothing for what has no element in it or around it, or isn\'t there', () => {
    assert.deepEqual(boxesOf(tree, '150'), { ids: [], inside: false });
    assert.deepEqual(boxesOf(tree, '999'), { ids: [], inside: false });
    assert.deepEqual(boxesOf(tree, null), { ids: [], inside: false });
    assert.deepEqual(boxesOf(null, '0'), { ids: [], inside: false });
});

test('says in a word what a node is', () => {
    assert.equal(kindOf(element('1')), 'element');
    assert.equal(kindOf(text('1')), 'text');
    assert.equal(kindOf(echo('1')), 'field');
    assert.equal(kindOf(node('1', 'atom', { kind: 'label', atom: 'echo' })), 'label');
    assert.equal(kindOf(node('1', 'atom', { kind: 'expression', atom: 'echo' })), 'expression');
    assert.equal(kindOf(block('1', 'if', [])), 'condition');
    assert.equal(kindOf(block('1', 'switch', [])), 'condition');
    assert.equal(kindOf(block('1', 'forelse', [])), 'loop');
    assert.equal(kindOf(block('1', 'section', [])), 'directive');
    assert.equal(kindOf(node('1', 'block', { kind: 'code', name: 'while', atom: 'directive' })), 'code');
    assert.equal(kindOf(node('1', 'block', { kind: 'visual', name: 'sunrice::form', atom: 'component' })), 'component');
    assert.equal(kindOf(arm('b1', 'else')), 'arm');
    assert.equal(kindOf(node('1', 'atom', { kind: 'visual', name: 'include', atom: 'directive' })), 'include');
    assert.equal(kindOf(node('1', 'atom', { kind: 'visual', name: 'includeWhen', atom: 'directive' })), 'include');
    assert.equal(kindOf(node('1', 'atom', { kind: 'visual', name: 'csrf', atom: 'directive' })), 'directive');
    assert.equal(kindOf(node('1', 'atom', { kind: 'code', name: 'php', atom: 'directive' })), 'code');
    assert.equal(kindOf(node('1', 'atom', { kind: 'visual', name: 'card', atom: 'component' })), 'component');
    assert.equal(kindOf(node('1', 'atom', { atom: 'comment' })), 'comment');
    assert.equal(kindOf(node('1', 'comment')), 'comment');
    assert.equal(kindOf(node('1', 'atom', { kind: 'code', name: 'div', atom: 'tag', stray: '<div> is not closed here' })), 'stray');
});
