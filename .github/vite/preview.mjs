// The Tailwind preview in a real browser, and then the Build button: no dev
// server, and the CSS not built since a class was added
// (.github/vite-check.sh sets that up, with npm run build as the site's command).
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

/** The header's code, as the code editor beside the page holds it, changed and saved: how a class is given on the screen. */
const saveHeader = async (change) => {
    await frame.locator('.site-header .brand').first().click();
    await page.waitForFunction(() => document.querySelector('.sve-tab--open .sve-tab__name')?.firstChild?.textContent === 'partials/header');
    const code = page.locator('section[aria-label^="Code of"] .sve-code__editor');
    await code.locator('.cm-editor').waitFor();
    await code.evaluate((holder, [from, to]) => {
        const text = holder.sveCodeEditor.text();
        if (!text.includes(from)) {
            throw new Error(`The header has no ${from}`);
        }
        holder.sveCodeEditor.setText(text.replace(from, to));
    }, change);
    await page.locator('section[aria-label^="Code of"] .sve-code__bar').getByRole('button', { name: 'Save', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('section[aria-label^="Code of"] .sve-code__bar .sve-tag') === null);
};

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

await step('say that a build is needed after a class is added, build from the bar, and give the visitor the style', async () => {
    const build = page.locator('.sve-bar__build');
    await build.getByText('Build', { exact: true }).waitFor();

    await saveHeader(['class="brand"', 'class="brand mt-px"']);
    await frame.locator('.site-header .mt-px').first().waitFor();
    await build.getByText('Build needed', { exact: true }).waitFor();

    // The site's own command: npm run build, with Vite and Tailwind.
    const answered = page.waitForResponse((response) => response.url().endsWith('/api/build'), { timeout: 150000 });
    await build.click();
    const answer = await answered;
    const built = await answer.json().catch(() => null);
    if (built?.ok !== true) {
        throw new Error(`The build answered ${answer.status()}: ${built?.message ?? 'nothing to read'}\n${String(built?.output ?? '').split('\n').slice(-4).join('\n')}`);
    }
    await page.getByText(/^Built in [\d.]+ seconds\.$/).waitFor();
    await build.getByText('Build', { exact: true }).waitFor();
    console.log(`     ${await page.getByText(/^Built in [\d.]+ seconds\.$/).textContent()}`);

    const visitor = await browser.newPage();
    await visitor.goto(`${base}/demo`);
    const top = await padding(visitor);
    const margin = await visitor.locator('.site-header .mt-px').first().evaluate((element) => getComputedStyle(element).marginTop);
    await visitor.close();
    if (top !== '36px' || margin !== '1px') {
        throw new Error(`After the build a visitor's header has ${top} of top padding, and what was given mt-px has a top margin of ${margin}`);
    }
});

await browser.close();
