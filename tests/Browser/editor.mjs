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

// --- The code panel --------------------------------------------------------------

const code = page.locator('.sve-code__field');

await step('open the footer as code, coloured and character for character', async () => {
    await page.goto(`${base}/cms/visual-editor`);
    await page.waitForSelector('.sve-template');
    await page.locator('.sve-side--left .sve-templates').first().locator('.sve-template', { hasText: 'partials/footer' }).click();
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
    await page.locator('.sve-notice--done', { hasText: 'Saved partials/footer.blade.php.' }).waitFor();
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
