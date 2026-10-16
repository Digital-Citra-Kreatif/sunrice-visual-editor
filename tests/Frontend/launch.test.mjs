import assert from 'node:assert/strict';
import { test } from 'node:test';
import { editorUrl, entryPath, pathOn } from '../../resources/js/lib/launch.ts';

const origin = 'https://site.test';
const may = { visualEditor: { open: true } };
const entry = (translations) => ({ ...may, mainLocale: 'en', entry: { translations } });

test('reads a public address as a path of this site', () => {
    assert.equal(pathOn('https://site.test/about?x=1', origin), '/about?x=1');
    assert.equal(pathOn('/news/hello', origin), '/news/hello');
    assert.equal(pathOn('https://elsewhere.test/about', origin), null);
    assert.equal(pathOn('', origin), null);
    assert.equal(pathOn(null, origin), null);
    assert.equal(pathOn('http://[', origin), null);
});

test('opens on the page the admin\'s own View page link goes to', () => {
    const props = entry({ en: { url: 'https://site.test/about' }, id: { url: 'https://site.test/id/tentang' } });
    assert.equal(entryPath('Entries/Edit', props, origin, 'https://site.test/id/tentang'), '/id/tentang');
});

test('else on the entry\'s page in the main language, or in any', () => {
    assert.equal(entryPath('Entries/Edit', entry({ id: { url: '/id/tentang' }, en: { url: '/about' } }), origin), '/about');
    assert.equal(entryPath('Entries/Edit', entry({ en: { url: null }, id: { url: '/id/tentang' } }), origin), '/id/tentang');
});

test('offers nothing where there is nothing to open, or nobody to open it', () => {
    assert.equal(entryPath('Entries/Index', entry({ en: { url: '/about' } }), origin), null);
    assert.equal(entryPath('Entries/Edit', { ...entry({ en: { url: '/about' } }), visualEditor: null }, origin), null);
    assert.equal(entryPath('Entries/Edit', { mainLocale: 'en', entry: { translations: { en: { url: '/about' } } } }, origin), null);
    // A new entry, and a collection without pages of its own.
    assert.equal(entryPath('Entries/Edit', { ...may, entry: null }, origin), null);
    assert.equal(entryPath('Entries/Edit', entry({ en: { url: null } }), origin), null);
    // Another site's address is never opened.
    assert.equal(entryPath('Entries/Edit', entry({ en: { url: 'https://elsewhere.test/about' } }), origin, 'https://elsewhere.test/about'), null);
});

test('makes the editor\'s address under the admin\'s path', () => {
    assert.equal(editorUrl((path) => `/cms/${path}`, '/about?x=1'), '/cms/visual-editor?url=%2Fabout%3Fx%3D1');
});
