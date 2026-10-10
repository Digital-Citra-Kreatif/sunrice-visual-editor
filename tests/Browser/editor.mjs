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

await step('take a screenshot', async () => {
    await page.goto(`${base}/cms/visual-editor`);
    await frame.getByText('Templates you can see').waitFor();
    await mkdir('docs/screenshots', { recursive: true });
    await page.screenshot({ path: 'docs/screenshots/editor.png' });
});

await browser.close();

if (errors.length > 0) {
    console.log(`FAIL the page threw errors:\n     ${errors.join('\n     ')}`);
    process.exit(1);
}
