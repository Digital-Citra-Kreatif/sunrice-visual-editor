// The editor in a real browser on a site whose CSS is Tailwind served by
// Vite's dev server (.github/vite-check.sh starts both and runs this).
//
//   BASE_URL=http://127.0.0.1:8091 node .github/vite/browser.mjs
import { chromium } from 'playwright-core';

const base = process.env.BASE_URL ?? 'http://127.0.0.1:8091';
const browser = await chromium.launch({ channel: process.env.BROWSER_CHANNEL ?? 'chrome' });
const page = await browser.newPage({ viewport: { width: 1440, height: 860 } });
page.on('pageerror', (error) => console.log(`page error: ${error.message}`));

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
const idle = () => page.waitForFunction(() => document.querySelector('.sve-shell')?.getAttribute('aria-busy') !== 'true');

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
const none = (colour) => colour === 'rgba(0, 0, 0, 0)' || colour === 'transparent';
/** The background of an element of the page in the frame, asked until it is a colour. */
const coloured = async (selector) => {
    let last = '';
    for (let attempt = 0; attempt < 60; attempt++) {
        last = await frame
            .locator(selector)
            .first()
            .evaluate((element) => getComputedStyle(element).backgroundColor)
            .catch((error) => `no answer: ${String(error.message).split('\n')[0]}`);
        if (/^(rgb|oklch|oklab|lab|lch|color)/.test(last) && !none(last)) {
            return last;
        }
        await page.waitForTimeout(500);
    }
    throw new Error(`The background of ${selector} is ${last}`);
};

await step('sign in', async () => {
    await page.goto(`${base}/cms/login`);
    await page.fill('#email', 'admin@example.com');
    await page.fill('#password', 'password');
    await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/login')), page.click('button[type=submit]')]);
});

await step('show a page whose CSS comes from the dev server, marked for the editor', async () => {
    await page.goto(`${base}/cms/visual-editor?url=/demo`);
    await page.waitForSelector('.sve-template');
    await frame.locator('script[src*="@vite/client"]').first().waitFor({ state: 'attached' });
    await frame.locator('[data-sve]').first().waitFor({ state: 'attached' });
});

await step('add a Tailwind class no rule was written for yet, and see its colour on the page', async () => {
    await saveHeader(['class="brand"', 'class="brand bg-sky-400"']);
    await frame.locator('.site-header .bg-sky-400').first().waitFor();
    console.log(`     it is ${await coloured('.site-header .bg-sky-400')}`);
});

await step('keep the editor in step with the page while Vite reloads it: the template stays open, the page stays marked', async () => {
    await idle();
    // Vite reloads a page whose Blade file changed, on top of the editor drawing it again.
    await page.waitForTimeout(2500);
    await idle();
    await frame.locator('.site-header .bg-sky-400[data-sve]').first().waitFor({ state: 'attached' });
    await page.waitForFunction(() => document.querySelector('.sve-tab--open .sve-tab__name')?.firstChild?.textContent === 'partials/header');
    await frame.locator('.site-header .brand').first().click();
    await page.locator('.sve-layer[aria-selected="true"]').waitFor();
    await page.locator('.sve-outline--selected').first().waitFor();
});

await step('take the class off, and it goes from the page', async () => {
    await saveHeader(['class="brand bg-sky-400"', 'class="brand"']);
    await idle();
    for (let attempt = 0; attempt < 40 && (await frame.locator('.site-header .bg-sky-400').count()) > 0; attempt++) {
        await page.waitForTimeout(250);
    }
    if ((await frame.locator('.site-header .bg-sky-400').count()) > 0) {
        throw new Error('The class is still on the page.');
    }
});

await browser.close();
