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
        // The first lines say what failed and what was being waited for.
        console.log(`FAIL ${name}\n     ${String(error.message).split('\n').slice(0, 7).join('\n     ')}\n     at ${page.url()}`);
        // Something thrown on the page is often why: said here, since nothing after this is run.
        for (const thrown of errors.slice(-5)) {
            console.log(`     thrown on the page: ${thrown}`);
        }
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

const listed = () => page.locator('.sve-side--left .sve-templates').first().locator('.sve-template__name').allTextContents();
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
    const row = page.locator('.sve-side--left .sve-templates').first().locator('.sve-template', { hasText: 'blocks/hero' });
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

// --- Layers and selection --------------------------------------------------------

const layerIs = (label) => page.waitForFunction((expected) => document.querySelector('.sve-layer[aria-selected="true"] .sve-layer__label')?.textContent === expected, label);
const outlineIs = (kind, label) => page.waitForFunction(([wanted, expected]) => [...document.querySelectorAll(`.sve-outline--${wanted} .sve-outline__label`)].some((each) => each.textContent === expected), [kind, label]);
/** Checks that an outline lies exactly over an element of the page in the frame. */
const outlineOver = async (kind, selector) => {
    const outline = await page.locator(`.sve-outline--${kind}`).first().boundingBox();
    const element = await frame.locator(selector).first().boundingBox();
    if (outline === null || element === null || ['x', 'y', 'width', 'height'].some((side) => Math.abs(outline[side] - element[side]) > 1.5)) {
        throw new Error(`The outline is at ${JSON.stringify(outline)}, the element at ${JSON.stringify(element)}`);
    }
};

await step('select an element by clicking it on the page: its template opens, its layer is selected', async () => {
    await page.goto(`${base}/cms/visual-editor`);
    await page.waitForSelector('.sve-template');
    await frame.locator('.hero h1').click();
    await openIs('blocks/hero');
    await layerIs('h1');
    await outlineIs('selected', 'h1');
    await outlineOver('selected', '.hero h1');
});

await step('select a layer: the page shows it', async () => {
    await page.locator('.sve-layer__pick', { hasText: 'a.button' }).click();
    await layerIs('a.button');
    await outlineIs('selected', 'a.button');
    await outlineOver('selected', '.hero a.button');
});

await step('point at a layer, and the page outlines it; point at the page, and the layer lights up', async () => {
    await page.locator('.sve-layer__pick', { hasText: 'p.muted' }).hover();
    await outlineIs('hover', 'p.muted');
    await frame.locator('.hero h1').hover();
    await page.waitForFunction(() => document.querySelector('.sve-layer--lit .sve-layer__label')?.textContent === 'h1');
});

await step('show a field, which has no box of its own, by the element around it', async () => {
    await page.locator('.sve-layer__pick', { hasText: '$block->heading' }).click();
    await page.waitForSelector('.sve-outline--around');
    await outlineOver('around', '.hero h1');
    await page.locator('.sve-selection__kind', { hasText: 'Field' }).waitFor();
    await page.waitForFunction(() => document.querySelector('.sve-settings textarea')?.value === '$block->heading');
});

await step('show a node inside a loop once for every turn of the loop', async () => {
    await open('/articles');
    await openIs('index');
    await frame.locator('.card p.muted').nth(1).click();
    await openIs('partials/card');
    await layerIs('p.muted');
    await page.waitForFunction(() => document.querySelectorAll('.sve-outline--selected').length === 5);
});

await step('select an element of a section, drawn inside the layout, in the page\'s own template', async () => {
    await frame.locator('main .hero h1').click();
    await openIs('index');
    await layerIs('h1');
    await outlineOver('selected', 'main .hero h1');
});

// --- Settings ----------------------------------------------------------------------

const settings = page.locator('.sve-side--right');
const idle = () => page.waitForFunction(() => document.querySelector('.sve-shell')?.getAttribute('aria-busy') !== 'true');

await step('add a class in the Settings panel and see it on the page, the element still selected', async () => {
    await page.goto(`${base}/cms/visual-editor`);
    await page.waitForSelector('.sve-template');
    await frame.locator('.hero h1').click();
    await layerIs('h1');
    await settings.getByLabel('Add a class').fill('sve-browser-test');
    await settings.getByLabel('Add a class').press('Enter');
    await layerIs('h1.sve-browser-test');
    await frame.locator('.hero h1.sve-browser-test').waitFor();
    await outlineIs('selected', 'h1.sve-browser-test');
});

await step('take the class off again', async () => {
    await idle();
    await settings.getByLabel('Remove the class sve-browser-test').click();
    await layerIs('h1');
    await frame.locator('.hero h1:not(.sve-browser-test)').waitFor();
});

await step('keep the panels to the template that is open when the answer to a change of another comes late', async () => {
    await idle();
    // The server takes its time over a change to the hero.
    let answer = () => {};
    const waited = new Promise((resolve) => {
        answer = resolve;
    });
    await page.route(
        '**/api/templates/**/operations',
        async (route) => {
            const response = await route.fetch();
            await waited;
            await route.fulfill({ response });
        },
        { times: 1 },
    );
    await settings.getByLabel('Add a class').fill('sve-late');
    await settings.getByLabel('Add a class').press('Enter');

    // Meanwhile the header is opened, and its layers are there.
    await page.locator('.sve-side--left .sve-templates').first().locator('.sve-template', { hasText: 'partials/header' }).click();
    await openIs('partials/header');
    await page.locator('.sve-layer__label', { hasText: 'header.site-header' }).first().waitFor();

    answer();
    // The change was made and the page shows it; the layers are still the header's.
    await frame.locator('.hero h1.sve-late').waitFor();
    await idle();
    const labels = await page.locator('.sve-layer__label').allTextContents();
    if (labels.some((label) => label.startsWith('h1')) || !labels.includes('header.site-header')) {
        throw new Error(`The header is open, and the layers are ${JSON.stringify(labels.slice(0, 6))}`);
    }

    // A change to the header is taken, not turned away as made for another file.
    await page.locator('.sve-layer__label', { hasText: 'header.site-header' }).first().click();
    await settings.getByLabel('Add a class').fill('sve-late-too');
    await settings.getByLabel('Add a class').press('Enter');
    await frame.locator('header.site-header.sve-late-too').waitFor();
    await idle();
    await settings.getByLabel('Remove the class sve-late-too').click();
    await frame.locator('header.site-header:not(.sve-late-too)').waitFor();

    // The hero, opened again, is as the change left it, with the step to undo it.
    await idle();
    await frame.locator('.hero h1').click();
    await openIs('blocks/hero');
    await layerIs('h1.sve-late');
    await settings.getByLabel('Remove the class sve-late').click();
    await frame.locator('.hero h1:not(.sve-late)').waitFor();
    await layerIs('h1');
});

await step('change what a field prints, and put it back', async () => {
    await idle();
    await page.locator('.sve-layer__pick').filter({ has: page.locator('.sve-layer__label', { hasText: /^\$block->subheading$/ }) }).click();
    await settings.getByLabel('Prints', { exact: true }).fill('$block->heading');
    await settings.getByLabel('Prints', { exact: true }).press('Control+Enter');
    await layerIs('$block->heading');
    await frame.locator('.hero p.muted', { hasText: 'Templates you can see' }).waitFor();
    await idle();
    await settings.getByLabel('Prints', { exact: true }).fill('$block->subheading');
    await settings.getByLabel('Prints', { exact: true }).press('Control+Enter');
    await layerIs('$block->subheading');
    await frame.locator('.hero p.muted', { hasText: 'This page is drawn by a layout' }).waitFor();
});

await step('refuse a change that doesn\'t compile, say why, and show the file again', async () => {
    await idle();
    await settings.getByLabel('Prints', { exact: true }).fill('$block->');
    await settings.getByLabel('Prints', { exact: true }).press('Control+Enter');
    await page.locator('.sve-notice--problem', { hasText: /not a PHP expression|doesn't compile/ }).waitFor();
    await page.waitForFunction(() => document.querySelector('.sve-side--right textarea')?.value === '$block->subheading');
    const home = await page.request.get(`${base}/`);
    if (home.status() !== 200) {
        throw new Error(`The homepage answered ${home.status()} after a refused change`);
    }
});

// --- Insert ------------------------------------------------------------------------

const inserting = page.getByRole('region', { name: 'Insert' });
const insertable = (title) => inserting.locator('.sve-insertable').filter({ has: page.locator('.sve-insertable__title', { hasText: new RegExp(`^${title}$`) }) });
const heroApi = `${base}/cms/visual-editor/api/templates/blocks/hero.blade.php`;
let hero = null;

await step('insert a container after the heading, then a heading inside it: each becomes the selection', async () => {
    hero = (await (await page.request.get(heroApi)).json()).template;
    await page.goto(`${base}/cms/visual-editor`);
    await page.waitForSelector('.sve-template');
    await frame.locator('.hero h1').click();
    await layerIs('h1');
    await page.locator('.sve-bar').getByRole('button', { name: 'Insert' }).click();
    await insertable('Container').click();
    await layerIs('div');
    await idle();
    // The container is empty, so the next thing goes inside it.
    await insertable('Heading').click();
    await layerIs('h2');
    await frame.locator('.hero h1 + div > h2', { hasText: 'Heading' }).waitFor();
});

await step('insert one of Sunrice\'s components, and see the site\'s own content drawn by it', async () => {
    await idle();
    await inserting.getByLabel('Find something to insert').fill('entries articles');
    await insertable('Entries: Articles').click();
    await frame.locator('.hero h1 + div article a', { hasText: 'First post' }).waitFor();
    await inserting.getByLabel('Find something to insert').fill('');
});

await step('refuse pasted HTML that leaves a tag open, and keep it to be put right', async () => {
    await idle();
    await inserting.getByText('Paste HTML or Blade').click();
    const pasted = inserting.getByLabel('HTML or Blade to insert');
    await pasted.fill('<div class="sve-browser-test"><p>Open');
    await inserting.getByRole('button', { name: 'Insert', exact: true }).click();
    await page.locator('.sve-notice--problem', { hasText: 'is never closed' }).waitFor();
    await idle();
    if ((await pasted.inputValue()) !== '<div class="sve-browser-test"><p>Open') {
        throw new Error('What was pasted is gone');
    }
    if ((await (await page.request.get(`${base}/`)).text()).includes('sve-browser-test')) {
        throw new Error('The refused paste is on the page');
    }
});

/** Writes the hero's file, as a code editor would. */
const writeHero = async (source) => {
    const token = decodeURIComponent((await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '');
    const now = (await (await page.request.get(heroApi)).json()).template;
    const put = await page.request.put(`${heroApi}/source`, { data: { version: now.version, source }, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token } });
    if (put.status() !== 200) {
        throw new Error(`Writing the hero answered ${put.status()}`);
    }
};

await step('put the hero back', async () => {
    await writeHero(hero.source);
    await inserting.getByRole('button', { name: 'Done' }).click();
    await page.getByRole('region', { name: 'Templates on this page' }).waitFor();
});

// --- Move, copy, delete --------------------------------------------------------------

const action = (name) => settings.getByRole('button', { name, exact: true });
const layerRow = (label, nth = 0) => page.locator('.sve-layer').filter({ has: page.locator('.sve-layer__label', { hasText: new RegExp(`^${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`) }) }).nth(nth);
const heroIs = async (source) => {
    await idle();
    const now = (await (await page.request.get(heroApi)).json()).template.source;
    if (now !== source) {
        throw new Error(`The hero is not as expected:\n${now}`);
    }
};

await step('move the heading down and up again with the buttons', async () => {
    await page.goto(`${base}/cms/visual-editor`);
    await page.waitForSelector('.sve-template');
    await frame.locator('.hero h1').click();
    await layerIs('h1');
    await action('Move down').click();
    await layerIs('h1');
    await frame.locator('.hero p.muted + h1').waitFor();
    await action('Move up').click();
    await frame.locator('.hero h1 + p.muted').waitFor();
    await heroIs(hero.source);
});

await step('duplicate it, and delete the copy with the Delete key in the layers', async () => {
    await layerIs('h1');
    await action('Duplicate').click();
    await page.waitForFunction(() => document.querySelector('iframe.sve-frame')?.contentDocument?.querySelectorAll('.hero h1').length === 2);
    await layerIs('h1');
    await idle();
    await layerRow('h1', 1).locator('.sve-layer__pick').press('Delete');
    await page.waitForFunction(() => document.querySelector('iframe.sve-frame')?.contentDocument?.querySelectorAll('.hero h1').length === 1);
    await heroIs(hero.source);
});

await step('drag a layer onto another, and see it move on the page', async () => {
    const target = layerRow('a.button');
    const box = await target.boundingBox();
    // The top of a row is before it: the heading goes to stand before the button.
    await layerRow('h1').dragTo(target, { targetPosition: { x: box.width / 2, y: 2 } });
    await frame.locator('.hero h1 + a.button').waitFor();
    await layerIs('h1');
});

await step('wrap it, unwrap it, and put the hero back', async () => {
    await idle();
    await settings.getByLabel('Wrap in').selectOption({ label: 'Container' });
    await layerIs('div');
    await frame.locator('.hero div > h1').waitFor();
    await idle();
    await action('Unwrap').click();
    await frame.locator('.hero h1 + a.button').waitFor();
    const token = decodeURIComponent((await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '');
    const now = (await (await page.request.get(heroApi)).json()).template;
    const put = await page.request.put(`${heroApi}/source`, { data: { version: now.version, source: hero.source }, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token } });
    if (put.status() !== 200) {
        throw new Error(`Putting the hero back answered ${put.status()}`);
    }
});

// --- Undo and redo -----------------------------------------------------------------

const undo = page.locator('.sve-bar').getByRole('button', { name: 'Undo', exact: true });
const redo = page.locator('.sve-bar').getByRole('button', { name: 'Redo', exact: true });
const heroNow = async () => {
    await idle();

    return (await (await page.request.get(heroApi)).json()).template.source;
};

await step('undo three changes of three kinds, one by one, back to the hero as it was', async () => {
    await page.goto(`${base}/cms/visual-editor`);
    await page.waitForSelector('.sve-template');
    await frame.locator('.hero h1').click();
    await layerIs('h1');
    if (!(await undo.isDisabled()) || !(await redo.isDisabled())) {
        throw new Error('There is something to undo before anything was done');
    }

    await settings.getByLabel('Add a class').fill('sve-browser-test');
    await settings.getByLabel('Add a class').press('Enter');
    await frame.locator('.hero h1.sve-browser-test').waitFor();
    const classed = await heroNow();
    await action('Move down').click();
    await frame.locator('.hero p.muted + h1').waitFor();
    const moved = await heroNow();
    await page.locator('.sve-bar').getByRole('button', { name: 'Insert' }).click();
    await insertable('Paragraph').click();
    await frame.locator('.hero h1 + p', { hasText: 'Text' }).waitFor();
    const inserted = await heroNow();
    if (new Set([hero.source, classed, moved, inserted]).size !== 4) {
        throw new Error('The three changes did not each change the file');
    }

    for (const expected of [moved, classed, hero.source]) {
        await undo.click();
        const now = await heroNow();
        if (now !== expected) {
            throw new Error(`After an undo the hero is not what it was:\n${now}`);
        }
    }
    await frame.locator('.hero h1:not(.sve-browser-test) + p.muted').waitFor();
    if (!(await undo.isDisabled())) {
        throw new Error('There is still something to undo');
    }
});

await step('redo, and undo again with Ctrl+Z on the page', async () => {
    await redo.click();
    await frame.locator('.hero h1.sve-browser-test').waitFor();
    // A click on the page gives the page the keyboard: the key still reaches the editor.
    await frame.locator('.hero h1').click();
    await idle();
    await frame.locator('body').press('Control+z');
    await frame.locator('.hero h1:not(.sve-browser-test)').waitFor();
    if ((await heroNow()) !== hero.source) {
        throw new Error('Ctrl+Z on the page did not bring the hero back');
    }
});

await step('say so when a change breaks the page where it is drawn, and undo it from there', async () => {
    await frame.locator('.hero h1').click();
    await layerIs('h1');
    // The menu partial needs $items, which a block doesn't have: it compiles, and fails when drawn.
    // What Laravel's own error page then does in the frame is not the editor's to answer for.
    const errorsBefore = errors.length;
    await inserting.getByLabel('Find something to insert').fill('partials/menu');
    await insertable('partials/menu').click();
    const broken = page.locator('.sve-notice--problem', { hasText: 'could not be drawn after that change' });
    await broken.waitFor({ timeout: 15000 }).catch(async (error) => {
        // What the editor and the page have to say for themselves, for whoever reads this log.
        console.log('     editor:', JSON.stringify(await page.evaluate(() => ({
            notice: document.querySelector('.sve-notice')?.textContent ?? null,
            busy: document.querySelector('.sve-shell')?.getAttribute('aria-busy'),
            templates: document.querySelector('.sve-side--left .sve-panel__body')?.textContent?.slice(0, 160) ?? null,
        }))));
        console.log('     page:', JSON.stringify(await page.evaluate(() => {
            const inFrame = document.querySelector('iframe.sve-frame')?.contentDocument;

            return {
                address: inFrame?.location.href ?? null,
                title: inFrame?.title ?? null,
                manifest: inFrame?.getElementById('sve-manifest')?.textContent?.slice(0, 120) ?? null,
                scripts: [...(inFrame?.scripts ?? [])].map((script) => script.src).filter((src) => src.includes('bridge')),
                text: inFrame?.body?.innerText.slice(0, 300) ?? null,
            };
        })));
        throw error;
    });
    await broken.getByRole('button', { name: 'Undo' }).click();
    await frame.locator('.hero h1').waitFor();
    if ((await heroNow()) !== hero.source) {
        throw new Error('The undo did not bring the hero back');
    }
    const home = await page.request.get(`${base}/`);
    if (home.status() !== 200) {
        throw new Error(`The homepage answers ${home.status()} after the undo`);
    }
    errors.length = errorsBefore;
});

await step('keep the page where it is scrolled to, never empty and its boxes in place, while a change is drawn', async () => {
    // A window low enough that the foot of the page has to be scrolled to.
    await page.setViewportSize({ width: 1440, height: 520 });
    await page.goto(`${base}/cms/visual-editor`);
    await page.waitForSelector('.sve-template');
    const last = frame.locator('.site-footer p').last();
    await last.scrollIntoViewIfNeeded();
    await last.click();
    await openIs('partials/footer');
    await layerIs('p');
    await outlineIs('selected', 'p');
    const before = await page.evaluate(() => document.querySelector('iframe.sve-frame').contentWindow.scrollY);
    if (before < 100) {
        throw new Error(`The page is scrolled to ${before}: the foot of it was to be out of sight at the top`);
    }
    // What is seen, at every frame the browser draws from here on.
    await page.evaluate(() => {
        document.querySelector('iframe.sve-frame').dataset.sveLookedAt = 'yes';
        window.sveSeen = [];
        const look = () => {
            const seen = document.querySelector('iframe.sve-frame');
            window.sveSeen.push({
                frames: document.querySelectorAll('iframe.sve-frame').length,
                scroll: seen?.contentWindow?.scrollY ?? null,
                elements: seen?.contentDocument?.body?.childElementCount ?? 0,
                boxes: document.querySelectorAll('.sve-outline--selected').length,
            });
            window.sveLooking = window.requestAnimationFrame(look);
        };
        look();
    });
    await settings.getByLabel('Add a class').fill('sve-browser-test');
    await settings.getByLabel('Add a class').press('Enter');
    await layerIs('p.sve-browser-test');
    await frame.locator('.site-footer p.sve-browser-test').waitFor();
    await outlineIs('selected', 'p.sve-browser-test');
    const seen = await page.evaluate(() => {
        window.cancelAnimationFrame(window.sveLooking);

        return window.sveSeen;
    });
    const wrong = seen.find((look) => look.frames !== 1 || look.scroll === null || Math.abs(look.scroll - before) > 1 || look.elements === 0 || look.boxes === 0);
    if (seen.length < 3 || wrong !== undefined) {
        throw new Error(`Looked ${seen.length} times, the page scrolled to ${before}, and saw ${JSON.stringify(wrong ?? null)}`);
    }
    // The new page was drawn out of sight and put in the place of the old one: a page
    // drawn again where it is being looked at goes to its top first, however briefly.
    if (await page.evaluate(() => document.querySelector('iframe.sve-frame').dataset.sveLookedAt === 'yes')) {
        throw new Error('The page was drawn again in the frame that was being looked at');
    }
    await outlineOver('selected', '.site-footer p.sve-browser-test');
    await idle();
    await settings.getByLabel('Remove the class sve-browser-test').click();
    await frame.locator('.site-footer p:not(.sve-browser-test)').last().waitFor();
    await layerIs('p');
    await idle();
    await page.setViewportSize({ width: 1440, height: 860 });
});

// --- Fields --------------------------------------------------------------------------

const picking = (label) => inserting.locator('.sve-pick__pick').filter({ has: page.locator('.sve-insertable__title', { hasText: new RegExp(`^${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`) }) });
const tab = (name) => inserting.getByRole('group', { name: 'What to insert' }).getByRole('button', { name, exact: true });
/** Fills the selected node's control from a field of the list under it. */
const useField = async (control, field) => {
    await idle();
    await settings.getByRole('button', { name: `Use a field for ${control}` }).click();
    await settings.getByRole('group', { name: `Fields for ${control}` }).locator('.sve-insertable').filter({ has: page.locator('.sve-insertable__title', { hasText: new RegExp(`^${field.replace(/[$]/g, '\\$&')}$`) }) }).click();
};

await step('build the hero from an empty file with clicks alone: elements, then the block\'s fields', async () => {
    await writeHero('');
    await page.goto(`${base}/cms/visual-editor`);
    await page.waitForSelector('.sve-template');
    // An empty template draws nothing to click on: it is opened from the list of all templates.
    await page.locator('.sve-side--left .sve-template', { hasText: 'blocks/hero' }).first().click();
    await openIs('blocks/hero');
    await page.locator('.sve-bar').getByRole('button', { name: 'Insert' }).click();

    await insertable('Section').click();
    await layerIs('section');
    await idle();
    await insertable('Heading').click();
    await layerIs('h2');
    // The heading's words become the block's heading.
    await idle();
    await layerRow('“Heading”').locator('.sve-layer__pick').click();
    await useField('the text', '$block › Heading');
    await layerIs('$block->heading');
    await frame.locator('section > h2', { hasText: 'Templates you can see' }).waitFor();

    // A paragraph after it, for the subheading.
    await idle();
    await layerRow('h2').locator('.sve-layer__pick').click();
    await insertable('Paragraph').click();
    await layerIs('p');
    await idle();
    await layerRow('“Text”').locator('.sve-layer__pick').click();
    await useField('the text', '$block › Subheading');
    await layerIs('$block->subheading');
    await frame.locator('section > p', { hasText: 'This page is drawn by a layout' }).waitFor();

    // The button, a link field, from the list of fields: a click writes the whole link.
    await idle();
    await layerRow('p').locator('.sve-layer__pick').click();
    await tab('Fields').click();
    await picking('Button').click();
    await frame.locator('section > a[href="https://example.com/docs"]', { hasText: 'Read the docs' }).waitFor();

    await heroIs([
        '<section>',
        '    <h2>{{ $block->heading }}</h2>',
        '    <p>{{ $block->subheading }}</p>',
        "    @if (($button = $block->button) && ! empty($button['url']))",
        "        <a href=\"{{ $button['url'] }}\" @if (! empty($button['new_tab'])) target=\"_blank\" rel=\"noopener\" @endif>{{ $button['label'] ?: $button['url'] }}</a>",
        '    @endif',
        '</section>',
    ].join('\n'));
});

await step('say which field a value is, and give an attribute a field\'s value', async () => {
    await layerRow('$block->heading').locator('.sve-layer__pick').click();
    await settings.locator('.sve-chip--field', { hasText: /^\$block › Heading$/ }).waitFor();

    await layerRow('section').locator('.sve-layer__pick').click();
    await idle();
    await settings.getByLabel('Name of a new attribute').fill('title');
    await settings.getByLabel('Value of the new attribute').fill('a title');
    await settings.getByRole('button', { name: 'Add', exact: true }).click();
    await frame.locator('section[title="a title"]').waitFor();
    await useField('title', '$block › Heading');
    await frame.locator('section[title="Templates you can see"]').waitFor();
    await idle();
    await settings.locator('.sve-attribute__field .sve-chip--field', { hasText: /^\$block › Heading$/ }).waitFor();
});

await step('find a menu by typing, insert it, and have its item to pick from inside its loop', async () => {
    await layerRow('p').locator('.sve-layer__pick').click();
    await idle();
    await inserting.getByLabel('Find a field or a variable').fill('main menu');
    await picking('Main menu').click();
    await frame.locator('section > ul a', { hasText: 'Articles' }).waitFor();
    await inserting.getByLabel('Find a field or a variable').fill('');
    // Where the menu's loop stands there is no $item; inside it there is.
    await idle();
    const listed = () => inserting.locator('.sve-pick__list[aria-busy="false"]').waitFor();
    await layerRow('li').locator('.sve-layer__pick').click();
    await picking('$item').waitFor();
    await picking('Label').waitFor();
    await layerRow('ul').locator('.sve-layer__pick').click();
    await page.waitForFunction(() => document.querySelector('.sve-insert__where code')?.textContent === 'ul');
    await listed();
    await picking('$block').waitFor();
    if ((await picking('$item').count()) !== 0) {
        throw new Error('The item of the loop is offered where the loop stands');
    }
    await writeHero(hero.source);
    await inserting.getByRole('button', { name: 'Done' }).click();
    await page.getByRole('region', { name: 'Templates on this page' }).waitFor();
});

await step('list the latest three articles, and show them on a condition, by choosing alone', async () => {
    await page.goto(`${base}/cms/visual-editor`);
    await page.waitForSelector('.sve-template');
    await frame.locator('.hero h1').click();
    await layerIs('h1');
    await page.locator('.sve-bar').getByRole('button', { name: 'Insert' }).click();
    await tab('Elements').click();
    await insertable('Entries: Articles').click();
    const shows = (count) => page.waitForFunction((wanted) => document.querySelector('iframe.sve-frame')?.contentDocument?.querySelectorAll('.hero article').length === wanted, count);
    await shows(5);

    // How many, and in which order: chosen, and written as what the component is given.
    await idle();
    await settings.getByLabel('Show', { exact: true }).selectOption('3');
    await shows(3);
    await idle();
    await settings.getByLabel('In the order').selectOption('-title');
    const listed = () => page.evaluate(() => [...(document.querySelector('iframe.sve-frame')?.contentDocument?.querySelectorAll('.hero article') ?? [])].map((article) => article.textContent.trim()).join(', '));
    await page.waitForFunction(() => document.querySelector('iframe.sve-frame')?.contentDocument?.querySelector('.hero article')?.textContent.trim() === 'Third post').catch(async () => {
        throw new Error(`The articles are ${await listed()}`);
    });
    if ((await listed()) !== 'Third post, Second post, Fourth post') {
        throw new Error(`The articles are ${await listed()}`);
    }
    if (!(await heroNow()).includes('<x-sunrice::entries collection="articles" :limit="3" order-by="-title">')) {
        throw new Error(`The component is not written as chosen:\n${await heroNow()}`);
    }
    await settings.getByLabel('From').selectOption('pages');
    await frame.locator('.hero article', { hasText: 'About' }).waitFor();
    await idle();
    await settings.getByLabel('From').selectOption('articles');
    await shows(3);

    // Around it a condition, asked of a field from the list, then turned round.
    await idle();
    await settings.getByLabel('Wrap in').selectOption({ label: 'Condition' });
    await layerIs('@if (true)');
    await idle();
    await settings.getByRole('button', { name: 'Use a field for the condition' }).click();
    await settings.getByRole('group', { name: 'Fields for the condition' }).locator('.sve-insertable').filter({ has: page.locator('.sve-insertable__title', { hasText: /^\$block › Subheading$/ }) }).click();
    await layerIs('@if ($block->subheading)');
    await shows(3);
    await idle();
    await settings.getByLabel('What is asked of it').selectOption({ label: 'is not set' });
    await layerIs('@if (! $block->subheading)');
    await shows(0);
    await idle();
    await settings.getByLabel('What is asked of it').selectOption({ label: 'is' });
    await layerIs("@if ($block->subheading == '')");
    await idle();
    await settings.getByLabel('Compared with').fill('Nothing the hero says');
    await settings.getByLabel('Compared with').press('Enter');
    await layerIs("@if ($block->subheading == 'Nothing the hero says')");
    await idle();
    await settings.getByLabel('What is asked of it').selectOption({ label: 'is set' });
    await shows(3);

    // And a menu, from the list of what the site has: no word typed.
    await idle();
    await layerRow('h1').locator('.sve-layer__pick').click();
    await tab('Fields').click();
    await picking('Main menu').click();
    await frame.locator('.hero ul a', { hasText: 'Articles' }).waitFor();
    await idle();
    // The loop says what it goes over.
    await layerRow("@foreach (sunrice_menu('main') as $item)").locator('.sve-layer__pick').click();
    await settings.locator('.sve-chip--field', { hasText: /^Main menu$/ }).waitFor();

    await writeHero(hero.source);
    await inserting.getByRole('button', { name: 'Done' }).click();
    await page.getByRole('region', { name: 'Templates on this page' }).waitFor();
});

// --- CSS ---------------------------------------------------------------------------

const css = page.locator('.sve-side--right .sve-css');
const sheet = async (path) => {
    const response = await page.request.get(`${base}/sunrice-theme/${path}`);

    return response.ok() ? response.text() : null;
};
/** Waits until a stylesheet reads as expected, and says how it reads when it never does. */
const sheetIs = async (path, expected) => {
    let now = null;
    for (let tries = 0; tries < 40; tries++) {
        now = await sheet(path);
        if (now === expected) {
            return;
        }
        await page.waitForTimeout(100);
    }
    throw new Error(`${path} is not what it should be:\n${now === null ? '(not there)' : now.slice(-400)}`);
};
const styleOf = (selector, property) => frame.locator(selector).first().evaluate((element, name) => getComputedStyle(element).getPropertyValue(name), property);
const styleIs = async (selector, property, expected) => {
    for (let tries = 0; tries < 40; tries++) {
        if ((await styleOf(selector, property)) === expected) {
            return;
        }
        await page.waitForTimeout(100);
    }
    throw new Error(`${property} of ${selector} is ${await styleOf(selector, property)}, not ${expected}`);
};
const appCss = await sheet('app.css');
const mutedRule = '.muted { color: var(--muted); }';

await step('show the rule for an element\'s class, change one value, and see it on the page as it stands', async () => {
    if (appCss === null || !appCss.includes(mutedRule)) {
        throw new Error('The starter stylesheet is not the one this check knows');
    }
    await page.goto(`${base}/cms/visual-editor`);
    await page.waitForSelector('.sve-template');
    await frame.locator('.hero p.muted').click();
    await layerIs('p.muted');
    await settings.getByRole('button', { name: 'CSS', exact: true }).click();
    await css.getByRole('group', { name: '.muted', exact: true }).waitFor();
    if ((await css.getByLabel('Value of color in .muted').inputValue()) !== 'var(--muted)') {
        throw new Error('The rule does not show what the file says');
    }

    // The page in the frame is not drawn again for a stylesheet: this mark of it stays.
    await frame.locator('body').evaluate((body) => body.setAttribute('data-test-same-page', '1'));
    await css.getByLabel('Value of color in .muted').fill('rgb(1, 2, 3)');
    await css.getByLabel('Value of color in .muted').press('Enter');
    await sheetIs('app.css', appCss.replace(mutedRule, '.muted { color: rgb(1, 2, 3); }'));
    await styleIs('.hero p.muted', 'color', 'rgb(1, 2, 3)');
    await frame.locator('body[data-test-same-page]').waitFor();
    await outlineOver('selected', '.hero p.muted');
});

await step('add declarations, one typed and two pasted, rename one and take one out', async () => {
    await css.getByLabel('New property for .muted', { exact: true }).fill('font-style');
    await css.getByLabel('Value of the new property for .muted').fill('italic');
    await css.getByLabel('Value of the new property for .muted').press('Enter');
    await styleIs('.hero p.muted', 'font-style', 'italic');
    await css.getByLabel('New property for .muted', { exact: true }).fill('letter-spacing: 2px; text-transform: uppercase');
    await css.getByLabel('New property for .muted', { exact: true }).press('Enter');
    await styleIs('.hero p.muted', 'letter-spacing', '2px');
    await styleIs('.hero p.muted', 'text-transform', 'uppercase');
    await sheetIs('app.css', appCss.replace(mutedRule, '.muted { color: rgb(1, 2, 3); font-style: italic; letter-spacing: 2px; text-transform: uppercase; }'));

    await css.getByLabel('Property 3 of .muted').fill('word-spacing');
    await css.getByLabel('Property 3 of .muted').press('Enter');
    await styleIs('.hero p.muted', 'word-spacing', '2px');
    await css.getByLabel('Remove text-transform from .muted').click();
    await styleIs('.hero p.muted', 'text-transform', 'none');
    await sheetIs('app.css', appCss.replace(mutedRule, '.muted { color: rgb(1, 2, 3); font-style: italic; word-spacing: 2px; }'));
    await mkdir('docs/screenshots', { recursive: true });
    await page.screenshot({ path: 'docs/screenshots/css.png' });
});

await step('refuse a value that would break out of its rule, say why, and show the file again', async () => {
    const before = await sheet('app.css');
    await css.getByLabel('Value of color in .muted').fill('red; } body { display: none');
    await css.getByLabel('Value of color in .muted').press('Enter');
    await page.locator('.sve-notice--problem').getByText('not one value').waitFor();
    await page.waitForFunction(() => document.querySelector('.sve-css [aria-label="Value of color in .muted"]')?.value === 'rgb(1, 2, 3)');
    if ((await sheet('app.css')) !== before) {
        throw new Error('The stylesheet was changed by a value that was refused');
    }
    await styleIs('body', 'display', 'block');
});

await step('undo every change to the stylesheet, back to the file as it was byte for byte, and redo one', async () => {
    const undoCss = css.getByRole('button', { name: 'Undo in app.css' });
    // Five changes were made; the one refused left nothing to undo.
    const versions = new Set([await sheet('app.css')]);
    for (let steps = 0; steps < 5; steps++) {
        const before = await sheet('app.css');
        await undoCss.click();
        for (let tries = 0; tries < 40 && (await sheet('app.css')) === before; tries++) {
            await page.waitForTimeout(100);
        }
        versions.add(await sheet('app.css'));
    }
    if (versions.size !== 6) {
        throw new Error(`Five undos went through ${versions.size - 1} other versions of the file`);
    }
    await sheetIs('app.css', appCss);
    await styleIs('.hero p.muted', 'font-style', 'normal');
    await page.waitForFunction(() => document.querySelector('.sve-css [aria-label="Undo in app.css"]')?.disabled === true);

    await css.getByRole('button', { name: 'Redo in app.css' }).click();
    await sheetIs('app.css', appCss.replace(mutedRule, '.muted { color: rgb(1, 2, 3); }'));
    await styleIs('.hero p.muted', 'color', 'rgb(1, 2, 3)');
    await undoCss.click();
    await sheetIs('app.css', appCss);
    await frame.locator('body[data-test-same-page]').waitFor();
});

await step('write a rule for a class that has none into the editor\'s own stylesheet, and link it from the layout', async () => {
    await settings.getByRole('button', { name: 'Settings', exact: true }).click();
    await settings.getByLabel('Add a class').fill('sve-new');
    await settings.getByLabel('Add a class').press('Enter');
    await frame.locator('.hero p.muted.sve-new').waitFor();
    await idle();
    await settings.getByRole('button', { name: 'CSS', exact: true }).click();
    await css.getByRole('button', { name: '.sve-new', exact: true }).click();
    const fresh = css.getByRole('group', { name: 'A new rule for .sve-new' });
    await fresh.getByText('no rule yet').waitFor();
    await fresh.getByLabel('New property for .sve-new in editor.css', { exact: true }).fill('outline');
    await fresh.getByLabel('Value of the new property for .sve-new in editor.css').fill('3px solid rgb(4, 5, 6)');
    await fresh.getByLabel('Value of the new property for .sve-new in editor.css').press('Enter');
    await sheetIs('editor.css', '.sve-new {\n    outline: 3px solid rgb(4, 5, 6);\n}\n');
    await css.getByRole('group', { name: '.sve-new', exact: true }).waitFor();

    // The page does not link that stylesheet yet: the panel says so, and links it.
    await css.getByText('This page does not link').waitFor();
    if ((await styleOf('.hero p.sve-new', 'outline-color')) === 'rgb(4, 5, 6)') {
        throw new Error('The rule shows on a page that does not link its stylesheet');
    }
    await css.getByRole('button', { name: 'Link it from layouts/app' }).click();
    await page.locator('.sve-notice').getByText('Linked editor.css from layouts/app.blade.php.').waitFor();
    await styleIs('.hero p.sve-new', 'outline-color', 'rgb(4, 5, 6)');
    await page.waitForFunction(() => document.querySelector('.sve-css__unlinked') === null);
    // What was selected still is, and the rule is changed like any other.
    await layerIs('p.muted');
    await outlineOver('selected', '.hero p.sve-new');
    await css.getByLabel('Value of outline in .sve-new').fill('1px dashed rgb(7, 8, 9)');
    await css.getByLabel('Value of outline in .sve-new').press('Enter');
    await styleIs('.hero p.sve-new', 'outline-color', 'rgb(7, 8, 9)');
    await sheetIs('editor.css', '.sve-new {\n    outline: 1px dashed rgb(7, 8, 9);\n}\n');
});

await step('take the class and the link away again', async () => {
    await settings.getByRole('button', { name: 'Settings', exact: true }).click();
    await idle();
    await settings.getByLabel('Remove the class sve-new').click();
    await frame.locator('.hero p.muted:not(.sve-new)').waitFor();
    await idle();

    // The link was a change to the layout: its undo takes it out.
    await page.locator('.sve-side--left .sve-templates').first().locator('.sve-template', { hasText: 'layouts/app' }).click();
    await openIs('layouts/app');
    await undo.click();
    await idle();
    const layout = (await (await page.request.get(`${base}/cms/visual-editor/api/templates/layouts/app.blade.php`)).json()).template.source;
    if (layout.includes('editor.css')) {
        throw new Error('The layout still links the editor\'s stylesheet');
    }
    await page.waitForFunction(() => document.querySelector('iframe.sve-frame')?.contentDocument?.querySelector('link[href*="editor.css"]') === null);
});

// --- Style -------------------------------------------------------------------------

const style = page.locator('.sve-side--right .sve-style');
const cardRule = '.card { border: 1px solid var(--line); border-radius: 12px; padding: 20px; }';
const cardIs = (declarations) => sheetIs('app.css', appCss.replace(cardRule, `.card { ${declarations} }`));

await step('show the rule for a class as controls: what it says, and in grey what the page has otherwise', async () => {
    if (!appCss.includes(cardRule)) {
        throw new Error('The starter stylesheet is not the one this check knows');
    }
    await page.goto(`${base}/cms/visual-editor?url=/articles`);
    await page.waitForSelector('.sve-template');
    await frame.locator('.card').first().click({ position: { x: 3, y: 3 } });
    await layerIs('li.card');
    await settings.getByRole('button', { name: 'Style', exact: true }).click();
    await style.getByText('Writes .card in app.css, line 59.').waitFor();

    if ((await style.getByLabel('Spacing: Padding').inputValue()) !== '20px' || (await style.getByLabel('Border: Radius').inputValue()) !== '12px') {
        throw new Error('The controls do not show what the rule says');
    }
    // Nothing set for these: the page says what the element has.
    await page.waitForFunction(() => document.querySelector('.sve-style [aria-label="Text: Size"]')?.getAttribute('placeholder') === '16px');
    if ((await style.getByLabel('Text: Size').inputValue()) !== '' || !/^\(block|list-item\)$/.test(await style.getByLabel('Layout: Display').locator('option').first().textContent())) {
        throw new Error('A control shows as set what the rule does not say');
    }
    // Controls for a flex container are not shown for what is none.
    if ((await style.getByLabel('Layout: Direction').count()) !== 0) {
        throw new Error('The direction of a flex container is offered for an element that is none');
    }
});

await step('set one declaration from each kind of control, and see each on the page and in the file, nothing else changed', async () => {
    await style.getByLabel('Layout: Display').selectOption('flex');
    await styleIs('.card', 'display', 'flex');
    await cardIs('border: 1px solid var(--line); border-radius: 12px; padding: 20px; display: flex;');
    // Now it is a flex container: its controls are there.
    await style.getByLabel('Layout: Direction').selectOption('column');
    await styleIs('.card', 'flex-direction', 'column');

    await style.getByLabel('Spacing: Padding').fill('32px');
    await style.getByLabel('Spacing: Padding').press('Enter');
    await styleIs('.card', 'padding-top', '32px');

    await style.getByLabel('Background: Colour', { exact: true }).fill('rgb(254, 243, 199)');
    await style.getByLabel('Background: Colour', { exact: true }).press('Enter');
    await styleIs('.card', 'background-color', 'rgb(254, 243, 199)');
    // The picker starts from the colour that was typed.
    await page.waitForFunction(() => document.querySelector('.sve-style [aria-label="Pick Background: Colour"]')?.value === '#fef3c7');
    await cardIs('border: 1px solid var(--line); border-radius: 12px; padding: 32px; display: flex; flex-direction: column; background-color: rgb(254, 243, 199);');
    await outlineOver('selected', '.card');
    await mkdir('docs/screenshots', { recursive: true });
    await page.screenshot({ path: 'docs/screenshots/style.png' });
});

await step('take a declaration out by emptying its control, and see the same rule in the CSS tab', async () => {
    await style.getByLabel('Layout: Direction').selectOption('');
    await style.getByLabel('Border: Radius').fill('');
    await style.getByLabel('Border: Radius').press('Enter');
    await styleIs('.card', 'border-top-left-radius', '0px');
    await cardIs('border: 1px solid var(--line); padding: 32px; display: flex; background-color: rgb(254, 243, 199);');

    await settings.getByRole('button', { name: 'CSS', exact: true }).click();
    if ((await css.getByLabel('Value of background-color in .card', { exact: true }).inputValue()) !== 'rgb(254, 243, 199)') {
        throw new Error('The CSS tab does not show what the Style tab wrote');
    }
    // Six changes, undone there: the file as it was.
    for (let steps = 0; steps < 6; steps++) {
        const before = await sheet('app.css');
        await css.getByRole('button', { name: 'Undo in app.css' }).click();
        for (let tries = 0; tries < 40 && (await sheet('app.css')) === before; tries++) {
            await page.waitForTimeout(100);
        }
    }
    await sheetIs('app.css', appCss);
    await settings.getByRole('button', { name: 'Style', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('.sve-style [aria-label="Spacing: Padding"]')?.value === '20px');
});

// --- Widths ------------------------------------------------------------------------

const widthChoice = page.getByLabel('Width of the page');
const widthIn = (frameLocator) => frameLocator.locator('html').evaluate(() => window.innerWidth);
const beside = (nth) => page.frameLocator(`iframe.sve-beside >> nth=${nth}`);
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

/** The outline of what is selected comes to lie over the element: after the frame changed size, the page measures again. */
const outlineSettles = (selector) =>
    until(`The outline is not on ${selector}`, async () => {
        try {
            await outlineOver('selected', selector);

            return true;
        } catch (error) {
            return error.message;
        }
    });

await step('show the page at one width: its frame is that wide, and what is selected stays outlined', async () => {
    // The card is still selected, on the articles page.
    await widthChoice.selectOption({ label: 'Mobile · 390' });
    await until('The frame is not 390 wide', async () => (await widthIn(frame)) === 390 || (await widthIn(frame)));
    // The page as a phone has it: the menu is folded away.
    await styleIs('.site-menu', 'display', 'none');
    await layerIs('li.card');
    await outlineSettles('.card');
});

await step('show every width side by side, each frame at its own, and edit in the one that is picked', async () => {
    await widthChoice.selectOption({ label: 'Side by side' });
    await page.locator('.sve-slot__editing').waitFor();
    await until('The frames are not at their widths', async () => {
        const at = [await widthIn(frame), await widthIn(beside(0)), await widthIn(beside(1))];

        return (at[0] === 390 && at[1] === 1280 && at[2] === 768) || at;
    });
    // Drawn at one scale, in the order of their widths.
    const boxes = await page.locator('.sve-slot').evaluateAll((slots) => slots.map((slot) => ({ name: slot.querySelector('.sve-slot__name').textContent, left: slot.getBoundingClientRect().left, width: slot.getBoundingClientRect().width })));
    const ordered = [...boxes].sort((a, b) => a.left - b.left);
    if (ordered.map((box) => box.name).join() !== 'Desktop,Tablet,Mobile' || Math.abs(ordered[0].width / ordered[2].width - 1280 / 390) > 0.05) {
        throw new Error(`The frames are laid out as ${JSON.stringify(ordered)}`);
    }
    await beside(0).locator('.card').first().waitFor();
    await beside(1).locator('.card').first().waitFor();
});

await step('write a style set at a width under the @media for it: it shows at that width and at no other', async () => {
    const forWidths = style.getByLabel('The widths it is set for');
    await page.waitForFunction(() => document.querySelector('.sve-style [aria-label="The widths it is set for"]')?.value === '@media (max-width: 760px)');
    await style.getByText('has no rule under (max-width: 760px) yet').waitFor();
    // Nothing is set for this width: the control shows what the page has, from the rule for every width.
    await page.waitForFunction(() => document.querySelector('.sve-style [aria-label="Spacing: Padding"]')?.getAttribute('placeholder') === '20px');

    await style.getByLabel('Spacing: Padding').fill('6px');
    await style.getByLabel('Spacing: Padding').press('Enter');
    await sheetIs('app.css', `${appCss}\n@media (max-width: 760px) {\n    .card {\n        padding: 6px;\n    }\n}\n`);
    await styleIs('.card', 'padding-top', '6px');
    await style.getByText('Writes .card under (max-width: 760px) in app.css').waitFor();
    // The frames beside have the new stylesheet too, and at their widths it says nothing new.
    await until('The other widths do not show the stylesheet as it is now', async () => {
        const versions = [];
        for (const one of [frame, beside(0), beside(1)]) {
            versions.push(await one.locator('link[rel="stylesheet"][href*="app.css"]').first().evaluate((link) => new URL(link.href).searchParams.get('sve-v')));
        }

        return (versions[0] !== null && versions.every((version) => version === versions[0])) || versions;
    });
    for (const nth of [0, 1]) {
        const padding = await beside(nth).locator('.card').first().evaluate((element) => getComputedStyle(element).paddingTop);
        if (padding !== '20px') {
            throw new Error(`At another width the card's padding is ${padding}`);
        }
    }
    await mkdir('docs/screenshots', { recursive: true });
    await page.screenshot({ path: 'docs/screenshots/widths.png' });

    // A second thing set goes into the same rule.
    await style.getByLabel('Border: Radius').fill('4px');
    await style.getByLabel('Border: Radius').press('Enter');
    await sheetIs('app.css', `${appCss}\n@media (max-width: 760px) {\n    .card {\n        padding: 6px;\n        border-radius: 4px;\n    }\n}\n`);

    // For every width, by choice: the rule outside any @media, as before.
    await forWidths.selectOption({ label: 'Every width' });
    await page.waitForFunction(() => document.querySelector('.sve-style [aria-label="Spacing: Padding"]')?.value === '20px');
    await style.getByText('Set for every width, though the page is shown at 390 pixels.').waitFor();
});

await step('edit at another of the widths: the frames keep their pages, and styles are for every width again', async () => {
    await beside(0).locator('body').evaluate((body) => body.setAttribute('data-test-same-page', '1'));
    await page.locator('.sve-slot', { hasText: 'Desktop' }).getByRole('button', { name: 'Edit here' }).click();
    await until('The frames did not change widths', async () => {
        const at = [await widthIn(frame), await widthIn(beside(0)), await widthIn(beside(1))];

        return (at[0] === 1280 && at[1] === 768 && at[2] === 390) || at;
    });
    // The frame that was at the side is the same page, now narrower: nothing was loaded anew.
    await beside(0).locator('body[data-test-same-page]').waitFor();
    await layerIs('li.card');
    await page.waitForFunction(() => document.querySelector('.sve-style [aria-label="The widths it is set for"]')?.value === '');
    await style.getByText('Writes .card in app.css, line 59.').waitFor();
    if ((await styleOf('.card', 'padding-top')) !== '20px' || (await beside(1).locator('.card').first().evaluate((element) => getComputedStyle(element).paddingTop)) !== '6px') {
        throw new Error('The rule for narrow pages does not show where it should, or shows where it should not');
    }
});

await step('draw a template change in every frame, then put the stylesheet and the view back', async () => {
    await settings.getByRole('button', { name: 'Settings', exact: true }).click();
    await settings.getByLabel('Add a class').fill('sve-browser-test');
    await settings.getByLabel('Add a class').press('Enter');
    for (const one of [frame, beside(0), beside(1)]) {
        await one.locator('.card.sve-browser-test').first().waitFor();
    }
    await idle();
    await settings.getByLabel('Remove the class sve-browser-test').click();
    for (const one of [frame, beside(0), beside(1)]) {
        await one.locator('.card:not(.sve-browser-test)').first().waitFor();
    }
    await idle();

    await settings.getByRole('button', { name: 'CSS', exact: true }).click();
    for (let steps = 0; steps < 2; steps++) {
        const before = await sheet('app.css');
        await css.getByRole('button', { name: 'Undo in app.css' }).click();
        for (let tries = 0; tries < 40 && (await sheet('app.css')) === before; tries++) {
            await page.waitForTimeout(100);
        }
    }
    await sheetIs('app.css', appCss);
    await widthChoice.selectOption({ label: 'Full width' });
    await page.waitForFunction(() => document.querySelectorAll('iframe.sve-beside').length === 0 && document.querySelector('.sve-slot__head') === null);
    // The page is as wide as the room again, and says where its elements now are.
    await outlineSettles('.card');
});

// --- Hiding and showing ----------------------------------------------------------

const bar = page.locator('.sve-bar');
const fold = (name) => page.locator('.sve-fold', { hasText: name }).first();
const laidOut = () => page.evaluate(() => JSON.parse(localStorage.getItem('sunrice-visual-editor:layout') ?? 'null'));

await step('fold a panel to its title and open it again, with what was in it as it was', async () => {
    await page.goto(`${base}/cms/visual-editor`);
    await page.waitForSelector('.sve-template');
    await frame.locator('.hero h1').click();
    await layerIs('h1');

    await fold('Layers').click();
    await page.waitForFunction(() => document.querySelector('.sve-panel[aria-label="Layers"] .sve-panel__body')?.hidden === true);
    if ((await fold('Layers').getAttribute('aria-expanded')) !== 'false' || !(await page.locator('.sve-template__name', { hasText: 'blocks/hero' }).first().isVisible())) {
        throw new Error('Layers is not folded alone');
    }
    await fold('Layers').click();
    await page.locator('.sve-layer[aria-selected="true"]').waitFor();
    await layerIs('h1');
});

await step('hide the panels on both sides: the page takes their room, and the outline stays on its element', async () => {
    const before = await widthIn(frame);
    await bar.getByRole('button', { name: 'Panels on the right' }).click();
    await bar.getByRole('button', { name: 'Panels on the left' }).click();
    await until('The sides are still there', async () => (await page.locator('.sve-side:visible').count()) === 0 || (await page.locator('.sve-side:visible').count()));
    await until('The page did not take the room', async () => (await widthIn(frame)) >= before + 400 || (await widthIn(frame)));
    await outlineSettles('.hero h1');
});

await step('find the editor as it was left when it is opened again', async () => {
    await page.goto(`${base}/cms/visual-editor`);
    await frame.getByText('Templates you can see').waitFor();
    if ((await page.locator('.sve-side:visible').count()) !== 0) {
        throw new Error('A side that was hidden is shown again');
    }
    for (const side of ['left', 'right']) {
        if ((await bar.getByRole('button', { name: `Panels on the ${side}` }).getAttribute('aria-pressed')) !== 'false') {
            throw new Error(`The button for the ${side} side says it is shown`);
        }
    }
});

await step('fly a hidden side out over the page: the page keeps its width, and Escape or a click on the page puts the side away', async () => {
    const before = await widthIn(frame);
    const flown = page.locator('.sve-side--flown');
    const edge = (side) => page.getByRole('button', { name: `Fly out the panels on the ${side}` });

    await edge('right').click();
    await page.locator('.sve-side--right.sve-side--flown').waitFor();
    const side = await flown.boundingBox();
    const shown = await page.locator('iframe.sve-frame').boundingBox();
    if ((await widthIn(frame)) !== before || side === null || shown === null || side.x < shown.x || side.x >= shown.x + shown.width) {
        throw new Error(`The page was ${before} wide and is ${await widthIn(frame)}; the side is at ${JSON.stringify(side)}, the page at ${JSON.stringify(shown)}`);
    }
    // The keyboard went with it, and comes back to what flies it out.
    await page.keyboard.press('Escape');
    await flown.waitFor({ state: 'detached' });
    await until('The keyboard is not on what flies the side out', async () => (await edge('right').evaluate((button) => button === document.activeElement)) || (await page.evaluate(() => document.activeElement?.className ?? null)));

    // What is changed in it is changed as beside the page, and the page drawn again leaves it out.
    await frame.locator('.hero h1').click();
    await layerIs('h1');
    await edge('right').click();
    await flown.getByLabel('Add a class').fill('flown-out');
    await flown.getByLabel('Add a class').press('Enter');
    await frame.locator('.hero h1.flown-out').waitFor();
    await idle();
    await flown.getByLabel('Remove the class flown-out').click();
    await idle();
    await until('The class is still on the page', async () => (await frame.locator('.hero h1.flown-out').count()) === 0);
    if (!(await flown.isVisible()) || (await widthIn(frame)) !== before) {
        throw new Error('The side went away, or the page changed its width, while something was changed in the side');
    }

    // A click on the page is for the page.
    await frame.locator('.hero .muted').click();
    await flown.waitFor({ state: 'detached' });
    await layerIs('p.muted');
    const kept = await laidOut();
    if (kept === null || kept.left !== false || kept.right !== false || 'flown' in kept) {
        throw new Error(`After a side was flown out the editor is left as ${JSON.stringify(kept)}`);
    }

    // The other side, kept open: it has its room back, as from the bar.
    await edge('left').click();
    await page.locator('.sve-side--left.sve-side--flown .sve-layer[aria-selected="true"]').waitFor();
    await flown.getByRole('button', { name: 'Keep open' }).click();
    await page.locator('.sve-side--left:not(.sve-side--flown)').waitFor();
    await until('The page did not give the side its room', async () => (await widthIn(frame)) <= before - 200 || (await widthIn(frame)));
    if ((await bar.getByRole('button', { name: 'Panels on the left' }).getAttribute('aria-pressed')) !== 'true' || (await edge('left').count()) !== 0) {
        throw new Error('The side that was kept open is not shown as one beside the page');
    }
    await bar.getByRole('button', { name: 'Panels on the left' }).click();
    await edge('left').waitFor();
    if ((await flown.count()) !== 0) {
        throw new Error('A side hidden from the bar flew out by itself');
    }
});

await step('bring the left side back by asking to insert something, and the right one from the bar', async () => {
    await bar.getByRole('button', { name: 'Insert' }).click();
    await page.locator('.sve-side--left .sve-panel[aria-label="Insert"]').waitFor();
    await bar.getByRole('button', { name: 'Insert' }).click();
    await page.locator('.sve-side--left .sve-panel[aria-label="Templates on this page"]').waitFor();
    await bar.getByRole('button', { name: 'Panels on the right' }).click();
    await page.locator('.sve-side--right').waitFor();
});

await step('show and hide the code from the bar, and fold a group of controls inside a panel', async () => {
    await frame.locator('.hero .muted').click();
    await layerIs('p.muted');
    const codeButton = bar.getByRole('button', { name: 'Code under the page' });
    await codeButton.click();
    await page.locator('.sve-code').waitFor();
    // Long enough for the code editor to have come and shown the template.
    await page.locator('.sve-code .cm-editor, .sve-code .sve-code__field').first().waitFor();
    await page.waitForTimeout(400);
    await codeButton.click();
    await page.waitForFunction(() => document.querySelector('.sve-code') === null);
    // Showing the code and hiding it selects nothing else.
    const still = await page.evaluate(() => document.querySelector('.sve-layer[aria-selected="true"] .sve-layer__label')?.textContent ?? null);
    if (still !== 'p.muted') {
        throw new Error(`After the code was shown and hidden, ${still} is selected, in ${await page.evaluate(() => document.querySelector('.sve-trail__here')?.textContent)}`);
    }

    await settings.getByRole('button', { name: 'Style', exact: true }).click();
    const spacing = settings.getByRole('group', { name: 'Spacing' });
    await spacing.getByLabel('Spacing: Padding').waitFor();
    await spacing.locator('.sve-fold').click();
    await page.waitForFunction(() => document.querySelector('.sve-style [aria-label="Spacing: Padding"]')?.closest('[hidden]') !== null);
    if (!(await settings.getByRole('group', { name: 'Text' }).locator('input, select').first().isVisible())) {
        throw new Error('Another group folded with it');
    }
    await spacing.locator('.sve-fold').click();
    await spacing.getByLabel('Spacing: Padding').waitFor();
    await settings.getByRole('button', { name: 'Settings', exact: true }).click();

    // Everything is shown and open again, and that is what is remembered.
    const kept = await laidOut();
    if (kept === null || kept.left !== true || kept.right !== true || kept.folded.length !== 0) {
        throw new Error(`The editor is left as ${JSON.stringify(kept)}`);
    }
});

// --- The code panel --------------------------------------------------------------

const code = page.locator('.sve-code__editor');
/** The code editor's text, asked of the editor itself: it draws only the lines that are in view. */
const codeText = () => code.evaluate((holder) => holder.sveCodeEditor.text());
const onDisk = async (path) => (await (await page.request.get(`${base}/cms/visual-editor/api/templates/${path}`)).json()).template.source;
const codeIs = async (expected) => {
    for (let tries = 0; tries < 50 && (await codeText()) !== expected; tries++) {
        await page.waitForTimeout(100);
    }
    if ((await codeText()) !== expected) {
        throw new Error(`The editor holds ${JSON.stringify((await codeText()).slice(-90))}`);
    }
};
const added = '<p class="sve-browser-test">Edited in the code panel.</p>';
let footer = '';

await step('open the footer as code in the code editor, coloured and character for character', async () => {
    await page.goto(`${base}/cms/visual-editor`);
    await page.waitForSelector('.sve-template');
    await page.locator('.sve-side--left .sve-templates').first().locator('.sve-template', { hasText: 'partials/footer' }).click();
    await openIs('partials/footer');
    await page.getByRole('button', { name: 'Edit as code' }).click();
    await code.locator('.cm-editor').waitFor();
    footer = await codeText();
    if (!footer.includes('<footer') || footer !== (await onDisk('partials/footer.blade.php'))) {
        throw new Error('The editor does not hold the footer as the file has it');
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
    await page.locator('.sve-code__bar').getByText('Not saved').waitFor();
    await page.keyboard.press('Control+s');
    await frame.getByText('Edited in the code panel.').waitFor();
    await page.waitForFunction(() => document.querySelector('.sve-code__bar .sve-tag') === null);
    await page.locator('.sve-notice--done', { hasText: 'Saved partials/footer.blade.php.' }).waitFor();
    if ((await onDisk('partials/footer.blade.php')) !== `${footer}${added}\n`) {
        throw new Error('The file is not what was typed');
    }
});

await step('find and replace in the code, and undo it with the editor\'s own undo', async () => {
    await page.keyboard.press('Control+f');
    await code.locator('.cm-search input[name=search]').fill('Edited in the code panel.');
    await code.locator('.cm-search input[name=replace]').fill('Changed by find and replace.');
    await code.locator('.cm-search button[name=replaceAll]').click();
    await codeIs(`${footer}${added.replace('Edited in the code panel.', 'Changed by find and replace.')}\n`);
    await page.locator('.sve-code__bar').getByText('Not saved').waitFor();

    await page.keyboard.press('Escape');
    await code.evaluate((holder) => holder.sveCodeEditor.focus());
    await page.keyboard.press('Control+z');
    await codeIs(`${footer}${added}\n`);
    // The text is the file's again: nothing is left to save.
    await page.waitForFunction(() => document.querySelector('.sve-code__bar .sve-tag') === null);
});

await step('refuse code that doesn\'t compile, say why, and leave the site as it was', async () => {
    const good = await codeText();
    await page.keyboard.press('Control+End');
    await page.keyboard.type('@if ($neverClosed)');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.locator('.sve-code__trouble').getByText('doesn’t compile').waitFor();
    // Nothing was written: the page still renders, with the last good footer.
    const home = await page.request.get(`${base}/`);
    if (home.status() !== 200 || !(await home.text()).includes('Edited in the code panel.')) {
        throw new Error(`The homepage answered ${home.status()} after a refused save`);
    }
    await page.getByRole('button', { name: 'Revert' }).click();
    await codeIs(good);
});

await step('put the footer back', async () => {
    await code.evaluate((holder, text) => {
        holder.sveCodeEditor.setText(text);
        holder.sveCodeEditor.focus();
    }, footer);
    await page.locator('.sve-code__bar').getByText('Not saved').waitFor();
    await page.keyboard.press('Control+s');
    await page.waitForFunction(() => document.querySelector('.sve-code__bar .sve-tag') === null);
    if ((await onDisk('partials/footer.blade.php')) !== footer) {
        throw new Error('The footer is not as it was');
    }
    await page.getByRole('button', { name: 'Hide the code' }).click();
});

/** Writes a template through the editor's API, as the code panel's Save does: for setting up what can't be typed. */
const write = (path, source) =>
    page.evaluate(
        async ([address, text]) => {
            const token = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
            const headers = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' };
            const now = await (await fetch(address, { headers, credentials: 'same-origin' })).json();
            const answer = await fetch(`${address}/source`, { method: 'PUT', headers, credentials: 'same-origin', body: JSON.stringify({ version: now.template.version, source: text }) });

            return answer.status;
        },
        [`${base}/cms/visual-editor/api/templates/${path}`, source],
    );

await step('give a file written on Windows back with the line breaks it came with', async () => {
    const windows = footer.replaceAll('\n', '\r\n');
    if ((await write('partials/footer.blade.php', windows)) !== 200) {
        throw new Error('The footer could not be written with other line breaks');
    }
    await page.goto(`${base}/cms/visual-editor`);
    await page.waitForSelector('.sve-template');
    await page.locator('.sve-side--left .sve-templates').first().locator('.sve-template', { hasText: 'partials/footer' }).click();
    await openIs('partials/footer');
    await page.getByRole('button', { name: 'Edit as code' }).click();
    await code.locator('.cm-editor').waitFor();
    await codeIs(windows);

    await code.evaluate((holder) => holder.sveCodeEditor.focus());
    await page.keyboard.press('Control+End');
    await page.keyboard.type('<!-- typed -->');
    await page.keyboard.press('Enter');
    await page.keyboard.press('Control+s');
    await page.waitForFunction(() => document.querySelector('.sve-code__bar .sve-tag') === null);
    await page.locator('.sve-notice--done', { hasText: 'Saved partials/footer.blade.php.' }).waitFor();
    // What was typed, a line break of the file's own kind after it, and not one other byte.
    if ((await onDisk('partials/footer.blade.php')) !== `${windows}<!-- typed -->\r\n`) {
        throw new Error('The file did not keep its line breaks');
    }

    if ((await write('partials/footer.blade.php', footer)) !== 200 || (await onDisk('partials/footer.blade.php')) !== footer) {
        throw new Error('The footer is not as it was');
    }
});

await step('show the code in a plain field when the code editor\'s script can\'t be fetched, and save from there', async () => {
    await page.route('**/code-editor.js*', (route) => route.abort());
    await page.goto(`${base}/cms/visual-editor`);
    await page.waitForSelector('.sve-template');
    await page.locator('.sve-side--left .sve-templates').first().locator('.sve-template', { hasText: 'partials/footer' }).click();
    await openIs('partials/footer');
    await page.getByRole('button', { name: 'Edit as code' }).click();
    const field = page.locator('.sve-code__field');
    await field.waitFor();
    if ((await field.inputValue()) !== footer || (await page.locator('.sve-code__colours .sve-code--directive').count()) === 0) {
        throw new Error('The plain field does not hold the footer, coloured');
    }
    await field.fill(`${footer}${added}\n`);
    await field.press('Control+s');
    await frame.getByText('Edited in the code panel.').waitFor();
    await page.waitForFunction(() => document.querySelector('.sve-code__bar .sve-tag') === null);
    await field.fill(footer);
    await field.press('Control+s');
    await page.waitForFunction(() => document.querySelector('.sve-code__bar .sve-tag') === null);
    await page.unroute('**/code-editor.js*');
    await page.getByRole('button', { name: 'Hide the code' }).click();
});

// --- Code mode -------------------------------------------------------------------

const tabNames = () => page.locator('.sve-tab__name').allTextContents();
const codeTab = (name) => page.locator('.sve-tab', { has: page.locator('.sve-tab__name', { hasText: new RegExp(`^${name}$`) }) });
const cursorLine = () => page.locator('.sve-code__editor .cm-activeLine').first().textContent();
const cursorLineHas = (text) => until(`The cursor is not on a line with ${text}`, async () => ((await cursorLine()) ?? '').includes(text) || (await cursorLine()));
const unsavedTabs = () => page.locator('.sve-tab', { has: page.locator('.sve-tab__dot') }).locator('.sve-tab__name').allTextContents();
let heroCode = '';
let headerCode = '';

await step('work in the code beside the page: the open template is a tab', async () => {
    await page.goto(`${base}/cms/visual-editor`);
    await page.waitForSelector('.sve-template');
    heroCode = await onDisk('blocks/hero.blade.php');
    headerCode = await onDisk('partials/header.blade.php');
    await bar.getByRole('button', { name: 'Code', exact: true }).click();
    await page.locator('.sve-canvas--beside .sve-code--beside .cm-editor').waitFor();
    const editorBox = await page.locator('.sve-code--beside').boundingBox();
    const pageBox = await page.locator('iframe.sve-frame').boundingBox();
    if (editorBox === null || pageBox === null || editorBox.x + editorBox.width > pageBox.x + 1 || Math.abs(editorBox.height - pageBox.height) > 2) {
        throw new Error(`The code is at ${JSON.stringify(editorBox)}, the page at ${JSON.stringify(pageBox)}`);
    }
    if (JSON.stringify(await tabNames()) !== JSON.stringify(['show'])) {
        throw new Error(`The tabs are ${JSON.stringify(await tabNames())}`);
    }
    // The code is there for as long as the mode is: nothing shows or hides it.
    if ((await page.locator('.sve-code__close').count()) !== 0 || (await page.getByRole('button', { name: 'Edit as code' }).count()) !== 0) {
        throw new Error('Code mode offers to hide the code');
    }
});

await step('click an element on the page: its template opens as a tab, with the cursor on that element', async () => {
    await frame.locator('.hero h1').click();
    await openIs('blocks/hero');
    await codeIs(heroCode);
    await cursorLineHas('<h1>{{ $block->heading }}</h1>');
    await outlineIs('selected', 'h1');
    if (JSON.stringify(await tabNames()) !== JSON.stringify(['show', 'blocks/hero']) || !(await page.evaluate(() => document.activeElement?.closest('.cm-editor') !== null))) {
        throw new Error('The hero is not a tab with the keyboard in its code');
    }
});

await step('leave text typed and not saved in one tab, save another, and find the first as it was left', async () => {
    await page.keyboard.press('End');
    await page.keyboard.type(' <!-- kept -->');
    await page.locator('.sve-code__bar').getByText('Not saved').waitFor();

    // The header, from the page: the hero's text stays with its tab.
    await frame.locator('.site-header .brand').click();
    await openIs('partials/header');
    await codeIs(headerCode);
    await cursorLineHas('{{');
    if (JSON.stringify(await unsavedTabs()) !== JSON.stringify(['blocks/hero']) || (await page.locator('.sve-code__bar .sve-tag').count()) !== 0) {
        throw new Error(`Not saved: ${JSON.stringify(await unsavedTabs())}`);
    }
    await page.keyboard.press('End');
    await page.keyboard.type(' <!-- header -->');
    await page.keyboard.press('Control+s');
    await page.locator('.sve-notice--done', { hasText: 'Saved partials/header.blade.php.' }).waitFor();
    if (!(await onDisk('partials/header.blade.php')).includes('<!-- header -->') || (await onDisk('blocks/hero.blade.php')) !== heroCode) {
        throw new Error('The header was not saved alone');
    }

    await codeTab('blocks/hero').locator('.sve-tab__name').click();
    await openIs('blocks/hero');
    await codeIs(heroCode.replace('<h1>{{ $block->heading }}</h1>', '<h1>{{ $block->heading }}</h1> <!-- kept -->'));
    await page.locator('.sve-code__bar').getByText('Not saved').waitFor();
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
    // What was typed before the tab was left, and what was typed after coming back: each a step or two of the editor's undo.
    for (let steps = 0; steps < 6 && (await codeText()) !== heroCode; steps++) {
        await page.keyboard.press('Control+z');
        await page.waitForTimeout(60);
    }
    await codeIs(heroCode);
    await page.waitForFunction(() => document.querySelector('.sve-code__bar .sve-tag') === null);

    await codeTab('partials/header').locator('.sve-tab__name').click();
    await openIs('partials/header');
    // As it was saved, a moment after the tab is asked for: the file is read again first.
    await until('The header is not shown', async () => (await codeText()).includes('<!-- header -->'));
    await page.waitForFunction(() => document.querySelector('.sve-code__editor .cm-content')?.getAttribute('aria-readonly') !== 'true');
    await code.evaluate((holder) => holder.sveCodeEditor.focus());
    await page.keyboard.press('Control+z');
    await codeIs(headerCode);
    await page.keyboard.press('Control+s');
    await page.waitForFunction(() => document.querySelector('.sve-code__bar .sve-tag') === null);
    await until('The header is not as it was', async () => (await onDisk('partials/header.blade.php')) === headerCode);
});

await step('outline on the page the element the cursor is in', async () => {
    await codeTab('blocks/hero').locator('.sve-tab__name').click();
    await openIs('blocks/hero');
    await codeIs(heroCode);
    await page.locator('.sve-code__editor .cm-line', { hasText: 'class="button"' }).first().click({ position: { x: 140, y: 8 } });
    await outlineIs('selected', 'a.button');
    await layerIs('a.button');
    await outlineSettles('.hero .button');
});

const fieldsPanel = page.locator('.sve-side--right .sve-panel[aria-label="Fields"]');
const fieldRow = (label) => fieldsPanel.locator('.sve-pick__pick', { has: page.locator('.sve-insertable__title', { hasText: new RegExp(`^${label}$`) }) });

await step('list what can be read where the cursor is, with a line typed above it and not saved', async () => {
    await frame.locator('.hero h1').click();
    await cursorLineHas('<h1>{{ $block->heading }}</h1>');
    await fieldRow('\\$block').first().waitFor();
    if ((await fieldRow('\\$kicker').count()) !== 0) {
        throw new Error('A variable nobody typed is listed');
    }
    await page.keyboard.press('End');
    await page.keyboard.press('Enter');
    await page.keyboard.type('@php($kicker = $block->heading)');
    await page.keyboard.press('Enter');
    // Set on the line above, in text that is in no file yet.
    await fieldRow('\\$kicker').first().waitFor();
    await fieldsPanel.getByText('What can be read where the cursor is.').waitFor();
});

await step('write a field at the cursor by a click, as the picker writes it and indented as the line is', async () => {
    await fieldRow('Subheading').first().click();
    await until('The field was not written at the cursor', async () => (await cursorLine()) === '    {{ $block->subheading }}' || (await cursorLine()));
    // A link is more than one line: each lands at the cursor's depth.
    await page.keyboard.press('Enter');
    await fieldRow('Button').first().click();
    const written = await codeText();
    const link = written.slice(written.indexOf('    @if (($button = $block->button)'));
    if (!/^ {4}@if \(\(\$button = \$block->button\) && ! empty\(\$button\['url'\]\)\)\n {8}<a href=.*\n {4}@endif\n/.test(link)) {
        throw new Error(`The link was written as ${JSON.stringify(link.slice(0, 200))}`);
    }
});

await step('offer what a variable has while it is typed, and write the one chosen', async () => {
    await page.keyboard.press('Enter');
    await page.keyboard.type('{{ $block->');
    const offered = page.locator('.cm-tooltip-autocomplete li');
    await offered.first().waitFor();
    if (!(await offered.allTextContents()).some((offer) => offer.startsWith('Heading')) || !(await offered.allTextContents()).some((offer) => offer.startsWith('Button › URL'))) {
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
    await page.waitForFunction(() => document.querySelector('.sve-code__bar .sve-tag') === null);
});

await step('close a tab, and be asked first when it has text that is not saved', async () => {
    await page.keyboard.press('End');
    await page.keyboard.type(' <!-- going -->');
    await page.locator('.sve-code__bar').getByText('Not saved').waitFor();
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
    // The tab beside it is the open one, and nothing of the closed one was written.
    await openIs('partials/header');
    await codeIs(headerCode);
    if ((await onDisk('blocks/hero.blade.php')) !== heroCode) {
        throw new Error('Closing the tab wrote the file');
    }
});

await step('drag the line between the code and the page, and find it there when the editor is opened again', async () => {
    const widthOf = async () => (await page.locator('.sve-code--beside').boundingBox()).width;
    const before = await widthOf();
    const line = await page.locator('.sve-divider').boundingBox();
    await page.mouse.move(line.x + line.width / 2, line.y + line.height / 2);
    await page.mouse.down();
    await page.mouse.move(line.x + line.width / 2 + 60, line.y + line.height / 2, { steps: 4 });
    await page.mouse.move(line.x + line.width / 2 + 120, line.y + line.height / 2, { steps: 4 });
    await page.mouse.up();
    await until('The code did not get the room', async () => Math.abs((await widthOf()) - before - 120) < 8 || [(await widthOf()), before]);
    const wide = await widthOf();

    await page.goto(`${base}/cms/visual-editor`);
    await page.locator('.sve-canvas--beside .sve-code--beside .cm-editor').waitFor();
    if (Math.abs((await widthOf()) - wide) > 2) {
        throw new Error(`The code is ${await widthOf()} wide, and was left ${wide} wide`);
    }
    // And by the keyboard, for who does not drag.
    await page.locator('.sve-divider').focus();
    await page.keyboard.press('ArrowLeft');
    await until('The line did not move by a key', async () => (await widthOf()) < wide - 10 || (await widthOf()));

    // Back to working on the page, with the editor as it is the first time.
    await bar.getByRole('button', { name: 'Visual', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('.sve-canvas--beside') === null);
    await page.evaluate(() => localStorage.removeItem('sunrice-visual-editor:layout'));
});

await step('keep what is typed in the address field while a page is still arriving', async () => {
    // The frame is sent off to a page, and before it has arrived something else is typed.
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
    // The site draws its error page with its own layout, so the panel lists that; a site
    // without one of its own would get word that the page can't be followed. Either way
    // the list is not the one of the page before, which had the page template in it.
    await page.waitForFunction(() => {
        const names = [...document.querySelectorAll('.sve-side--left > .sve-panel:first-child .sve-panel__body > .sve-templates .sve-template__name')].map((name) => name.textContent);

        return !names.includes('show') && (names.length > 0 || document.querySelector('.sve-side--left > .sve-panel:first-child .sve-panel__body > .sve-empty') !== null);
    });
});

await step('say so when what is in the frame is no page of the site', async () => {
    // A file the web server hands out itself: nothing renders it, so nothing marks it.
    await open('vendor/sunrice-visual-editor/bridge.js');
    await page.locator('.sve-side--left > .sve-panel:first-child .sve-empty').getByText('not drawn for the editor').waitFor();
});

await step('refuse the admin\'s own address', async () => {
    await open('/cms/users');
    await page.getByRole('alert').getByText('admin').waitFor();
});

// --- The site's build command ---------------------------------------------------------

const buildButton = page.locator('.sve-bar__build');

await step('say that a build is needed once a template has changed, run it, and say how long it took', async () => {
    await page.goto(`${base}/cms/visual-editor`);
    await page.waitForSelector('.sve-template');
    await frame.locator('.hero h1').waitFor();
    if ((await buildButton.textContent())?.trim() !== 'Build') {
        throw new Error(`Before anything changed the button says “${await buildButton.textContent()}”`);
    }

    await frame.locator('.hero h1').click();
    await layerIs('h1');
    await settings.getByLabel('Add a class').fill('built-later');
    await settings.getByLabel('Add a class').press('Enter');
    await frame.locator('.hero h1.built-later').waitFor();
    await idle();
    await buildButton.getByText('Build needed', { exact: true }).waitFor();

    await buildButton.click();
    await page.getByText(/^Built in [\d.]+ seconds\.$/).waitFor();
    await buildButton.getByText('Build', { exact: true }).waitFor();
    // The page was drawn again with what was built, and what was selected still is.
    await frame.locator('.hero h1.built-later[data-sve]').waitFor();
    await layerIs('h1.built-later');

    await settings.getByLabel('Remove the class built-later').click();
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
    // What was needed still is.
    await buildButton.getByText('Build needed', { exact: true }).waitFor();
    await failed.getByRole('button', { name: 'Put this away' }).click();
    await failed.waitFor({ state: 'detached' });
    await page.unroute('**/visual-editor/api/build');
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

// --- Someone who may edit, and not write code --------------------------------------

await step('sign in as the designer, who may change templates and not write code', async () => {
    await page.context().clearCookies();
    await page.goto(`${base}/cms/login`);
    await page.fill('#email', 'designer@example.com');
    await page.fill('#password', 'password');
    await Promise.all([
        page.waitForURL((url) => !url.pathname.endsWith('/login')),
        page.click('button[type=submit]'),
    ]);
    await page.getByRole('link', { name: 'Visual editor' }).first().click();
    await page.waitForSelector('.sve-template');
    await frame.getByText('Templates you can see').waitFor();
    // The site's build command is not theirs to run.
    if ((await page.locator('.sve-bar__build').count()) !== 0) {
        throw new Error('The Build button is shown to someone who may not build');
    }
});

await step('show them code and let them choose from lists, not type it', async () => {
    await frame.locator('.hero h1').click();
    await layerIs('h1');
    await layerRow('$block->heading').locator('.sve-layer__pick').click();

    // What the echo prints is written code: there to read. A field is chosen in its place.
    if (!(await settings.getByLabel('Prints', { exact: true }).isDisabled())) {
        throw new Error('What an echo prints can be typed by someone who may not edit code');
    }
    if ((await settings.locator('.sve-settings__source').count()) !== 0) {
        throw new Error('The node is offered to be rewritten as code');
    }
    await settings.getByRole('button', { name: 'Use a field for what it prints' }).click();
    await settings.getByLabel('Find a field').fill('subheading');
    await settings.getByRole('group', { name: 'Fields for what it prints' }).getByRole('button').first().click();
    await frame.locator('.hero h1', { hasText: 'This page is drawn by a layout' }).waitFor();
    await idle();

    // The template's code can be looked at, and that is all.
    await page.getByRole('button', { name: 'View the code' }).click();
    await code.locator('.cm-editor').waitFor();
    await page.locator('.sve-code__bar').getByText('Read-only').waitFor();
    const shown = await codeText();
    await code.evaluate((holder) => holder.sveCodeEditor.focus());
    await page.keyboard.type('typed by someone who may not');
    await page.keyboard.press('Control+a');
    await page.keyboard.press('Delete');
    await page.waitForTimeout(200);
    if ((await codeText()) !== shown || (await page.locator('.sve-code__bar').getByRole('button', { name: 'Save' }).count()) !== 0) {
        throw new Error('The code panel can be typed in');
    }
    await page.getByRole('button', { name: 'Hide the code' }).click();

    // In Code mode too: the code to read, and no list of fields to write from.
    await bar.getByRole('button', { name: 'Code', exact: true }).click();
    await page.locator('.sve-code--beside .cm-editor').waitFor();
    await page.locator('.sve-code__bar').getByText('Read-only').waitFor();
    if ((await fieldsPanel.count()) !== 0) {
        throw new Error('Someone who may not edit code is offered fields to write into it');
    }
    await bar.getByRole('button', { name: 'Visual', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('.sve-canvas--beside') === null);
});

await step('refuse code they paste, say why, and take what is no code', async () => {
    await page.locator('.sve-bar').getByRole('button', { name: 'Insert' }).click();
    const paste = page.locator('.sve-paste');
    await paste.locator('summary').click();
    await paste.getByLabel('HTML or Blade to insert').fill('<p class="sve-pasted">{{ phpinfo() }}</p>');
    await paste.getByRole('button', { name: 'Insert' }).click();
    await page.locator('.sve-notice--problem').getByText('you may not edit code').waitFor();
    if ((await frame.locator('.sve-pasted').count()) !== 0) {
        throw new Error('What was refused is on the page');
    }

    await paste.getByLabel('HTML or Blade to insert').fill('<p class="sve-pasted">Just text</p>');
    await paste.getByRole('button', { name: 'Insert' }).click();
    await frame.locator('.sve-pasted', { hasText: 'Just text' }).waitFor();
    await idle();

    // Their undo works like anyone's: twice, back to the hero as it was.
    await undo.click();
    await frame.locator('.sve-pasted').waitFor({ state: 'detached' });
    await idle();
    await undo.click();
    await frame.locator('.hero h1', { hasText: 'Templates you can see' }).waitFor();
    await idle();
    if ((await heroNow()) !== hero.source) {
        throw new Error('The hero is not what it was');
    }
});

await browser.close();

if (errors.length > 0) {
    console.log(`FAIL the page threw errors:\n     ${errors.join('\n     ')}`);
    process.exit(1);
}
