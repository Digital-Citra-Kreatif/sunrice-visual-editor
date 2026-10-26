/**
 * What the code editor offers: while a variable is being typed, what it
 * has; asked for (Ctrl+Space), every field and variable at the cursor in
 * each way it can be written. No CodeMirror in here: this says what to
 * offer, the editor shows it.
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

/** The most that is offered when everything at the place is asked for: all of it, short of a runaway list. */
const ALL = 400;

type Reached = { trail: string[]; field: PickField };

/** Everything that can be read from a value, with the way to it, the nearest first. */
function reached(of: PickVariable | PickField, trail: string[] = []): Reached[] {
    const here = of.groups.flatMap((group) => group.fields.map((field) => ({ trail: [...trail, field.label], field })));

    return [...here, ...here.flatMap(({ trail: to, field }) => reached(field, to))];
}

/** What a field is, and where only some of the collections sharing the template have it: "text · Not in Pages". */
function detailOf(field: PickField): string {
    return field.note === null ? field.what : `${field.what} · ${field.note}`;
}

/** What a variable is called in a trail: "$entry", or for what is no variable its title ("Site", "Main menu"). */
function nameOf(variable: PickVariable): string {
    return variable.code.startsWith('$') ? variable.code : variable.title;
}

/**
 * Everything at the place written as Blade of its own, each way it can be
 * (an image as an `<img>` or a link to the file, a list as a loop or with
 * commas), the place and the template before the site, for the words at
 * the end of what was typed.
 */
function browsed(before: string, variables: PickVariable[]): Offers | null {
    const word = /\w*$/.exec(before)?.[0] ?? '';
    const typed = word.toLowerCase();
    const offers = [...variables]
        .sort((a, b) => Number(a.from === 'site') - Number(b.from === 'site'))
        .flatMap((variable) => [
            { trail: [nameOf(variable)], value: variable as PickVariable | PickField, detail: variable.what },
            ...reached(variable).map(({ trail, field }) => ({ trail: [nameOf(variable), ...trail], value: field as PickVariable | PickField, detail: detailOf(field) })),
        ])
        .filter(({ trail, value }) => typed === '' || `${trail.join(' ')} ${value.code}`.toLowerCase().includes(typed))
        .flatMap(({ trail, value, detail }) =>
            value.inserts.map((insert, index) => ({
                label: index === 0 ? trail.join(' › ') : `${trail.join(' › ')} (${insert.title.toLowerCase()})`,
                // The usual way says how it is written, unless that is what the value is ("text").
                detail: index === 0 && insert.title.toLowerCase() !== value.what ? `${detail} · ${insert.title.toLowerCase()}` : detail,
                insert: insert.blade,
            })),
        )
        .slice(0, ALL);

    return offers.length === 0 ? null : { from: before.length - word.length, offers };
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
 * - Asked for (`explicit`, Ctrl+Space) anywhere else: everything at the
 *   place, written as Blade of its own each way it can be.
 */
export function offersFor(before: string, variables: PickVariable[], explicit = false): Offers | null {
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
            .map(({ trail, field }) => ({ label: trail.join(' › '), detail: detailOf(field), insert: field.expression ?? '' }));

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
                    .map(({ trail, field }) => ({ label: `${variable.code} › ${trail.join(' › ')}`, detail: detailOf(field), insert: field.expression ?? '', site: variable.from === 'site' })),
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

    return explicit ? browsed(before, variables) : null;
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
