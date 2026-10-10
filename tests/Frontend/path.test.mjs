import assert from 'node:assert/strict';
import { test } from 'node:test';
import { sitePath } from '../../resources/js/lib/path.ts';

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
