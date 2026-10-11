import assert from 'node:assert/strict';
import { test } from 'node:test';
import { appliesAt, asMedia, breakpoints, mediaFor, mediaLabel, sameMedia, widthRange } from '../../resources/js/lib/media.ts';
import { targetRule } from '../../resources/js/lib/styleFields.ts';
import { resolve } from '../../resources/js/lib/styles.ts';
import { GAP, HEAD, activeWidth, layout, readWidths, viewOf, viewValue } from '../../resources/js/lib/widths.ts';

const widths = [
    { name: 'Desktop', width: 1280, media: null },
    { name: 'Tablet', width: 768, media: '(max-width: 1024px)' },
    { name: 'Mobile', width: 390, media: '(max-width: 640px)' },
];

// --- Frames ----------------------------------------------------------------------

test('as wide as the room: one frame, life size, nothing named', () => {
    const plan = layout({ kind: 'full' }, widths, { width: 900, height: 700 });

    assert.deepEqual(plan, { scale: 1, height: 700, boxHeight: 700, slots: [{ at: null, name: 'Full width', width: 900, box: 900, active: true }] });
    assert.equal(activeWidth({ kind: 'full' }, widths), null);
});

test('one width: life size when there is room, smaller when there is not', () => {
    const fits = layout({ kind: 'one', at: 2 }, widths, { width: 900, height: 700 });
    assert.equal(fits.scale, 1);
    assert.deepEqual(fits.slots, [{ at: 2, name: 'Mobile', width: 390, box: 390, active: true }]);
    assert.equal(fits.boxHeight, 700 - HEAD);
    assert.equal(fits.height, 700 - HEAD);

    const tight = layout({ kind: 'one', at: 0 }, widths, { width: 640, height: 700 });
    assert.equal(tight.scale, 0.5);
    assert.equal(tight.slots[0].box, 640);
    // The page is as tall as it takes to fill the frame once it is drawn smaller.
    assert.equal(tight.height, (700 - HEAD) * 2);
});

test('side by side: every width at one scale, the one being edited first', () => {
    const plan = layout({ kind: 'all', at: 1 }, widths, { width: 1280 + 768 + 390 + 2 * GAP, height: 600 });
    assert.equal(plan.scale, 1);
    assert.deepEqual(plan.slots.map((slot) => [slot.at, slot.active, slot.box]), [[1, true, 768], [0, false, 1280], [2, false, 390]]);

    const small = layout({ kind: 'all', at: 0 }, widths, { width: 1000, height: 600 });
    assert.ok(small.scale < 0.41 && small.scale > 0.39);
    assert.deepEqual(small.slots.map((slot) => slot.at), [0, 1, 2]);
    assert.ok(small.slots.reduce((sum, slot) => sum + slot.box, 0) + 2 * GAP <= 1000);
    // What is wider looks wider, by as much.
    assert.ok(Math.abs(small.slots[0].box / small.slots[2].box - 1280 / 390) < 0.02);
});

test('a room not yet measured, or a width that is not there, shows the page as wide as the room', () => {
    assert.equal(layout({ kind: 'all', at: 0 }, widths, { width: 0, height: 0 }).slots.length, 1);
    assert.equal(layout({ kind: 'one', at: 7 }, widths, { width: 900, height: 700 }).slots[0].at, null);
});

test('the choice of view is read back as it was written', () => {
    for (const view of [{ kind: 'full' }, { kind: 'one', at: 2 }, { kind: 'all', at: 0 }]) {
        assert.deepEqual(viewOf(viewValue(view), widths, { kind: 'full' }), view);
    }
    // Side by side keeps the width that was being edited.
    assert.deepEqual(viewOf('all', widths, { kind: 'one', at: 2 }), { kind: 'all', at: 2 });
    assert.deepEqual(viewOf('one:9', widths, { kind: 'full' }), { kind: 'full' });
    assert.deepEqual(viewOf('all', [], { kind: 'full' }), { kind: 'full' });
    assert.deepEqual(viewOf('nonsense', widths, { kind: 'all', at: 1 }), { kind: 'full' });
});

test('widths are taken from the server as far as they are widths', () => {
    assert.deepEqual(readWidths([{ name: 'Tablet', width: 768.4, media: '(max-width: 1024px)' }, { name: 'Wide', width: 1600 }, { name: 'Tiny', width: 20 }, { width: 500 }, null, 'x']), [
        { name: 'Tablet', width: 768, media: '(max-width: 1024px)' },
        { name: 'Wide', width: 1600, media: null },
    ]);
    assert.deepEqual(readWidths(undefined), []);
});

// --- @media ----------------------------------------------------------------------

test('a @media block is for a range of widths, or for something else altogether', () => {
    assert.deepEqual(widthRange('@media (max-width: 760px)'), { min: 0, max: 760 });
    assert.deepEqual(widthRange('@media screen and (min-width: 40em)'), { min: 640, max: Infinity });
    assert.deepEqual(widthRange('@media (min-width: 640px) and (max-width: 1023.98px)'), { min: 640, max: 1023.98 });
    assert.deepEqual(widthRange('@media only screen and (MAX-WIDTH:48rem)'), { min: 0, max: 768 });
    assert.deepEqual(widthRange('@media (width >= 600px)'), { min: 600, max: Infinity });
    assert.deepEqual(widthRange('@media (400px <= width <= 700px)'), { min: 400, max: 700 });
    assert.equal(widthRange('@media (width < 600px)').max < 600, true);

    for (const other of ['@media print', '@media (prefers-color-scheme: dark)', '@media (max-width: 760px), print', '@media (max-width: 760px) and (orientation: landscape)', '@media (max-width: 50vw)', '@media (max-width: calc(40em + 1px))', '@media screen', '@media']) {
        assert.equal(widthRange(other), null, other);
    }
});

test('a block applies at the widths it is for', () => {
    assert.equal(appliesAt('@media (max-width: 760px)', 390), true);
    assert.equal(appliesAt('@media (max-width: 760px)', 760), true);
    assert.equal(appliesAt('@media (max-width: 760px)', 768), false);
    assert.equal(appliesAt('@media (min-width: 40em)', 640), true);
    assert.equal(appliesAt('@media print', 390), false);
});

const app = { path: 'app.css', url: '/sunrice-theme/app.css', version: 'v1', exists: true };
const own = { path: 'editor.css', url: '/sunrice-theme/editor.css', version: 'v0', exists: false };
const rule = (more) => ({ file: 'app.css', id: 500, selector: '.card', within: [], exact: true, line: 5, declarations: [], ...more });
const styles = (rules, media = [], more = {}) => ({ class: 'card', selector: '.card', files: [app], rules, more: 0, media, own, ...more });

test('the widths the site styles for are those its stylesheets name, each once', () => {
    const read = styles(
        [rule({ within: ['@media  (min-width: 1200px)'] }), rule({ within: ['@media print'] }), rule({ within: ['@supports (x)', '@media (max-width: 1px)'] })],
        [
            { file: 'app.css', id: 10, prelude: '@media (prefers-color-scheme: dark)' },
            { file: 'app.css', id: 90, prelude: '@media (max-width: 760px)' },
            { file: 'editor.css', id: 0, prelude: '@media (MAX-WIDTH: 760px)' },
        ],
    );

    assert.deepEqual(breakpoints(read), ['@media (max-width: 760px)', '@media (min-width: 1200px)']);
    assert.deepEqual(breakpoints(null), []);
});

test('a rule for a width goes under the narrowest condition that applies there', () => {
    const points = ['@media (max-width: 1024px)', '@media (max-width: 760px)', '@media (min-width: 1200px)', '@media (max-width: 480px)'];

    assert.equal(mediaFor(points, 390), '@media (max-width: 480px)');
    assert.equal(mediaFor(points, 600), '@media (max-width: 760px)');
    assert.equal(mediaFor(points, 768), '@media (max-width: 1024px)');
    assert.equal(mediaFor(points, 1280), '@media (min-width: 1200px)');
    assert.equal(mediaFor(points, 1100), null);
    assert.equal(mediaFor([], 390), null);
});

test('a condition is written as a block starts, and read without the word', () => {
    assert.equal(asMedia('(max-width: 640px)'), '@media (max-width: 640px)');
    assert.equal(asMedia(' @media (max-width: 640px) '), '@media (max-width: 640px)');
    assert.equal(mediaLabel('@media (max-width: 640px)'), '(max-width: 640px)');
    assert.equal(sameMedia('@media  (Max-Width: 640px)', '@media (max-width: 640px) '), true);
    assert.equal(sameMedia('@media (max-width: 640px)', '@media (max-width: 641px)'), false);
});

// --- A control, for one width ----------------------------------------------------

const base = rule({ declarations: [{ id: 508, property: 'padding', value: '20px' }] });
const narrow = rule({ id: 120, within: ['@media (max-width: 760px)'], declarations: [{ id: 130, property: 'padding', value: '8px' }] });
const m760 = '@media (max-width: 760px)';

test('for a width, the controls read and write the rule directly inside its block', () => {
    const now = styles([narrow, base]);

    assert.equal(targetRule(now), 1);
    assert.equal(targetRule(now, m760), 0);
    assert.equal(targetRule(now, '@media (max-width: 480px)'), null);
    // Not a rule that is deeper in: under a @supports inside the block, say.
    assert.equal(targetRule(styles([rule({ within: [m760, '@supports (x)'] })]), m760), null);

    assert.deepEqual(resolve(now, { kind: 'style', property: 'padding', value: '4px', media: m760 }), { file: app, changes: [{ type: 'set', declaration: 130, value: '4px' }] });
    assert.deepEqual(resolve(now, { kind: 'style', property: 'margin', value: '0', media: m760 }), { file: app, changes: [{ type: 'add', rule: 120, property: 'margin', value: '0' }] });
    assert.deepEqual(resolve(now, { kind: 'style', property: 'padding', value: '', media: m760 }), { file: app, changes: [{ type: 'remove', declaration: 130 }] });
    // And without a width, the rule outside any, as before.
    assert.deepEqual(resolve(now, { kind: 'style', property: 'padding', value: '4px', media: null }), { file: app, changes: [{ type: 'set', declaration: 508, value: '4px' }] });
});

test('the first thing set for a width goes into a block for it that comes after the class’s own rule', () => {
    const after = styles([base], [{ file: 'app.css', id: 900, prelude: m760 }, { file: 'app.css', id: 1500, prelude: '@media  (max-width: 760px)' }]);

    assert.deepEqual(resolve(after, { kind: 'style', property: 'padding', value: '8px', media: m760 }), { file: app, changes: [{ type: 'rule', selector: '.card', declarations: [['padding', '8px']], in: 1500 }] });
    assert.equal(resolve(after, { kind: 'style', property: 'padding', value: '', media: m760 }), null);
});

test('a block that comes before the class’s own rule would not count over it: a new one is written at the end', () => {
    const before = styles([base], [{ file: 'app.css', id: 90, prelude: m760 }, { file: 'other.css', id: 900, prelude: m760 }]);

    assert.deepEqual(resolve(before, { kind: 'style', property: 'padding', value: '8px', media: m760 }), { file: app, changes: [{ type: 'rule', selector: '.card', declarations: [['padding', '8px']], media: m760 }] });
});

test('for a class without a rule of its own, the rule for a width goes into the editor’s stylesheet', () => {
    const none = styles([], [{ file: 'app.css', id: 90, prelude: m760 }]);
    assert.deepEqual(resolve(none, { kind: 'style', property: 'padding', value: '8px', media: m760 }), { file: own, changes: [{ type: 'rule', selector: '.card', declarations: [['padding', '8px']], media: m760 }] });

    // Into a block that is there already, in that stylesheet.
    const there = styles([], [{ file: 'editor.css', id: 40, prelude: m760 }], { files: [app, { ...own, exists: true }], own: { ...own, exists: true } });
    assert.deepEqual(resolve(there, { kind: 'style', property: 'padding', value: '8px', media: m760 }), { file: { ...own, exists: true }, changes: [{ type: 'rule', selector: '.card', declarations: [['padding', '8px']], in: 40 }] });

    assert.equal(resolve({ ...none, own: null }, { kind: 'style', property: 'padding', value: '8px', media: m760 }), null);
});
