/**
 * Colours for Blade in the code panel: enough to tell Blade from HTML and
 * tags from text at a glance. It reads the source in one pass and never
 * changes a character: the pieces put back together are the source.
 */

export type TokenKind =
    | 'text'
    /** {{-- … --}} and <!-- … --> */
    | 'comment'
    /** {{ … }}, {!! … !!}: the braces */
    | 'echo'
    /** @if, @endforeach, @include */
    | 'directive'
    /** What is inside an echo, a directive's brackets, @php … @endphp or <?php … ?> */
    | 'php'
    /** <, </, >, /> and the tag's name */
    | 'tag'
    | 'attribute'
    /** A quoted attribute value */
    | 'string';

export type Token = { kind: TokenKind; text: string };

/** The end of a bracketed stretch starting at `from` (an opening bracket), quotes respected; -1 when it never closes. */
function closingBracket(source: string, from: number): number {
    let depth = 0;
    let quote: string | null = null;
    for (let at = from; at < source.length; at++) {
        const char = source[at];
        if (quote !== null) {
            if (char === '\\') {
                at++;
            } else if (char === quote) {
                quote = null;
            }
        } else if (char === '"' || char === "'") {
            quote = char;
        } else if (char === '(') {
            depth++;
        } else if (char === ')' && --depth === 0) {
            return at;
        }
    }

    return -1;
}

/** A Blade construct starting at `at`, as tokens; null when there is none. */
function blade(source: string, at: number): Token[] | null {
    if (source.startsWith('{{--', at)) {
        const end = source.indexOf('--}}', at + 4);

        return [{ kind: 'comment', text: source.slice(at, end === -1 ? source.length : end + 4) }];
    }
    for (const [open, close] of [
        ['{!!', '!!}'],
        ['{{', '}}'],
    ]) {
        if (source.startsWith(open, at)) {
            const end = source.indexOf(close, at + open.length);
            if (end === -1) {
                return null;
            }

            return [
                { kind: 'echo', text: open },
                { kind: 'php', text: source.slice(at + open.length, end) },
                { kind: 'echo', text: close },
            ];
        }
    }
    if (source.startsWith('<?php', at) || source.startsWith('<?=', at)) {
        const end = source.indexOf('?>', at);

        return [{ kind: 'php', text: source.slice(at, end === -1 ? source.length : end + 2) }];
    }
    if (source[at] === '@') {
        // As Blade reads it: "@name" is a directive unless a letter, digit or "_" comes before it, and "@@" is an "@".
        const before = at === 0 ? '' : source[at - 1];
        const name = /^@[A-Za-z_]\w*(?:::\w+)?/.exec(source.slice(at, at + 80));
        if (name === null || /[\w@]/.test(before)) {
            return null;
        }
        const tokens: Token[] = [{ kind: 'directive', text: name[0] }];
        let next = at + name[0].length;
        if (name[0] === '@php' || name[0] === '@verbatim') {
            const brackets = /^[ \t]*\(/.exec(source.slice(next, next + 20));
            if (name[0] === '@verbatim' || brackets === null) {
                // A block of PHP, or of text Blade leaves alone, up to its end.
                const closing = name[0] === '@php' ? '@endphp' : '@endverbatim';
                const end = source.indexOf(closing, next);
                if (end !== -1) {
                    tokens.push({ kind: name[0] === '@php' ? 'php' : 'text', text: source.slice(next, end) }, { kind: 'directive', text: closing });
                }

                return tokens;
            }
        }
        const space = /^[ \t]*/.exec(source.slice(next, next + 20))?.[0] ?? '';
        if (source[next + space.length] === '(') {
            const end = closingBracket(source, next + space.length);
            if (end !== -1) {
                tokens.push({ kind: 'php', text: source.slice(next, end + 1) });
                next = end + 1;
            }
        }

        return tokens;
    }

    return null;
}

/** An HTML tag starting at `at` (a "<"), as tokens, with any Blade inside it; null when it is no tag. */
function tag(source: string, at: number): Token[] | null {
    const open = /^<\/?[A-Za-z][\w:.-]*/.exec(source.slice(at, at + 120));
    if (open === null) {
        return null;
    }
    const tokens: Token[] = [{ kind: 'tag', text: open[0] }];
    let next = at + open[0].length;
    let plain = '';
    const flush = () => {
        if (plain !== '') {
            tokens.push({ kind: 'text', text: plain });
            plain = '';
        }
    };

    while (next < source.length) {
        const char = source[next];
        if (char === '>' || source.startsWith('/>', next)) {
            flush();
            tokens.push({ kind: 'tag', text: char === '>' ? '>' : '/>' });

            return tokens;
        }
        const inside = blade(source, next);
        if (inside !== null) {
            flush();
            tokens.push(...inside);
            next += inside.reduce((length, token) => length + token.text.length, 0);
        } else if (char === '"' || char === "'") {
            flush();
            // A value in quotes, with the Blade in it picked out.
            let value = char;
            let end = next + 1;
            while (end < source.length && source[end] !== char) {
                const echoed = blade(source, end);
                if (echoed !== null) {
                    tokens.push({ kind: 'string', text: value }, ...echoed);
                    value = '';
                    end += echoed.reduce((length, token) => length + token.text.length, 0);
                } else {
                    value += source[end++];
                }
            }
            tokens.push({ kind: 'string', text: value + (end < source.length ? char : '') });
            next = Math.min(end + 1, source.length);
        } else {
            const name = /^[^\s"'<>\/=@{]+/.exec(source.slice(next, next + 120));
            if (name !== null) {
                flush();
                tokens.push({ kind: 'attribute', text: name[0] });
                next += name[0].length;
            } else {
                plain += char;
                next++;
            }
        }
    }
    flush();

    return tokens;
}

/** The source as coloured pieces. Joined, their text is the source again. */
export function highlight(source: string): Token[] {
    const tokens: Token[] = [];
    let text = '';
    const flush = () => {
        if (text !== '') {
            tokens.push({ kind: 'text', text });
            text = '';
        }
    };
    const take = (found: Token[]) => {
        flush();
        tokens.push(...found);

        return found.reduce((length, token) => length + token.text.length, 0);
    };

    let at = 0;
    while (at < source.length) {
        const char = source[at];
        let found: Token[] | null = null;
        if (char === '{' || char === '@' || source.startsWith('<?', at)) {
            found = blade(source, at);
        } else if (source.startsWith('<!--', at)) {
            const end = source.indexOf('-->', at + 4);
            found = [{ kind: 'comment', text: source.slice(at, end === -1 ? source.length : end + 3) }];
        } else if (char === '<') {
            found = tag(source, at);
        }

        if (found !== null) {
            at += take(found);
        } else {
            text += char;
            at++;
        }
    }
    flush();

    return tokens;
}
