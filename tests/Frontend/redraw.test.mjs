// When a page drawn again out of sight is shown in place of the old one.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { startRedraw } from '../../resources/js/lib/redraw.ts';

/** A clock to turn by hand, and what happened. */
function setting() {
    let now = 0;
    const waiting = [];
    const happened = [];
    const later = (after, run) => {
        const entry = { at: now + after, run, off: false };
        waiting.push(entry);

        return () => {
            entry.off = true;
        };
    };
    const pass = (time) => {
        now += time;
        for (const entry of waiting.filter((one) => !one.off && one.at <= now).sort((a, b) => a.at - b.at)) {
            entry.off = true;
            entry.run();
        }
    };
    const redraw = startRedraw({ settle: 250, giveUp: 10000, later, show: () => happened.push('show'), fail: () => happened.push('fail') });

    return { redraw, pass, happened };
}

test('a page that has loaded is shown at once', () => {
    const { redraw, pass, happened } = setting();
    pass(300);
    redraw.heard('ready');
    pass(40);
    redraw.heard('load');
    assert.deepEqual(happened, ['show']);
    pass(20000);
    assert.deepEqual(happened, ['show'], 'and only once');
});

test('a page that is ready is given a short while to finish loading, and shown then', () => {
    const { redraw, pass, happened } = setting();
    redraw.heard('ready');
    pass(249);
    assert.deepEqual(happened, []);
    pass(1);
    assert.deepEqual(happened, ['show']);
    redraw.heard('load');
    assert.deepEqual(happened, ['show'], 'the load that comes after changes nothing');
});

test('saying ready twice does not start the wait again', () => {
    const { redraw, pass, happened } = setting();
    redraw.heard('ready');
    pass(200);
    redraw.heard('ready');
    pass(50);
    assert.deepEqual(happened, ['show']);
});

test('a page that loads without saying it is ready is shown: it is what the server sent', () => {
    const { redraw, happened } = setting();
    redraw.heard('load');
    assert.deepEqual(happened, ['show']);
});

test('a page that never arrives is given up on', () => {
    const { redraw, pass, happened } = setting();
    pass(9999);
    assert.deepEqual(happened, []);
    pass(1);
    assert.deepEqual(happened, ['fail']);
    redraw.heard('load');
    assert.deepEqual(happened, ['fail'], 'and what it says afterwards is not heard');
});

test('one that is called off does nothing more', () => {
    const { redraw, pass, happened } = setting();
    redraw.heard('ready');
    redraw.cancel();
    pass(20000);
    redraw.heard('load');
    assert.deepEqual(happened, []);
});
