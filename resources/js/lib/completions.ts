/**
 * What the code editor offers while a variable is being typed: the fields
 * the picker lists at the cursor, written the way the picker writes them.
 * No CodeMirror in here: this says what to offer, the editor shows it.
 */
import type { PickField, PickVariable } from './api';

export type Offer = {
    /** What the row says: "Cover › Alt text". */
    label: string;
    /** What it is: "text", "file", "list of entries". */
    detail: string;
    /** What is written in place of what was typed. */
    insert: string;
};

export type Offers = {
    /** Where what is replaced begins, in the text before the cursor. */
    from: number;
    offers: Offer[];
};

/** The most that is offered at once: beyond it the list is no help. */
const MOST = 60;

type Reached = { trail: string[]; field: PickField };

/** Everything that can be read from a value, with the way to it, the nearest first. */
function reached(of: PickVariable | PickField, trail: string[] = []): Reached[] {
    const here = of.groups.flatMap((group) => group.fields.map((field) => ({ trail: [...trail, field.label], field })));

    return [...here, ...here.flatMap(({ trail: to, field }) => reached(field, to))];
}

/** Whether what was typed after "->" could be meant for a field: part of its handle, or of what it is called. */
function meant(typed: string, { trail, field }: Reached): boolean {
    const wanted = typed.replace(/['"]/g, '').toLowerCase();

    return wanted === '' || field.handle.toLowerCase().includes(wanted) || trail.join(' ').toLowerCase().includes(wanted);
}

/**
 * What to offer for the text on the cursor's line before the cursor; null
 * when nothing of it is the start of a variable.
 *
 * - After `$entry->` or `$row['` (and whatever of a name follows): what
 *   that value has, each written whole and guarded as the picker writes
 *   it, in place of the variable and what was typed after it.
 * - After `{{ ` or `{!! `: everything at the place that can be printed.
 * - After a `$` and the start of a name: the variables at the place.
 */
export function offersFor(before: string, variables: PickVariable[]): Offers | null {
    // The longest way of writing a value first: "sunrice_global('site')" before anything it starts with.
    for (const variable of [...variables].sort((a, b) => b.code.length - a.code.length)) {
        const at = before.lastIndexOf(variable.code);
        const after = at === -1 ? null : /^(?:\?->|->|\[['"]?)([\w'"]*)$/.exec(before.slice(at + variable.code.length));
        // Not the end of a longer name: "$entry" in "$myentry->".
        if (after === null || (at > 0 && /[\w$]/.test(before[at - 1]) && variable.code.startsWith('$'))) {
            continue;
        }
        const offers = reached(variable)
            .filter((each) => each.field.expression !== null && meant(after[1], each))
            .slice(0, MOST)
            .map(({ trail, field }) => ({ label: trail.join(' › '), detail: field.what, insert: field.expression ?? '' }));

        return offers.length === 0 ? null : { from: at, offers };
    }

    const echo = /(\{\{|\{!!)\s*(\$?\w*)$/.exec(before);
    if (echo !== null) {
        const typed = echo[2].replace(/^\$/, '').toLowerCase();
        const printed = variables
            .flatMap((variable) => [
                ...(variable.expression === null ? [] : [{ label: variable.code, detail: variable.title, insert: variable.expression, site: variable.from === 'site' }]),
                ...reached(variable)
                    .filter(({ field }) => field.expression !== null && (echo[1] === '{!!' || field.print === null || !field.print.startsWith('{!!')))
                    .map(({ trail, field }) => ({ label: `${variable.code} › ${trail.join(' › ')}`, detail: field.what, insert: field.expression ?? '', site: variable.from === 'site' })),
            ])
            .filter((offer) => typed === '' || offer.label.toLowerCase().includes(typed))
            // What the place and the template have before what every template has.
            .sort((a, b) => Number(a.site) - Number(b.site))
            .slice(0, MOST)
            .map(({ label, detail, insert }) => ({ label, detail, insert }));

        return printed.length === 0 ? null : { from: before.length - echo[2].length, offers: printed };
    }

    const name = /(?<![\w$>])\$(\w*)$/.exec(before);
    if (name !== null) {
        const offers = variables
            .filter((variable) => variable.code.startsWith('$') && variable.code.slice(1).toLowerCase().startsWith(name[1].toLowerCase()) && variable.code !== `$${name[1]}`)
            .map((variable) => ({ label: variable.code, detail: variable.title, insert: variable.code }));

        return offers.length === 0 ? null : { from: before.length - name[0].length, offers };
    }

    return null;
}

/**
 * Blade to put in at the cursor, with every line after its first indented
 * as the cursor's line is: a loop the picker writes keeps its shape where
 * it lands.
 */
export function indented(blade: string, lineBefore: string): string {
    const indent = /^[ \t]*/.exec(lineBefore)?.[0] ?? '';

    return blade.replace(/\n(?=[^\n])/g, `\n${indent}`);
}
