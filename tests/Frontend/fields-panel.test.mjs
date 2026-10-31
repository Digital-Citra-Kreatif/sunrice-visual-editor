import assert from 'node:assert/strict';
import { test, after } from 'node:test';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const directory = await mkdtemp(join(tmpdir(), 'sve-fields-'));
after(() => rm(directory, { recursive: true, force: true }));
const root = fileURLToPath(new URL('../../', import.meta.url));
await build({ stdin: { contents: `export { harness } from './tests/Frontend/support/hooks.mjs'; export { FieldsPanel } from './resources/js/panels/FieldsPanel.tsx';`, resolveDir: root }, outfile: join(directory, 'panel.mjs'), bundle: true, format: 'esm', platform: 'node', jsx: 'automatic', alias: { react: join(root, 'tests/Frontend/support/hooks.mjs'), 'react/jsx-runtime': join(root, 'tests/Frontend/support/hooks.mjs') } });
const { harness, FieldsPanel } = await import(pathToFileURL(join(directory, 'panel.mjs')).href);
const template = (version = 'v1', path = 'blocks/hero.blade.php') => ({ version, path, origin: 'site', role: 'block' });
const variable = (code) => ({ code, title: code, what: 'text', from: 'page', groups: [], of: [], inserts: [], print: `{{ ${code} }}` });
const links = { sources: {}, entry: { title: 'Home', values: '/admin/cms/entries/1/edit?locale=id', definitions: [] }, manage: [] };
function nodes(node, predicate) {
    if (!node || typeof node !== 'object') return [];
    return [...(predicate(node) ? [node] : []), ...[node.props?.children].flat(Infinity).flatMap((child) => nodes(child, predicate))];
}
async function flush(state) { for (let count = 0; count < 6; count++) { await new Promise((resolve) => setImmediate(resolve)); state.render(); } }
function environment() {
    const events = {};
    const add = (type, handler) => (events[type] ??= new Set()).add(handler);
    const remove = (type, handler) => events[type]?.delete(handler);
    globalThis.window = { location: { origin: 'http://localhost', href: 'http://localhost/admin/visual-editor' }, addEventListener: add, removeEventListener: remove };
    globalThis.document = { cookie: '', visibilityState: 'visible', addEventListener: add, removeEventListener: remove };
    globalThis.Sunrice = { adminUrl: (path) => '/admin' + (path ? '/' + path : '') };
    const calls = [];
    globalThis.fetch = async (url) => { calls.push(url); return { ok: true, json: async () => url.includes('/field-editors?') ? links : { version: 'v1', variables: [variable('$block')] } }; };
    return { calls, event(type) { for (const handler of events[type] ?? []) handler(); } };
}
const props = () => ({ active: false, template: template(), node: null, entry: { id: 1, title: 'Home', edit: '/admin/cms/entries/1/edit?locale=id' }, unsaved: false, onBack() {}, onReload() {} });

test('the Fields tab loads on demand, caches the context and preserves locale for CMS editing', async () => {
    const env = environment();
    const state = harness(FieldsPanel, props()); state.render(); await flush(state);
    assert.equal(env.calls.length, 0);
    state.props.active = true; state.render(); await flush(state);
    assert.equal(env.calls.length, 2);
    assert.ok(env.calls.some((url) => url.includes('reference=1')));
    assert.ok(env.calls.some((url) => url.endsWith('/field-editors?entry=1&locale=id')));
    state.props.active = false; state.render(); state.props.active = true; state.render(); await flush(state);
    assert.equal(env.calls.length, 2);
    state.props.template = template('v2'); state.render(); await flush(state);
    assert.equal(env.calls.length, 3);
    assert.ok(env.calls[2].includes('version=v2'));
});

test('returning from a native CMS editor invalidates the catalog and refreshes the preview once', async () => {
    const env = environment(); let returned = 0;
    const state = harness(FieldsPanel, { ...props(), active: true, onBack: () => returned++ }); state.render(); await flush(state);
    nodes(state.output, (node) => typeof node.type === 'function' && node.type.name === 'EditorLinks')[0].props.onOpen();
    env.event('focus'); env.event('visibilitychange'); state.render(); await flush(state);
    assert.equal(returned, 1);
    assert.equal(env.calls.length, 4);
});

test('a late catalog response cannot replace the newly selected template context', async () => {
    environment(); let resolveOld;
    globalThis.fetch = async (url) => {
        if (url.includes('/field-editors?')) return { ok: true, json: async () => links };
        if (url.includes('version=v1')) return new Promise((resolve) => { resolveOld = () => resolve({ ok: true, json: async () => ({ version: 'v1', variables: [variable('$old')] }) }); });
        return { ok: true, json: async () => ({ version: 'v2', variables: [variable('$new')] }) };
    };
    const state = harness(FieldsPanel, { ...props(), active: true }); state.render();
    state.props.template = template('v2'); state.render(); await flush(state);
    resolveOld(); await flush(state);
    assert.deepEqual(nodes(state.output, (node) => typeof node.type === 'function' && node.type.name === 'Variable').map((node) => node.props.value.code), ['$new']);
});

test('malformed entry metadata cannot crash or open a foreign CMS editor', async () => {
    const env = environment();
    const state = harness(FieldsPanel, { ...props(), active: true, entry: { id: 1, title: 'Bad', edit: 'https://[' } });
    state.render(); await flush(state);
    assert.ok(env.calls.some((url) => url.endsWith('/field-editors?')));
    assert.ok(env.calls.every((url) => !url.includes('entry=1')));
});
