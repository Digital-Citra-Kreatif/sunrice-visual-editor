import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isSheet, sheetAddress } from '../../resources/js/bridge/protocol.ts';
import { byFile, declarationsIn, hasOwnRule, layoutOf, resolve, sheetsOf, shiftedId, typedDeclarations } from '../../resources/js/lib/styles.ts';

const app = { path: 'app.css', url: 'http://site.test/sunrice-theme/app.css', version: 'v1', exists: true };
const own = { path: 'editor.css', url: 'http://site.test/sunrice-theme/editor.css', version: 'v0', exists: false };
const styles = {
    class: 'card',
    selector: '.card',
    files: [app],
    rules: [
        { file: 'app.css', id: 100, selector: '.card', within: [], exact: true, line: 5, declarations: [{ id: 108, property: 'padding', value: '20px' }, { id: 123, property: 'color', value: 'red' }] },
        { file: 'app.css', id: 300, selector: '.card h2', within: [], exact: false, line: 9, declarations: [] },
    ],
    more: 0,
    own,
};

test('what is typed into one field is read as the declarations it holds', () => {
    assert.deepEqual(declarationsIn('color: red'), [['color', 'red']]);
    assert.deepEqual(declarationsIn(' color : red ; margin:0;'), [['color', 'red'], ['margin', '0']]);
    assert.deepEqual(declarationsIn('background: url(data:image/png;base64,AAAA) no-repeat; content: "a;b:c"'), [['background', 'url(data:image/png;base64,AAAA) no-repeat'], ['content', '"a;b:c"']]);
    assert.deepEqual(declarationsIn("content: 'it\\'s; fine'; top: 0"), [['content', "'it\\'s; fine'"], ['top', '0']]);
    assert.deepEqual(declarationsIn('--theme: { a: b; c: d }; x: y'), [['--theme', '{ a: b; c: d }'], ['x', 'y']]);
    assert.deepEqual(declarationsIn('color'), [['color', '']]);
    assert.deepEqual(declarationsIn(' ; ;'), []);
});

test('an empty row gives declarations only when it holds whole ones', () => {
    assert.deepEqual(typedDeclarations('color', 'red'), [['color', 'red']]);
    assert.deepEqual(typedDeclarations(' color ', ' red; '), [['color', 'red']]);
    assert.deepEqual(typedDeclarations('color: red; margin: 0', ''), [['color', 'red'], ['margin', '0']]);
    assert.deepEqual(typedDeclarations('color: red; margin', ''), [['color', 'red']]);
    assert.deepEqual(typedDeclarations('color', ''), []);
    assert.deepEqual(typedDeclarations('', 'red'), []);
    // With a value of its own, the property's field is the property's name: the server says what is wrong with it.
    assert.deepEqual(typedDeclarations('a:b', 'c'), [['a:b', 'c']]);
});

test('an edit becomes the changes for the file as it is now', () => {
    assert.deepEqual(resolve(styles, { kind: 'value', rule: 0, index: 0, value: ' 32px; ' }), { file: app, changes: [{ type: 'set', declaration: 108, value: '32px' }] });
    assert.deepEqual(resolve(styles, { kind: 'property', rule: 0, index: 1, property: 'background' }), { file: app, changes: [{ type: 'rename', declaration: 123, property: 'background' }] });
    assert.deepEqual(resolve(styles, { kind: 'remove', rule: 0, index: 1 }), { file: app, changes: [{ type: 'remove', declaration: 123 }] });
    assert.deepEqual(resolve(styles, { kind: 'add', rule: 1, property: 'margin', value: '0' }), { file: app, changes: [{ type: 'add', rule: 300, property: 'margin', value: '0' }] });
    assert.deepEqual(resolve(styles, { kind: 'add', rule: 0, property: 'margin: 0; top: 1px', value: '' }), {
        file: app,
        changes: [
            { type: 'add', rule: 100, property: 'margin', value: '0' },
            { type: 'add', rule: 100, property: 'top', value: '1px' },
        ],
    });
});

test('a row that is emptied takes its declaration out', () => {
    assert.deepEqual(resolve(styles, { kind: 'value', rule: 0, index: 0, value: '  ' }), { file: app, changes: [{ type: 'remove', declaration: 108 }] });
    assert.deepEqual(resolve(styles, { kind: 'property', rule: 0, index: 0, property: '' }), { file: app, changes: [{ type: 'remove', declaration: 108 }] });
});

test('nothing is sent for a row that reads as the file does, or is no longer there', () => {
    assert.equal(resolve(styles, { kind: 'value', rule: 0, index: 0, value: '20px' }), null);
    assert.equal(resolve(styles, { kind: 'property', rule: 0, index: 0, property: ' padding ' }), null);
    assert.equal(resolve(styles, { kind: 'value', rule: 0, index: 7, value: '1px' }), null);
    assert.equal(resolve(styles, { kind: 'value', rule: 9, index: 0, value: '1px' }), null);
    assert.equal(resolve(styles, { kind: 'add', rule: 0, property: 'color', value: '' }), null);
    assert.equal(resolve({ ...styles, files: [] }, { kind: 'remove', rule: 0, index: 0 }), null);
});

test('a class without a rule gets one in the editor’s own stylesheet', () => {
    assert.deepEqual(resolve(styles, { kind: 'create', property: 'color', value: 'red' }), { file: own, changes: [{ type: 'rule', selector: '.card', declarations: [['color', 'red']] }] });
    assert.deepEqual(resolve(styles, { kind: 'create', property: 'a: b; c: d', value: '' }), { file: own, changes: [{ type: 'rule', selector: '.card', declarations: [['a', 'b'], ['c', 'd']] }] });
    assert.equal(resolve({ ...styles, own: null }, { kind: 'create', property: 'color', value: 'red' }), null);
    assert.equal(resolve({ ...styles, selector: null }, { kind: 'create', property: 'color', value: 'red' }), null);
});

test('the rules are listed by file, each knowing its place in the whole list', () => {
    const two = { ...styles, files: [app, { ...own, exists: true }], rules: [styles.rules[0], { ...styles.rules[1], file: 'editor.css' }, { ...styles.rules[1], id: 400 }] };

    assert.deepEqual(
        byFile(two).map((group) => [group.file.path, group.rules.map((each) => each.at)]),
        [['app.css', [0, 2]], ['editor.css', [1]]],
    );
});

test('a class has a rule of its own when one is for the class itself, outside any @media', () => {
    assert.equal(hasOwnRule(styles), true);
    assert.equal(hasOwnRule({ ...styles, rules: [styles.rules[1]] }), false);
    assert.equal(hasOwnRule({ ...styles, rules: [{ ...styles.rules[0], within: ['@media print'] }] }), false);
});

test('the page is told of the stylesheets that are there', () => {
    assert.deepEqual(sheetsOf({ ...styles, files: [app, own] }), [{ url: app.url, version: 'v1' }]);
    assert.deepEqual(sheetsOf(null), []);
});

test('a stylesheet is linked from the page’s own layout, when that can be changed', () => {
    const file = (more) => ({ key: 'k', path: 'layouts/app.blade.php', origin: 'site', view: 'sunrice.layouts.app', role: 'layout', usedBy: [], editable: true, copyTo: null, opens: true, ...more });

    assert.equal(layoutOf([file({ role: 'partial', path: 'partials/header.blade.php' }), file({})])?.path, 'layouts/app.blade.php');
    assert.equal(layoutOf([file({ origin: 'package', editable: false })]), null);
    assert.equal(layoutOf([file({ opens: false })]), null);
    assert.equal(layoutOf(null), null);
});

test('a node after text that was put in moves by what was put in', () => {
    const before = '<head>\n</head>\n<body><p>é</p></body>';
    const after = '<head>\n<link>\n</head>\n<body><p>é</p></body>';

    assert.equal(shiftedId('0', before, after), '0');
    assert.equal(shiftedId('15', before, after), '22');
    assert.equal(shiftedId('21', before, after), '28');
    assert.equal(shiftedId('b21', before, after), 'b21');
    assert.equal(shiftedId(null, before, after), null);
    // Counted in bytes, as the ids are: "é" is two.
    assert.equal(shiftedId('5', 'é<p>', 'éé<p>'), '7');
});

test('a link shows a version of a stylesheet by its address', () => {
    const sheet = { url: 'http://site.test/sunrice-theme/app.css', version: 'abc' };
    const base = 'http://site.test/about?sve=1';

    assert.equal(sheetAddress('http://site.test/sunrice-theme/app.css', sheet, base), 'http://site.test/sunrice-theme/app.css?sve-v=abc');
    assert.equal(sheetAddress('/sunrice-theme/app.css?v=1700000000', sheet, base), 'http://site.test/sunrice-theme/app.css?v=1700000000&sve-v=abc');
    assert.equal(sheetAddress('http://site.test/sunrice-theme/app.css?sve-v=old', sheet, base), 'http://site.test/sunrice-theme/app.css?sve-v=abc');
    // It shows that version already, or is another file.
    assert.equal(sheetAddress('http://site.test/sunrice-theme/app.css?sve-v=abc', sheet, base), null);
    assert.equal(sheetAddress('http://site.test/sunrice-theme/print.css', sheet, base), null);
    assert.equal(sheetAddress('http://[', sheet, base), null);

    assert.equal(isSheet('/sunrice-theme/app.css?v=2', sheet, base), true);
    assert.equal(isSheet('/sunrice-theme/app.min.css', sheet, base), false);
});
