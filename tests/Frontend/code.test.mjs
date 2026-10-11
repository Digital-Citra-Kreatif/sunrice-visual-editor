import assert from 'node:assert/strict';
import { test } from 'node:test';
import { byteOfIndex, coloured, difference, indentOf, indexOfByte, lineBreaksOf, MAX_COLOURED } from '../../resources/js/code/text.ts';
import { nodeAt } from '../../resources/js/lib/tree.ts';

test('knows how the lines of a text end', () => {
    assert.equal(lineBreaksOf(''), 'unix');
    assert.equal(lineBreaksOf('one line'), 'unix');
    assert.equal(lineBreaksOf('a\nb\n'), 'unix');
    assert.equal(lineBreaksOf('a\r\nb\r\n'), 'windows');
    assert.equal(lineBreaksOf('a\r\nb'), 'windows');
    // Some of each, or a return on its own: neither the one nor the other.
    assert.equal(lineBreaksOf('a\r\nb\nc'), 'mixed');
    assert.equal(lineBreaksOf('a\rb\r\n'), 'mixed');
    assert.equal(lineBreaksOf('a\rb'), 'mixed');
});

test('indents by what the file itself uses', () => {
    assert.equal(indentOf('<div>\n  <p>x</p>\n</div>'), '  ');
    assert.equal(indentOf('<div>\n\t<p>x</p>\n</div>'), '\t');
    assert.equal(indentOf('<div>\n        <p>x</p>\n</div>'), '        ');
    assert.equal(indentOf('<p>nothing is indented</p>'), '    ');
});

test('says where the colours are, by place in the text', () => {
    const text = '<p class="a">{{ $x }}</p>\n@if ($y)\n@endif\n';
    const found = coloured(text);

    // Every stretch is where it says it is, in order, none over another.
    let before = 0;
    for (const { from, to } of found) {
        assert.ok(from >= before && to > from && to <= text.length);
        before = to;
    }
    const kinds = (kind) => found.filter((each) => each.kind === kind).map(({ from, to }) => text.slice(from, to));
    assert.ok(kinds('directive').includes('@if'));
    assert.ok(kinds('directive').includes('@endif'));
    assert.ok(kinds('string').includes('"a"'));
    assert.ok(kinds('php').some((php) => php.includes('$x')));
    assert.ok(found.every((each) => each.kind !== 'text'));
});

test('gives a very long text no colours, and still shows it', () => {
    assert.deepEqual(coloured('x'.repeat(MAX_COLOURED + 1)), []);
    assert.notDeepEqual(coloured(`@if (true)\n${'x'.repeat(1000)}\n@endif`), []);
});

test('turns a place counted in bytes into one counted in characters, and back', () => {
    const text = 'a é 日本 😀 z';
    for (let index = 0; index <= text.length; index++) {
        // Half of a pair of surrogates is no place of its own.
        if (index > 0 && text.charCodeAt(index - 1) >= 0xd800 && text.charCodeAt(index - 1) <= 0xdbff) {
            continue;
        }
        assert.equal(indexOfByte(text, byteOfIndex(text, index)), index, `at ${index}`);
    }
    assert.equal(byteOfIndex(text, text.length), new TextEncoder().encode(text).length);
    assert.equal(indexOfByte('abc', 0), 0);
    assert.equal(indexOfByte('abc', 99), 3);
    assert.equal(indexOfByte('é', 2), 1);
});

test('finds the one change between two texts', () => {
    const apply = (before, { from, to, insert }) => before.slice(0, from) + insert + before.slice(to);
    for (const [before, after] of [
        ['abc', 'abc'],
        ['abc', 'abXc'],
        ['abc', 'ac'],
        ['', 'new'],
        ['gone', ''],
        ['aaa', 'aaaa'],
        ['<p>one</p>\n<p>two</p>', '<p>one</p>\n<h1>x</h1>\n<p>two</p>'],
    ]) {
        const change = difference(before, after);
        assert.equal(apply(before, change), after, `${before} → ${after}`);
        assert.ok(change.from <= change.to && change.to <= before.length);
    }
    assert.deepEqual(difference('same', 'same'), { from: 4, to: 4, insert: '' });
});

test('finds the innermost node a place in the file is in', () => {
    const tree = {
        id: 'root', start: 0, end: 40, children: [
            { id: '0', start: 0, end: 10 },
            { id: '12', start: 12, end: 38, children: [{ id: '16', start: 16, end: 30, children: [{ id: '20', start: 20, end: 25 }] }] },
        ],
    };
    assert.equal(nodeAt(tree, 5).id, '0');
    assert.equal(nodeAt(tree, 22).id, '20');
    assert.equal(nodeAt(tree, 27).id, '16');
    assert.equal(nodeAt(tree, 13).id, '12');
    // Between everything else, and at a node's very end: the root.
    assert.equal(nodeAt(tree, 11).id, 'root');
    assert.equal(nodeAt(tree, 38).id, 'root');
    assert.equal(nodeAt(null, 3), null);
});
