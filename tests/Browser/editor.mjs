// Opens the editor in a real browser against the running workbench
// (`composer serve`, or .github/workbench-smoke.sh in CI) and saves a
// screenshot to docs/screenshots/.
//
//   BASE_URL=http://127.0.0.1:8000 npm run test:browser
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright-core';

const base = process.env.BASE_URL ?? 'http://127.0.0.1:8000';
const browser = await chromium.launch(process.env.BROWSER_PATH ? { executablePath: process.env.BROWSER_PATH } : { channel: process.env.BROWSER_CHANNEL ?? 'chrome' });
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
        // The first lines say what failed and what was being waited for.
        // FAIL_SHOT=path/to.png: what the editor looked like when it failed.
        if (process.env.FAIL_SHOT) {
            await page.screenshot({ path: process.env.FAIL_SHOT }).catch(() => {});
        }
        console.log(`FAIL ${name}\n     ${String(error.message).split('\n').slice(0, 7).join('\n     ')}\n     at ${page.url()}`);
        // Something thrown on the page is often why: said here, since nothing after this is run.
        for (const thrown of errors.slice(-5)) {
            console.log(`     thrown on the page: ${thrown}`);
        }
        await browser.close();
        process.exit(1);
    }
};

const until = async (what, check) => {
    let last;
    for (let tries = 0; tries < 50; tries++) {
        last = await check();
        if (last === true) {
            return;
        }
        await page.waitForTimeout(100);
    }
    throw new Error(`${what}: ${JSON.stringify(last)}`);
};

const frame = page.frameLocator('iframe.sve-frame');
const open = async (address) => {
    await page.fill('#sve-address', address);
    await page.click('.sve-address button[type=submit]');
};
const bar = page.locator('.sve-bar');
const idle = () => page.waitForFunction(() => document.querySelector('.sve-shell')?.getAttribute('aria-busy') !== 'true');
const signIn = async (email) => {
    await page.context().clearCookies();
    await page.goto(`${base}/admin/login`);
    await page.fill('#email', email);
    await page.fill('#password', 'Sunrice-Demo-2026!');
    await Promise.all([
        page.waitForURL((url) => !url.pathname.endsWith('/login')),
        page.click('button[type=submit]'),
    ]);
};

await step('sign in as the workbench admin', async () => {
    await signIn('admin@example.com');
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
    await page.goto(`${base}/admin/visual-editor?url=/articles`);
    await frame.getByRole('link', { name: 'Fifth post', exact: true }).waitFor();
});

// --- Three parts: the tree, the page, the code ---------------------------------------

const code = page.locator('section[aria-label^="Code of"] .sve-code__editor');
/** The code editor's text, asked of the editor itself: it draws only the lines that are in view. */
const codeText = () => code.evaluate((holder) => holder.sveCodeEditor.text());
const onDisk = async (path) => (await (await page.request.get(`${base}/admin/visual-editor/api/templates/${path}`)).json()).template.source;
const codeIs = async (expected) => {
    for (let tries = 0; tries < 50 && (await codeText()) !== expected; tries++) {
        await page.waitForTimeout(100);
    }
    if ((await codeText()) !== expected) {
        throw new Error(`The editor holds ${JSON.stringify((await codeText()).slice(-90))}`);
    }
};
const codeBar = page.locator('section[aria-label^="Code of"] .sve-code__bar');
const saved = () => page.waitForFunction(() => document.querySelector('section[aria-label^="Code of"] .sve-code__bar .sve-tag') === null);
const tabNames = () => page.locator('.sve-tab__name').allTextContents();
const codeTab = (name) => page.locator('.sve-tab', { has: page.locator('.sve-tab__name', { hasText: new RegExp(`^${name}$`) }) });
const openIs = (name) => page.waitForFunction((expected) => document.querySelector('.sve-tab--open .sve-tab__name')?.firstChild?.textContent === expected && document.querySelector('.sve-template[aria-current="true"] .sve-template__name, .sve-others .sve-template[aria-current="true"] .sve-template__name') !== null, name);
const addressIs = (path) => page.waitForFunction((expected) => document.querySelector('#sve-address')?.value === expected, path);
const listed = () => page.locator('.sve-side--left .sve-templates').first().locator('.sve-template__name').allTextContents();
const treeTab = (name) => page.locator('.sve-side--left .sve-code-tabs__tab', { hasText: name });
/** The left side's Templates tab, where the templates are listed: picking one brings its Layers. */
const showTemplates = () => treeTab('Templates').click();
const treeShows = (name) => page.waitForFunction((wanted) => document.querySelector('.sve-side--left .sve-code-tabs__tab[aria-selected="true"]')?.textContent?.trim() === wanted, name);

await step('show the tree on the left, the page in the middle and the code on the right', async () => {
    await page.goto(`${base}/admin/visual-editor`);
    await page.waitForSelector('.sve-layer');
    await code.locator('.cm-editor').waitFor();
    const tree = await page.locator('.sve-side--left').boundingBox();
    const shown = await page.locator('iframe.sve-frame').boundingBox();
    const coded = await page.locator('.sve-side--code').boundingBox();
    if (tree === null || shown === null || coded === null || !(tree.x + tree.width <= shown.x + 1 && shown.x + shown.width <= coded.x + 1)) {
        throw new Error(`The tree is at ${JSON.stringify(tree)}, the page at ${JSON.stringify(shown)}, the code at ${JSON.stringify(coded)}`);
    }
    // Nothing else beside the page: no settings, no insert, no styles.
    for (const gone of ['Settings', 'Insert', 'Style']) {
        if ((await page.getByRole('region', { name: gone, exact: true }).count()) !== 0 || (await bar.getByRole('button', { name: gone, exact: true }).count()) !== 0) {
            throw new Error(`The editor still has ${gone}`);
        }
    }
});

await step('list the templates that drew the homepage, the layout first', async () => {
    const names = await listed();
    for (const name of ['layout', 'collections/show', 'partials/blocks', 'partials/menu', 'blocks/hero', 'blocks/text']) {
        if (!names.includes(name)) {
            throw new Error(`"${name}" is not in the list: ${names.join(', ')}`);
        }
    }
    if (names[0] !== 'layout') {
        throw new Error(`The list starts with "${names[0]}"`);
    }
});

await step('show the layers on the left to begin with, the templates a tab away, and the layers again once a template is picked', async () => {
    await treeShows('Layers');
    await page.locator('.sve-layer').first().waitFor();
    if (await page.locator('.sve-side--left .sve-templates').first().isVisible()) {
        throw new Error('The templates are shown beside the layers');
    }
    await showTemplates();
    await treeShows('Templates');
    await page.locator('.sve-side--left .sve-templates').first().locator('.sve-template', { hasText: 'blocks/text' }).click();
    await treeShows('Layers');
    await openIs('blocks/text');
    // And when something is clicked on the page.
    await showTemplates();
    await frame.locator('.site-header .site-name').click();
    await treeShows('Layers');
    await openIs('layout');
    // Back to the page's own template, as the editor opened.
    await showTemplates();
    await page.locator('.sve-side--left .sve-templates').first().locator('.sve-template', { hasText: 'collections/show' }).click();
    await openIs('collections/show');
});

await step('open the page\'s own template to begin with, in the code too', async () => {
    await openIs('collections/show');
    await codeIs(await onDisk('collections/show.blade.php'));
});

await step('outline the template under the pointer, with its name', async () => {
    await frame.locator('.site-header .site-name').hover();
    await page.waitForSelector('.sve-outline--hover');
    const label = await page.locator('.sve-outline--hover .sve-outline__label').first().textContent();
    if (label !== 'layout') {
        throw new Error(`The outline is labelled "${label}"`);
    }
});

await step('open the header when it is clicked on the page, and stay on the page', async () => {
    await frame.locator('.site-header .site-name').click();
    await openIs('layout');
    await codeIs(await onDisk('layout.blade.php'));
    await frame.getByText('Templates you can see').waitFor();
});

await step('outline a template when its row is pointed at, and open it on a click', async () => {
    await showTemplates();
    const row = page.locator('.sve-side--left .sve-templates').first().locator('.sve-template', { hasText: 'blocks/hero' });
    await row.hover();
    await page.locator('.sve-outline--hover .sve-outline__label', { hasText: 'blocks/hero' }).waitFor();
    await row.click();
    await openIs('blocks/hero');
});

await step('follow a link when browsing, with the address and the list following', async () => {
    await page.locator('.sve-modes__mode', { hasText: 'Browse' }).click();
    // Beside the tree and the code the page is narrow: its menu is behind its Menu button.
    const menu = frame.getByRole('button', { name: 'Menu' });
    if (await menu.isVisible()) {
        await menu.click();
    }
    await frame.getByRole('link', { name: 'Articles', exact: true }).first().click();
    await addressIs('/articles');
    await openIs('collections/index');
    await frame.getByRole('link', { name: 'Fifth post', exact: true }).waitFor();
    // An editor render all the way: the page in the frame still carries marks.
    await frame.locator('[data-sve]').first().waitFor({ state: 'attached' });
    await page.locator('.sve-modes__mode', { hasText: 'Select' }).click();
});

await step('open a template that is on another page, and bring that page', async () => {
    await showTemplates();
    await page.locator('.sve-others summary').click();
    await page.locator('.sve-others .sve-template', { hasText: 'blocks/hero' }).click();
    await addressIs('/');
    await openIs('blocks/hero');
    await frame.getByText('Templates you can see').waitFor();
});

await step('show the CMS\'s own views as read-only: a fallback with a way to copy it to the site, a view of its own with why not', async () => {
    await showTemplates();
    await page.locator('.sve-others .sve-template').filter({ has: page.locator('.sve-template__name', { hasText: /^listing$/ }) }).click();
    await openIs('listing');
    await codeBar.getByText('Read-only').waitFor();
    await page.locator('.sve-code__banner').getByText('used while the site has no collections/index.blade.php').waitFor();
    await page.locator('.sve-code__banner').getByRole('button', { name: 'Copy to site' }).waitFor();

    await showTemplates();
    await page.locator('.sve-others .sve-template').filter({ has: page.locator('.sve-template__name', { hasText: /^draft-banner$/ }) }).click();
    await openIs('draft-banner');
    await page.locator('.sve-code__banner').getByText('The CMS never looks for it among the site’s templates').waitFor();
    if ((await page.locator('.sve-code__banner').getByRole('button', { name: 'Copy to site' }).count()) !== 0) {
        throw new Error('A view the CMS never looks for in the site is offered to be copied there');
    }
});

// --- The tree, and the page ----------------------------------------------------------

const layerIs = (label) => page.waitForFunction((expected) => document.querySelector('.sve-layer[aria-selected="true"] .sve-layer__label')?.textContent === expected, label);
const layerRow = (label, nth = 0) => page.locator('.sve-layer').filter({ has: page.locator('.sve-layer__label', { hasText: new RegExp(`^${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`) }) }).nth(nth);
const outlineIs = (kind, label) => page.waitForFunction(([wanted, expected]) => [...document.querySelectorAll(`.sve-outline--${wanted} .sve-outline__label`)].some((each) => each.textContent === expected), [kind, label]);
/** Checks that an outline lies exactly over an element of the page in the frame. */
const outlineOver = async (kind, selector) => {
    const outline = await page.locator(`.sve-outline--${kind}`).first().boundingBox();
    const element = await frame.locator(selector).first().boundingBox();
    if (outline === null || element === null || ['x', 'y', 'width', 'height'].some((side) => Math.abs(outline[side] - element[side]) > 1.5)) {
        throw new Error(`The outline is at ${JSON.stringify(outline)}, the element at ${JSON.stringify(element)}`);
    }
};
/** The outline of what is selected comes to lie over the element: once the page has scrolled to it and measured again. */
const outlineSettles = (selector) =>
    until(`The outline is not on ${selector}`, async () => {
        try {
            await outlineOver('selected', selector);

            return true;
        } catch (error) {
            return error.message;
        }
    });
const cursorLine = () => code.locator('.cm-activeLine').first().textContent();
const cursorLineHas = (text) => until(`The cursor is not on a line with ${text}`, async () => ((await cursorLine()) ?? '').includes(text) || (await cursorLine()));
const heroApi = `${base}/admin/visual-editor/api/templates/blocks/hero.blade.php`;
const heroNow = async () => {
    await idle();

    return (await (await page.request.get(heroApi)).json()).template.source;
};
let heroCode = '';

await step('select an element by clicking it on the page: its template opens, its row is selected, the code is on it', async () => {
    await page.goto(`${base}/admin/visual-editor`);
    await page.waitForSelector('.sve-template', { state: 'attached' });
    heroCode = await onDisk('blocks/hero.blade.php');
    await frame.locator('.hero h1').click();
    await openIs('blocks/hero');
    await layerIs('h1');
    await outlineIs('selected', 'h1');
    await outlineOver('selected', '.hero h1');
    await codeIs(heroCode);
    await cursorLineHas('<h1>{{ $block->heading }}</h1>');
    if (!(await page.evaluate(() => document.activeElement?.closest('.cm-editor') !== null))) {
        throw new Error('The keyboard is not in the code');
    }
});

await step('select a row of the tree: the page shows it, and the code goes to it', async () => {
    await page.locator('.sve-layer__pick', { hasText: 'a.button' }).click();
    await layerIs('a.button');
    await outlineIs('selected', 'a.button');
    await outlineOver('selected', '.hero a.button');
    await cursorLineHas('class="button"');
});

await step('point at a row, and the page outlines it; point at the page, and the row lights up', async () => {
    await page.locator('.sve-layer__pick', { hasText: 'p.muted' }).hover();
    await outlineIs('hover', 'p.muted');
    await frame.locator('.hero h1').hover();
    await page.waitForFunction(() => document.querySelector('.sve-layer--lit .sve-layer__label')?.textContent === 'h1');
});

await step('show a field, which has no box of its own, by the element around it', async () => {
    await page.locator('.sve-layer__pick', { hasText: '$block->heading' }).click();
    await page.waitForSelector('.sve-outline--around');
    await outlineOver('around', '.hero h1');
});

await step('show a node inside a loop once for every turn of the loop', async () => {
    await open('/articles');
    await openIs('collections/index');
    await frame.locator('.card p.muted').nth(1).click();
    await openIs('partials/card');
    await layerIs('p.muted');
    await page.waitForFunction(() => document.querySelectorAll('.sve-outline--selected').length === 5);
});

await step('scroll the page to a row of the tree that is out of sight, and outline it there', async () => {
    await page.setViewportSize({ width: 1440, height: 520 });
    await page.goto(`${base}/admin/visual-editor`);
    await page.waitForSelector('.sve-template', { state: 'attached' });
    await showTemplates();
    await page.locator('.sve-side--left .sve-templates').first().locator('.sve-template', { hasText: 'layout' }).click();
    await openIs('layout');
    await page.waitForFunction(() => document.querySelector('iframe.sve-frame').contentWindow.scrollY === 0);
    await layerRow('p.muted').last().locator('.sve-layer__pick').click();
    await until('The page did not scroll to the footer', async () => (await page.evaluate(() => document.querySelector('iframe.sve-frame').contentWindow.scrollY)) > 100 || (await page.evaluate(() => document.querySelector('iframe.sve-frame').contentWindow.scrollY)));
    await outlineSettles('.site-footer p.muted');
    await page.setViewportSize({ width: 1440, height: 860 });
});

// --- Changes from the tree, and undo -----------------------------------------------------

/** Waits for something on the page in the frame that is seen: asked of that frame each time, as the editor swaps its two frames after a change. */
const onPage = (selector) => page.waitForFunction((wanted) => (document.querySelector('iframe.sve-frame')?.contentDocument?.querySelector(wanted) ?? null) !== null, selector);
const undo = bar.getByRole('button', { name: 'Undo', exact: true });
const redo = bar.getByRole('button', { name: 'Redo', exact: true });

await step('move a row down and up again with the keys, and see it on the page', async () => {
    await page.goto(`${base}/admin/visual-editor`);
    await page.waitForSelector('.sve-template', { state: 'attached' });
    await frame.locator('.hero h1').click();
    await layerIs('h1');
    if (!(await undo.isDisabled()) || !(await redo.isDisabled())) {
        throw new Error('There is something to undo before anything was done');
    }
    await layerRow('h1').locator('.sve-layer__pick').press('Alt+ArrowDown');
    await frame.locator('.hero p.muted + h1').waitFor();
    await layerIs('h1');
    await idle();
    await layerRow('h1').locator('.sve-layer__pick').press('Alt+ArrowUp');
    await frame.locator('.hero h1 + p.muted').waitFor();
    if ((await heroNow()) !== heroCode) {
        throw new Error('The hero is not as it was');
    }
});

await step('duplicate a row, and delete the copy with the Delete key', async () => {
    await layerRow('h1').locator('.sve-layer__pick').press('Control+d');
    await page.waitForFunction(() => document.querySelector('iframe.sve-frame')?.contentDocument?.querySelectorAll('.hero h1').length === 2);
    await idle();
    await layerRow('h1', 1).locator('.sve-layer__pick').press('Delete');
    await page.waitForFunction(() => document.querySelector('iframe.sve-frame')?.contentDocument?.querySelectorAll('.hero h1').length === 1);
    if ((await heroNow()) !== heroCode) {
        throw new Error('The hero is not as it was');
    }
});

await step('drag a row onto another, and see it move on the page', async () => {
    const target = layerRow('a.button');
    const box = await target.boundingBox();
    // The top of a row is before it: the heading goes to stand before the button.
    await layerRow('h1').dragTo(target, { targetPosition: { x: box.width / 2, y: 2 } });
    await frame.locator('.hero h1 + a.button').waitFor();
    await layerIs('h1');
});

await step('undo every change, one by one, back to the hero as it was, and redo one', async () => {
    for (let steps = 0; steps < 6 && (await heroNow()) !== heroCode; steps++) {
        await undo.click();
        await idle();
    }
    if ((await heroNow()) !== heroCode) {
        throw new Error('Undo did not bring the hero back');
    }
    await onPage('.hero h1 + p.muted');
    await redo.click();
    await onPage('.hero h1 + a.button');
    await idle();
    // Ctrl+Z outside the code is the editor's undo: here, in the tree.
    await layerRow('h1').locator('.sve-layer__pick').press('Control+z');
    await onPage('.hero h1 + p.muted');
    if ((await heroNow()) !== heroCode) {
        throw new Error('Ctrl+Z in the tree did not bring the hero back');
    }
});

// --- The code ------------------------------------------------------------------------

const added = '<p class="sve-browser-test">Edited in the code.</p>';
let footer = '';

await step('open the footer in the code editor, coloured and character for character', async () => {
    await page.goto(`${base}/admin/visual-editor`);
    await page.waitForSelector('.sve-template', { state: 'attached' });
    await showTemplates();
    await page.locator('.sve-side--left .sve-templates').first().locator('.sve-template', { hasText: 'layout' }).click();
    await openIs('layout');
    footer = await onDisk('layout.blade.php');
    await codeIs(footer);
    if (!footer.includes('<footer')) {
        throw new Error('The editor does not hold the footer');
    }
    if ((await code.locator('.cm-content .sve-code--directive').count()) === 0 || (await code.locator('.cm-content .sve-code--tag').count()) === 0) {
        throw new Error('Directives and tags are not coloured');
    }
    if ((await code.locator('.cm-lineNumbers .cm-gutterElement').count()) < 5) {
        throw new Error('The lines are not numbered');
    }
});

await step('type at the end of the footer, save with Ctrl+S and see it on the page', async () => {
    await code.evaluate((holder) => holder.sveCodeEditor.focus());
    await page.keyboard.press('Control+End');
    await page.keyboard.type(added);
    await page.keyboard.press('Enter');
    await codeIs(`${footer}${added}\n`);
    await codeBar.getByText('Not saved').waitFor();
    await page.keyboard.press('Control+s');
    await frame.getByText('Edited in the code.').waitFor();
    await saved();
    await page.locator('.sve-notice--done', { hasText: 'Saved layout.blade.php.' }).waitFor();
    if ((await onDisk('layout.blade.php')) !== `${footer}${added}\n`) {
        throw new Error('The file is not what was typed');
    }
});

await step('find and replace in the code, and undo it with the editor\'s own undo', async () => {
    await page.keyboard.press('Control+f');
    await code.locator('.cm-search input[name=search]').fill('Edited in the code.');
    await code.locator('.cm-search input[name=replace]').fill('Changed by find and replace.');
    await code.locator('.cm-search button[name=replaceAll]').click();
    await codeIs(`${footer}${added.replace('Edited in the code.', 'Changed by find and replace.')}\n`);
    await codeBar.getByText('Not saved').waitFor();

    await page.keyboard.press('Escape');
    await code.evaluate((holder) => holder.sveCodeEditor.focus());
    await page.keyboard.press('Control+z');
    await codeIs(`${footer}${added}\n`);
    // The text is the file's again: nothing is left to save.
    await saved();
});

await step('refuse code that doesn\'t compile, say why, and leave the site as it was', async () => {
    const good = await codeText();
    await page.keyboard.press('Control+End');
    await page.keyboard.type('@if ($neverClosed)');
    await codeBar.getByRole('button', { name: 'Save', exact: true }).click();
    await page.locator('.sve-code__trouble').getByText('doesn’t compile').waitFor();
    // Nothing was written: the page still renders, with the last good footer.
    const home = await page.request.get(`${base}/`);
    if (home.status() !== 200 || !(await home.text()).includes('Edited in the code.')) {
        throw new Error(`The homepage answered ${home.status()} after a refused save`);
    }
    await codeBar.getByRole('button', { name: 'Revert' }).click();
    await codeIs(good);
});

await step('undo a save from the bar, back to the footer as it was', async () => {
    await undo.click();
    await idle();
    await until('The footer is not as it was', async () => (await onDisk('layout.blade.php')) === footer);
    await frame.getByText('Edited in the code.').waitFor({ state: 'detached' });
    await codeIs(footer);
});

/** Writes a template through the editor's API, as the code's Save does: for setting up what can't be typed. */
const write = (path, source) =>
    page.evaluate(
        async ([address, text]) => {
            const token = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
            const headers = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' };
            const now = await (await fetch(address, { headers, credentials: 'same-origin' })).json();
            const answer = await fetch(`${address}/source`, { method: 'PUT', headers, credentials: 'same-origin', body: JSON.stringify({ version: now.template.version, source: text }) });

            return answer.status;
        },
        [`${base}/admin/visual-editor/api/templates/${path}`, source],
    );

await step('give a file written on Windows back with the line breaks it came with', async () => {
    const windows = footer.replaceAll('\n', '\r\n');
    if ((await write('layout.blade.php', windows)) !== 200) {
        throw new Error('The footer could not be written with other line breaks');
    }
    await page.goto(`${base}/admin/visual-editor`);
    await page.waitForSelector('.sve-template', { state: 'attached' });
    await showTemplates();
    await page.locator('.sve-side--left .sve-templates').first().locator('.sve-template', { hasText: 'layout' }).click();
    await openIs('layout');
    await codeIs(windows);

    await code.evaluate((holder) => holder.sveCodeEditor.focus());
    await page.keyboard.press('Control+End');
    await page.keyboard.type('<!-- typed -->');
    await page.keyboard.press('Enter');
    await page.keyboard.press('Control+s');
    await saved();
    await page.locator('.sve-notice--done', { hasText: 'Saved layout.blade.php.' }).waitFor();
    // What was typed, a line break of the file's own kind after it, and not one other byte.
    if ((await onDisk('layout.blade.php')) !== `${windows}<!-- typed -->\r\n`) {
        throw new Error('The file did not keep its line breaks');
    }

    if ((await write('layout.blade.php', footer)) !== 200 || (await onDisk('layout.blade.php')) !== footer) {
        throw new Error('The footer is not as it was');
    }
});

await step('show the code in a plain field when the code editor\'s script can\'t be fetched, and save from there', async () => {
    await page.route('**/code-editor.js*', (route) => route.abort());
    await page.goto(`${base}/admin/visual-editor`);
    await page.waitForSelector('.sve-template', { state: 'attached' });
    await showTemplates();
    await page.locator('.sve-side--left .sve-templates').first().locator('.sve-template', { hasText: 'layout' }).click();
    await openIs('layout');
    const field = page.locator('section[aria-label^="Code of"] .sve-code__field');
    await field.waitFor();
    await until('The plain field does not hold the footer', async () => (await field.inputValue()) === footer);
    if ((await page.locator('section[aria-label^="Code of"] .sve-code__colours .sve-code--directive').count()) === 0) {
        throw new Error('The plain field is not coloured');
    }
    await field.fill(`${footer}${added}\n`);
    await field.press('Control+s');
    await frame.getByText('Edited in the code.').waitFor();
    await saved();
    await field.fill(footer);
    await field.press('Control+s');
    await saved();
    await page.unroute('**/code-editor.js*');
});

const unsavedTabs = () => page.locator('.sve-tab', { has: page.locator('.sve-tab__dot') }).locator('.sve-tab__name').allTextContents();
let headerCode = '';

await step('open a template clicked on the page as a tab of the code', async () => {
    await page.goto(`${base}/admin/visual-editor`);
    await page.waitForSelector('.sve-template', { state: 'attached' });
    headerCode = await onDisk('layout.blade.php');
    await openIs('collections/show');
    if (JSON.stringify(await tabNames()) !== JSON.stringify(['collections/show'])) {
        throw new Error(`The tabs are ${JSON.stringify(await tabNames())}`);
    }
    await frame.locator('.hero h1').click();
    await openIs('blocks/hero');
    await codeIs(heroCode);
    await cursorLineHas('<h1>{{ $block->heading }}</h1>');
    if (JSON.stringify(await tabNames()) !== JSON.stringify(['collections/show', 'blocks/hero'])) {
        throw new Error(`The tabs are ${JSON.stringify(await tabNames())}`);
    }
});

await step('leave text typed and not saved in one tab, save another, and find the first as it was left', async () => {
    await page.keyboard.press('End');
    await page.keyboard.type(' <!-- kept -->');
    await codeBar.getByText('Not saved').waitFor();

    // The header, from the page: the hero's text stays with its tab.
    await frame.locator('.site-header .site-name').click();
    await openIs('layout');
    await codeIs(headerCode);
    await cursorLineHas('{{');
    if (JSON.stringify(await unsavedTabs()) !== JSON.stringify(['blocks/hero']) || (await codeBar.locator('.sve-tag').count()) !== 0) {
        throw new Error(`Not saved: ${JSON.stringify(await unsavedTabs())}`);
    }
    await page.keyboard.press('End');
    await page.keyboard.type(' <!-- header -->');
    await page.keyboard.press('Control+s');
    await page.locator('.sve-notice--done', { hasText: 'Saved layout.blade.php.' }).waitFor();
    if (!(await onDisk('layout.blade.php')).includes('<!-- header -->') || (await onDisk('blocks/hero.blade.php')) !== heroCode) {
        throw new Error('The header was not saved alone');
    }

    await codeTab('blocks/hero').locator('.sve-tab__name').click();
    await openIs('blocks/hero');
    await codeIs(heroCode.replace('<h1>{{ $block->heading }}</h1>', '<h1>{{ $block->heading }}</h1> <!-- kept -->'));
    await codeBar.getByText('Not saved').waitFor();
});

await step('find a place clicked on the page in text that has been typed in since', async () => {
    // A line put in at the very top: everything in the file is further down than the page knows.
    await code.evaluate((holder) => holder.sveCodeEditor.focus());
    await page.keyboard.press('Control+Home');
    await page.keyboard.type('{{-- a line that is not saved --}}');
    await page.keyboard.press('Enter');
    await frame.locator('.hero .muted').click();
    await cursorLineHas('$block->subheading');
    await frame.locator('.hero h1').click();
    await cursorLineHas('<h1>{{ $block->heading }}</h1> <!-- kept -->');
});

await step('undo in a tab that was left and come back to, with the editor\'s own undo', async () => {
    for (let steps = 0; steps < 6 && (await codeText()) !== heroCode; steps++) {
        await page.keyboard.press('Control+z');
        await page.waitForTimeout(60);
    }
    await codeIs(heroCode);
    await saved();

    await codeTab('layout').locator('.sve-tab__name').click();
    await openIs('layout');
    await until('The header is not shown', async () => (await codeText()).includes('<!-- header -->'));
    await page.waitForFunction(() => document.querySelector('section[aria-label^="Code of"] .cm-content')?.getAttribute('aria-readonly') !== 'true');
    await code.evaluate((holder) => holder.sveCodeEditor.focus());
    await page.keyboard.press('Control+z');
    await codeIs(headerCode);
    await page.keyboard.press('Control+s');
    await saved();
    await until('The header is not as it was', async () => (await onDisk('layout.blade.php')) === headerCode);
});

await step('outline on the page, and in the tree, the element the cursor is in', async () => {
    await codeTab('blocks/hero').locator('.sve-tab__name').click();
    await openIs('blocks/hero');
    await codeIs(heroCode);
    await code.locator('.cm-line', { hasText: 'class="button"' }).first().click({ position: { x: 140, y: 8 } });
    await outlineIs('selected', 'a.button');
    await layerIs('a.button');
    await outlineSettles('.hero .button');
});

await step('offer what a variable has while it is typed, and write the one chosen', async () => {
    await frame.locator('.hero h1').click();
    await cursorLineHas('<h1>{{ $block->heading }}</h1>');
    await page.keyboard.press('End');
    await page.keyboard.press('Enter');
    await page.keyboard.type('{{ $block->');
    const offered = page.locator('.cm-tooltip-autocomplete li');
    await offered.first().waitFor();
    if (!(await offered.allTextContents()).some((offer) => offer.startsWith('Heading'))) {
        throw new Error(`Offered: ${JSON.stringify(await offered.allTextContents())}`);
    }
    await page.keyboard.type('sub');
    await until('The offers were not narrowed', async () => (await offered.count()) === 1 || (await offered.allTextContents()));
    await page.keyboard.press('Enter');
    await page.keyboard.type(' }}');
    await until('The field was not written', async () => (await cursorLine()) === '    {{ $block->subheading }}' || (await cursorLine()));

    // Back to the file as it is, by the editor's own undo.
    for (let steps = 0; steps < 30 && (await codeText()) !== heroCode; steps++) {
        await page.keyboard.press('Control+z');
        await page.waitForTimeout(40);
    }
    await codeIs(heroCode);
    await saved();
});

await step('close a tab, and be asked first when it has text that is not saved', async () => {
    await page.keyboard.press('End');
    await page.keyboard.type(' <!-- going -->');
    await codeBar.getByText('Not saved').waitFor();
    // Leaving for the admin asks too, and staying keeps everything.
    page.once('dialog', (dialog) => dialog.dismiss());
    await page.locator('.sve-bar__back').click();
    await page.waitForTimeout(300);
    if (!page.url().includes('/visual-editor') || !(await codeText()).includes('<!-- going -->')) {
        throw new Error('The editor was left without asking');
    }

    page.once('dialog', (dialog) => dialog.dismiss());
    await codeTab('blocks/hero').locator('.sve-tab__close').click();
    await page.waitForTimeout(200);
    if (!(await tabNames()).includes('blocks/hero') || !(await codeText()).includes('<!-- going -->')) {
        throw new Error('The tab closed without asking');
    }
    page.once('dialog', (dialog) => dialog.accept());
    await codeTab('blocks/hero').locator('.sve-tab__close').click();
    await until('The hero is still a tab', async () => !(await tabNames()).includes('blocks/hero') || (await tabNames()));
    if ((await onDisk('blocks/hero.blade.php')) !== heroCode) {
        throw new Error('Closing the tab wrote the file');
    }
});

await step('say so when a saved change breaks the page where it is drawn, and undo it from there', async () => {
    await frame.locator('.hero h1').click();
    await openIs('blocks/hero');
    await codeIs(heroCode);
    await cursorLineHas('<h1>{{ $block->heading }}</h1>');
    // The menu partial needs $items, which a block doesn't have: it compiles, and fails when drawn.
    // What Laravel's own error page then does in the frame is not the editor's to answer for.
    const errorsBefore = errors.length;
    await page.keyboard.press('End');
    await page.keyboard.press('Enter');
    await page.keyboard.type("@include('cms.partials.menu')");
    await page.keyboard.press('Control+s');
    const broken = page.locator('.sve-notice--problem', { hasText: 'could not be drawn after that change' });
    await broken.waitFor({ timeout: 15000 });
    await broken.getByRole('button', { name: 'Undo' }).click();
    await frame.locator('.hero h1').waitFor();
    if ((await heroNow()) !== heroCode) {
        throw new Error('The undo did not bring the hero back');
    }
    const home = await page.request.get(`${base}/`);
    if (home.status() !== 200) {
        throw new Error(`The homepage answers ${home.status()} after the undo`);
    }
    errors.length = errorsBefore;
});

// --- Room for the page --------------------------------------------------------------

const laidOut = () => page.evaluate(() => JSON.parse(localStorage.getItem('sunrice-visual-editor:layout:2') ?? 'null'));

await step('drag the line between the page and the code, and find it there when the editor is opened again', async () => {
    const widthOf = async () => (await page.locator('.sve-side--code').boundingBox()).width;
    const before = await widthOf();
    const line = await page.locator('.sve-divider').boundingBox();
    await page.mouse.move(line.x + line.width / 2, line.y + line.height / 2);
    await page.mouse.down();
    await page.mouse.move(line.x + line.width / 2 - 60, line.y + line.height / 2, { steps: 4 });
    await page.mouse.move(line.x + line.width / 2 - 120, line.y + line.height / 2, { steps: 4 });
    await page.mouse.up();
    await until('The code did not get the room', async () => Math.abs((await widthOf()) - before - 120) < 8 || [(await widthOf()), before]);
    const wide = await widthOf();

    await page.goto(`${base}/admin/visual-editor`);
    await code.locator('.cm-editor').waitFor();
    if (Math.abs((await widthOf()) - wide) > 2) {
        throw new Error(`The code is ${await widthOf()} wide, and was left ${wide} wide`);
    }
    // And by the keyboard, for who does not drag.
    await page.locator('.sve-divider').focus();
    await page.keyboard.press('ArrowRight');
    await until('The line did not move by a key', async () => (await widthOf()) < wide - 10 || (await widthOf()));
});

await step('fold the tree and the code to the sides: the page takes their room, and the editor opens as it was left', async () => {
    const pageWidth = async () => (await page.locator('iframe.sve-frame').boundingBox()).width;
    const before = await pageWidth();
    await page.getByRole('button', { name: 'Fold the tree to the side' }).click();
    await page.getByRole('button', { name: 'Fold the code to the side' }).click();
    await until('The page did not take the room', async () => (await pageWidth()) > before + 400 || (await pageWidth()));
    // Each side leaves a strip at its edge, named, that opens it again.
    for (const name of ['Show the tree', 'Show the code']) {
        const rail = await page.getByRole('button', { name, exact: true }).boundingBox();
        if (rail === null || rail.width > 40) {
            throw new Error(`"${name}" is ${JSON.stringify(rail)}`);
        }
    }
    const kept = await laidOut();
    if (kept?.left !== false || kept?.right !== false) {
        throw new Error(`The editor is left as ${JSON.stringify(kept)}`);
    }
    await page.goto(`${base}/admin/visual-editor`);
    await frame.getByText('Templates you can see').waitFor();
    if (await page.locator('.sve-side--left').isVisible() || await page.locator('.sve-side--code').isVisible()) {
        throw new Error('A folded side is shown again');
    }
    await page.getByRole('button', { name: 'Show the tree', exact: true }).click();
    await bar.getByRole('button', { name: 'Code on the right' }).click();
    await page.locator('.sve-side--left').waitFor();
    await page.locator('.sve-side--code').waitFor();
    await page.evaluate(() => localStorage.removeItem('sunrice-visual-editor:layout:2'));
});

// --- Devices --------------------------------------------------------------------------

const devices = bar.getByRole('group', { name: 'Device to preview the page at' });
const widthIn = (frameLocator) => frameLocator.locator('html').evaluate(() => window.innerWidth);

await step('preview the page at one device: its frame is that wide, and what is selected stays outlined', async () => {
    await frame.locator('.hero h1').click();
    await layerIs('h1');
    await devices.getByRole('button', { name: 'Mobile' }).click();
    await until('The page is not as wide as a phone', async () => (await widthIn(frame)) === 390 || (await widthIn(frame)));
    await outlineSettles('.hero h1');
});

await step('preview every device side by side, and select in another', async () => {
    await devices.getByRole('button', { name: 'All', exact: true }).click();
    const beside = (nth) => page.frameLocator(`iframe.sve-beside >> nth=${nth}`);
    await until('The frames are not at their widths', async () => {
        const all = [await widthIn(frame), await widthIn(beside(0)), await widthIn(beside(1))].sort((a, b) => a - b);

        return JSON.stringify(all) === JSON.stringify([390, 768, 1280]) || all;
    });
    await page.locator('.sve-slot', { hasText: 'Desktop' }).getByRole('button', { name: 'Select here' }).click();
    await until('The page being edited is not the desktop one', async () => (await widthIn(frame)) === 1280 || (await widthIn(frame)));
    await devices.getByRole('button', { name: 'Fit', exact: true }).click();
    await until('The page is not as wide as the room', async () => (await widthIn(frame)) < 1280 || (await widthIn(frame)));
});

// --- Fields, in the code's completions ---------------------------------------------

const offered = page.locator('.cm-tooltip-autocomplete li');

await step('offer what the template can read where the cursor is on Ctrl+Space, and write it as Blade of its own', async () => {
    await frame.locator('.hero h1').click();
    await cursorLineHas('<h1>{{ $block->heading }}</h1>');
    // Into the line itself: the keys pressed next are the code's, whatever the click on the page is still doing.
    await code.locator('.cm-line', { hasText: '<h1>{{ $block->heading }}</h1>' }).first().click();
    await page.keyboard.press('End');
    await page.keyboard.press('Enter');
    await page.keyboard.press('Control+Space');
    await offered.filter({ hasText: '$block › Subheading' }).first().waitFor();
    // Everything at the place: the site's too.
    await offered.filter({ hasText: 'Main menu' }).first().waitFor();
    // Narrowed by a word typed, and written in its place.
    await page.keyboard.type('subhead');
    // As a text, or as a condition that it is set: the usual way first.
    await until('The offers were not narrowed', async () => (await offered.count()) === 2 || (await offered.allTextContents()));
    await offered.filter({ hasText: '(only when it is set)' }).waitFor();
    await page.keyboard.press('Enter');
    await until('The field was not written at the cursor', async () => (await cursorLine()) === '    {{ $block->subheading }}' || (await cursorLine()));
    await codeBar.getByRole('button', { name: 'Revert' }).click();
    await codeIs(heroCode);
});

// --- Content --------------------------------------------------------------------------

await step('say there is no single entry to edit on a listing', async () => {
    await open('/articles');
    await addressIs('/articles');
    await frame.getByRole('link', { name: 'Fifth post', exact: true }).waitFor();
    const off = bar.locator('.sve-bar__link--off', { hasText: 'Edit content' });
    await off.waitFor();
    if (!(await off.getAttribute('title'))?.startsWith('This page shows no single entry')) {
        throw new Error(`It says ${await off.getAttribute('title')}`);
    }
});

/** The entry's page in the admin, opened from the top bar in a tab of its own: its title published as given, then back to the editor. */
const publishTitle = async (from, to) => {
    const link = bar.locator('a.sve-bar__link', { hasText: 'Edit content' });
    await until('The link is not to the entry', async () => (await link.getAttribute('title'))?.startsWith(`Edit ${from} in the admin`) || (await link.getAttribute('title')));
    const [admin] = await Promise.all([page.context().waitForEvent('page'), link.click()]);
    const title = admin.getByLabel('Title', { exact: true }).first();
    await title.waitFor();
    if ((await title.inputValue()) !== from) {
        throw new Error(`The content is of ${await title.inputValue()}`);
    }
    await title.fill(to);
    await admin.getByRole('button', { name: /^Publish/ }).first().click();
    await admin.getByText('Changes pending').first().waitFor({ state: 'detached' });
    await admin.locator('h1', { hasText: to }).first().waitFor();
    await admin.close();
    await page.bringToFront();
    // Back in the editor's window.
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
};

await step('open the entry the page shows in the admin, in a tab of its own, and draw the page again on coming back', async () => {
    await open('/articles/fifth-post');
    await addressIs('/articles/fifth-post');
    await publishTitle('Fifth post', 'Fifth post, edited in the admin');
    await frame.getByText('Fifth post, edited in the admin').first().waitFor();
    await publishTitle('Fifth post, edited in the admin', 'Fifth post');
    await frame.getByText('Fifth post, edited in the admin').first().waitFor({ state: 'detached' });
});

// --- Custom CSS ----------------------------------------------------------------------

const custom = page.locator('.sve-custom-css .sve-code__editor');
const customApi = `${base}/admin/visual-editor/api/styles/custom`;
const customNow = async () => (await (await page.request.get(customApi)).json()).custom;
let customBefore = '';

await step('write custom CSS beside the page, save it, and see it on the page with no template changed', async () => {
    await page.goto(`${base}/admin/visual-editor`);
    await page.waitForSelector('.sve-template', { state: 'attached' });
    await frame.locator('.hero h1').waitFor();
    customBefore = (await customNow()).source;
    const layout = await onDisk('layout.blade.php');
    await page.locator('.sve-side--code .sve-code-tabs__tab', { hasText: 'Custom CSS' }).click();
    await custom.locator('.cm-editor').waitFor();
    await page.locator('.sve-custom-css .sve-custom-css__note').getByText("@stack('head')").waitFor();
    await custom.evaluate((holder, text) => {
        holder.sveCodeEditor.setText(text);
        holder.sveCodeEditor.focus();
    }, `${customBefore}.hero h1 { color: rgb(200, 0, 100); }\n`);
    await page.locator('.sve-custom-css .sve-code__bar').getByText('Not saved').waitFor();
    await page.keyboard.press('Control+s');
    await page.locator('.sve-notice--done', { hasText: 'Saved custom.css.' }).waitFor();
    await until('The page does not show the custom CSS', async () => (await frame.locator('.hero h1').evaluate((element) => getComputedStyle(element).color)) === 'rgb(200, 0, 100)' || 'not yet');
    if ((await frame.locator('link[data-sunrice-custom-css]').count()) !== 1 || (await onDisk('layout.blade.php')) !== layout) {
        throw new Error('The custom CSS is not linked once, or the layout was changed for it');
    }
    // The site itself has it, not only the editor.
    if (!(await (await page.request.get(`${base}/`)).text()).includes('data-sunrice-custom-css')) {
        throw new Error('The site does not link the custom CSS');
    }
});

await step('keep the code of a template and the custom CSS apart, each with its own text', async () => {
    await page.locator('.sve-side--code .sve-code-tabs__tab', { hasText: 'Template' }).click();
    await code.locator('.cm-editor').waitFor();
    // A click on the page shows the template's code again.
    await page.locator('.sve-side--code .sve-code-tabs__tab', { hasText: 'Custom CSS' }).click();
    await frame.locator('.hero h1').click();
    await page.waitForFunction(() => document.querySelector('.sve-side--code .sve-code-tabs__tab[aria-selected="true"]')?.textContent?.startsWith('Template'));
    await cursorLineHas('<h1>{{ $block->heading }}</h1>');
});

await step('put the custom CSS back', async () => {
    await page.locator('.sve-side--code .sve-code-tabs__tab', { hasText: 'Custom CSS' }).click();
    await custom.evaluate((holder, text) => {
        holder.sveCodeEditor.setText(text);
        holder.sveCodeEditor.focus();
    }, customBefore);
    // Its own Save, not Ctrl+S: the click on the page a moment ago may still be sending the keyboard to the template's code.
    await page.locator('.sve-custom-css .sve-code__bar').getByRole('button', { name: 'Save', exact: true }).click();
    await until('The custom CSS is not as it was', async () => (await customNow()).source === customBefore || 'not yet');
    await until('The page still shows the custom CSS', async () => (await frame.locator('.hero h1').evaluate((element) => getComputedStyle(element).color)) !== 'rgb(200, 0, 100)' || 'not yet');
    await page.locator('.sve-side--code .sve-code-tabs__tab', { hasText: 'Template' }).click();
});

const customJs = page.locator('.sve-custom-js .sve-code__editor');
const jsApi = `${base}/admin/visual-editor/api/scripts/custom`;
const jsNow = async () => (await (await page.request.get(jsApi)).json()).custom;
let jsBefore = '';

await step('write custom JS beside the page, save it, and see it run on the page', async () => {
    jsBefore = (await jsNow()).source;
    await page.locator('.sve-side--code .sve-code-tabs__tab', { hasText: 'Custom JS' }).click();
    await customJs.locator('.cm-editor').waitFor();
    await page.locator('.sve-custom-js .sve-custom-css__note').getByText("@push('head')").waitFor();
    await customJs.evaluate((holder, text) => {
        holder.sveCodeEditor.setText(text);
        holder.sveCodeEditor.focus();
    }, `${jsBefore}document.querySelector('.hero h1')?.setAttribute('data-custom-js', 'ran');\n`);
    await page.locator('.sve-side--code .sve-code-tabs__tab', { hasText: 'Custom JS' }).locator('.sve-tab__dot').waitFor();
    await page.keyboard.press('Control+s');
    await page.locator('.sve-notice--done', { hasText: 'Saved custom.js.' }).waitFor();
    await onPage('.hero h1[data-custom-js="ran"]');
    if (!(await (await page.request.get(`${base}/`)).text()).includes('data-sunrice-custom-js')) {
        throw new Error('The site does not load the custom script');
    }
});

await step('put the custom JS back', async () => {
    await customJs.evaluate((holder, text) => {
        holder.sveCodeEditor.setText(text);
        holder.sveCodeEditor.focus();
    }, jsBefore);
    await page.locator('.sve-custom-js .sve-code__bar').getByRole('button', { name: 'Save', exact: true }).click();
    await until('The custom script is not as it was', async () => (await jsNow()).source === jsBefore || 'not yet');
    await page.locator('.sve-side--code .sve-code-tabs__tab', { hasText: 'Template' }).click();
});

// --- From anywhere in the admin --------------------------------------------------------

await step('find a page by its title in the command palette, and open the editor on it', async () => {
    await page.goto(`${base}/admin`);
    await page.getByRole('button', { name: /Go to/ }).first().click();
    await page.locator('[role=dialog] input[role=combobox]').fill('About');
    const item = page.locator('[role=dialog] [role=option]', { hasText: 'Edit About in the visual editor' }).first();
    await item.waitFor();
    await item.click();
    await page.waitForSelector('.sve-shell');
    await addressIs('/about');
    await frame.getByText('A page with a rich text body and no blocks.').waitFor();
});

// --- Odd pages --------------------------------------------------------------------------

await step('keep what is typed in the address field while a page is still arriving', async () => {
    await page.goto(`${base}/admin/visual-editor`);
    await page.waitForSelector('.sve-template', { state: 'attached' });
    await open('/articles');
    await page.fill('#sve-address', 'about');
    await frame.getByRole('link', { name: 'Fifth post', exact: true }).waitFor();
    await page.waitForTimeout(300);
    if ((await page.inputValue('#sve-address')) !== 'about') {
        throw new Error(`The field now says "${await page.inputValue('#sve-address')}"`);
    }
    await page.click('.sve-address button[type=submit]');
    await addressIs('/about');
    await frame.getByText('A page with a rich text body and no blocks.').waitFor();
});

await step('follow a missing page, or say that it can\'t be followed', async () => {
    await open('no-such-page-anywhere');
    await addressIs('/no-such-page-anywhere');
    await page.waitForFunction(() => {
        const names = [...document.querySelectorAll('.sve-side--left > section.sve-panel:first-of-type .sve-panel__body > .sve-templates .sve-template__name')].map((name) => name.textContent);

        return !names.includes('collections/show') && (names.length > 0 || document.querySelector('.sve-side--left > section.sve-panel:first-of-type .sve-panel__body > .sve-empty') !== null);
    });
});

await step('say so when what is in the frame is no page of the site', async () => {
    // A file the web server hands out itself: nothing renders it, so nothing marks it.
    await open('vendor/sunrice-visual-editor/bridge.js');
    await showTemplates();
    await page.locator('.sve-side--left > section.sve-panel:first-of-type .sve-empty').getByText('not drawn for the editor').waitFor();
});

await step('refuse the admin\'s own address', async () => {
    await open('/admin/users');
    await page.getByRole('alert').getByText('admin').waitFor();
});

// --- The site's build command ---------------------------------------------------------

const buildButton = page.locator('.sve-bar__build');

await step('say that a build is needed once a template has changed, run it, and say how long it took', async () => {
    await page.goto(`${base}/admin/visual-editor`);
    await page.waitForSelector('.sve-template', { state: 'attached' });
    await frame.locator('.hero h1').waitFor();
    if ((await buildButton.textContent())?.trim() !== 'Build') {
        throw new Error(`Before anything changed the button says “${await buildButton.textContent()}”`);
    }

    await frame.locator('.hero h1').click();
    await layerIs('h1');
    await layerRow('h1').locator('.sve-layer__pick').press('Alt+ArrowDown');
    await frame.locator('.hero p.muted + h1').waitFor();
    await idle();
    await buildButton.getByText('Build needed', { exact: true }).waitFor();

    await buildButton.click();
    await page.getByText(/^Built in [\d.]+ seconds\.$/).waitFor();
    await buildButton.getByText('Build', { exact: true }).waitFor();
    await frame.locator('.hero p.muted + h1[data-sve]').waitFor();

    await undo.click();
    await frame.locator('.hero h1 + p.muted').waitFor();
    await idle();
    await buildButton.getByText('Build needed', { exact: true }).waitFor();
});

await step('show what a build that failed printed, until it is put away', async () => {
    // The demo site's command cannot fail: the answer of one that did is put in its place.
    await page.route('**/visual-editor/api/build', (route) =>
        route.fulfill({
            json: { ok: false, seconds: 1.2, code: 1, message: 'The build failed (exit code 1).', output: 'error: Cannot apply unknown utility class `bg-skyy-400`' },
        }),
    );
    await buildButton.click();
    const failed = page.getByRole('alert', { name: 'The build' });
    await failed.getByText('The build failed (exit code 1).').waitFor();
    await failed.getByText('Cannot apply unknown utility class').waitFor();
    await buildButton.getByText('Build needed', { exact: true }).waitFor();
    await failed.getByRole('button', { name: 'Put this away' }).click();
    await failed.waitFor({ state: 'detached' });
    await page.unroute('**/visual-editor/api/build');
});

await step('take a screenshot', async () => {
    await page.goto(`${base}/admin/visual-editor`);
    await frame.getByText('Templates you can see').waitFor();
    await page.waitForSelector('.sve-template', { state: 'attached' });
    await frame.locator('.hero h1').click();
    await layerIs('h1');
    await frame.getByText('Templates you can see').hover();
    await code.locator('.cm-editor').waitFor();
    await mkdir('docs/screenshots', { recursive: true });
    await page.screenshot({ path: 'docs/screenshots/editor.png' });
});

// --- Someone who may edit, and not write code --------------------------------------

await step('sign in as the designer, who may change templates and not write code', async () => {
    await signIn('designer@example.com');
    await page.getByRole('link', { name: 'Visual editor' }).first().click();
    await page.waitForSelector('.sve-template', { state: 'attached' });
    await frame.getByText('Templates you can see').waitFor();
    // The site's build command is not theirs to run.
    if ((await page.locator('.sve-bar__build').count()) !== 0) {
        throw new Error('The Build button is shown to someone who may not build');
    }
});

await step('show them the code to read, not to change', async () => {
    await frame.locator('.hero h1').click();
    await openIs('blocks/hero');
    await codeIs(heroCode);
    await codeBar.getByText('Read-only').waitFor();
    await page.locator('.sve-code__banner').getByText('You may read the code here, not change it.').waitFor();
    await code.evaluate((holder) => holder.sveCodeEditor.focus());
    await page.keyboard.type('typed by someone who may not');
    await page.keyboard.press('Control+a');
    await page.keyboard.press('Delete');
    await page.waitForTimeout(200);
    if ((await codeText()) !== heroCode || (await codeBar.getByRole('button', { name: 'Save' }).count()) !== 0) {
        throw new Error('The code can be typed in');
    }
});

await step('let them read the custom JS, which runs in visitors\' browsers, and not change it', async () => {
    await page.locator('.sve-side--code .sve-code-tabs__tab', { hasText: 'Custom JS' }).click();
    await page.locator('.sve-custom-js').getByText('changing it takes the permission to edit code').waitFor();
    await page.locator('.sve-custom-js .sve-code__bar').getByText('Read-only').waitFor();
    // The custom CSS runs nothing: theirs to write.
    await page.locator('.sve-side--code .sve-code-tabs__tab', { hasText: 'Custom CSS' }).click();
    await page.locator('.sve-custom-css .sve-code__bar').getByRole('button', { name: 'Save' }).waitFor();
    await page.locator('.sve-side--code .sve-code-tabs__tab', { hasText: 'Template' }).click();
});

await step('let them move what is there in the tree, and undo it', async () => {
    await layerRow('h1').locator('.sve-layer__pick').press('Alt+ArrowDown');
    await frame.locator('.hero p.muted + h1').waitFor();
    await idle();
    await undo.click();
    await frame.locator('.hero h1 + p.muted').waitFor();
    if ((await heroNow()) !== heroCode) {
        throw new Error('The hero is not what it was');
    }
});

await browser.close();

if (errors.length > 0) {
    console.log(`FAIL the page threw errors:\n     ${errors.join('\n     ')}`);
    process.exit(1);
}
