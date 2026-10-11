import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isAdminPath, sitePath } from '../../resources/js/lib/path.ts';

const origin = 'https://site.test';

test('keeps a path of the site', () => {
    assert.equal(sitePath('/about', origin), '/about');
    assert.equal(sitePath('/articles?page=2', origin), '/articles?page=2');
});

test('reads a bare word as a path', () => {
    assert.equal(sitePath('about', origin), '/about');
    assert.equal(sitePath('  articles/first-post  ', origin), '/articles/first-post');
});

test('opens the homepage for an empty address', () => {
    assert.equal(sitePath('', origin), '/');
    assert.equal(sitePath('   ', origin), '/');
});

test('accepts the site\'s own full address', () => {
    assert.equal(sitePath('https://site.test/about#team', origin), '/about');
});

test('refuses any other site', () => {
    assert.equal(sitePath('https://example.com/', origin), null);
    assert.equal(sitePath('//example.com/x', origin), null);
    assert.equal(sitePath('javascript:alert(1)', origin), null);
    assert.equal(sitePath('http://site.test/', origin), null);
});

test('refuses what a browser would read as another site once it has dropped tabs and line breaks', () => {
    assert.equal(sitePath('/\t/example.com', origin), null);
    assert.equal(sitePath('/\n/example.com/x', origin), null);
    assert.equal(sitePath('/\r/example.com', origin), null);
    assert.equal(sitePath('/\\example.com', origin), null);
    // A tab inside a path of the site is dropped like anywhere else, and the path stays the site's.
    assert.equal(sitePath('/abo\tut', origin), '/about');
});

test('knows the admin from the site', () => {
    assert.equal(isAdminPath('/cms', '/cms'), true);
    assert.equal(isAdminPath('/cms/users?page=2', '/cms'), true);
    assert.equal(isAdminPath('/cms/', 'cms/'), true);
    assert.equal(isAdminPath('/cmsx', '/cms'), false);
    assert.equal(isAdminPath('/about/cms', '/cms'), false);
    assert.equal(isAdminPath('/about?next=/cms', '/cms'), false);
    assert.equal(isAdminPath('/', '/cms'), false);
    // A site whose admin has another address.
    assert.equal(isAdminPath('/control/pages', '/control'), true);
    assert.equal(isAdminPath('/cms', '/control'), false);
    // An admin at the root would leave no site to edit; nothing is refused for it.
    assert.equal(isAdminPath('/about', '/'), false);
});
