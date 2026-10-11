/**
 * @media conditions about the width of the page: which of a stylesheet's
 * apply at a width, and so where a rule for that width goes.
 */
import type { Styles } from './api';

/** One way of writing it: spaces as one, case aside. */
export function sameMedia(a: string, b: string): boolean {
    const plain = (text: string) => text.replace(/\s+/g, ' ').trim().toLowerCase();

    return plain(a) === plain(b);
}

/** A length in CSS pixels; null for one that depends on more than the page's width. */
function pixels(text: string): number | null {
    const match = /^(-?\d*\.?\d+)(px|em|rem)?$/.exec(text.trim().toLowerCase());
    if (match === null || (match[2] === undefined && Number(match[1]) !== 0)) {
        return null;
    }

    return Number(match[1]) * (match[2] === 'em' || match[2] === 'rem' ? 16 : 1);
}

/**
 * The widths a @media block is for, from `min` to `max`, both counted in.
 * Null when it is about anything but the width of a screen: print, a
 * colour scheme, a list of alternatives.
 */
export function widthRange(prelude: string): { min: number; max: number } | null {
    const condition = prelude.replace(/^\s*@media\b/i, '').trim().toLowerCase();
    if (condition === '' || condition.includes(',')) {
        return null;
    }
    let min = 0;
    let max = Infinity;
    let about = false;
    for (const part of condition.split(/\s+and\s+/)) {
        const text = part.trim();
        if (text === 'screen' || text === 'all' || text === 'only screen') {
            continue;
        }
        const named = /^\(\s*(min|max)-width\s*:\s*([^)]+)\)$/.exec(text);
        const compared = /^\(\s*width\s*(<=|>=|<|>)\s*([^)]+)\)$/.exec(text);
        const between = /^\(\s*([^<>=()]+?)\s*(<=|<)\s*width\s*(<=|<)\s*([^)]+)\)$/.exec(text);
        if (named !== null) {
            const length = pixels(named[2]);
            if (length === null) {
                return null;
            }
            named[1] === 'min' ? (min = Math.max(min, length)) : (max = Math.min(max, length));
        } else if (compared !== null) {
            const length = pixels(compared[2]);
            if (length === null) {
                return null;
            }
            // Less than a width is, for whole pixels, up to the one before it.
            compared[1] === '>=' ? (min = Math.max(min, length)) : compared[1] === '>' ? (min = Math.max(min, length + 0.01)) : compared[1] === '<=' ? (max = Math.min(max, length)) : (max = Math.min(max, length - 0.01));
        } else if (between !== null) {
            const from = pixels(between[1]);
            const to = pixels(between[4]);
            if (from === null || to === null) {
                return null;
            }
            min = Math.max(min, between[2] === '<' ? from + 0.01 : from);
            max = Math.min(max, between[3] === '<' ? to - 0.01 : to);
        } else {
            return null;
        }
        about = true;
    }

    return about ? { min, max } : null;
}

/** Whether a @media block is for a page of this width, and for nothing but a width. */
export function appliesAt(prelude: string, width: number): boolean {
    const range = widthRange(prelude);

    return range !== null && width >= range.min && width <= range.max;
}

/**
 * The @media conditions about width that the theme's stylesheets use, each
 * once, in the order they come: the blocks at the top of each file, and
 * what the rules for the class are inside.
 */
export function breakpoints(styles: Styles | null): string[] {
    const found: string[] = [];
    const add = (prelude: string) => {
        if (widthRange(prelude) !== null && !found.some((each) => sameMedia(each, prelude))) {
            found.push(prelude.replace(/\s+/g, ' ').trim());
        }
    };
    (styles?.media ?? []).forEach((block) => add(block.prelude));
    (styles?.rules ?? []).forEach((rule) => rule.within.length === 1 && add(rule.within[0]));

    return found;
}

/**
 * The @media condition a rule for a width goes under: of those the site
 * uses that apply at that width, the one for the fewest widths (the last
 * of equals). Null when none applies.
 */
export function mediaFor(points: string[], width: number): string | null {
    let best: { prelude: string; span: number } | null = null;
    for (const prelude of points) {
        const range = widthRange(prelude);
        if (range !== null && width >= range.min && width <= range.max && (best === null || range.max - range.min <= best.span)) {
            best = { prelude, span: range.max - range.min };
        }
    }

    return best?.prelude ?? null;
}

/** "(max-width: 640px)", as a setting has it, as the start of a block: "@media (max-width: 640px)". */
export function asMedia(condition: string): string {
    return /^\s*@media\b/i.test(condition) ? condition.trim() : `@media ${condition.trim()}`;
}

/** A @media condition as a person reads it: without the word every one starts with. */
export function mediaLabel(prelude: string): string {
    return prelude.replace(/^\s*@media\s*/i, '').trim();
}
