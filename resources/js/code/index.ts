/**
 * The code editor: CodeMirror, set up for a Blade template. Built into a
 * script of its own (dist/code-editor.js) that is fetched the first time
 * code is shown, so the rest of the admin does not carry it. No React in
 * here: the panel that shows it is in the editor's main script.
 */
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands';
import { bracketMatching, indentUnit } from '@codemirror/language';
import { highlightSelectionMatches, search, searchKeymap } from '@codemirror/search';
import { Compartment, EditorState, StateField, type Range } from '@codemirror/state';
import {
    Decoration,
    type DecorationSet,
    drawSelection,
    EditorView,
    highlightActiveLine,
    highlightActiveLineGutter,
    highlightSpecialChars,
    keymap,
    lineNumbers,
    rectangularSelection,
} from '@codemirror/view';
import { coloured, lineBreaksOf } from './text.ts';

export type CodeEditorOptions = {
    text: string;
    readOnly: boolean;
    /** What Tab puts in. */
    indent: string;
    /** What the editor is called for someone who can't see it. */
    label: string;
    /** The text is no longer what it was: here it is, with the file's own line breaks. */
    onChange(text: string): void;
    /** Ctrl+S or ⌘S. */
    onSave(): void;
};

export type CodeEditor = {
    text(): string;
    /** Puts another text in its place, as one step that can be undone. */
    setText(text: string): void;
    setReadOnly(readOnly: boolean): void;
    focus(): void;
    destroy(): void;
};

/** One mark for each colour: the classes are the code panel's own (visual-editor.css). */
const marks = Object.fromEntries(
    (['comment', 'echo', 'directive', 'php', 'tag', 'attribute', 'string'] as const).map((kind) => [kind, Decoration.mark({ class: `sve-code--${kind}` })]),
);

/** The colours of the whole text, worked out again when it changes: templates are short. */
const colours = StateField.define<DecorationSet>({
    create: (state) => decorate(state.doc.toString()),
    update: (value, transaction) => (transaction.docChanged ? decorate(transaction.newDoc.toString()) : value),
    provide: (field) => EditorView.decorations.from(field),
});

function decorate(text: string): DecorationSet {
    const ranges: Range<Decoration>[] = coloured(text).map(({ from, to, kind }) => marks[kind].range(from, to));

    return Decoration.set(ranges);
}

/** How it looks, in the admin's own colours: they follow light and dark by themselves. */
const look = EditorView.theme({
    '&': { height: '100%', color: 'var(--foreground)', backgroundColor: 'transparent', fontSize: '0.75rem' },
    '&.cm-focused': { outline: 'none' },
    '.cm-scroller': { fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace', lineHeight: 'var(--sve-code-line, 1.25rem)' },
    '.cm-content': { padding: '0.5rem 0', caretColor: 'var(--foreground)' },
    '.cm-line': { padding: '0 0.75rem' },
    '.cm-gutters': { backgroundColor: 'var(--card)', color: 'var(--muted-foreground)', border: '0', borderInlineEnd: '1px solid var(--border)' },
    '.cm-lineNumbers .cm-gutterElement': { minWidth: '2.75rem', padding: '0 0.625rem 0 0.5rem' },
    '.cm-activeLine': { backgroundColor: 'color-mix(in oklab, var(--accent) 45%, transparent)' },
    '.cm-activeLineGutter': { backgroundColor: 'transparent', color: 'var(--foreground)' },
    '.cm-cursor, .cm-dropCursor': { borderInlineStartColor: 'var(--foreground)' },
    '&.cm-focused > .cm-scroller > .cm-selectionLayer .cm-selectionBackground, .cm-selectionBackground, .cm-content ::selection': {
        backgroundColor: 'color-mix(in oklab, var(--ring) 35%, transparent)',
    },
    '.cm-selectionMatch': { backgroundColor: 'color-mix(in oklab, var(--ring) 18%, transparent)' },
    '.cm-searchMatch': { backgroundColor: 'color-mix(in oklab, var(--ring) 30%, transparent)', outline: '1px solid var(--ring)' },
    '.cm-searchMatch.cm-searchMatch-selected': { backgroundColor: 'color-mix(in oklab, var(--ring) 60%, transparent)' },
    '&.cm-focused .cm-matchingBracket': { backgroundColor: 'color-mix(in oklab, var(--ring) 30%, transparent)', outline: '0' },
    '.cm-panels': { backgroundColor: 'var(--card)', color: 'var(--foreground)' },
    '.cm-panels.cm-panels-top': { borderBlockEnd: '1px solid var(--border)' },
    '.cm-panel.cm-search': { padding: '0.375rem 0.5rem', fontSize: '0.75rem' },
    '.cm-panel.cm-search label': { fontSize: 'inherit' },
    '.cm-textfield': { border: '1px solid var(--border)', borderRadius: 'calc(var(--radius) - 2px)', backgroundColor: 'var(--background)', color: 'var(--foreground)', fontSize: 'inherit' },
    '.cm-button': { border: '1px solid var(--border)', borderRadius: 'calc(var(--radius) - 2px)', backgroundImage: 'none', backgroundColor: 'var(--secondary)', color: 'var(--secondary-foreground)', fontSize: 'inherit' },
    '.cm-panel.cm-search [name=close]': { color: 'var(--muted-foreground)', cursor: 'pointer' },
});

export function mount(parent: HTMLElement, options: CodeEditorOptions): CodeEditor {
    const readOnly = new Compartment();
    const lineBreaks = lineBreaksOf(options.text);
    /** The text as the file writes it: the editor counts a line break as one character whatever it is. */
    const written = (state: EditorState) => state.sliceDoc();

    const view = new EditorView({
        parent,
        state: EditorState.create({
            doc: options.text,
            extensions: [
                // A file is given back with the line breaks it came with (see lineBreaksOf).
                ...(lineBreaks === 'unix' ? [] : [EditorState.lineSeparator.of(lineBreaks === 'windows' ? '\r\n' : '\n')]),
                lineNumbers(),
                highlightActiveLineGutter(),
                highlightSpecialChars(),
                history(),
                drawSelection(),
                EditorState.allowMultipleSelections.of(true),
                rectangularSelection(),
                bracketMatching(),
                highlightActiveLine(),
                highlightSelectionMatches(),
                search({ top: true }),
                indentUnit.of(options.indent),
                EditorState.tabSize.of(4),
                colours,
                look,
                readOnly.of(EditorState.readOnly.of(options.readOnly)),
                EditorView.contentAttributes.of({ 'aria-label': options.label, spellcheck: 'false', autocapitalize: 'off', autocorrect: 'off' }),
                keymap.of([
                    {
                        key: 'Mod-s',
                        preventDefault: true,
                        run: () => {
                            options.onSave();

                            return true;
                        },
                    },
                    // Tab indents. Escape first, then Tab, moves on to the next control as usual.
                    indentWithTab,
                    ...searchKeymap,
                    ...historyKeymap,
                    ...defaultKeymap,
                ]),
                EditorView.updateListener.of((update) => {
                    if (update.docChanged) {
                        options.onChange(written(update.state));
                    }
                }),
            ],
        }),
    });

    const editor: CodeEditor = {
        text: () => written(view.state),
        setText: (text) => {
            if (text !== written(view.state)) {
                view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: text } });
            }
        },
        setReadOnly: (now) => view.dispatch({ effects: readOnly.reconfigure(EditorState.readOnly.of(now)) }),
        focus: () => view.focus(),
        destroy: () => view.destroy(),
    };
    // The editor can be asked from the element it is in: the browser check reads and sets the text this way.
    (parent as HTMLElement & { sveCodeEditor?: CodeEditor }).sveCodeEditor = editor;

    return editor;
}
