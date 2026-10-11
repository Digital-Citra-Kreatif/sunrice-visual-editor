/**
 * Writing a condition and a query for entries without typing code: what
 * the controls of the Settings panel offer, the code each choice writes,
 * and reading code back into the controls when it was written by them.
 */
import type { PickField, PickVariable } from './api';
// With its extension: Node runs this file as it is for the tests.
import { everything, type Found } from './fields.ts';
import type { Attribute } from './settings.ts';

// --- Conditions -----------------------------------------------------------------

/** What a condition asks of a value. */
export type Test = 'set' | 'unset' | 'is' | 'isnt' | 'more' | 'less' | 'contains' | 'has' | 'past' | 'future';

type Asked = Pick<PickVariable | PickField, 'kind' | 'what' | 'asked' | 'compared' | 'looped'>;

/** The tests that make sense for a value, the usual one first. */
export function testsFor(value: Asked): Test[] {
    if (value.looped !== null) {
        return value.what === 'list of texts' ? ['set', 'unset', 'has'] : ['set', 'unset'];
    }
    switch (value.kind) {
        case 'bool':
            return ['set', 'unset'];
        case 'number':
            return ['set', 'unset', 'is', 'isnt', 'more', 'less'];
        case 'text':
        case 'html':
        case 'value':
            return ['set', 'unset', 'is', 'isnt', 'contains'];
        case 'date':
            return ['set', 'unset', 'past', 'future'];
        default:
            return ['set', 'unset'];
    }
}

/** What a test is called for a value: a switch "is on", a list "has any", anything else "is set". */
export function testLabel(test: Test, value: Asked): string {
    const list = value.looped !== null;
    switch (test) {
        case 'set':
            return list ? 'has any' : value.kind === 'bool' ? 'is on' : 'is set';
        case 'unset':
            return list ? 'has none' : value.kind === 'bool' ? 'is off' : 'is not set';
        case 'is':
            return 'is';
        case 'isnt':
            return 'is not';
        case 'more':
            return 'is more than';
        case 'less':
            return 'is less than';
        case 'contains':
            return 'contains';
        case 'has':
            return 'has';
        case 'past':
            return 'is in the past';
        default:
            return 'is in the future';
    }
}

/** Whether a test is against something typed: "is" what, "more than" what. */
export function takesValue(test: Test): boolean {
    return ['is', 'isnt', 'more', 'less', 'contains', 'has'].includes(test);
}

/** What was typed as PHP: a number as it is, anything else as a text. */
export function literal(typed: string): string {
    const text = typed.trim();
    if (/^-?\d+(\.\d+)?$/.test(text) || ['true', 'false', 'null'].includes(text)) {
        return text;
    }

    return `'${typed.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

/** The text of a PHP number or quoted text; null for anything else. */
export function unliteral(code: string): string | null {
    const text = code.trim();
    if (/^-?\d+(\.\d+)?$/.test(text) || ['true', 'false', 'null'].includes(text)) {
        return text;
    }
    const quoted = /^'((?:[^'\\]|\\.)*)'$/.exec(text);

    return quoted === null ? null : quoted[1].replace(/\\(['\\])/g, '$1');
}

/** The opposite of a condition, written as someone would: `! $a`, `empty($a)` for `! empty($a)`. */
export function not(code: string): string {
    const text = code.trim();
    if (text.startsWith('! ')) {
        return text.slice(2);
    }

    return /^\S+$/.test(text) ? `! ${text}` : `! (${text})`;
}

const COMPARES: Partial<Record<Test, string>> = { is: '==', isnt: '!=', more: '>', less: '<' };

/** The PHP of a condition: a value, what is asked of it, and what was typed to ask it against. */
export function condition(value: Asked, test: Test, typed = ''): string {
    switch (test) {
        case 'set':
            return value.asked;
        case 'unset':
            return not(value.asked);
        case 'contains':
            return `str_contains((string) ${value.compared}, ${literal(typed)})`;
        case 'has':
            return `in_array(${literal(typed)}, (array) ${value.compared})`;
        case 'past':
            return `${value.compared}?->isPast()`;
        case 'future':
            return `${value.compared}?->isFuture()`;
        default:
            return `${value.compared} ${COMPARES[test]} ${literal(typed)}`;
    }
}

const squeezed = (code: string) => code.replace(/\s+/g, '');

/**
 * A condition read back: the value it asks about, what it asks, and
 * against what. Null when it was not written by `condition()` for one of
 * the values at the place: it is then shown as code, and left alone.
 */
export function readCondition(variables: PickVariable[] | null, code: string | null): { found: Found; test: Test; typed: string } | null {
    if (variables === null || code === null || code.trim() === '') {
        return null;
    }
    const wanted = squeezed(code);
    for (const found of everything(variables)) {
        for (const test of testsFor(found.value)) {
            if (!takesValue(test)) {
                if (squeezed(condition(found.value, test)) === wanted) {
                    return { found, test, typed: '' };
                }

                continue;
            }
            // Written with a mark where the value goes, to find what stands there.
            const [before, after] = squeezed(condition(found.value, test, '\u0000')).split("'\u0000'");
            if (after !== undefined && wanted.startsWith(before) && wanted.endsWith(after) && wanted.length >= before.length + after.length) {
                // The value as it stands in the code, spaces and all: found again in the code as written.
                const typed = valueIn(code, before, after);
                if (typed !== null) {
                    return { found, test, typed };
                }
            }
        }
    }

    return null;
}

/** What is written in `code` between two pieces of it given without their spaces; null when it is no plain value. */
function valueIn(code: string, before: string, after: string): string | null {
    let at = 0;
    for (let seen = 0; seen < before.length && at < code.length; at++) {
        if (!/\s/.test(code[at])) {
            seen++;
        }
    }
    let end = code.length;
    for (let seen = 0; seen < after.length && end > at; end--) {
        if (!/\s/.test(code[end - 1])) {
            seen++;
        }
    }

    return unliteral(code.slice(at, end));
}

// --- Entries --------------------------------------------------------------------

/** What a component is given under a name, bound (`:limit="3"`) or plain (`limit="3"`); null when it isn't. */
export function given(attributes: Attribute[], name: string): { value: string; bound: boolean } | null {
    for (const [written, bound] of [
        [`:${name}`, true],
        [name, false],
    ] as const) {
        const attribute = attributes.find((each) => !each.code && each.name === written);
        if (attribute !== undefined) {
            return { value: attribute.value ?? '', bound };
        }
    }

    return null;
}

/** How many there are to choose from in "Show". */
export const LIMITS = [1, 2, 3, 4, 5, 6, 8, 10, 12, 24];

/** The orders entries can be listed in: Sunrice's own, then by each field of the collection. */
export function orders(fields: { handle: string; label: string }[]): { value: string; label: string }[] {
    return [
        { value: '', label: 'As the collection is set' },
        { value: '-published_at', label: 'Newest first' },
        { value: 'published_at', label: 'Oldest first' },
        { value: 'title', label: 'Title, A to Z' },
        { value: '-title', label: 'Title, Z to A' },
        { value: 'manual', label: 'As ordered in the admin' },
        ...fields.flatMap((field) => [
            { value: field.handle, label: `${field.label}, lowest first` },
            { value: `-${field.handle}`, label: `${field.label}, highest first` },
        ]),
    ];
}

/** One filter of a query for entries: a field, how it is compared, and with what. */
export type Where = { field: string; operator: string; value: string };

export const OPERATORS: { operator: string; label: string }[] = [
    { operator: '=', label: 'is' },
    { operator: '!=', label: 'is not' },
    { operator: '>', label: 'is more than' },
    { operator: '>=', label: 'is at least' },
    { operator: '<', label: 'is less than' },
    { operator: '<=', label: 'is at most' },
];

/** Filters as the `where` a component is given: `[['price', '>', 10]]`. Null for none. */
export function writeWhere(rows: Where[]): string | null {
    const kept = rows.filter((row) => row.field.trim() !== '');

    return kept.length === 0 ? null : `[${kept.map((row) => `[${literal(row.field.trim())}, ${literal(row.operator)}, ${literal(row.value)}]`).join(', ')}]`;
}

/**
 * A `where` read back into filters. Null when it is written another way
 * (`['author' => 3]`, a variable): it is then left as it is, to change
 * as code.
 */
export function readWhere(code: string | null): Where[] | null {
    const text = (code ?? '').trim();
    if (text === '' || text === '[]') {
        return [];
    }
    const outer = /^\[([\s\S]*)\]$/.exec(text);
    if (outer === null) {
        return null;
    }
    const one = /\[\s*('(?:[^'\\]|\\.)*')\s*,\s*('(?:[^'\\]|\\.)*')\s*,\s*('(?:[^'\\]|\\.)*'|-?\d+(?:\.\d+)?|true|false|null)\s*\]/y;
    const rows: Where[] = [];
    let at = 0;
    const inner = outer[1];
    while (at < inner.length) {
        at += /^\s*,?\s*/.exec(inner.slice(at))?.[0].length ?? 0;
        if (at >= inner.length) {
            break;
        }
        one.lastIndex = at;
        const match = one.exec(inner);
        const [field, operator, value] = match === null ? [null, null, null] : [unliteral(match[1]), unliteral(match[2]), unliteral(match[3])];
        if (match === null || field === null || operator === null || value === null || !OPERATORS.some((each) => each.operator === operator)) {
            return null;
        }
        rows.push({ field, operator, value });
        at = one.lastIndex;
    }

    return rows;
}
