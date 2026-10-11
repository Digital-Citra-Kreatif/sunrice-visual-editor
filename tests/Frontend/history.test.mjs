import assert from 'node:assert/strict';
import { test } from 'node:test';
import { LIMIT, canRedo, canUndo, recorded, redoStep, redone, undoStep, undone } from '../../resources/js/lib/history.ts';

const step = (source, selected = null) => ({ source, selected });

/**
 * A file and its history, changed, undone and redone the way the editor
 * does it. The version of a source is the source itself, in brackets.
 */
function session(source) {
    let history;
    let selected = null;
    const version = () => `[${source}]`;

    return {
        get source() {
            return source;
        },
        get selected() {
            return selected;
        },
        get history() {
            return history;
        },
        change(to, select = null) {
            const was = version();
            const before = step(source, selected);
            source = to;
            selected = select;
            history = recorded(history, before, was, version());
        },
        /** Changed otherwise: in a code editor, by someone else. */
        changedElsewhere(to) {
            source = to;
            selected = null;
        },
        undo() {
            const back = undoStep(history, version());
            if (back === null) {
                return false;
            }
            const left = step(source, selected);
            ({ source, selected } = back);
            history = undone(history, left, version());

            return true;
        },
        redo() {
            const forward = redoStep(history, version());
            if (forward === null) {
                return false;
            }
            const left = step(source, selected);
            ({ source, selected } = forward);
            history = redone(history, left, version());

            return true;
        },
        canUndo: () => canUndo(history, version()),
        canRedo: () => canRedo(history, version()),
    };
}

test('has nothing to undo or redo to begin with', () => {
    const file = session('a');

    assert.equal(file.canUndo(), false);
    assert.equal(file.canRedo(), false);
    assert.equal(file.undo(), false);
    assert.equal(file.redo(), false);
    assert.equal(file.source, 'a');
});

test('goes back through every change to the file as it was, and forward again', () => {
    const file = session('0');
    const sources = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'];
    for (const source of sources) {
        file.change(source);
    }

    for (const expected of ['9', '8', '7', '6', '5', '4', '3', '2', '1', '0']) {
        assert.equal(file.undo(), true);
        assert.equal(file.source, expected);
    }
    assert.equal(file.canUndo(), false);
    assert.equal(file.undo(), false);

    for (const expected of sources) {
        assert.equal(file.redo(), true);
        assert.equal(file.source, expected);
    }
    assert.equal(file.canRedo(), false);
    assert.equal(file.canUndo(), true);
});

test('brings back what was selected with the file as it was', () => {
    const picked = session('x');
    picked.change('y', '12');
    picked.change('z', '40');
    picked.undo();
    assert.deepEqual([picked.source, picked.selected], ['y', '12']);
    picked.undo();
    assert.deepEqual([picked.source, picked.selected], ['x', null]);
    picked.redo();
    assert.deepEqual([picked.source, picked.selected], ['y', '12']);
});

test('forgets what was undone once something else is changed', () => {
    const file = session('a');
    file.change('b');
    file.change('c');
    file.undo();
    assert.equal(file.canRedo(), true);

    file.change('d');
    assert.equal(file.canRedo(), false);
    assert.equal(file.redo(), false);
    file.undo();
    assert.equal(file.source, 'b');
    file.undo();
    assert.equal(file.source, 'a');
});

test('offers nothing for a file that has changed otherwise', () => {
    const file = session('a');
    file.change('b');
    file.changedElsewhere('edited in a code editor');

    assert.equal(file.canUndo(), false, 'an undo would write over a change it has not seen');
    assert.equal(file.undo(), false);
    assert.equal(file.source, 'edited in a code editor');

    // The next change starts a history of its own.
    file.change('c');
    assert.equal(file.undo(), true);
    assert.equal(file.source, 'edited in a code editor');
    assert.equal(file.canUndo(), false);
});

test('keeps so many steps and no more', () => {
    const file = session('0');
    for (let n = 1; n <= LIMIT + 20; n++) {
        file.change(String(n));
    }
    assert.equal(file.history.past.length, LIMIT);

    let undos = 0;
    while (file.undo()) {
        undos++;
    }
    assert.equal(undos, LIMIT);
    assert.equal(file.source, '20', 'the oldest steps are the ones let go');
});

test('leaves the history as it is when a change changed nothing', () => {
    const file = session('a');
    file.change('b');
    const before = file.history;
    file.change('b');

    assert.equal(file.history, before);
    assert.equal(file.undo(), true);
    assert.equal(file.source, 'a');
});
