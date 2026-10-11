// Conditions and queries for entries written from choices, and read back into them.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { condition, given, literal, not, orders, readCondition, readWhere, takesValue, testLabel, testsFor, unliteral, writeWhere } from '../../resources/js/lib/builders.ts';

const value = (code, kind, more = {}) => ({ code, kind, what: kind, of: [], inserts: [], print: null, expression: null, groups: [], asked: code, compared: code, looped: null, ...more });
const field = (handle, label, of) => ({ handle, label, note: null, ...of });

const summary = value("$entry->get('summary')", 'text');
const price = value("$entry->get('price')", 'number');
const featured = value("$entry->get('featured')", 'bool');
const madeOn = value("$entry->get('made_on')", 'date');
const cover = value("$entry->get('cover')", 'asset');
const photos = value("$entry->get('photos')", 'list', { what: 'list of files', asked: "collect($entry->get('photos'))->isNotEmpty()", looped: "$entry->get('photos') ?? []" });
const sizes = value("$entry->get('sizes')", 'list', { what: 'list of texts', asked: "collect($entry->get('sizes'))->isNotEmpty()", looped: "$entry->get('sizes') ?? []" });
const label = value("$step['label']", 'text', { asked: "! empty($step['label'])", compared: "($step['label'] ?? null)" });

const variables = [
    { ...value('$step', 'row', { groups: [{ title: 'Fields', fields: [field('label', 'Label', label)] }] }), title: 'Each of $steps', from: 'loop', sure: true },
    {
        ...value('$entry', 'entry', {
            groups: [{ title: 'Things', fields: [field('summary', 'Summary', summary), field('price', 'Price', price), field('featured', 'Featured', featured), field('made_on', 'Made on', madeOn), field('cover', 'Cover', cover), field('photos', 'Photos', photos), field('sizes', 'Sizes', sizes)] }],
        }),
        title: 'The entry',
        from: 'page',
        sure: true,
    },
];

test('offers the tests that make sense for a value, and calls them what they are for it', () => {
    assert.deepEqual(testsFor(summary), ['set', 'unset', 'is', 'isnt', 'contains']);
    assert.deepEqual(testsFor(price), ['set', 'unset', 'is', 'isnt', 'more', 'less']);
    assert.deepEqual(testsFor(featured), ['set', 'unset']);
    assert.deepEqual(testsFor(madeOn), ['set', 'unset', 'past', 'future']);
    assert.deepEqual(testsFor(cover), ['set', 'unset']);
    assert.deepEqual(testsFor(photos), ['set', 'unset']);
    assert.deepEqual(testsFor(sizes), ['set', 'unset', 'has']);

    assert.equal(testLabel('set', summary), 'is set');
    assert.equal(testLabel('set', featured), 'is on');
    assert.equal(testLabel('unset', featured), 'is off');
    assert.equal(testLabel('set', photos), 'has any');
    assert.equal(testLabel('unset', photos), 'has none');
    assert.equal(takesValue('set'), false);
    assert.equal(takesValue('more'), true);
});

test('writes each condition as PHP', () => {
    assert.equal(condition(summary, 'set'), "$entry->get('summary')");
    assert.equal(condition(summary, 'unset'), "! $entry->get('summary')");
    assert.equal(condition(summary, 'is', 'Hello'), "$entry->get('summary') == 'Hello'");
    assert.equal(condition(summary, 'isnt', "it's"), "$entry->get('summary') != 'it\\'s'");
    assert.equal(condition(summary, 'contains', 'sale'), "str_contains((string) $entry->get('summary'), 'sale')");
    assert.equal(condition(price, 'more', '100'), "$entry->get('price') > 100");
    assert.equal(condition(price, 'less', '9.5'), "$entry->get('price') < 9.5");
    assert.equal(condition(price, 'is', ' 12 '), "$entry->get('price') == 12");
    assert.equal(condition(featured, 'unset'), "! $entry->get('featured')");
    assert.equal(condition(madeOn, 'past'), "$entry->get('made_on')?->isPast()");
    assert.equal(condition(madeOn, 'future'), "$entry->get('made_on')?->isFuture()");
    assert.equal(condition(photos, 'set'), "collect($entry->get('photos'))->isNotEmpty()");
    assert.equal(condition(photos, 'unset'), "! collect($entry->get('photos'))->isNotEmpty()");
    assert.equal(condition(sizes, 'has', 'l'), "in_array('l', (array) $entry->get('sizes'))");
    // What is read from an array is asked with empty() and compared with a fallback.
    assert.equal(condition(label, 'set'), "! empty($step['label'])");
    assert.equal(condition(label, 'unset'), "empty($step['label'])");
    assert.equal(condition(label, 'is', 'Cut'), "($step['label'] ?? null) == 'Cut'");
});

test('writes the opposite of a condition as someone would', () => {
    assert.equal(not('$a'), '! $a');
    assert.equal(not('! $a'), '$a');
    assert.equal(not('! empty($a)'), 'empty($a)');
    assert.equal(not('$a && $b'), '! ($a && $b)');
});

test('writes what was typed as a number or a text, and reads it back', () => {
    assert.equal(literal('12'), '12');
    assert.equal(literal('-3.5'), '-3.5');
    assert.equal(literal('true'), 'true');
    assert.equal(literal('twelve'), "'twelve'");
    assert.equal(literal('1 2'), "'1 2'");
    assert.equal(literal("it's a \\ thing"), "'it\\'s a \\\\ thing'");
    for (const typed of ['12', '-3.5', 'true', 'twelve', '1 2', "it's a \\ thing", '']) {
        assert.equal(unliteral(literal(typed)), typed);
    }
    assert.equal(unliteral('$entry->title'), null);
    assert.equal(unliteral("'a' . 'b'"), null);
});

test('reads a condition back into the value, the test and what was typed', () => {
    const read = (code) => {
        const got = readCondition(variables, code);

        return got === null ? null : [got.found.trail.join(' › '), got.test, got.typed];
    };

    assert.deepEqual(read("$entry->get('summary')"), ['$entry › Summary', 'set', '']);
    assert.deepEqual(read("! $entry->get('summary')"), ['$entry › Summary', 'unset', '']);
    assert.deepEqual(read("$entry->get('summary') == 'Hello there'"), ['$entry › Summary', 'is', 'Hello there'], 'spaces in the value are kept');
    assert.deepEqual(read("$entry->get( 'price' )  >  100"), ['$entry › Price', 'more', '100'], 'however it is spaced');
    assert.deepEqual(read("str_contains((string) $entry->get('summary'), 'sale')"), ['$entry › Summary', 'contains', 'sale']);
    assert.deepEqual(read("$entry->get('made_on')?->isPast()"), ['$entry › Made on', 'past', '']);
    assert.deepEqual(read("collect($entry->get('photos'))->isNotEmpty()"), ['$entry › Photos', 'set', '']);
    assert.deepEqual(read("in_array('l', (array) $entry->get('sizes'))"), ['$entry › Sizes', 'has', 'l']);
    assert.deepEqual(read("empty($step['label'])"), ['$step › Label', 'unset', '']);
    assert.deepEqual(read("($step['label'] ?? null) == 'Cut'"), ['$step › Label', 'is', 'Cut']);
    // Every condition it writes, it reads.
    for (const each of [summary, price, featured, madeOn, cover, photos, sizes, label]) {
        for (const test of testsFor(each)) {
            const typed = takesValue(test) ? (each.kind === 'number' ? '42' : 'a b') : '';
            const got = readCondition(variables, condition(each, test, typed));
            assert.deepEqual([got?.found.value.code, got?.test, got?.typed], [each.code, test, typed], `${each.code} ${test}`);
        }
    }
    // What was written by hand stays code.
    assert.equal(read("$entry->get('summary') == $other"), null);
    assert.equal(read("$entry->get('price') > 100 && $entry->get('featured')"), null);
    assert.equal(read('true'), null);
    assert.equal(read(''), null);
    assert.equal(readCondition(null, "$entry->get('summary')"), null);
});

test('reads what a component is given, bound or plain', () => {
    const attributes = [
        { name: 'collection', value: 'articles', dynamic: false, code: false, source: '' },
        { name: ':limit', value: '3', dynamic: false, code: false, source: '' },
        { name: '{{ $attributes }}', value: null, dynamic: true, code: true, source: '' },
    ];

    assert.deepEqual(given(attributes, 'collection'), { value: 'articles', bound: false });
    assert.deepEqual(given(attributes, 'limit'), { value: '3', bound: true });
    assert.equal(given(attributes, 'order-by'), null);
});

test('lists the orders: Sunrice\'s own, then by each field', () => {
    const listed = orders([{ handle: 'price', label: 'Price' }]);

    assert.deepEqual(listed.map((each) => each.value), ['', '-published_at', 'published_at', 'title', '-title', 'manual', 'price', '-price']);
    assert.equal(listed[1].label, 'Newest first');
    assert.equal(listed[7].label, 'Price, highest first');
});

test('writes filters as the where a component is given, and reads them back', () => {
    const rows = [
        { field: 'price', operator: '>', value: '10' },
        { field: 'colour', operator: '=', value: 'blue' },
        { field: 'featured', operator: '=', value: 'true' },
    ];

    assert.equal(writeWhere(rows), "[['price', '>', 10], ['colour', '=', 'blue'], ['featured', '=', true]]");
    assert.deepEqual(readWhere(writeWhere(rows)), rows);
    assert.equal(writeWhere([]), null);
    assert.equal(writeWhere([{ field: ' ', operator: '=', value: 'x' }]), null, 'a filter without a field is none');
    assert.deepEqual(readWhere(''), []);
    assert.deepEqual(readWhere('[]'), []);
    assert.deepEqual(readWhere("[ [ 'price' , '>=' , 10 ] ]"), [{ field: 'price', operator: '>=', value: '10' }]);
    assert.deepEqual(readWhere("[['title', '=', 'a, b]']]"), [{ field: 'title', operator: '=', value: 'a, b]' }]);
    // Written another way: left as it is.
    assert.equal(readWhere("['author' => 3]"), null);
    assert.equal(readWhere('$filters'), null);
    assert.equal(readWhere("[['price', 'between', 10]]"), null);
    assert.equal(readWhere("[['price', '>', $limit]]"), null);
});
