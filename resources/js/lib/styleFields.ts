/**
 * The Style tab: a control for each of the declarations most often
 * written, reading and writing the rule for a class.
 */
import type { StyleRule, Styles } from './api';

export type StyleControl = {
    property: string;
    label: string;
    /** text: typed as CSS writes it. choice: one of a few words. colour: typed, or picked. */
    kind: 'text' | 'choice' | 'colour';
    /** For a choice: the words to choose from. */
    options?: string[];
    /** What a value looks like, shown when there is nothing else to show. */
    example?: string;
    /** Only shown for an element laid out this way: what it sets means nothing otherwise. */
    when?: 'flex' | 'grid';
    /** What the page has for it is not worth showing: a long list of everything it is short for. */
    quiet?: boolean;
};

export const STYLE_GROUPS: { title: string; controls: StyleControl[] }[] = [
    {
        title: 'Layout',
        controls: [
            { property: 'display', label: 'Display', kind: 'choice', options: ['block', 'inline-block', 'inline', 'flex', 'inline-flex', 'grid', 'none'] },
            { property: 'flex-direction', label: 'Direction', kind: 'choice', options: ['row', 'column', 'row-reverse', 'column-reverse'], when: 'flex' },
            { property: 'flex-wrap', label: 'Wrap', kind: 'choice', options: ['nowrap', 'wrap', 'wrap-reverse'], when: 'flex' },
            { property: 'justify-content', label: 'Along', kind: 'choice', options: ['flex-start', 'center', 'flex-end', 'space-between', 'space-around', 'space-evenly'], when: 'flex' },
            { property: 'align-items', label: 'Across', kind: 'choice', options: ['stretch', 'flex-start', 'center', 'flex-end', 'baseline'], when: 'flex' },
            { property: 'grid-template-columns', label: 'Columns', kind: 'text', example: 'repeat(3, 1fr)', when: 'grid', quiet: true },
            { property: 'gap', label: 'Gap', kind: 'text', example: '1rem' },
        ],
    },
    {
        title: 'Size',
        controls: [
            { property: 'width', label: 'Width', kind: 'text', example: 'auto' },
            { property: 'height', label: 'Height', kind: 'text', example: 'auto' },
            { property: 'max-width', label: 'Max width', kind: 'text', example: 'none' },
            { property: 'min-height', label: 'Min height', kind: 'text', example: '0' },
        ],
    },
    {
        title: 'Spacing',
        controls: [
            { property: 'margin', label: 'Margin', kind: 'text', example: '0 auto' },
            { property: 'padding', label: 'Padding', kind: 'text', example: '1rem 2rem' },
        ],
    },
    {
        title: 'Text',
        controls: [
            { property: 'font-family', label: 'Font', kind: 'text', example: 'system-ui, sans-serif' },
            { property: 'font-size', label: 'Size', kind: 'text', example: '1rem' },
            { property: 'font-weight', label: 'Weight', kind: 'choice', options: ['300', '400', '500', '600', '700', '800'] },
            { property: 'line-height', label: 'Line height', kind: 'text', example: '1.5' },
            { property: 'letter-spacing', label: 'Letter spacing', kind: 'text', example: '0.02em' },
            { property: 'text-align', label: 'Align', kind: 'choice', options: ['left', 'center', 'right', 'justify'] },
            { property: 'text-transform', label: 'Case', kind: 'choice', options: ['none', 'uppercase', 'lowercase', 'capitalize'] },
            { property: 'color', label: 'Colour', kind: 'colour' },
        ],
    },
    {
        title: 'Background',
        controls: [
            { property: 'background-color', label: 'Colour', kind: 'colour' },
            { property: 'background-image', label: 'Image', kind: 'text', example: 'url(/storage/hero.jpg)', quiet: true },
        ],
    },
    {
        title: 'Border',
        controls: [
            { property: 'border', label: 'Border', kind: 'text', example: '1px solid #e4e4e7', quiet: true },
            { property: 'border-radius', label: 'Radius', kind: 'text', example: '8px' },
            { property: 'box-shadow', label: 'Shadow', kind: 'text', example: '0 1px 2px rgb(0 0 0 / 10%)', quiet: true },
        ],
    },
];

/** Every property the tab has a control for: what the page is asked about. */
export const STYLE_PROPERTIES: string[] = STYLE_GROUPS.flatMap((group) => group.controls.map((control) => control.property));

/**
 * The rule the controls read and write, by its place in the list: the one
 * for the class itself, outside any @media. Where several are, the last,
 * since what it says counts over what the ones before it say. Null when
 * the class has no rule of its own yet.
 */
export function targetRule(styles: Styles): number | null {
    let found: number | null = null;
    styles.rules.forEach((rule, at) => {
        if (rule.exact && rule.within.length === 0) {
            found = at;
        }
    });

    return found;
}

/** Where in a rule a property is declared: the last time, which is the one that counts. -1 when it is not. */
export function declaredAt(rule: StyleRule, property: string): number {
    for (let index = rule.declarations.length - 1; index >= 0; index--) {
        if (rule.declarations[index].property.toLowerCase() === property.toLowerCase()) {
            return index;
        }
    }

    return -1;
}

/** What a rule says for a property; null when it says nothing. */
export function declared(rule: StyleRule | null, property: string): string | null {
    const index = rule === null ? -1 : declaredAt(rule, property);

    return rule === null || index === -1 ? null : rule.declarations[index].value;
}

/** Whether a control is shown: one for flex or grid only when the element is laid out so, by its rule or on the page. */
export function shown(control: StyleControl, rule: StyleRule | null, onPage: Record<string, string> | null): boolean {
    if (control.when === undefined) {
        return true;
    }
    const display = declared(rule, 'display') ?? onPage?.display ?? '';

    return display.includes(control.when) || declared(rule, control.property) !== null;
}

/**
 * A colour as a colour picker wants it, "#rrggbb"; null for what is no
 * plain colour (a variable, a name, one that is partly see-through).
 */
export function hexOf(value: string | null): string | null {
    const text = (value ?? '').trim().toLowerCase();
    const short = /^#([0-9a-f])([0-9a-f])([0-9a-f])$/.exec(text);
    if (short !== null) {
        return `#${short[1]}${short[1]}${short[2]}${short[2]}${short[3]}${short[3]}`;
    }
    if (/^#[0-9a-f]{6}$/.test(text)) {
        return text;
    }
    const rgb = /^rgba?\(\s*(\d{1,3})\s*[, ]\s*(\d{1,3})\s*[, ]\s*(\d{1,3})\s*(?:[,/]\s*(1|1\.0+|100%)\s*)?\)$/.exec(text);
    if (rgb !== null && [rgb[1], rgb[2], rgb[3]].every((part) => Number(part) <= 255)) {
        return `#${[rgb[1], rgb[2], rgb[3]].map((part) => Number(part).toString(16).padStart(2, '0')).join('')}`;
    }

    return null;
}
