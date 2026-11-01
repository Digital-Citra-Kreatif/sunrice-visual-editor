import assert from 'node:assert/strict';
import { test } from 'node:test';
import { checkNote, cssProblem, jsProblem, troubleParts } from '../../resources/js/lib/syntax.ts';

test('finds nothing in valid CSS, escapes, strings, comments and unquoted url() included', () => {
    for (const css of [
        '',
        '.a { color: red; }\n@media (max-width: 640px) { .b { margin: 0 } }',
        '.sm\\:flex { display: flex } .w-\\[10px\\] { width: 10px }',
        '.a::before { content: "}"; } .b::after { content: \'(\' }',
        '/* { ( [ */ .a { background: url(images/it\'s.png) }',
        '.a { background: url(data:image/svg+xml;utf8,<svg xmlns=\'x\'></svg>) }',
        '[data-x="a]"] { color: blue }',
        '.a { content: "a\\\nb" }',
        // The same, with a Windows line break.
        '.a {\r\n  content: "a\\\r\nb";\r\n}',
        '.a { background: url(foo\\)bar.png) }',
    ]) {
        assert.equal(cssProblem(css), null, css);
    }
});

test('names the line of what is wrong in CSS', () => {
    assert.deepEqual(cssProblem('.a {\n  color: red;\n'), { line: 1, message: '“{” is never closed with “}”.' });
    assert.deepEqual(cssProblem('.a { }\n}'), { line: 2, message: '“}” closes nothing: there is no “{” before it.' });
    assert.deepEqual(cssProblem('}}} @@@ {'), { line: 1, message: '“}” closes nothing: there is no “{” before it.' });
    assert.equal(cssProblem('.a {\n  width: calc(1px + 2px;\n}').line, 3);
    assert.match(cssProblem('.a {\n  width: calc(1px + 2px;\n}').message, /“\)” was expected, to close the “\(” of line 2/);
    assert.deepEqual(cssProblem('.a { }\n/* open'), { line: 2, message: 'A comment “/*” is never closed with “*/”.' });
    assert.deepEqual(cssProblem('.a {\n content: "x\n}'), { line: 2, message: 'A string "…" is not closed on its line.' });
});

test('finds nothing in valid JS: strings, template literals, regular expressions and comments', () => {
    for (const js of [
        'window.a = 1;',
        'const s = "}"; const t = \'{\'; // }\n/* ( */ f(1, [2]);',
        'const t = `a ${b ? `c${d}` : "}"} e`;',
        'const r = /[/{]+/g.test(x); const d = a / b / c;',
        'if (x) { return /\\)/.exec(y); }',
        'const o = { a: [1, 2], b() { return (1); } };',
    ]) {
        assert.equal(jsProblem(js), null, js);
    }
});

test('names the line of what is wrong in JS', () => {
    assert.deepEqual(jsProblem('window.a=1;\nfunction( {{{'), { line: 2, message: '“{” is never closed with “}”.' });
    assert.equal(jsProblem('f(1;\n').line, 1);
    assert.equal(jsProblem('a = 1;\nb = "x;\n').line, 2);
    assert.equal(jsProblem('a = `x ${y}').line, 1);
    assert.equal(jsProblem('a = 1;\n}').line, 2);
});

test('splits a refusal into what it says and what the checker printed', () => {
    assert.deepEqual(troubleParts('Something went wrong.'), { said: 'Something went wrong.', output: null });
    assert.deepEqual(troubleParts('JS check failed on line 2. Nothing was saved.\n[stdin]:2\nfunction( {{{\n^^^^^^^^\n'), { said: 'JS check failed on line 2. Nothing was saved.', output: '[stdin]:2\nfunction( {{{\n^^^^^^^^' });
});

test('says how saves are checked', () => {
    assert.match(checkNote('css', 'builtin', true), /unclosed braces/);
    assert.match(checkNote('js', 'node', true), /node --check/);
    assert.match(checkNote('js', 'command', true), /configured syntax check/);
    // A server from before `checker` was sent.
    assert.match(checkNote('js', undefined, true), /configured syntax check/);
    assert.match(checkNote('js', null, false), /Node\.js was not found/);
    assert.match(checkNote('css', null, false), /syntax_checks\.css is false/);
});
