// The Tailwind preview in a real browser: no dev server, and the CSS not
// built since a class was added (.github/vite-check.sh sets that up).
//
//   BASE_URL=http://127.0.0.1:8091 node .github/vite/preview.mjs
import { chromium } from 'playwright-core';

const base = process.env.BASE_URL ?? 'http://127.0.0.1:8091';
const browser = await chromium.launch({ channel: process.env.BROWSER_CHANNEL ?? 'chrome' });
const page = await browser.newPage({ viewport: { width: 1440, height: 860 } });
const thrown = [];
page.on('pageerror', (error) => thrown.push(error.message));

const step = async (name, action) => {
    try {
        await action();
        console.log(`ok   ${name}`);
    } catch (error) {
        console.log(`FAIL ${name}\n     ${String(error.message).split('\n').slice(0, 6).join('\n     ')}\n     at ${page.url()}`);
        for (const each of thrown.slice(-5)) {
            console.log(`     thrown on the page: ${each}`);
        }
        await browser.close();
        process.exit(1);
    }
};

const frame = page.frameLocator('iframe.sve-frame');
/** The top padding of the site's header: the class the check added is `pt-9`, which is 36 pixels. */
const padding = (where) => where.locator('header.site-header').first().evaluate((element) => getComputedStyle(element).paddingTop);

await step('sign in', async () => {
    await page.goto(`${base}/cms/login`);
    await page.fill('#email', 'admin@example.com');
    await page.fill('#password', 'password');
    await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/login')), page.click('button[type=submit]')]);
});

await step('show a class the build has no rule for, in the editor, and say that it is a preview', async () => {
    await page.goto(`${base}/cms/visual-editor?url=/demo`);
    await page.waitForSelector('.sve-template');
    await frame.locator('header.site-header.pt-9').first().waitFor();
    let now = '';
    for (let attempt = 0; attempt < 60 && now !== '36px'; attempt++) {
        now = await padding(frame).catch(() => '');
        await page.waitForTimeout(250);
    }
    if (now !== '36px') {
        throw new Error(`In the editor the header's top padding is ${now}`);
    }
    await page.locator('.sve-bar__preview', { hasText: 'Preview CSS' }).waitFor();
});

await step('give a visitor the built CSS and nothing else', async () => {
    const visitor = await browser.newPage();
    await visitor.goto(`${base}/demo`);
    await visitor.locator('header.site-header.pt-9').first().waitFor();
    await visitor.waitForTimeout(1500);
    const seen = await padding(visitor);
    const added = await visitor.locator('[data-sve-preview], script[src*="tailwindcss/browser"]').count();
    await visitor.close();
    if (seen === '36px' || added !== 0) {
        throw new Error(`A visitor's header has ${seen} of top padding, and ${added} of the preview's elements`);
    }
});

await browser.close();
