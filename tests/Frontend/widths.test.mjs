import assert from 'node:assert/strict';
import { test } from 'node:test';
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

