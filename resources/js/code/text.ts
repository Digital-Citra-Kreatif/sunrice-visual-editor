/**
 * What the code editor needs to know about a text before it shows it, kept
 * apart from the editor itself: this has no CodeMirror in it and is tested
 * on its own.
 */
import { highlight, type TokenKind } from '../lib/highlight.ts';

/** Above this many characters a text gets no colours: they are worked out anew for the whole text as it is typed. */
export const MAX_COLOURED = 400_000;

/**
 * How the lines of a text end. A text is given back with the line breaks
 * it came with, whichever this is:
 *
 * - "unix": every line ends "\n". What is pasted in with other line breaks
 *   is made to match.
 * - "windows": every line ends "\r\n", and the editor writes that for a
 *   line break.
 * - "mixed": some of each. A line break is "\n" and a "\r" before it is a
 *   character of the line like any other, shown as one.
 */
export function lineBreaksOf(text: string): 'unix' | 'windows' | 'mixed' {
    const returns = text.split('\r').length - 1;
    if (returns === 0) {
        return 'unix';
    }

    return returns === text.split('\r\n').length - 1 && returns === text.split('\n').length - 1 ? 'windows' : 'mixed';
}

/** What Tab puts in: the indentation the file itself uses, four spaces when it shows none. */
export function indentOf(source: string): string {
    const match = /^(\t|[ ]{2,8})\S/m.exec(source);

    return match === null ? '    ' : match[1];
}

export type Coloured = { from: number; to: number; kind: Exclude<TokenKind, 'text'> };

/**
 * The stretches of a text that have a colour, by where they are in it.
 * `text` is the text as the editor counts it: one character for each line
 * break, however the file writes them.
 */
export function coloured(text: string): Coloured[] {
    if (text.length > MAX_COLOURED) {
        return [];
    }
    const found: Coloured[] = [];
    let at = 0;
    for (const token of highlight(text)) {
        if (token.kind !== 'text' && token.text !== '') {
            found.push({ from: at, to: at + token.text.length, kind: token.kind });
        }
        at += token.text.length;
    }

    return found;
}

/**
 * A place in a text counted in characters, from where it is counted in
 * bytes: a node of a template says where it is in bytes of the file.
 * Past the end is the end.
 */
export function indexOfByte(text: string, byte: number): number {
    let bytes = 0;
    let index = 0;
    for (const character of text) {
        if (bytes >= byte) {
            break;
        }
        const point = character.codePointAt(0) ?? 0;
        bytes += point < 0x80 ? 1 : point < 0x800 ? 2 : point < 0x10000 ? 3 : 4;
        index += character.length;
    }

    return index;
}

/** The same the other way: how many bytes of a text come before a place counted in characters. */
export function byteOfIndex(text: string, index: number): number {
    return new TextEncoder().encode(text.slice(0, Math.max(0, index))).length;
}

/**
 * The one change that turns a text into another: what is between the start
 * they share and the end they share. Good for carrying a place across when
 * nothing better is known of how the one became the other.
 */
export function difference(before: string, after: string): { from: number; to: number; insert: string } {
    const most = Math.min(before.length, after.length);
    let start = 0;
    while (start < most && before[start] === after[start]) {
        start++;
    }
    let end = 0;
    while (end < most - start && before[before.length - 1 - end] === after[after.length - 1 - end]) {
        end++;
    }

    return { from: start, to: before.length - end, insert: after.slice(start, after.length - end) };
}
