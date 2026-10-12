import assert from 'node:assert/strict';
import { test } from 'node:test';
import { build } from 'esbuild';
import { EVERYTHING, readLayout, siding, writeLayout } from '../../resources/js/lib/layout.ts';

// Render the sidebar without a browser; state and children must survive hiding.
const compiled = await build({
    entryPoints: ['resources/js/panels/Panel.tsx'], bundle: true, write: false, format: 'esm',
    tsconfigRaw: { compilerOptions: { jsxFactory: 'h' } }, jsxFactory: 'h', banner: { js: 'const h = (type, props, ...children) => ({ type, props: props ?? {}, children: children.flat() });' },
    plugins: [{ name: 'react-test', setup(b) {
        b.onResolve({ filter: /^react$/ }, () => ({ path: 'react', namespace: 'test' }));
        b.onLoad({ filter: /.*/, namespace: 'test' }, () => ({ contents: `
            export const createContext = (value) => ({ value });
            export const useContext = (context) => context.value;
            export const useEffect = () => {};
            export const useId = () => 'body';
            export const useRef = () => ({ current: null });
        ` }));
    } }],
});
const { Side } = await import(`data:text/javascript;base64,${Buffer.from(compiled.outputFiles[0].text).toString('base64')}`);
const nodes = (node) => node && typeof node === 'object' ? [node, ...node.children.flatMap(nodes)] : [];

test('both sidebars expose a named minimize action', () => {
    for (const [side, label] of [['left', 'Structure'], ['right', 'Inspector']]) {
        let minimized = false;
        const rendered = Side({ side, children: 'kept', onMinimize: () => { minimized = true; } });
        const button = nodes(rendered).find((n) => n.props['aria-label'] === `Minimize ${label}`);
        assert.ok(button);
        button.props.onClick();
        assert.equal(minimized, true);
    }
});

test('minimizing keeps children mounted, and temporary reopening offers pin and close', () => {
    const content = { type: 'input', props: { value: 'unfinished' }, children: [] };
    const hidden = Side({ side: 'left', shown: false, children: content });
    assert.equal(hidden.props.hidden, true);
    assert.ok(nodes(hidden).includes(content));
    let pinned = false, closed = false;
    const overlay = Side({ side: 'left', shown: false, flown: true, children: content, onKeep: () => { pinned = true; }, onAway: () => { closed = true; } });
    assert.equal(overlay.props.hidden, false);
    const buttons = nodes(overlay).filter((n) => n.type === 'button');
    buttons[0].props.onClick(); buttons[1].props.onClick();
    assert.ok(pinned && closed);
});

test('minimized sides persist without losing code mode, folded groups, or divider position', () => {
    const original = { ...EVERYTHING, mode: 'code', folded: ['panel:Template'], share: 0.35 };
    const hidden = siding(siding(original, 'left', false), 'right', false);
    let saved;
    const store = { getItem: () => saved, setItem: (_, value) => { saved = value; } };
    writeLayout(hidden, store);
    assert.deepEqual(readLayout(store), hidden);
    assert.deepEqual(siding(siding(readLayout(store), 'left', true), 'right', true), original);
});
