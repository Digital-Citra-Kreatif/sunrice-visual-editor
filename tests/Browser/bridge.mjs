// Runs the bridge script (dist/bridge.js) in a real browser: a stand-in for
// the editor holds a page in a frame, as the editor will, and the checks
// below read what the bridge says and tell it what the editor would.
//
//   npm run build && npm run test:bridge
//
// BROWSER_CHANNEL picks the browser: "chrome" (the default, as on CI) or
// "bundled" for the Chromium that comes with Playwright.
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { chromium } from 'playwright-core';

const fixtures = new URL('./fixtures/', import.meta.url);
const files = {
    '/editor': [new URL('editor.html', fixtures), 'text/html'],
    '/page': [new URL('page.html', fixtures), 'text/html'],
    '/other': [new URL('other.html', fixtures), 'text/html'],
    '/bridge.js': [new URL('../../dist/bridge.js', import.meta.url), 'text/javascript'],
};

// Every address the frame asked for, as the site would see it.
const requested = [];
// The theme's stylesheet: what it says now, and every address it was asked for by.
let stylesheet = '#word { color: rgb(1, 2, 3); }';
const sheetRequests = [];
const server = createServer(async (request, response) => {
    const url = new URL(request.url, 'http://127.0.0.1');
    if (url.pathname === '/sunrice-theme/app.css') {
        sheetRequests.push(url.pathname + url.search);
        // A browser may keep this for an hour: the same address would show the same file.
        response.writeHead(200, { 'Content-Type': 'text/css; charset=utf-8', 'Cache-Control': 'max-age=3600' }).end(stylesheet);

        return;
    }
    const file = files[url.pathname];
    if (!file) {
        response.writeHead(404).end();

        return;
    }
    if (url.pathname !== '/bridge.js' && url.pathname !== '/editor') {
        requested.push(url.pathname + url.search);
    }
    response.writeHead(200, { 'Content-Type': `${file[1]}; charset=utf-8` }).end(await readFile(file[0]));
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const base = `http://127.0.0.1:${server.address().port}`;

const channel = process.env.BROWSER_CHANNEL ?? 'chrome';
const browser = await chromium.launch(channel === 'bundled' ? {} : { channel });
const page = await browser.newPage({ viewport: { width: 1000, height: 600 } });
page.on('pageerror', (error) => console.log(`page error: ${error.message}`));

let failed = false;
const step = async (name, action) => {
    if (failed) {
        return;
    }
    try {
        await action();
        console.log(`ok   ${name}`);
    } catch (error) {
        failed = true;
        console.log(`FAIL ${name}\n     ${String(error.message).split('\n').slice(0, 6).join('\n     ')}`);
    }
};

const frame = () => page.frames().find((candidate) => candidate !== page.mainFrame());
const messages = () => page.evaluate(() => window.messages);
const forget = () => page.evaluate(() => { window.messages = []; });
const tell = (message) => page.evaluate((sent) => window.tell(sent), message);
/** The first message of a type since forget(), waiting for it to arrive. */
const next = async (type, matches = () => true) => {
    const deadline = Date.now() + 3000;
    for (;;) {
        const found = (await messages()).find((message) => message.type === type && matches(message));
        if (found) {
            return found;
        }
        if (Date.now() > deadline) {
            throw new Error(`No "${type}" message arrived. Got: ${JSON.stringify((await messages()).map((message) => message.type))}`);
        }
        await page.waitForTimeout(25);
    }
};
/** An element's box in the frame's viewport, as the browser has it. */
const boxOf = (selector) => frame().locator(selector).evaluate((element) => {
    const box = element.getBoundingClientRect();

    return { x: box.x, y: box.y, width: box.width, height: box.height };
});
const close = (actual, expected) => ['x', 'y', 'width', 'height'].every((side) => Math.abs(actual[side] - expected[side]) < 1);

await step('says it is ready, with the page as a visitor has it and the manifest', async () => {
    await page.goto(`${base}/editor`);
    const ready = await next('ready');

    assert.equal(ready.url, '/page');
    assert.equal(ready.title, 'A page of the site');
    assert.equal(ready.mode, 'select');
    assert.deepEqual(ready.manifest.files.map((file) => file.path), ['show.blade.php', 'partials/item.blade.php', 'layouts/app.blade.php']);
    // What the server answered the page with comes along: the editor says so when a page failed to draw.
    assert.equal(ready.manifest.status, 200);
});

await step('says so again when the editor asks who is there', async () => {
    await forget();
    await tell({ type: 'hello' });
    const ready = await next('ready');

    assert.equal(ready.url, '/page');
    assert.equal(ready.manifest.files.length, 3);
});

await step('says what the pointer is over: the nearest marked element, its box and what is around it', async () => {
    await forget();
    await frame().locator('#word').hover();
    const hover = await next('hover', (message) => message.target?.mark.id === '60');

    assert.deepEqual(hover.target.mark, { file: 'cccccccccc', id: '60' });
    assert.equal(hover.target.tag, 'em');
    assert.ok(close(hover.target.rect, await boxOf('#word')), JSON.stringify(hover.target.rect));
    assert.deepEqual(hover.target.path, [
        { file: 'cccccccccc', id: '60' },
        { file: 'cccccccccc', id: '44' },
        { file: 'aaaaaaaaaa', id: '125' },
        { file: 'aaaaaaaaaa', id: '80' },
        { file: 'aaaaaaaaaa', id: '16' },
    ]);
});

await step('reports an element with no mark as the marked one around it', async () => {
    await forget();
    await frame().locator('#unmarked').hover();
    const hover = await next('hover');

    assert.deepEqual(hover.target.mark, { file: 'aaaaaaaaaa', id: '125' });
    assert.equal(hover.target.tag, 'main');
});

await step('says so once, not for every move inside the same element', async () => {
    await forget();
    await frame().locator('#title').hover({ position: { x: 5, y: 5 } });
    await next('hover');
    await frame().locator('#title').hover({ position: { x: 12, y: 9 } });
    await frame().locator('#title').hover({ position: { x: 20, y: 12 } });
    await page.waitForTimeout(100);

    assert.equal((await messages()).filter((message) => message.type === 'hover').length, 1);
});

await step('picks an element on a click and keeps the page where it is', async () => {
    await forget();
    await frame().locator('#home').click();
    const select = await next('select');

    assert.deepEqual(select.target.mark, { file: 'bbbbbbbbbb', id: '30' });
    assert.equal(select.target.tag, 'a');
    await page.waitForTimeout(150);
    assert.equal(new URL(frame().url()).pathname, '/page');
    assert.deepEqual(requested, ['/page?sve=1']);
});

await step('does not send a form while picking', async () => {
    await frame().locator('#search button').click();
    await page.waitForTimeout(150);

    assert.equal(new URL(frame().url()).pathname, '/page');
    assert.deepEqual(requested, ['/page?sve=1']);
});

await step('measures what the editor asks it to watch: every copy of an element, and what a template drew', async () => {
    await forget();
    await tell({ type: 'watch', watch: { marks: [{ file: 'dddddddddd', id: '0' }, { file: 'cccccccccc', id: '44' }, { file: 'cccccccccc', id: '9999' }], files: ['cccccccccc', 'dddddddddd', 'aaaaaaaaaa'] } });
    const { measured, scroll, page: size } = await next('measured');

    // The answer says what it answers.
    assert.deepEqual(measured.watch.files, ['cccccccccc', 'dddddddddd', 'aaaaaaaaaa']);
    assert.deepEqual(measured.watch.marks.map((mark) => mark.id), ['0', '44', '9999']);
    // The partial was drawn three times; the heading once; a node that isn't on the page, not at all.
    assert.deepEqual(measured.marks.map((rects) => rects.length), [3, 1, 0]);
    assert.ok(close(measured.marks[1][0], await boxOf('#title')));
    assert.ok(measured.marks[0][0].y < measured.marks[0][1].y && measured.marks[0][1].y < measured.marks[0][2].y);
    // The page template drew four elements side by side in <main>; <em> is inside one of them.
    // The item partial: three. The layout: <html> alone, everything else of its own being inside it.
    assert.deepEqual(measured.files.map((rects) => rects.length), [4, 3, 1]);
    assert.deepEqual(scroll, { x: 0, y: 0 });
    assert.ok(size.height > 1400, JSON.stringify(size));
});

await step('measures again when the page scrolls', async () => {
    await forget();
    const before = await boxOf('#title');
    await frame().evaluate(() => window.scrollTo(0, 300));
    const { measured, scroll } = await next('measured', (message) => message.scroll.y === 300);

    assert.equal(scroll.y, 300);
    assert.ok(Math.abs(measured.marks[1][0].y - (before.y - 300)) < 1, JSON.stringify(measured.marks[1][0]));
});

await step('brings an element into view when asked', async () => {
    await forget();
    await tell({ type: 'watch', watch: { marks: [{ file: 'cccccccccc', id: '300' }], files: [] } });
    await tell({ type: 'reveal', mark: { file: 'cccccccccc', id: '300' } });
    const { measured } = await next('measured', (message) => message.scroll.y > 300);

    const box = measured.marks[0][0];
    assert.ok(box.y >= 0 && box.y + box.height <= 500, JSON.stringify(box));
});

await step('ignores what is not the editor\'s to say', async () => {
    await forget();
    // Not in the envelope, and from the page itself rather than the editor.
    await page.evaluate(() => document.getElementById('frame').contentWindow.postMessage({ type: 'watch', watch: { marks: [], files: ['aaaaaaaaaa'] } }, location.origin));
    await frame().evaluate(() => window.postMessage({ sve: 1, message: { type: 'watch', watch: { marks: [], files: ['aaaaaaaaaa'] } } }, location.origin));
    await page.waitForTimeout(150);

    assert.deepEqual((await messages()).filter((message) => message.type === 'measured'), []);
});

await step('hands the editor its keys while picking: undo, redo, delete', async () => {
    await forget();
    await frame().locator('#word').click();
    for (const [keys, expected] of [['Control+z', 'undo'], ['Control+Shift+z', 'redo'], ['Control+y', 'redo'], ['Meta+z', 'undo'], ['Delete', 'delete'], ['Backspace', 'delete']]) {
        await forget();
        await frame().locator('body').press(keys);
        assert.deepEqual(await next('key'), { type: 'key', key: expected }, keys);
    }

    // Other keys are the page's, and so is every key in a field or while browsing.
    await forget();
    await frame().locator('body').press('a');
    await frame().locator('body').press('Control+c');
    await frame().locator('#search input').press('Control+z');
    await frame().locator('#search input').press('Backspace');
    await tell({ type: 'mode', mode: 'browse' });
    await frame().locator('body').press('Control+z');
    await frame().locator('body').press('Delete');
    await tell({ type: 'mode', mode: 'select' });
    await tell({ type: 'hello' });
    await next('ready');
    assert.deepEqual((await messages()).filter((message) => message.type === 'key'), []);
});

await step('follows a link to another page of the site as an editor render when browsing', async () => {
    await forget();
    await tell({ type: 'mode', mode: 'browse' });
    await frame().evaluate(() => window.scrollTo(0, 0));
    await frame().locator('#home').click();
    const ready = await next('ready');

    assert.equal(ready.url, '/other');
    assert.equal(ready.title, 'Another page');
    assert.equal(requested.at(-1), '/other?sve=1');
    // The page it left said so on the way out, with the address a visitor has.
    assert.deepEqual((await messages()).find((message) => message.type === 'leaving'), { type: 'leaving', url: '/page' });
});

await step('sends a search form on to an editor render of its results', async () => {
    await page.goto(`${base}/editor`);
    await next('ready');
    await forget();
    await tell({ type: 'mode', mode: 'browse' });
    await frame().locator('#search button').click();
    const ready = await next('ready');

    assert.equal(ready.url, '/other?q=tea');
    assert.equal(requested.at(-1), '/other?q=tea&sve=1');
});

await step('leaves a link to another site as it is', async () => {
    await page.goto(`${base}/editor`);
    await next('ready');
    await tell({ type: 'mode', mode: 'browse' });
    // Stop the browser from actually going there; the bridge has had its say by then.
    await frame().evaluate(() => document.getElementById('away').addEventListener('click', (event) => event.preventDefault()));
    await frame().locator('#away').click();

    assert.equal(await frame().locator('#away').getAttribute('href'), 'https://example.com/');
});

const links = () => frame().locator('link[rel="stylesheet"]').evaluateAll((all) => all.map((link) => new URL(link.href).pathname + new URL(link.href).search));
const colorIs = async (expected) => {
    const deadline = Date.now() + 3000;
    for (;;) {
        const color = await frame().locator('#word').evaluate((element) => getComputedStyle(element).color);
        if (color === expected) {
            return;
        }
        if (Date.now() > deadline) {
            throw new Error(`The word is ${color}, not ${expected}`);
        }
        await page.waitForTimeout(25);
    }
};

await step('shows a stylesheet of the theme as it is now, and says which of them the page links', async () => {
    await page.goto(`${base}/editor`);
    await next('ready');
    await colorIs('rgb(1, 2, 3)');
    assert.deepEqual(await links(), ['/sunrice-theme/app.css?v=1']);

    // The file changes; its address in the page does not, and the browser may keep what it has.
    stylesheet = '#word { color: rgb(4, 5, 6); }';
    await forget();
    await tell({ type: 'styles', sheets: [{ url: `${base}/sunrice-theme/app.css`, version: 'two' }, { url: `${base}/sunrice-theme/editor.css`, version: 'x' }] });

    assert.deepEqual((await next('styles')).linked, [`${base}/sunrice-theme/app.css`]);
    await colorIs('rgb(4, 5, 6)');
    // One link, as before: what the page added to the address is kept.
    assert.deepEqual(await links(), ['/sunrice-theme/app.css?v=1&sve-v=two']);
    assert.equal(sheetRequests.at(-1), '/sunrice-theme/app.css?v=1&sve-v=two');
});

await step('fetches nothing for a version the page shows already', async () => {
    const before = sheetRequests.length;
    await forget();
    await tell({ type: 'styles', sheets: [{ url: '/sunrice-theme/app.css', version: 'two' }] });
    assert.deepEqual((await next('styles')).linked, ['/sunrice-theme/app.css']);
    await page.waitForTimeout(150);

    assert.equal(sheetRequests.length, before);
    assert.deepEqual(await links(), ['/sunrice-theme/app.css?v=1&sve-v=two']);
});

await step('ends with the last of two versions told one right after the other', async () => {
    stylesheet = '#word { color: rgb(7, 8, 9); }';
    await tell({ type: 'styles', sheets: [{ url: '/sunrice-theme/app.css', version: 'three' }] });
    await tell({ type: 'styles', sheets: [{ url: '/sunrice-theme/app.css', version: 'four' }] });

    await colorIs('rgb(7, 8, 9)');
    const deadline = Date.now() + 3000;
    while ((await links()).length !== 1 && Date.now() < deadline) {
        await page.waitForTimeout(25);
    }
    assert.deepEqual(await links(), ['/sunrice-theme/app.css?v=1&sve-v=four']);
});

await step('says what the page has for an element, and says so again once a stylesheet has been fetched anew', async () => {
    await forget();
    await tell({ type: 'computed', ask: { mark: { file: 'cccccccccc', id: '60' }, properties: ['color', 'display', 'no-such-property'] } });
    const first = await next('computed');

    assert.deepEqual(first.mark, { file: 'cccccccccc', id: '60' });
    assert.deepEqual(first.values, { color: 'rgb(7, 8, 9)', display: 'inline', 'no-such-property': '' });

    stylesheet = '#word { color: rgb(10, 11, 12); display: inline-block; }';
    await forget();
    await tell({ type: 'styles', sheets: [{ url: '/sunrice-theme/app.css', version: 'five' }] });
    const again = await next('computed', (message) => message.values?.color === 'rgb(10, 11, 12)');

    assert.equal(again.values.display, 'inline-block');
});

await step('says what the page has again when the page is shown at another width', async () => {
    stylesheet = '#word { color: rgb(10, 11, 12); } @media (max-width: 500px) { #word { color: rgb(20, 21, 22); } }';
    await tell({ type: 'styles', sheets: [{ url: '/sunrice-theme/app.css', version: 'six' }] });
    await tell({ type: 'computed', ask: { mark: { file: 'cccccccccc', id: '60' }, properties: ['color'] } });
    await next('computed', (message) => message.values?.color === 'rgb(10, 11, 12)');

    await forget();
    await page.evaluate(() => { document.querySelector('iframe').style.width = '400px'; });
    await next('computed', (message) => message.values?.color === 'rgb(20, 21, 22)');
    await page.evaluate(() => { document.querySelector('iframe').style.width = ''; });
    await next('computed', (message) => message.values?.color === 'rgb(10, 11, 12)');
});

await step('says an element is not there, and nothing once nothing is asked', async () => {
    await forget();
    await tell({ type: 'computed', ask: { mark: { file: 'cccccccccc', id: '999' }, properties: ['color'] } });
    assert.equal((await next('computed')).values, null);

    await forget();
    await tell({ type: 'computed', ask: null });
    await tell({ type: 'computed', ask: { mark: 'not a mark', properties: ['color'] } });
    await tell({ type: 'hello' });
    await next('ready');
    assert.equal((await messages()).some((message) => message.type === 'computed'), false);
});

await browser.close();
server.close();
process.exit(failed ? 1 : 0);
