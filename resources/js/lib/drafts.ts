/** A draft keeps the file it was based on until it is saved or explicitly discarded. */
export type Snapshot = { source: string; version: string };
export type Draft = { text: string; base: Snapshot };

export function typedDraft(previous: Draft | undefined, base: Snapshot, text: string): Draft | undefined {
    const original = previous?.base ?? base;

    return text === original.source ? undefined : { text, base: original };
}

/** A completed save acknowledges only the submitted text, never typing that came after it. */
export function savedDraft(current: Draft | undefined, sent: Draft, saved: Snapshot): Draft | undefined {
    // No draft may mean the person typed back to the original text while the save was pending.
    const text = current?.text ?? sent.base.source;

    return text === saved.source ? undefined : { text, base: saved };
}

/** A tree and a preview can exchange byte offsets only for the same source. */
export function sameVersion(rendered: string | null | undefined, current: string | null | undefined): boolean {
    return typeof rendered === 'string' && rendered !== '' && rendered === current;
}
