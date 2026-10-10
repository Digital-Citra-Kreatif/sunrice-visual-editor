import assert from 'node:assert/strict';
import { test } from 'node:test';
import { actionOn, actionsFor, dropAt, moveTo, step, WRAPPERS } from '../../resources/js/lib/move.ts';

/** A node as the server describes one, with only what these helpers read. */
const can = (more = {}) => ({ move: true, delete: true, wrap: true, insert: false, attributes: false, ...more });
const node = (id, type, more = {}, children = undefined) => ({ id, type, kind: null, name: '', label: id, start: 0, end: 0, can: can(), ...more, ...(children === undefined ? {} : { children }) });
const element = (id, children = []) => node(id, 'element', { name: 'div', can: can({ insert: true, attributes: true }) }, children);
const empty = (id) => node(id, 'element', { name: 'br', can: can({ attributes: true }) });
const text = (id) => node(id, 'text', { text: 'words', can: can({ move: false, delete: false }) });
const block = (id, name, arms) => node(id, 'block', { name }, arms);
const arm = (id, name, children = []) => node(id, 'branch', { name, can: can({ move: false, delete: false, wrap: false, insert: true }) }, children);
const armBreak = (id) => node(id, 'atom', { name: 'break', can: can({ move: false, delete: false, wrap: false }) });

/*
 * <section 0>
 *   <h1 10>text 14</h1>
 *   <br 20>
 *   @if 30 … <p 40></p> @endif                     one arm
 *   @if 50 … <p 60></p> @else … <p 80></p> @endif  two arms
 *   @switch 90 … @case 100 … <b 110></b> @break 120 … @endswitch
 *   <!-- 130 -->
 * </section>
 * <footer 200></footer>
 */
const tree = node('root', 'root', { can: can({ move: false, delete: false, wrap: false, insert: true }) }, [
    element('0', [
        element('10', [text('14')]),
        empty('20'),
        block('30', 'if', [arm('b30', 'if', [element('40')])]),
        block('50', 'if', [arm('b50', 'if', [element('60')]), arm('b70', 'else', [element('80')])]),
        block('90', 'switch', [node('b90', 'branch', { name: 'switch', can: can({ move: false, delete: false, wrap: false }) }), arm('b100', 'case', [element('110'), armBreak('120')])]),
        node('130', 'comment'),
    ]),
    element('200'),
]);

test('steps a node up or down among what is beside it', () => {
    assert.deepEqual(step(tree, '20', 'up'), { target: '10', position: 'before' });
    assert.deepEqual(step(tree, '20', 'down'), { target: '30', position: 'after' });
    assert.equal(step(tree, '10', 'up'), null, 'the first has nothing above it');
    assert.equal(step(tree, '130', 'down'), null, 'the last has nothing below it');
    assert.deepEqual(step(tree, '0', 'down'), { target: '200', position: 'after' }, 'at the top of the template');
    assert.equal(step(tree, '40', 'up'), null, 'alone in its arm');
    assert.equal(step(tree, 'b70', 'up'), null, 'an arm stays where it is');
    assert.equal(step(tree, '14', 'down'), null, 'text is not picked up');
    assert.equal(step(tree, '110', 'down'), null, 'nothing goes after the @break that ends a @case');
    assert.equal(step(tree, 'gone', 'up'), null);
    assert.equal(step(null, '10', 'up'), null);
});

test('says what can be done to a node', () => {
    assert.deepEqual(actionsFor(tree, '20'), { up: true, down: true, duplicate: true, unwrap: false, delete: true, wrap: true }, 'an element that holds nothing');
    assert.deepEqual(actionsFor(tree, '10'), { up: false, down: true, duplicate: true, unwrap: true, delete: true, wrap: true }, 'the first, with something in it');
    assert.equal(actionsFor(tree, '30').unwrap, true, 'a block with one arm: what the arm holds is kept');
    assert.equal(actionsFor(tree, '50').unwrap, false, 'a block with two arms');
    assert.deepEqual(actionsFor(tree, 'b70'), { up: false, down: false, duplicate: false, unwrap: false, delete: false, wrap: false }, 'an arm');
    assert.deepEqual(actionsFor(tree, '14'), { up: false, down: false, duplicate: false, unwrap: false, delete: true, wrap: true }, 'text: emptied and wrapped, not moved');
    assert.deepEqual(actionsFor(tree, '130'), { up: true, down: false, duplicate: true, unwrap: false, delete: true, wrap: true }, 'a comment');
    assert.deepEqual(actionsFor(tree, null), { up: false, down: false, duplicate: false, unwrap: false, delete: false, wrap: false });
    assert.deepEqual(actionsFor(tree, 'gone'), { up: false, down: false, duplicate: false, unwrap: false, delete: false, wrap: false });
});

test('writes the operation for an action', () => {
    assert.deepEqual(actionOn(tree, '20', 'up'), { type: 'move', node: '20', target: '10', position: 'before' });
    assert.deepEqual(actionOn(tree, '20', 'down'), { type: 'move', node: '20', target: '30', position: 'after' });
    assert.deepEqual(actionOn(tree, '20', 'duplicate'), { type: 'duplicate', node: '20' });
    assert.deepEqual(actionOn(tree, '10', 'unwrap'), { type: 'unwrap', node: '10' });
    assert.deepEqual(actionOn(tree, '20', 'delete'), { type: 'remove', node: '20' });
    assert.deepEqual(actionOn(tree, '14', 'delete'), { type: 'setText', node: '14', text: '' }, 'text is emptied');
    assert.equal(actionOn(tree, '10', 'up'), null);
    assert.equal(actionOn(tree, '20', 'unwrap'), null);
    assert.equal(actionOn(tree, 'b70', 'delete'), null);
});

test('says where a dragged node would go, by where on a row the pointer is', () => {
    // A row that holds things: its top is before it, its bottom after it, the rest into it.
    assert.deepEqual(dropAt(tree, '20', '10', 0.1), { target: '10', position: 'before' });
    assert.deepEqual(dropAt(tree, '20', '10', 0.5), { target: '10', position: 'inside' });
    assert.deepEqual(dropAt(tree, '20', '10', 0.9), { target: '10', position: 'after' });
    // A block with one arm takes it into the arm.
    assert.deepEqual(dropAt(tree, '20', '30', 0.5), { target: 'b30', position: 'inside' });
    // A row that holds nothing: the top half is before it, the bottom half after it.
    assert.deepEqual(dropAt(tree, '10', '20', 0.4), { target: '20', position: 'before' });
    assert.deepEqual(dropAt(tree, '10', '20', 0.6), { target: '20', position: 'after' });
    assert.deepEqual(dropAt(tree, '10', '14', 0.2), null, 'not beside what is inside itself');
    assert.deepEqual(dropAt(tree, '20', '14', 0.2), { target: '14', position: 'before' }, 'beside text');
    // A block with two arms takes nothing itself; one of its arms does, and only inside.
    assert.deepEqual(dropAt(tree, '20', '50', 0.5), { target: '50', position: 'after' });
    assert.deepEqual(dropAt(tree, '20', '50', 0.3), { target: '50', position: 'before' });
    assert.deepEqual(dropAt(tree, '20', 'b70', 0.05), { target: 'b70', position: 'inside' });
    assert.deepEqual(dropAt(tree, '20', 'b70', 0.95), { target: 'b70', position: 'inside' });
    // Nothing goes after the @break that ends a @case.
    assert.deepEqual(dropAt(tree, '20', '120', 0.9), { target: '120', position: 'before' });
});

test('refuses a drop onto itself, into itself, or of what can\'t be moved', () => {
    assert.equal(dropAt(tree, '10', '10', 0.5), null);
    assert.equal(dropAt(tree, '0', '40', 0.5), null, 'into something inside itself');
    assert.equal(dropAt(tree, '30', 'b30', 0.5), null, 'into its own arm');
    assert.equal(dropAt(tree, 'b70', '20', 0.5), null, 'an arm');
    assert.equal(dropAt(tree, '14', '20', 0.5), null, 'text');
    assert.equal(dropAt(tree, 'gone', '20', 0.5), null);
    assert.equal(dropAt(tree, '20', 'gone', 0.5), null);
    assert.equal(dropAt(null, '20', '10', 0.5), null);
});

test('writes the move for a drop, and offers wrappers that are empty', () => {
    assert.deepEqual(moveTo('20', { target: 'b30', position: 'inside' }), { type: 'move', node: '20', target: 'b30', position: 'inside' });
    assert.deepEqual(
        WRAPPERS.map((each) => each.title),
        ['Container', 'Section', 'Link', 'Condition'],
    );
});
