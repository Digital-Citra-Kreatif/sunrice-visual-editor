import assert from 'node:assert/strict';
import { test } from 'node:test';
import { typedDraft, savedDraft, sameVersion } from '../../resources/js/lib/drafts.ts';

const base = { source: 'original', version: 'v1' };
const saved = { source: 'submitted', version: 'v2' };

test('a draft retains the version it was typed against across a fresh disk read', () => {
    const draft = typedDraft(undefined, base, 'submitted');
    assert.deepEqual(typedDraft(draft, { source: 'external edit', version: 'external' }, 'more typing').base, base);
});

test('a save keeps later typing and advances its baseline for the next save', () => {
    const sent = typedDraft(undefined, base, 'submitted');
    const later = typedDraft(sent, base, 'newer text');
    assert.deepEqual(savedDraft(later, sent, saved), { text: 'newer text', base: saved });
    assert.equal(savedDraft(sent, sent, saved), undefined);
});

test('typing back to the original while a save is pending remains an unsaved change afterwards', () => {
    const sent = typedDraft(undefined, base, 'submitted');
    assert.equal(typedDraft(sent, base, base.source), undefined);
    assert.deepEqual(savedDraft(undefined, sent, saved), { text: 'original', base: saved });
});

test('missing or different preview versions can never authorize a byte offset', () => {
    assert.equal(sameVersion('old', 'new'), false);
    assert.equal(sameVersion(null, null), false);
    assert.equal(sameVersion(undefined, 'new'), false);
    assert.equal(sameVersion('', ''), false);
    assert.equal(sameVersion('new', 'new'), true);
});
