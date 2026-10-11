import assert from 'node:assert/strict';
import { test } from 'node:test';
import { editorAddress, markValue, parseMark, shortcutOf, unwrap, visitorAddress, wrap } from '../../resources/js/bridge/protocol.ts';

test('reads a mark, and nothing else as one', () => {
    assert.deepEqual(parseMark('a1b2c3d4e5:412'), { file: 'a1b2c3d4e5', id: '412' });
    assert.deepEqual(parseMark('0:0'), { file: '0', id: '0' });
    assert.equal(parseMark(''), null);
    assert.equal(parseMark(null), null);
    assert.equal(parseMark(undefined), null);
    assert.equal(parseMark('a1b2c3d4e5'), null);
    assert.equal(parseMark('a1b2c3d4e5:b12'), null);
    assert.equal(parseMark('../x:1'), null);
    assert.equal(parseMark('a1:1"] , body'), null);
});

test('writes a mark the way it is read', () => {
    const mark = { file: 'a1b2c3d4e5', id: '412' };
    assert.equal(markValue(mark), 'a1b2c3d4e5:412');
    assert.deepEqual(parseMark(markValue(mark)), mark);
});

test('turns a page of the site into its editor render', () => {
    const page = 'https://site.test/articles?page=2&sve=1';

    assert.equal(editorAddress('/about', page), 'https://site.test/about?sve=1');
    assert.equal(editorAddress('first-post', 'https://site.test/articles/'), 'https://site.test/articles/first-post?sve=1');
    assert.equal(editorAddress('https://site.test/search?q=tea#results', page), 'https://site.test/search?q=tea&sve=1#results');
    assert.equal(editorAddress('?page=3', page), 'https://site.test/articles?page=3&sve=1');
    // Already one: still one.
    assert.equal(editorAddress('/about?sve=1', page), 'https://site.test/about?sve=1');
    assert.equal(editorAddress('/about?sve=0', page), 'https://site.test/about?sve=1');
});

test('leaves every other address alone', () => {
    const page = 'https://site.test/';

    assert.equal(editorAddress('https://example.com/', page), null);
    assert.equal(editorAddress('//example.com/x', page), null);
    assert.equal(editorAddress('http://site.test/', page), null);
    assert.equal(editorAddress('https://site.test:8443/', page), null);
    assert.equal(editorAddress('mailto:hello@site.test', page), null);
    assert.equal(editorAddress('tel:+62123', page), null);
    assert.equal(editorAddress('javascript:void(0)', page), null);
    assert.equal(editorAddress('/about', 'not an address'), null);
});

test('tells the editor the address a visitor would have', () => {
    assert.equal(visitorAddress('https://site.test/about?sve=1'), '/about');
    assert.equal(visitorAddress('https://site.test/articles?page=2&sve=1#list'), '/articles?page=2#list');
    assert.equal(visitorAddress('https://site.test/?sve=1&q=tea'), '/?q=tea');
    assert.equal(visitorAddress('https://site.test/plain'), '/plain');
});

test('takes only its own messages', () => {
    const message = { type: 'mode', mode: 'browse' };

    assert.deepEqual(unwrap(wrap(message)), message);
    assert.deepEqual(wrap(message), { sve: 1, message });
    assert.equal(unwrap(message), null);
    assert.equal(unwrap({ sve: 2, message }), null);
    assert.equal(unwrap({ sve: 1 }), null);
    assert.equal(unwrap({ sve: 1, message: 'mode' }), null);
    assert.equal(unwrap(null), null);
    assert.equal(unwrap('sve'), null);
    assert.equal(unwrap(undefined), null);
});

test('reads the editor\'s shortcuts from a key press, and nothing typed into a field', () => {
    const press = (key, more = {}) => ({ key, ctrlKey: false, metaKey: false, shiftKey: false, altKey: false, target: { tagName: 'BODY' }, ...more });

    assert.equal(shortcutOf(press('z', { ctrlKey: true })), 'undo');
    assert.equal(shortcutOf(press('z', { metaKey: true })), 'undo');
    assert.equal(shortcutOf(press('Z', { ctrlKey: true, shiftKey: true })), 'redo');
    assert.equal(shortcutOf(press('z', { metaKey: true, shiftKey: true })), 'redo');
    assert.equal(shortcutOf(press('y', { ctrlKey: true })), 'redo');
    assert.equal(shortcutOf(press('Delete')), 'delete');
    assert.equal(shortcutOf(press('Backspace')), 'delete');

    assert.equal(shortcutOf(press('z')), null);
    assert.equal(shortcutOf(press('c', { ctrlKey: true })), null);
    assert.equal(shortcutOf(press('Delete', { shiftKey: true })), null);
    assert.equal(shortcutOf(press('z', { ctrlKey: true, altKey: true })), null);
    assert.equal(shortcutOf(press('ArrowUp', { altKey: true })), null);
    for (const tagName of ['INPUT', 'TEXTAREA', 'SELECT']) {
        assert.equal(shortcutOf(press('z', { ctrlKey: true, target: { tagName } })), null, tagName);
        assert.equal(shortcutOf(press('Backspace', { target: { tagName } })), null, tagName);
    }
    assert.equal(shortcutOf(press('Backspace', { target: { tagName: 'DIV', isContentEditable: true } })), null);
    assert.equal(shortcutOf(press('z', { ctrlKey: true, target: null })), 'undo');
});
