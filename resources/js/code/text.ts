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
