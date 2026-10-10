// Opens the editor in a real browser against the running workbench
// (`composer serve`, or .github/workbench-smoke.sh in CI) and saves a
// screenshot to docs/screenshots/.
//
//   BASE_URL=http://127.0.0.1:8000 npm run test:browser
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright-core';

const base = process.env.BASE_URL ?? 'http://127.0.0.1:8089';
const browser = await chromium.launch({ channel: process.env.BROWSER_CHANNEL ?? 'chrome' });
const page = await browser.newPage({ viewport: { width: 1440, height: 860 } });

const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
page.on('console', (message) => {
    if (message.type() === 'error') {
        console.log(`console error: ${message.text()}`);
    }
});

const step = async (name, action) => {
    try {
        await action();
        console.log(`ok   ${name}`);
    } catch (error) {
        console.log(`FAIL ${name}\n     ${String(error.message).split('\n')[0]}\n     at ${page.url()}`);
        await browser.close();
        process.exit(1);
    }
};

const frame = page.frameLocator('iframe.sve-frame');
const open = async (address) => {
    await page.fill('#sve-address', address);
    await page.click('.sve-address button[type=submit]');
};

await step('sign in as the workbench admin', async () => {
    await page.goto(`${base}/cms/login`);
    await page.fill('#email', 'admin@example.com');
    await page.fill('#password', 'password');
    await Promise.all([
        page.waitForURL((url) => !url.pathname.endsWith('/login')),
        page.click('button[type=submit]'),
    ]);
});

await step('reach the editor from the admin sidebar', async () => {
    await page.getByRole('link', { name: 'Visual editor' }).first().click();
    await page.waitForSelector('.sve-shell');
});

await step('show the homepage in the frame', async () => {
    await frame.getByText('Templates you can see').waitFor();
});

await step('open another page from the address field', async () => {
    await open('about');
    await frame.getByText('A page with a rich text body and no blocks.').waitFor();
});

await step('refuse an address on another site', async () => {
    await open('https://example.com/');
    await page.getByRole('alert').waitFor();
});

await step('open on the page named in the link', async () => {
    await page.goto(`${base}/cms/visual-editor?url=/articles`);
    await frame.getByRole('link', { name: 'Fifth post', exact: true }).waitFor();
});

// --- The templates of a page ----------------------------------------------------

const listed = () => page.locator('.sve-panel--left .sve-templates').first().locator('.sve-template__name').allTextContents();
const openIs = (name) => page.waitForFunction((expected) => document.querySelector('.sve-trail__here')?.textContent === expected, name);
const addressIs = (path) => page.waitForFunction((expected) => document.querySelector('#sve-address')?.value === expected, path);

await step('list the templates that drew the homepage, the layout first', async () => {
    await page.goto(`${base}/cms/visual-editor`);
    await page.waitForSelector('.sve-template');
    const names = await listed();
    for (const name of ['layouts/app', 'show', 'partials/header', 'partials/footer', 'blocks/hero', 'blocks/text']) {
        if (!names.includes(name)) {
            throw new Error(`"${name}" is not in the list: ${names.join(', ')}`);
        }
    }
    if (names[0] !== 'layouts/app') {
        throw new Error(`The list starts with "${names[0]}"`);
    }
});

await step('open the page\'s own template to begin with', async () => {
    await openIs('show');
});

await step('outline the template under the pointer, with its name', async () => {
    await frame.locator('.site-header .brand').hover();
    await page.waitForSelector('.sve-outline--hover');
    const label = await page.locator('.sve-outline--hover .sve-outline__label').first().textContent();
    if (label !== 'partials/header') {
        throw new Error(`The outline is labelled "${label}"`);
    }
});

await step('open the header when it is clicked on the page, and stay on the page', async () => {
    await frame.locator('.site-header .brand').click();
    await openIs('partials/header');
    await page.locator('.sve-facts').getByText('partials/header.blade.php').waitFor();
    await frame.getByText('Templates you can see').waitFor();
});

await step('go back to the page\'s template in one click', async () => {
    await page.locator('.sve-trail__back').click();
    await openIs('show');
});

await step('outline a template when its row is pointed at, and open it on a click', async () => {
    const row = page.locator('.sve-panel--left .sve-templates').first().locator('.sve-template', { hasText: 'blocks/hero' });
    await row.hover();
    await page.locator('.sve-outline--hover .sve-outline__label', { hasText: 'blocks/hero' }).waitFor();
    await row.click();
    await openIs('blocks/hero');
});

await step('follow a link when browsing, with the address and the list following', async () => {
    await page.locator('.sve-modes__mode', { hasText: 'Browse' }).click();
    await frame.getByRole('link', { name: 'Articles', exact: true }).first().click();
    await addressIs('/articles');
    await openIs('index');
    await frame.getByRole('link', { name: 'Fifth post', exact: true }).waitFor();
    // An editor render all the way: the page in the frame still carries marks.
    await frame.locator('[data-sve]').first().waitFor({ state: 'attached' });
});

await step('open a template that is on another page, and bring that page', async () => {
    await page.locator('.sve-others summary').click();
    await page.locator('.sve-others .sve-template', { hasText: 'blocks/hero' }).click();
    await addressIs('/');
    await openIs('blocks/hero');
    await frame.getByText('Templates you can see').waitFor();
});

await step('show one of Sunrice\'s own views as read-only', async () => {
    await page.locator('.sve-others .sve-template', { hasText: 'defaults/draft-banner' }).click();
    await openIs('defaults/draft-banner');
    await page.locator('.sve-note').getByText('one of Sunrice’s own views').waitFor();
});

// --- The code panel --------------------------------------------------------------

const code = page.locator('.sve-code__field');

await step('open the footer as code, coloured and character for character', async () => {
    await page.goto(`${base}/cms/visual-editor`);
    await page.waitForSelector('.sve-template');
    await page.locator('.sve-panel--left .sve-templates').first().locator('.sve-template', { hasText: 'partials/footer' }).click();
    await openIs('partials/footer');
    await page.getByRole('button', { name: 'Edit as code' }).click();
    await code.waitFor();
    const source = await code.inputValue();
    if (!source.includes('<footer')) {
        throw new Error('The field does not hold the footer');
    }
    if ((await page.locator('.sve-code__colours').textContent()) !== `${source}\n`) {
        throw new Error('The colours are not the text');
    }
    if ((await page.locator('.sve-code__colours .sve-code--directive').count()) === 0) {
        throw new Error('No directive is coloured');
    }
});

await step('save a change to the footer and see it on the page', async () => {
    await code.fill(`${await code.inputValue()}\n<p class="sve-browser-test">Edited in the code panel.</p>\n`);
    await page.locator('.sve-code__bar').getByText('Not saved').waitFor();
    await code.press('Control+s');
    await frame.getByText('Edited in the code panel.').waitFor();
    await page.waitForFunction(() => document.querySelector('.sve-code__bar .sve-tag') === null);
});

await step('refuse code that doesn\'t compile, say why, and leave the site as it was', async () => {
    const good = await code.inputValue();
    await code.fill(`${good}\n@if ($neverClosed)\n`);
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.locator('.sve-code__trouble').getByText('doesn’t compile').waitFor();
    // Nothing was written: the page still renders, with the last good footer.
    const home = await page.request.get(`${base}/`);
    if (home.status() !== 200 || !(await home.text()).includes('Edited in the code panel.')) {
        throw new Error(`The homepage answered ${home.status()} after a refused save`);
    }
    await page.getByRole('button', { name: 'Revert' }).click();
    await page.waitForFunction((expected) => document.querySelector('.sve-code__field')?.value === expected, good);
});

await step('put the footer back', async () => {
    await code.fill((await code.inputValue()).replace('\n<p class="sve-browser-test">Edited in the code panel.</p>\n', ''));
    await code.press('Control+s');
    await page.waitForFunction(() => document.querySelector('.sve-code__bar .sve-tag') === null);
    await page.getByRole('button', { name: 'Hide the code' }).click();
});

await step('follow a missing page like any other: the error page is drawn by the site too', async () => {
    await open('no-such-page-anywhere');
    await addressIs('/no-such-page-anywhere');
    // Its templates, or word that it has none of the site's: either way the panel knows the page.
    await page.locator('.sve-panel--left .sve-templates, .sve-panel--left .sve-empty:has-text("No template of the site")').first().waitFor();
});

await step('say so when what is in the frame is no page of the site', async () => {
    // A file the web server hands out itself: nothing renders it, so nothing marks it.
    await open('vendor/sunrice-visual-editor/bridge.js');
    await page.locator('.sve-panel--left .sve-empty').getByText('not drawn for the editor').waitFor();
});

await step('refuse the admin\'s own address', async () => {
    await open('/cms/users');
    await page.getByRole('alert').getByText('admin').waitFor();
});

await step('take a screenshot', async () => {
    await page.goto(`${base}/cms/visual-editor`);
    await frame.getByText('Templates you can see').waitFor();
    await page.waitForSelector('.sve-template');
    await frame.getByText('Templates you can see').hover();
    await page.locator('.sve-outline--hover .sve-outline__label', { hasText: 'blocks/hero' }).waitFor();
    await mkdir('docs/screenshots', { recursive: true });
    await page.screenshot({ path: 'docs/screenshots/editor.png' });
});

await browser.close();

if (errors.length > 0) {
    console.log(`FAIL the page threw errors:\n     ${errors.join('\n     ')}`);
    process.exit(1);
}
