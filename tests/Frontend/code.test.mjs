import assert from 'node:assert/strict';
import { test } from 'node:test';
import { coloured, indentOf, lineBreaksOf, MAX_COLOURED } from '../../resources/js/code/text.ts';

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
