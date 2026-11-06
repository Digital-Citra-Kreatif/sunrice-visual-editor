/**
 * What is marked in the code of the custom CSS and JS as it is typed:
 * a `{` `(` `[` never closed, or closed by the wrong pair, and a string or
 * comment never closed. The same check the server makes of CSS when it
 * has no other (Support/CssSyntax.php); for JS the server asks Node.js,
 * where it has it, and this is only a first look.
 *
 * Lines are counted from 1.
 */

export type Problem = { line: number; message: string };

const PAIRS: Record<string, string> = { '{': '}', '(': ')', '[': ']' };
const CLOSERS: Record<string, string> = { '}': '{', ')': '(', ']': '[' };

/** Counts lines as the text is read. */
class Reader {
    at = 0;
    line = 1;
    readonly open: { char: string; line: number }[] = [];

    readonly text: string;

    constructor(text: string) {
        this.text = text;
    }

    /** Steps over one character, counting a line break. */
    step(): void {
        if (this.text[this.at] === '\n') {
            this.line++;
        }
        this.at++;
    }

    /** A bracket: pushed, or matched against the last one open. */
    bracket(char: string): Problem | null {
        if (char in PAIRS) {
            this.open.push({ char, line: this.line });

            return null;
        }
        const last = this.open.pop();
        if (last === undefined) {
            return { line: this.line, message: `“${char}” closes nothing: there is no “${CLOSERS[char]}” before it.` };
        }
        if (PAIRS[last.char] !== char) {
            return { line: this.line, message: `“${char}” where “${PAIRS[last.char]}” was expected, to close the “${last.char}” of line ${last.line}.` };
        }

        return null;
    }

    /** Past a block comment that starts here; a problem when it never ends. */
    comment(): Problem | null {
        const end = this.text.indexOf('*/', this.at + 2);
        if (end === -1) {
            return { line: this.line, message: 'A comment “/*” is never closed with “*/”.' };
        }
        while (this.at < end + 2) {
            this.step();
        }

        return null;
    }

    /** Past a quoted string that starts here; a problem when it isn't closed on its line. */
    string(quote: string): Problem | null {
        const start = this.line;
        this.at++;
        while (this.at < this.text.length && this.text[this.at] !== quote) {
            if (this.text[this.at] === '\n') {
                return { line: start, message: `A string ${quote}…${quote} is not closed on its line.` };
            }
            if (this.text[this.at] === '\\') {
                this.step();
                // An escaped line break goes on to the next line: "\r\n" is one.
                if (this.text[this.at] === '\r' && this.text[this.at + 1] === '\n') {
                    this.step();
                }
            }
            this.step();
        }
        if (this.at >= this.text.length) {
            return { line: start, message: `A string ${quote}…${quote} is never closed.` };
        }
        this.at++;

        return null;
    }

    /** What is still open at the end. */
    end(): Problem | null {
        const last = this.open.pop();

        return last === undefined ? null : { line: last.line, message: `“${last.char}” is never closed with “${PAIRS[last.char]}”.` };
    }
}

/** The first thing wrong in a stylesheet, or null. */
export function cssProblem(css: string): Problem | null {
    const reader = new Reader(css);
    while (reader.at < css.length) {
        const char = css[reader.at];
        if (char === '\\') {
            // An escape: ".sm\:flex", "\{" is part of a name.
            reader.step();
            reader.step();
            continue;
        }
        if (char === '/' && css[reader.at + 1] === '*') {
            const problem = reader.comment();
            if (problem !== null) {
                return problem;
            }
            continue;
        }
        if (char === '"' || char === "'") {
            const problem = reader.string(char);
            if (problem !== null) {
                return problem;
            }
            continue;
        }
        if (char === '(' && /(?:^|[^A-Za-z0-9_-])url$/i.test(css.slice(Math.max(0, reader.at - 4), reader.at)) && /^\(\s*[^\s"')]/.test(css.slice(reader.at, reader.at + 200))) {
            // url(images/a.png): the address, quotes and all; "\)" is part of it.
            let end = reader.at + 1;
            while (end < css.length && css[end] !== ')') {
                end += css[end] === '\\' ? 2 : 1;
            }
            if (end >= css.length) {
                return { line: reader.line, message: '“url(” is never closed with “)”.' };
            }
            while (reader.at <= end) {
                reader.step();
            }
            continue;
        }
        if (char in PAIRS || char in CLOSERS) {
            const problem = reader.bracket(char);
            if (problem !== null) {
                return problem;
            }
        }
        reader.step();
    }

    return reader.end();
}

/** Words after which a "/" starts a regular expression, not a division. */
const BEFORE_REGEX = new Set(['return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void', 'throw', 'case', 'do', 'else', 'yield', 'await']);

/** The first thing wrong in a script, or null: brackets, strings, template literals, comments and regular expressions. */
export function jsProblem(js: string): Problem | null {
    const reader = new Reader(js);
    /** For each template literal open, how many of the brackets were open when its "${" was. */
    const templates: number[] = [];
    /** What came last that wasn't space or a comment: whether a "/" there starts a regular expression. */
    let last = '';

    const literal = (): Problem | null => {
        // In a template literal, after "`" or "}": up to its end, or to a "${".
        const start = reader.line;
        while (reader.at < js.length) {
            const char = js[reader.at];
            if (char === '\\') {
                reader.step();
                reader.step();
                continue;
            }
            if (char === '`') {
                reader.at++;

                return null;
            }
            if (char === '$' && js[reader.at + 1] === '{') {
                reader.at += 2;
                templates.push(reader.open.length);
                reader.open.push({ char: '{', line: reader.line });

                return null;
            }
            reader.step();
        }

        return { line: start, message: 'A template literal `…` is never closed.' };
    };

    while (reader.at < js.length) {
        const char = js[reader.at];
        if (/\s/.test(char)) {
            reader.step();
            continue;
        }
        if (char === '/' && js[reader.at + 1] === '/') {
            while (reader.at < js.length && js[reader.at] !== '\n') {
                reader.at++;
            }
            continue;
        }
        if (char === '/' && js[reader.at + 1] === '*') {
            const problem = reader.comment();
            if (problem !== null) {
                return problem;
            }
            continue;
        }
        if (char === '"' || char === "'") {
            const problem = reader.string(char);
            if (problem !== null) {
                return problem;
            }
            last = 'a';
            continue;
        }
        if (char === '`') {
            reader.at++;
            const problem = literal();
            if (problem !== null) {
                return problem;
            }
            last = 'a';
            continue;
        }
        if (char === '/' && (last === '' || /[(,=:[!&|?{};+\-*%<>~^]$/.test(last) || BEFORE_REGEX.has(last))) {
            const start = reader.line;
            let inClass = false;
            reader.at++;
            while (reader.at < js.length && (inClass || js[reader.at] !== '/')) {
                const now = js[reader.at];
                if (now === '\n') {
                    return { line: start, message: 'A regular expression /…/ is not closed on its line.' };
                }
                if (now === '\\') {
                    reader.at++;
                } else if (now === '[') {
                    inClass = true;
                } else if (now === ']') {
                    inClass = false;
                }
                reader.at++;
            }
            if (reader.at >= js.length) {
                return { line: start, message: 'A regular expression /…/ is never closed.' };
            }
            reader.at++;
            while (/[a-z]/i.test(js[reader.at] ?? '')) {
                reader.at++;
            }
            last = 'a';
            continue;
        }
        if (char === '}' && templates.length > 0 && templates[templates.length - 1] === reader.open.length - 1) {
            // The end of a "${…}": back in the template literal.
            templates.pop();
            reader.open.pop();
            reader.at++;
            const problem = literal();
            if (problem !== null) {
                return problem;
            }
            last = 'a';
            continue;
        }
        if (char in PAIRS || char in CLOSERS) {
            const problem = reader.bracket(char);
            if (problem !== null) {
                return problem;
            }
            reader.at++;
            last = char;
            continue;
        }
        const word = /^[A-Za-z_$][\w$]*/.exec(js.slice(reader.at, reader.at + 64));
        if (word !== null) {
            reader.at += word[0].length;
            last = word[0];
            continue;
        }
        reader.at++;
        last = char;
    }

    return reader.end();
}

/** What is said of how saves are checked, by the checker the server says applies. */
export function checkNote(kind: 'css' | 'js', checker: 'command' | 'builtin' | 'node' | null | undefined, checked: boolean | undefined): string {
    switch (checker ?? (checked === true ? 'command' : null)) {
        case 'command':
            return 'Saves must pass the site’s configured syntax check.';
        case 'builtin':
            return 'Saves are checked for unclosed braces, brackets, strings and comments.';
        case 'node':
            return 'Saves must pass a syntax check by Node.js (node --check).';
        default:
            return kind === 'js'
                ? 'Saves are not syntax-checked: Node.js was not found on the server, or syntax_checks.js is false. An administrator can set syntax_checks.js in the editor configuration.'
                : 'Saves are not syntax-checked: syntax_checks.css is false in the editor configuration.';
    }
}

/** A refusal's message as shown: its first line said, and what the checker printed kept as it was laid out. */
export function troubleParts(message: string): { said: string; output: string | null } {
    const at = message.indexOf('\n');

    return at === -1 ? { said: message, output: null } : { said: message.slice(0, at), output: message.slice(at + 1).replace(/^\n+|\n+$/g, '') || null };
}
