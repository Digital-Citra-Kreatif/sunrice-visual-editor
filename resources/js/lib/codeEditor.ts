/**
 * The code editor's script, fetched when code is first shown and once for
 * as long as the admin is open. Until it is there, and if it never comes,
 * the code panel shows its plain text field.
 */
import type { CodeEditor, CodeEditorOptions } from '../code/index.ts';

export type CodeEditorScript = { mount(parent: HTMLElement, options: CodeEditorOptions): CodeEditor };

let fetched: Promise<CodeEditorScript> | null = null;

export function codeEditorScript(address: string): Promise<CodeEditorScript> {
    fetched ??= (import(/* @vite-ignore */ address) as Promise<CodeEditorScript>).catch((error: unknown) => {
        // Asked for again the next time code is shown: the network may have been the matter.
        fetched = null;
        throw error;
    });

    return fetched;
}
