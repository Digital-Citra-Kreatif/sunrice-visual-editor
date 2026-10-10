import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ApiError, pathInAddress, refusal } from '../../resources/js/lib/api.ts';
import { isEditorAddress } from '../../resources/js/bridge/protocol.ts';

const answer = (status, body) => ({ status, json: async () => (body === undefined ? Promise.reject(new SyntaxError('not JSON')) : body) });

test('writes a template\'s path into an address, slashes kept', () => {
    assert.equal(pathInAddress('partials/header.blade.php'), 'partials/header.blade.php');
    assert.equal(pathInAddress('pages/about us.blade.php'), 'pages/about%20us.blade.php');
    assert.equal(pathInAddress('a?b/c#d.blade.php'), 'a%3Fb/c%23d.blade.php');
});

test('reads a refusal as the server gave it', async () => {
    const conflict = await refusal(answer(409, { reason: 'conflict', message: 'It has changed.', version: 'abc' }));
    assert.ok(conflict instanceof ApiError);
    assert.deepEqual([conflict.reason, conflict.status, conflict.message, conflict.version, conflict.operation], ['conflict', 409, 'It has changed.', 'abc', null]);

    const rejected = await refusal(answer(422, { reason: 'rejected', message: 'No such node.', operation: 2 }));
    assert.deepEqual([rejected.reason, rejected.operation, rejected.version], ['rejected', 2, null]);

    // The first operation is number 0, and a result refused as a whole has none.
    assert.equal((await refusal(answer(422, { reason: 'rejected', message: 'x', operation: 0 }))).operation, 0);
    assert.equal((await refusal(answer(422, { reason: 'rejected', message: 'x', operation: null }))).operation, null);
});

test('has something to say when the server doesn\'t', async () => {
    const unknown = await refusal(answer(500, { reason: 'on fire' }));
    assert.deepEqual([unknown.reason, unknown.message], ['failed', 'The server answered 500.']);

    const html = await refusal(answer(502, undefined));
    assert.deepEqual([html.reason, html.message], ['failed', 'The server answered 502.']);

    for (const status of [401, 419]) {
        assert.match((await refusal(answer(status, { message: '' }))).message, /session has ended/);
    }
    // Laravel's own message for a refusal the editor has no word for.
    assert.equal((await refusal(answer(403, { message: 'This action is unauthorized.' }))).message, 'This action is unauthorized.');
});

test('knows an editor render by its address', () => {
    assert.equal(isEditorAddress('https://site.test/about?sve=1'), true);
    assert.equal(isEditorAddress('https://site.test/search?q=a%20b&sve=1#top'), true);
    assert.equal(isEditorAddress('https://site.test/about'), false);
    assert.equal(isEditorAddress('https://site.test/about?sve=0'), false);
    assert.equal(isEditorAddress('https://site.test/about?nosve=1'), false);
    assert.equal(isEditorAddress('about:blank'), false);
    assert.equal(isEditorAddress('not an address'), false);
});
