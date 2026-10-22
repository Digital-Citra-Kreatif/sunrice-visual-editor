/**
 * The panel the editor runs in: its runtime on window.Sunrice (sunrice/admin,
 * runtime version 3).
 */

/** The runtime version this bundle is built for; another panel's refuses it, as @sunrice/admin-sdk's definePlugin() does. */
export const RUNTIME_VERSION = 3;

function runtime() {
    const sunrice = globalThis.Sunrice;
    if (sunrice === undefined) {
        throw new Error('[sunrice-visual-editor] This script runs only inside the Sunrice admin panel (window.Sunrice is missing).');
    }

    return sunrice;
}

/** 'visual-editor' → '/admin/visual-editor', whatever path the panel lives under. */
export function adminUrl(path = ''): string {
    return runtime().adminUrl(path);
}

/** Registers the editor's pages with the panel, refusing a panel of another runtime with an error that says what to do. */
export function definePages(pages: Record<string, Parameters<NonNullable<typeof globalThis.Sunrice>['registerPage']>[1]>): void {
    const sunrice = runtime();
    if (sunrice.version !== RUNTIME_VERSION) {
        throw new Error(`[sunrice-visual-editor] This build is for the panel's runtime ${RUNTIME_VERSION}, but the panel runs ${sunrice.version}: update the package to one for this Sunrice.`);
    }
    for (const [name, component] of Object.entries(pages)) {
        sunrice.registerPage(name, component);
    }
}
