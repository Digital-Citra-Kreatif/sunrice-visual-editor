import assert from 'node:assert/strict';
import { test } from 'node:test';
import { STYLE_GROUPS, STYLE_PROPERTIES, declared, declaredAt, hexOf, shown, targetRule } from '../../resources/js/lib/styleFields.ts';
import { resolve } from '../../resources/js/lib/styles.ts';

const app = { path: 'app.css', url: '/sunrice-theme/app.css', version: 'v1', exists: true };
const own = { path: 'editor.css', url: '/sunrice-theme/editor.css', version: 'v0', exists: false };
const rule = (more) => ({ file: 'app.css', id: 100, selector: '.card', within: [], exact: true, line: 5, declarations: [], ...more });
const styles = (rules, more = {}) => ({ class: 'card', selector: '.card', files: [app], rules, more: 0, own, ...more });
const card = rule({ declarations: [{ id: 108, property: 'padding', value: '20px' }, { id: 123, property: 'color', value: 'red' }, { id: 140, property: 'Color', value: 'blue' }] });

test('every property has one control, and the page is asked about each', () => {
    assert.equal(new Set(STYLE_PROPERTIES).size, STYLE_PROPERTIES.length);
    assert.deepEqual(STYLE_GROUPS.map((group) => group.title), ['Layout', 'Size', 'Spacing', 'Text', 'Background', 'Border']);
    for (const control of STYLE_GROUPS.flatMap((group) => group.controls)) {
        assert.equal(control.kind === 'choice', Array.isArray(control.options), control.property);
    }
});

test('the controls write the rule for the class itself, outside any @media: the last of them', () => {
    assert.equal(targetRule(styles([card])), 0);
    assert.equal(targetRule(styles([rule({ exact: false, selector: '.card h2' }), card, rule({ within: ['@media print'] })])), 1);
    assert.equal(targetRule(styles([card, rule({ file: 'editor.css', id: 0 })])), 1);
    assert.equal(targetRule(styles([rule({ exact: false }), rule({ within: ['@media print'] })])), null);
    assert.equal(targetRule(styles([])), null);
});

test('what a rule says for a property is what it says last, however the property is written', () => {
    assert.equal(declared(card, 'color'), 'blue');
    assert.equal(declaredAt(card, 'color'), 2);
    assert.equal(declared(card, 'padding'), '20px');
    assert.equal(declared(card, 'margin'), null);
    assert.equal(declared(null, 'margin'), null);
});

test('controls for flex and grid show only for an element laid out so', () => {
    const direction = STYLE_GROUPS[0].controls.find((control) => control.property === 'flex-direction');
    const columns = STYLE_GROUPS[0].controls.find((control) => control.property === 'grid-template-columns');
    const gap = STYLE_GROUPS[0].controls.find((control) => control.property === 'gap');
    const flex = rule({ declarations: [{ id: 1, property: 'display', value: 'inline-flex' }] });

    assert.equal(shown(gap, null, null), true);
    assert.equal(shown(direction, null, null), false);
    assert.equal(shown(direction, flex, null), true);
    assert.equal(shown(columns, flex, null), false);
    // By what the page has, when the rule says nothing.
    assert.equal(shown(direction, card, { display: 'flex' }), true);
    assert.equal(shown(columns, card, { display: 'grid' }), true);
    // The rule counts over the page: it is about to be so.
    assert.equal(shown(direction, rule({ declarations: [{ id: 1, property: 'display', value: 'block' }] }), { display: 'flex' }), false);
    // One that is set shows, whatever the layout: it is there to be taken out.
    assert.equal(shown(direction, rule({ declarations: [{ id: 1, property: 'flex-direction', value: 'column' }] }), { display: 'block' }), true);
});

test('a colour is handed to the picker only when it is a plain one', () => {
    assert.equal(hexOf('#FFF'), '#ffffff');
    assert.equal(hexOf(' #2563eb '), '#2563eb');
    assert.equal(hexOf('rgb(24, 24, 27)'), '#18181b');
    assert.equal(hexOf('rgb(24 24 27)'), '#18181b');
    assert.equal(hexOf('rgba(0, 0, 0, 1)'), '#000000');
    assert.equal(hexOf('rgba(0, 0, 0, 0)'), null);
    assert.equal(hexOf('rgb(0 0 0 / 50%)'), null);
    assert.equal(hexOf('rgb(300, 0, 0)'), null);
    assert.equal(hexOf('var(--accent)'), null);
    assert.equal(hexOf('red'), null);
    assert.equal(hexOf(''), null);
    assert.equal(hexOf(null), null);
});

test('a control sets the declaration that is there, adds one that is not, and takes out one that is emptied', () => {
    const now = styles([card]);

    assert.deepEqual(resolve(now, { kind: 'style', property: 'padding', value: ' 32px ' }), { file: app, changes: [{ type: 'set', declaration: 108, value: '32px' }] });
    // The last of two: the one that counts.
    assert.deepEqual(resolve(now, { kind: 'style', property: 'color', value: 'green' }), { file: app, changes: [{ type: 'set', declaration: 140, value: 'green' }] });
    assert.deepEqual(resolve(now, { kind: 'style', property: 'display', value: 'flex' }), { file: app, changes: [{ type: 'add', rule: 100, property: 'display', value: 'flex' }] });
    assert.deepEqual(resolve(now, { kind: 'style', property: 'padding', value: '' }), { file: app, changes: [{ type: 'remove', declaration: 108 }] });
    // Nothing to do: it says so already, or says nothing and is to say nothing.
    assert.equal(resolve(now, { kind: 'style', property: 'padding', value: '20px' }), null);
    assert.equal(resolve(now, { kind: 'style', property: 'display', value: '' }), null);
});

test('the first thing set for a class without a rule writes one into the editor’s own stylesheet', () => {
    const none = styles([rule({ exact: false, selector: '.card h2' })]);

    assert.deepEqual(resolve(none, { kind: 'style', property: 'padding', value: '8px' }), { file: own, changes: [{ type: 'rule', selector: '.card', declarations: [['padding', '8px']] }] });
    assert.equal(resolve(none, { kind: 'style', property: 'padding', value: '' }), null);
    assert.equal(resolve({ ...none, own: null }, { kind: 'style', property: 'padding', value: '8px' }), null);
});

test('a control writes the last rule for the class, where what it says counts', () => {
    const two = styles([card, rule({ file: 'editor.css', id: 0, declarations: [{ id: 8, property: 'padding', value: '4px' }] })], { files: [app, { ...own, exists: true }] });

    assert.deepEqual(resolve(two, { kind: 'style', property: 'padding', value: '6px' }), { file: { ...own, exists: true }, changes: [{ type: 'set', declaration: 8, value: '6px' }] });
    assert.deepEqual(resolve(two, { kind: 'style', property: 'color', value: 'green' }), { file: { ...own, exists: true }, changes: [{ type: 'add', rule: 0, property: 'color', value: 'green' }] });
});
