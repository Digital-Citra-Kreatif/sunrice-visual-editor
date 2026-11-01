/**
 * The languages of the custom CSS and JS tabs: CodeMirror's CSS and
 * JavaScript, for colours and, for JS, a first look at its syntax.
 *
 * Not in code-editor.js itself: esbuild splits this off into a chunk of its
 * own (dist/code-languages-<hash>.js) that the code editor imports the first
 * time one of those tabs is shown. Templates, edited far more often, never
 * fetch it. It shares the code editor's CodeMirror, which must not be loaded
 * twice.
 */
import { css } from '@codemirror/lang-css';
import { javascript } from '@codemirror/lang-javascript';
import { ensureSyntaxTree, HighlightStyle, syntaxHighlighting, syntaxTree } from '@codemirror/language';
import type { EditorState, Extension } from '@codemirror/state';
import { tags } from '@lezer/highlight';

/** The code panel's own colours (visual-editor.css), for what these languages tell apart. */
const colours = HighlightStyle.define([
    { tag: [tags.comment, tags.lineComment, tags.blockComment], class: 'sve-code--comment' },
    { tag: [tags.string, tags.special(tags.string), tags.regexp, tags.escape], class: 'sve-code--string' },
    { tag: [tags.keyword, tags.controlKeyword, tags.definitionKeyword, tags.moduleKeyword, tags.operatorKeyword, tags.modifier], class: 'sve-code--directive' },
    { tag: [tags.propertyName, tags.attributeName, tags.definition(tags.propertyName)], class: 'sve-code--attribute' },
    { tag: [tags.tagName, tags.className, tags.typeName, tags.labelName, tags.constant(tags.className)], class: 'sve-code--tag' },
    { tag: [tags.number, tags.bool, tags.null, tags.atom, tags.unit, tags.color, tags.self], class: 'sve-code--php' },
]);

export type Language = {
    extension: Extension;
    /** Where the parser read something it could not make sense of: none for CSS, whose parser stumbles on valid CSS newer than it. */
    problems(state: EditorState): { from: number; to: number }[];
};

/** How many places of one text are marked at most: a parser that lost its way finds many more after the first. */
const MOST = 10;

export function language(kind: 'css' | 'js'): Language {
    if (kind === 'css') {
        return { extension: [css(), syntaxHighlighting(colours)], problems: () => [] };
    }

    return {
        extension: [javascript(), syntaxHighlighting(colours)],
        problems: (state) => {
            const found: { from: number; to: number }[] = [];
            (ensureSyntaxTree(state, state.doc.length, 200) ?? syntaxTree(state)).iterate({
                enter: (node) => {
                    if (found.length < MOST && node.type.isError) {
                        found.push({ from: node.from, to: Math.max(node.to, Math.min(node.from + 1, state.doc.length)) });
                    }

                    return found.length < MOST;
                },
            });

            return found;
        },
    };
}
