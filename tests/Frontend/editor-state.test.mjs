import assert from 'node:assert/strict';
import { test, after } from 'node:test';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const directory = await mkdtemp(join(tmpdir(), 'sve-state-'));
after(() => rm(directory, { recursive: true, force: true }));
const root = fileURLToPath(new URL('../../', import.meta.url));
await build({
    stdin: { contents: `export { harness } from './tests/Frontend/support/hooks.mjs';
        export { Editor } from './resources/js/Editor.tsx';
        export { CodePanel, PlainCode } from './resources/js/panels/CodePanel.tsx';
        export { CustomFilePanel } from './resources/js/panels/CustomFilePanel.tsx';
        export { TemplatesPanel } from './resources/js/panels/TemplatesPanel.tsx';
        export { LayersPanel } from './resources/js/panels/LayersPanel.tsx';`, resolveDir: root },
    outfile: join(directory, 'components.mjs'), bundle: true, format: 'esm', platform: 'node', jsx: 'automatic',
    alias: { react: join(root, 'tests/Frontend/support/hooks.mjs'), 'react/jsx-runtime': join(root, 'tests/Frontend/support/hooks.mjs'), '@inertiajs/react': join(root, 'tests/Frontend/support/inertia.mjs') },
});
const { harness, Editor, CodePanel, PlainCode, CustomFilePanel, TemplatesPanel, LayersPanel } = await import(pathToFileURL(join(directory, 'components.mjs')).href);

function find(node, type) {
    if (!node || typeof node !== 'object') return null;
    if (node.type === type) return node;
    for (const child of [node.props?.children].flat(Infinity)) {
        const found = find(child, type);
        if (found) return found;
    }
    return null;
}
async function flush(state) {
    for (let index = 0; index < 6; index++) {
        await new Promise((resolve) => setImmediate(resolve));
        state.render();
    }
}
const template = (path, source, version) => ({ path, source, version, origin: 'site', editable: true, tree: null, role: 'entry', opens: true });

function environment() {
    const listeners = {};
    globalThis.window = {
        location: { href: 'http://localhost/admin/visual-editor', origin: 'http://localhost' },
        localStorage: { getItem: () => null, setItem() {} },
        addEventListener(type, listener) { (listeners[type] ??= new Set()).add(listener); },
        removeEventListener(type, listener) { listeners[type]?.delete(listener); },
        setTimeout: () => 0, clearTimeout() {}, confirm: () => true,
    };
    globalThis.document = { cookie: '' };
    globalThis.Sunrice = { adminUrl: (path) => '/admin/' + (path ?? '') };
    const files = { 'a.blade.php': template('a.blade.php', 'original', 'a1'), 'b.blade.php': template('b.blade.php', 'other', 'b1') };
    const requests = [];
    const fieldRequests = [];
    let pending;
    globalThis.fetch = async (url, options) => {
        if (options.method === 'PUT' || url.endsWith('/operations')) {
            requests.push({ url, body: JSON.parse(options.body) });
            return new Promise((resolve) => { pending = (body, status = 200) => resolve({ ok: status < 400, status, json: async () => body }); });
        }
        if (url.endsWith('/fields')) { fieldRequests.push(url); return { ok: true, json: async () => ({ variables: [], exact: true }) }; }
        if (url.endsWith('/templates')) return { ok: true, json: async () => ({ templates: Object.values(files) }) };
        if (url.endsWith('/custom')) return { ok: true, json: async () => ({ custom: { path: 'custom.css', displayPath: 'public/cms/custom.css', source: 'original', version: 'c1', exists: true } }) };
        return { ok: true, json: async () => ({ template: files[url.split('/templates/')[1]] }) };
    };
    return { files, requests, fieldRequests, resolve: (...args) => pending(...args), send(state, message) {
        const frame = find(state.output, 'iframe').props.ref.current;
        for (const listener of listeners.message ?? []) listener({ origin: window.location.origin, source: frame.contentWindow, data: { sve: 1, message } });
        state.render();
    } };
}
async function editor() {
    const state = harness(Editor, { startPath: '/', can: { edit: true, code: true } });
    state.render();
    await flush(state);
    state.open = async (path) => {
        find(state.output, TemplatesPanel).props.onOpen({ path, origin: 'site' });
        state.render();
        await flush(state);
    };
    state.panel = () => find(state.output, CodePanel).props;
    await state.open('a.blade.php');
    return state;
}

test('the real template save callback retains typing made after the request was sent', async () => {
    const env = environment();
    const state = await editor();
    state.panel().onDraft('submitted'); state.render();
    const code = harness(CodePanel, state.panel()); code.render();
    find(code.output, PlainCode).props.onSave();
    state.panel().onDraft('newer typing'); state.render();
    env.resolve({ template: template('a.blade.php', 'submitted', 'a2') });
    await flush(state);
    assert.equal(state.panel().draft, 'newer typing');
    assert.equal(state.panel().template.version, 'a2');
    assert.equal(state.panel().template.source, 'submitted');
});

test('an old draft is never assigned an external edit version when its tab reopens', async () => {
    const env = environment();
    const state = await editor();
    state.panel().onDraft('old draft'); state.render();
    await state.open('b.blade.php');
    env.files['a.blade.php'] = template('a.blade.php', 'external edit', 'a2');
    await state.open('a.blade.php');
    assert.equal(state.panel().draft, 'old draft');
    assert.equal(state.panel().template.version, 'a1');
    assert.equal(state.panel().conflict, true);
    const code = harness(CodePanel, state.panel()); code.render();
    find(code.output, PlainCode).props.onSave();
    assert.equal(env.requests[0].body.version, 'a1');
});

for (const kind of ['css', 'js']) {
    test(`the ${kind} panel preserves later typing when a save completes`, async () => {
        const env = environment();
        const state = harness(CustomFilePanel, { kind, mayEdit: true, editorScript: null, onUnsaved() {}, onSaved() {} });
        state.render(); await flush(state);
        find(state.output, PlainCode).props.onText('submitted'); state.render();
        find(state.output, PlainCode).props.onSave(); state.render();
        find(state.output, PlainCode).props.onText('newer typing'); state.render();
        env.resolve(kind === 'css' ? { file: { version: 'c2' } } : { custom: { version: 'c2' } });
        await flush(state);
        assert.equal(find(state.output, PlainCode).props.text, 'newer typing');
    });
}

test('an old canvas offset cannot select or delete the different node now at that offset', async () => {
    const env = environment();
    const state = await editor();
    const rendered = { ...env.files['a.blade.php'], key: 'file-a', version: 'a1' };
    env.send(state, { type: 'ready', url: '/', title: 'Page', manifest: { files: [rendered] } });
    await flush(state);
    await state.open('b.blade.php');
    env.files['a.blade.php'] = { ...template('a.blade.php', '<p>X</p><p>A</p><p>B</p>', 'a2'), tree: { id: 'root', type: 'root', start: 0, end: 24, children: ['X', 'A', 'B'].map((label, index) => ({ id: String(index * 8), type: 'element', name: 'p', label, start: index * 8, end: index * 8 + 8, can: { delete: true, move: true, attributes: true } })) } };
    await state.open('a.blade.php');
    // The old frame reports ready again before its replacement arrives.
    env.send(state, { type: 'ready', url: '/', title: 'Page', manifest: { files: [rendered] } });
    env.send(state, { type: 'select', target: { mark: { file: 'file-a', id: '8' }, version: 'a1' } });
    assert.equal(find(state.output, LayersPanel).props.selected, null);
    assert.equal(find(state.output, LayersPanel).props.fixed, true);
    env.send(state, { type: 'key', key: 'delete' });
    assert.equal(env.requests.length, 0);
});

test('a matching preview version still selects and edits the named node', async () => {
    const env = environment();
    env.files['a.blade.php'].tree = { id: 'root', type: 'root', start: 0, end: 8, children: [{ id: '0', type: 'element', name: 'p', label: 'p', start: 0, end: 8, can: { delete: true, move: true, attributes: true }, children: [] }] };
    const state = await editor();
    env.send(state, { type: 'ready', url: '/', title: 'Page', manifest: { files: [{ ...env.files['a.blade.php'], key: 'file-a' }] } });
    await flush(state);
    env.send(state, { type: 'select', target: { mark: { file: 'file-a', id: '0' }, version: 'a1' } });
    assert.equal(find(state.output, LayersPanel).props.selected, '0');
    env.send(state, { type: 'key', key: 'delete' });
    assert.deepEqual(env.requests[0].body, { version: 'a1', operations: [{ type: 'remove', node: '0' }] });
});

test('a refreshed preview clears a selection from an older source version', async () => {
    const env = environment();
    const node = (id, label) => ({ id, type: 'element', name: 'p', label, start: Number(id), end: Number(id) + 8, can: { delete: true, attributes: true }, children: [] });
    env.files['a.blade.php'].tree = { id: 'root', type: 'root', start: 0, end: 16, children: [node('0', 'A'), node('8', 'B')] };
    const state = await editor();
    const ready = () => env.send(state, { type: 'ready', url: '/', title: 'Page', manifest: { files: [{ ...env.files['a.blade.php'], key: 'file-a' }] } });
    ready(); await flush(state);
    env.send(state, { type: 'select', target: { mark: { file: 'file-a', id: '8' }, version: 'a1' } });
    assert.equal(find(state.output, LayersPanel).props.selected, '8');

    env.files['a.blade.php'] = { ...template('a.blade.php', '<p>X</p><p>A</p><p>B</p>', 'a2'), tree: { id: 'root', type: 'root', start: 0, end: 24, children: [node('0', 'X'), node('8', 'A'), node('16', 'B')] } };
    ready(); await flush(state);
    assert.equal(find(state.output, LayersPanel).props.selected, null);
    env.send(state, { type: 'key', key: 'delete' });
    assert.equal(env.requests.length, 0);

    env.send(state, { type: 'select', target: { mark: { file: 'file-a', id: '16' }, version: 'a2' } });
    env.send(state, { type: 'key', key: 'delete' });
    assert.deepEqual(env.requests[0].body, { version: 'a2', operations: [{ type: 'remove', node: '16' }] });
});


function matching(node, predicate) {
    if (!node || typeof node !== 'object') return null;
    if (predicate(node)) return node;
    for (const child of [node.props?.children].flat(Infinity)) {
        const found = matching(child, predicate);
        if (found) return found;
    }
    return null;
}
const button = (output, label) => matching(output, (node) => node.type === 'button' && node.props.children === label);
const alert = (output) => matching(output, (node) => node.props?.role === 'alert');

test('Revert clears a refused template save message and restores the saved text', async () => {
    const env = environment();
    const state = await editor();
    state.panel().onDraft('broken'); state.render();
    const code = harness(CodePanel, state.panel()); code.render();
    find(code.output, PlainCode).props.onSave();
    env.resolve({ reason: 'syntax', message: 'Broken Blade' }, 422);
    await flush(code);
    assert.ok(alert(code.output));
    button(code.output, 'Revert').props.onClick();
    state.render(); code.props = state.panel(); code.render();
    assert.equal(alert(code.output), null);
    assert.equal(find(code.output, PlainCode).props.text, 'original');
});

test('the custom file uses its configured disk path and clears a refused save on Revert', async () => {
    const env = environment();
    const state = harness(CustomFilePanel, { kind: 'css', mayEdit: true, editorScript: null, onUnsaved() {}, onSaved() {} });
    state.render(); await flush(state);
    assert.ok(matching(state.output, (node) => node.props?.children === 'public/cms/custom.css'));
    find(state.output, PlainCode).props.onText('broken'); state.render();
    find(state.output, PlainCode).props.onSave();
    env.resolve({ reason: 'syntax', message: 'Broken CSS' }, 422);
    await flush(state);
    assert.ok(alert(state.output));
    button(state.output, 'Revert').props.onClick(); state.render();
    assert.equal(alert(state.output), null);
    assert.equal(find(state.output, PlainCode).props.text, 'original');
});

test('opening a template fetches no field catalog until completion is requested', async () => {
    const env = environment();
    const state = await editor();
    assert.equal(env.fieldRequests.length, 0);
    let load;
    window.setTimeout = (callback) => { load = callback; return 0; };
    state.panel().onFieldsNeeded(); state.render();
    assert.equal(typeof load, 'function');
    load(); await flush(state);
    assert.equal(env.fieldRequests.length, 1);
});

test('panel switches expose grouped pressed buttons without incomplete tab roles', async () => {
    environment();
    const state = await editor();
    assert.equal(matching(state.output, (node) => node.props?.role === 'tablist'), null);
    const templates = button(state.output, 'Templates');
    assert.equal(templates.props['aria-pressed'], false);
    templates.props.onClick(); state.render();
    assert.equal(button(state.output, 'Templates').props['aria-pressed'], true);
});
