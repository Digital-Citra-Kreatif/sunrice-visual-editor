/**
 * The code editor: CodeMirror, set up for a Blade template. Built into a
 * script of its own (dist/code-editor.js) that is fetched the first time
 * code is shown, so the rest of the admin does not carry it. No React in
 * here: the panel that shows it is in the editor's main script.
 *
 * One editor shows one text at a time and keeps others aside (`show`): a
 * tab that is come back to has its text, its undo, its cursor and where it
 * was scrolled to.
 */
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands';
import { bracketMatching, indentUnit } from '@codemirror/language';
import { highlightSelectionMatches, search, searchKeymap } from '@codemirror/search';
import { ChangeSet, Compartment, EditorState, StateEffect, StateField, type Range, type Text } from '@codemirror/state';
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
import { coloured, difference, indentOf, lineBreaksOf } from './text.ts';

export type CodeEditorOptions = {
    /** What the editor is called for someone who can't see it. */
    label: string;
    /** The text is no longer what it was: here it is, with the file's own line breaks. */
    onChange(text: string): void;
    /** Ctrl+S or ⌘S. */
    onSave(): void;
    /** The cursor is somewhere else: where that is in the file as it was saved, counted in characters. */
    onCursor?(place: number): void;
};

/** A text to show: what is typed, and the file it is a change to. */
export type Shown = {
    /** The text as it is now. */
    text: string;
    /** The file as it is saved: places in it can be carried into the text, and back. */
    saved: string;
    readOnly: boolean;
};

export type CodeEditor = {
    text(): string;
    /**
     * Shows the text kept under a name, as it was left (its undo, its
     * cursor, where it was scrolled to) when what is kept is this text;
     * else this text, new.
     */
    show(name: string, shown: Shown): void;
    /** Forgets what is kept under a name: its tab is closed. */
    forget(name: string): void;
    /** Puts another text in the place of the one shown, as one step that can be undone. */
    setText(text: string): void;
    /** The file now holds this: places in the file are counted in it from here on. */
    setSaved(saved: string): void;
    setReadOnly(readOnly: boolean): void;
    /** Puts the cursor at a place of the file as saved, counted in characters, wherever that now is in the text; and the keyboard there too, when asked. */
    goTo(place: number, focus: boolean): void;
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

/** The file as saved, and everything typed since: what a place in the one is in the other. */
type Since = { saved: Text; changes: ChangeSet };

const savedAs = StateEffect.define<Since>();

const since = StateField.define<Since>({
    create: (state) => ({ saved: state.doc, changes: ChangeSet.empty(state.doc.length) }),
    update(value, transaction) {
        let now = transaction.docChanged ? { saved: value.saved, changes: value.changes.compose(transaction.changes) } : value;
        for (const effect of transaction.effects) {
            if (effect.is(savedAs)) {
                now = effect.value;
            }
        }

        return now;
    },
});

/** What turns the saved file into a text, when all that is known is the two of them. */
function sinceSaved(state: EditorState, saved: string): Since {
    const before = state.toText(saved);
    const change = difference(before.toString(), state.doc.toString());

    return { saved: before, changes: ChangeSet.of(change.from === change.to && change.insert === '' ? [] : [{ from: change.from, to: change.to, insert: change.insert }], before.length) };
}

/**
 * A place counted in characters of the text as a file writes it, as the
 * editor counts it, and back. They differ where lines end "\r\n": the
 * editor counts a line break as one character whatever it is.
 */
function inEditor(doc: Text, place: number, windows: boolean): number {
    if (!windows) {
        return Math.max(0, Math.min(place, doc.length));
    }
    let line = doc.lineAt(Math.max(0, Math.min(place, doc.length)));
    while (line.number > 1 && line.from + line.number - 1 > place) {
        line = doc.line(line.number - 1);
    }

    return Math.max(line.from, Math.min(place - (line.number - 1), line.to));
}

function inFile(doc: Text, position: number, windows: boolean): number {
    return windows ? position + doc.lineAt(position).number - 1 : position;
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
    /** The text as the file writes it: the editor counts a line break as one character whatever it is. */
    const written = (state: EditorState) => state.sliceDoc();
    const windows = (state: EditorState) => state.lineBreak === '\r\n';
    /** Where the cursor is, as a place in the saved file counted in characters. */
    const cursorInSaved = (state: EditorState) => {
        const { saved, changes } = state.field(since);

        return inFile(saved, changes.invertedDesc.mapPos(state.selection.main.head, -1), windows(state));
    };

    /** A text as the editor holds it, new: nothing typed, nothing to undo. */
    const make = ({ text, saved, readOnly: locked }: Shown): EditorState => {
        const lineBreaks = lineBreaksOf(text);

        return EditorState.create({
            doc: text,
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
                indentUnit.of(indentOf(text)),
                EditorState.tabSize.of(4),
                colours,
                since.init((state) => sinceSaved(state, saved)),
                look,
                readOnly.of(EditorState.readOnly.of(locked)),
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
                    // Only a cursor moved by whoever is in the editor: not one put somewhere by the editor itself,
                    // or by a browser settling its own selection while the keyboard is elsewhere.
                    if (update.selectionSet && !sent && update.view.hasFocus && options.onCursor !== undefined) {
                        options.onCursor(cursorInSaved(update.state));
                    }
                }),
            ],
        });
    };

    const view = new EditorView({ parent, state: make({ text: '', saved: '', readOnly: true }) });
    /** The texts put aside, each as it was left, and where it was scrolled to. */
    const kept = new Map<string, { state: EditorState; scroll: StateEffect<unknown> }>();
    let showing: string | null = null;
    /** The cursor is being put somewhere by goTo, not moved by whoever is typing. */
    let sent = false;

    const editor: CodeEditor = {
        text: () => written(view.state),
        show: (name, shown) => {
            if (showing !== null && showing !== name) {
                kept.set(showing, { state: view.state, scroll: view.scrollSnapshot() });
            }
            const had = showing === name ? { state: view.state, scroll: null } : (kept.get(name) ?? null);
            showing = name;
            if (had === null || written(had.state) !== shown.text) {
                view.setState(make(shown));

                return;
            }
            if (had.state !== view.state) {
                view.setState(had.state);
            }
            view.dispatch({
                effects: [
                    readOnly.reconfigure(EditorState.readOnly.of(shown.readOnly)),
                    ...(view.state.field(since).saved.eq(view.state.toText(shown.saved)) ? [] : [savedAs.of(sinceSaved(view.state, shown.saved))]),
                    ...(had.scroll === null ? [] : [had.scroll]),
                ],
            });
        },
        forget: (name) => void kept.delete(name),
        setText: (text) => {
            if (text !== written(view.state)) {
                view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: text } });
            }
        },
        setSaved: (saved) => {
            if (!view.state.field(since).saved.eq(view.state.toText(saved))) {
                view.dispatch({ effects: savedAs.of(sinceSaved(view.state, saved)) });
            }
        },
        setReadOnly: (now) => view.dispatch({ effects: readOnly.reconfigure(EditorState.readOnly.of(now)) }),
        goTo: (place, focus) => {
            const { saved, changes } = view.state.field(since);
            const position = changes.mapPos(inEditor(saved, place, windows(view.state)), 1);
            // Not said back as the cursor having moved: it was sent there.
            sent = true;
            view.dispatch({ selection: { anchor: position }, effects: EditorView.scrollIntoView(position, { y: 'center' }) });
            sent = false;
            if (focus) {
                view.focus();
            }
        },
        focus: () => view.focus(),
        destroy: () => view.destroy(),
    };
    // The editor can be asked from the element it is in: the browser check reads and sets the text this way.
    (parent as HTMLElement & { sveCodeEditor?: CodeEditor }).sveCodeEditor = editor;

    return editor;
}
