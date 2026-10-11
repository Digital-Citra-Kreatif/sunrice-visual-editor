/**
 * The field picker: what the server says can be read at a place, found by
 * what is typed, laid out for the panel, and written into a control.
 */
import type { FieldInsert, Operation, PickField, PickVariable, TreeNode } from './api';

/** Where the variables of a place come from, as the panel groups them. */
export type Origin = 'here' | 'template' | 'site';

export const ORIGINS: { origin: Origin; title: string }[] = [
    { origin: 'here', title: 'At this place' },
    { origin: 'template', title: 'This template' },
    { origin: 'site', title: 'The site' },
];

export function originOf(variable: PickVariable): Origin {
    return variable.from === 'site' ? 'site' : variable.from === 'page' || variable.from === 'included' ? 'template' : 'here';
}

/** A value of the picker with the way to it: "Entry", "Cover", "Alt text". */
export type Found = {
    /** What sets it apart: the code that reads it. */
    key: string;
    trail: string[];
    value: PickVariable | PickField;
    /** "Not in Pages", for a field only some collections have. */
    note: string | null;
};

/** What a variable is called in a trail: "$entry", or for what is no variable its title ("Site", "Main menu"). */
export function nameOf(variable: PickVariable): string {
    return variable.code.startsWith('$') ? variable.code : variable.title;
}

/** Every value there is to pick, each with the way to it, in the order of the panel. */
export function everything(variables: PickVariable[]): Found[] {
    const found: Found[] = [];
    const into = (trail: string[], fields: PickField[]) => {
        for (const field of fields) {
            const here = [...trail, field.label];
            found.push({ key: field.code, trail: here, value: field, note: field.note });
            for (const group of field.groups) {
                into(here, group.fields);
            }
        }
    };
    for (const variable of variables) {
        found.push({ key: variable.code, trail: [nameOf(variable)], value: variable, note: null });
        for (const group of variable.groups) {
            into([nameOf(variable)], group.fields);
        }
    }

    return found;
}

/**
 * What matches what was typed: every word typed is in the way to it, its
 * code or what it is. Nothing typed: nothing, the panel shows the tree.
 */
export function matchingFields(variables: PickVariable[], typed: string): Found[] {
    const words = typed.toLowerCase().split(/\s+/).filter((word) => word !== '');
    if (words.length === 0) {
        return [];
    }

    return everything(variables).filter((found) => {
        const text = `${found.trail.join(' ')} ${found.value.code} ${found.value.what}`.toLowerCase();

        return words.every((word) => text.includes(word));
    });
}

/** What can be written where a text goes: in a text, an echo, an attribute. */
export function printable(variables: PickVariable[]): Found[] {
    return everything(variables).filter((found) => found.value.print !== null);
}

/** What a click on a value inserts: the usual way, or null when it is only read into. */
export function usual(value: PickVariable | PickField): FieldInsert | null {
    return value.inserts[0] ?? null;
}

const squeezed = (code: string) => code.replace(/\s+/g, '');

/**
 * The value an expression reads, when it is one of those at the place:
 * for saying "Entry › Title" beside `$entry->title`.
 */
export function boundTo(variables: PickVariable[] | null, expression: string | null): Found | null {
    if (variables === null || expression === null || expression.trim() === '') {
        return null;
    }
    const wanted = squeezed(expression);

    return everything(variables).find((found) => squeezed(found.value.code) === wanted || (found.value.expression !== null && squeezed(found.value.expression) === wanted)) ?? null;
}

/** The expression of an attribute's value when the value is one echo and nothing else: `{{ $entry->url }}`. */
export function echoed(value: string | null): string | null {
    const match = /^\s*\{\{\s*([\s\S]+?)\s*\}\}\s*$/.exec(value ?? '');

    return match === null || match[1].includes('}}') ? null : match[1];
}

/**
 * The operations that make a control show a field.
 *
 * A text becomes the field's echo: the words are emptied and the echo put
 * after them. An echo is given the field's expression, and printed as HTML
 * or not as the field wants. An attribute's value becomes `{{ … }}`.
 */
export function binding(node: TreeNode, to: PickVariable | PickField, attribute: string | null = null): Operation[] {
    if (to.print === null || to.expression === null) {
        return [];
    }
    if (attribute !== null) {
        return [{ type: 'setAttribute', node: node.id, name: attribute, value: `{{ ${to.expression} }}`, blade: true }];
    }
    if (node.type === 'text') {
        return [
            { type: 'setText', node: node.id, text: '' },
            { type: 'insertAfter', node: node.id, blade: to.print },
        ];
    }
    const raw = to.print.startsWith('{!!');
    const isRaw = node.atom === 'raw_echo';

    return [{ type: 'setExpression', node: node.id, expression: to.expression }, ...(raw === isRaw ? [] : [{ type: 'setEchoRaw' as const, node: node.id, raw }])];
}
