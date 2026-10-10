import assert from 'node:assert/strict';
import { test } from 'node:test';
import { argumentsOf, attribute, classesOf, headOf, joinInclude, joinLoop, sourceOf, splitInclude, splitLoop } from '../../resources/js/lib/settings.ts';

const attr = (name, value, more = {}) => ({ name, value, dynamic: false, code: false, source: value === null ? name : `${name}="${value}"`, ...more });
const element = (attributes) => ({ id: '0', type: 'element', attributes });

test('reads an element\'s classes as words when the attribute is plain text', () => {
    assert.deepEqual(classesOf(element([attr('class', 'hero  wide\n dark')])), ['hero', 'wide', 'dark']);
    assert.deepEqual(classesOf(element([attr('class', '')])), []);
    assert.deepEqual(classesOf(element([attr('id', 'top')])), []);
    assert.deepEqual(classesOf(element([attr('class', null)])), []);
});

test('leaves classes Blade has a hand in to be edited as written', () => {
    assert.equal(classesOf(element([attr('class', 'card {{ $size }}', { dynamic: true })])), null);
    assert.equal(classesOf(element([attr('@class', null, { code: true, source: "@class(['on' => $a])" })])), null);
});

test('finds an attribute written as one, not one a directive produces', () => {
    const node = element([attr('href', '/about'), attr('@if', null, { code: true })]);

    assert.equal(attribute(node, 'href').value, '/about');
    assert.equal(attribute(node, '@if'), null);
    assert.equal(attribute(node, 'title'), null);
    assert.equal(attribute({ id: '0', type: 'text' }, 'href'), null);
});

test('cuts a node\'s text out of the file by bytes', () => {
    const source = '<p>Café — <b>thé</b></p>';
    const bytes = (text) => new TextEncoder().encode(text).length;
    const start = bytes('<p>Café — ');

    assert.equal(sourceOf(source, { start, end: start + bytes('<b>thé</b>') }), '<b>thé</b>');
    assert.equal(sourceOf(source, { start: 0, end: bytes(source) }), source);
    assert.equal(sourceOf('<i>x</i>', { start: 3, end: 4 }), 'x');
});

test('reads a loop as what is looped over and what each one is called', () => {
    assert.deepEqual(splitLoop('$entries as $entry'), { source: '$entries', item: '$entry' });
    assert.deepEqual(splitLoop("sunrice_menu('main') as \$key => \$item"), { source: "sunrice_menu('main')", item: '$key => $item' });
    assert.deepEqual(splitLoop(' $block->images  as  $image '), { source: '$block->images', item: '$image' });
    // The last " as " is the loop's own.
    assert.deepEqual(splitLoop("$things->where('kind as given', 1) as $thing"), { source: "$things->where('kind as given', 1)", item: '$thing' });
    assert.equal(splitLoop('$i = 0; $i < 3; $i++'), null);
    assert.equal(splitLoop(null), null);
    assert.equal(joinLoop(' $entries ', ' $entry'), '$entries as $entry');
});

test('reads an include as a view and the data passed to it', () => {
    assert.deepEqual(splitInclude("'sunrice.partials.card', ['entry' => $entry]"), { view: 'sunrice.partials.card', data: "['entry' => $entry]" });
    assert.deepEqual(splitInclude('"sunrice.partials.header"'), { view: 'sunrice.partials.header', data: '' });
    // A view worked out by PHP is not a plain name: it is edited as written.
    assert.equal(splitInclude("'sunrice.blocks.'.$block->type, ['block' => $block]"), null);
    assert.equal(splitInclude('$view'), null);
    assert.equal(splitInclude(null), null);

    assert.equal(joinInclude('sunrice.partials.card', "['entry' => $entry]"), "'sunrice.partials.card', ['entry' => $entry]");
    assert.equal(joinInclude(' sunrice.partials.header ', '  '), "'sunrice.partials.header'");
    // A quote typed into the name can't end the name.
    assert.equal(joinInclude("a'.system('id').'b", ''), "'a.system(id).b'");
});

test('finds where a block keeps its condition or loop', () => {
    const arm = { id: 'b5', type: 'branch', arguments: '$a' };
    const block = { id: '5', type: 'block', children: [arm, { id: 'b20', type: 'branch', arguments: null }] };

    assert.equal(headOf(block), arm);
    assert.equal(headOf(arm), arm);
    assert.equal(headOf({ id: '1', type: 'element' }), null);
    assert.equal(headOf({ id: '1', type: 'block', children: [] }), null);
    assert.equal(argumentsOf(arm), '$a');
    assert.equal(argumentsOf(block.children[1]), null);
    assert.equal(argumentsOf(null), null);
});
