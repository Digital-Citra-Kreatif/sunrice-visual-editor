// The panel's runtime on window.Sunrice (sunrice/admin, runtime version 3),
// as far as the editor uses it. See the panel's docs, "Admin pages in React",
// and @sunrice/admin-sdk, which this mirrors: the editor's bundle is built
// with esbuild (build.mjs), not the SDK's Vite plugin.
import type { ComponentType, ReactNode } from 'react';

/** A page component; `bare` draws it without the panel's layout (sidebar, header). */
type PageComponent = ComponentType<any> & { bare?: boolean };

interface SunriceRuntime {
    version: number;
    /** The modules shared with plugin bundles: react, react-dom, @inertiajs/react … */
    modules: Record<string, Record<string, unknown>>;
    registerPage(name: string, component: PageComponent): void;
    /** 'visual-editor' → '/admin/visual-editor', with the panel's path. */
    adminUrl(path?: string): string;
}

declare global {
    var Sunrice: SunriceRuntime | undefined;
}

export {};
